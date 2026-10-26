<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationLink;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\InventoryValuationService;
use App\Services\Legacy\LegacyDataset;
use App\Services\Legacy\SourceImporter;
use App\Services\Legacy\SourceIntegrityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Orchestrates every stage of a migration run through the state machine; nothing here runs without the run lock. */
final class MigrationWorkflow
{
    private const PRE_FINANCIAL = ['uploaded', 'parsing', 'parsed', 'review', 'masters_preflight', 'masters_ready', 'masters_created', 'openings_preflight', 'openings_ready', 'failed_recoverable'];

    public function __construct(
        private readonly RunStateMachine $sm,
        private readonly SourceImporter $importer,
        private readonly MappingGenerator $mappings,
        private readonly MasterDataService $masters,
        private readonly OpeningBalanceService $openings,
        private readonly DocumentStager $stager,
        private readonly DocumentExecutionService $executor,
        private readonly ReconciliationService $reconciliation,
        private readonly PartyOpeningBalanceService $partyOpenings,
        private readonly AccountingService $accounting,
        private readonly InventoryValuationService $valuation,
    ) {}

    public function machine(): RunStateMachine
    {
        return $this->sm;
    }

    /** @return array<string, mixed> */
    public function parse(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'parse', self::PRE_FINANCIAL, function (DataMigrationRun $run) use ($actor): array {
            if ($this->sm->hasFinancialEffects($run)) {
                throw RunStateMachine::refuse('financial_effects_exist', 'The source cannot be analysed again after financial effects were posted.');
            }
            if ($run->source_removed_at !== null) {
                throw RunStateMachine::refuse('source_removed', 'The source file was removed by the retention policy.');
            }
            if ($run->status !== 'parsing') {
                $this->sm->transition($run, 'parsing', 'parse_started', $actor);
            }
            try {
                $integrity = $this->importer->import($run);
                $run->forceFill(['integrity' => $integrity])->save();
                $this->sm->transition($run, 'parsed', 'parsed', $actor, null, ['staged_rows' => array_sum($integrity['staged_rows'])]);
                $dataset = LegacyDataset::load($run);
                $counts = $run->mode === 'party_openings_phase1'
                    ? $this->mappings->generatePhaseOneParties($run, $dataset)
                    : $this->mappings->generate($run, $dataset);
                $run->forceFill(['summary' => array_merge($run->summary ?? [], ['mappings' => $counts])])->save();
                $this->sm->transition($run, 'review', 'mappings_generated', $actor, null, ['mappings' => array_sum(array_map('array_sum', $counts))]);

                return ['integrity' => $integrity, 'mappings' => $counts];
            } catch (SourceIntegrityException $e) {
                $run->forceFill(['integrity' => $e->integrity])->save();
                $this->sm->transition($run, 'failed_terminal', 'source_rejected', $actor, null, ['errors' => $e->integrity['error_count'] ?? 0], $e->getMessage());
                throw ValidationException::withMessages(['source' => $e->getMessage()]);
            } catch (Throwable $e) {
                if ($run->fresh()->status === 'parsing' || $run->fresh()->status === 'parsed') {
                    $this->sm->transition($run->fresh(), 'failed_recoverable', 'parse_failed', $actor, null, [], 'unexpected_error:'.class_basename($e));
                }
                report($e);
                throw $e;
            }
        });
    }

    /** @return array<string, mixed> */
    public function preflightMasters(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'masters_preflight', ['review', 'masters_preflight', 'masters_ready'], function (DataMigrationRun $run) use ($actor): array {
            if ($run->status !== 'masters_preflight') {
                if ($run->status === 'masters_ready') {
                    $this->sm->transition($run, 'review', 'masters_recheck', $actor);
                }
                $this->sm->transition($run, 'masters_preflight', 'masters_preflight', $actor);
            }
            $result = $this->masters->preflight($run);
            $run->forceFill(['preflight' => ['masters' => $result]])->save();
            $this->sm->transition($run, $result['ok'] ? 'masters_ready' : 'review', $result['ok'] ? 'masters_ready' : 'masters_blocked', $actor, null, ['blockers' => count($result['blockers'])]);

            return $result;
        });
    }

    /** @return array<string, int> */
    public function createMasters(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'masters_create', ['masters_ready'], function (DataMigrationRun $run) use ($actor): array {
            $check = $this->masters->preflight($run);
            if (! $check['ok']) {
                $run->forceFill(['preflight' => ['masters' => $check]])->save();
                $this->sm->transition($run, 'review', 'masters_blocked', $actor, null, ['blockers' => count($check['blockers'])]);
                throw RunStateMachine::refuse('preflight_failed', 'Master-data preflight found blockers; nothing was created.', ['blockers' => array_slice($check['blockers'], 0, 50)], 422);
            }
            try {
                $counts = $this->masters->create($run, $actor);
            } catch (Throwable $e) {
                $this->sm->record($run, 'masters_failed', $run->status, $run->status, $actor, null, [], $e instanceof ValidationException ? implode(' ', array_map('strval', array_merge(...array_values($e->errors())))) : 'unexpected_error:'.class_basename($e));
                throw $e;
            }
            $this->sm->transition($run, 'masters_created', 'masters_created', $actor, null, $counts);

            return $counts;
        });
    }

    /** @return array<string, mixed> */
    public function preflightPartyPhaseOne(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'phase1_party_preflight', ['review', 'party_openings_preflight', 'party_openings_ready'], function (DataMigrationRun $run) use ($actor): array {
            abort_unless($run->mode === 'party_openings_phase1', 409, 'This run is not scoped to Phase 1 party openings.');
            if ($run->status === 'party_openings_ready') {
                $this->sm->transition($run, 'party_openings_preflight', 'phase1_party_recheck', $actor);
            } elseif ($run->status === 'review') {
                $this->sm->transition($run, 'party_openings_preflight', 'phase1_party_preflight', $actor);
            }
            $preview = $this->partyOpenings->preview($run);
            $masters = $this->masters->preflightPhaseOne($run);
            $blockers = array_merge($preview['unresolved'], $masters['blockers']);
            $result = ['ok' => $blockers === [], 'blockers' => $blockers, 'preview' => $preview, 'masters' => $masters];
            $run->forceFill(['preflight' => array_merge($run->preflight ?? [], ['phase1_parties' => ['ok' => $result['ok'], 'blockers' => $blockers, 'totals' => $preview['totals']]])])->save();
            $this->sm->transition($run, $result['ok'] ? 'party_openings_ready' : 'review', $result['ok'] ? 'phase1_party_ready' : 'phase1_party_blocked', $actor, null, ['blockers' => count($blockers)]);

            return $result;
        });
    }

    /** @return array<string, int> */
    public function createPhaseOnePartyMasters(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'phase1_party_masters', ['party_openings_ready'], function (DataMigrationRun $run) use ($actor): array {
            abort_unless($run->mode === 'party_openings_phase1', 409, 'This run is not scoped to Phase 1 party openings.');
            $preview = $this->partyOpenings->preview($run);
            if (! $preview['ok']) {
                throw RunStateMachine::refuse('phase1_party_blocked', 'Resolve all party mappings and balance issues first.', ['blockers' => array_slice($preview['unresolved'], 0, 100)], 422);
            }
            $created = $this->partyOpenings->createMasters($run, $actor, $this->masters);
            $this->sm->record($run, 'phase1_party_masters_created', $run->status, $run->status, $actor, null, $created);

            return $created;
        });
    }

    /** Approve deterministic party mappings and create all safe new parties in one action. */
    /** @return array<string, mixed> */
    public function createAllPhaseOnePartyMasters(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'phase1_party_masters_bulk', ['review', 'party_openings_preflight', 'party_openings_ready'], function (DataMigrationRun $run) use ($actor): array {
            abort_unless($run->mode === 'party_openings_phase1', 409, 'This run is not scoped to Phase 1 party openings.');

            $approved = ['customers' => 0, 'suppliers' => 0, 'matched' => 0];
            foreach (DataMigrationMapping::query()->where('run_id', $run->id)->whereIn('entity_type', ['customer', 'supplier'])->whereNull('locked_at')->lockForUpdate()->get() as $mapping) {
                $payload = $mapping->payload ?? [];
                $safe = false;
                if ($mapping->decision === 'create_required') {
                    $safe = trim((string) data_get($payload, 'source_name', '')) !== ''
                        && ! (bool) data_get($payload, 'missing_in_source', false)
                        && data_get($payload, 'note') === null;
                } elseif ($mapping->decision === 'matched' && data_get($payload, 'note') === 'name_exact') {
                    $safe = true;
                }
                if (! $safe || $mapping->isReviewed()) {
                    continue;
                }
                $payload['reviewed'] = true;
                $mapping->forceFill([
                    'payload' => $payload,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'reason' => 'Bulk approval of deterministic party mapping.',
                ])->save();
                $approved[$mapping->decision === 'create_required' ? ($mapping->entity_type === 'customer' ? 'customers' : 'suppliers') : 'matched']++;
            }

            if ($run->status === 'review') {
                $this->sm->transition($run, 'party_openings_preflight', 'phase1_party_bulk_preflight', $actor);
            }
            $preview = $this->partyOpenings->preview($run->fresh());
            $masters = $this->masters->preflightPhaseOne($run->fresh());
            $blockers = array_merge($preview['unresolved'], $masters['blockers']);
            if ($blockers !== []) {
                $run = $run->fresh();
                $run->forceFill(['preflight' => array_merge($run->preflight ?? [], ['phase1_parties' => ['ok' => false, 'blockers' => $blockers]])])->save();
                if ($run->status !== 'review') {
                    $this->sm->transition($run, 'review', 'phase1_party_bulk_blocked', $actor, null, ['blockers' => count($blockers)]);
                }
                throw RunStateMachine::refuse('phase1_party_blocked', 'Some party mappings or balance reviews still require a decision.', ['blockers' => array_slice($blockers, 0, 100)], 422);
            }

            $created = $this->partyOpenings->createMasters($run->fresh(), $actor, $this->masters);
            $this->sm->transition($run->fresh(), 'party_openings_ready', 'phase1_party_masters_created_bulk', $actor, null, $created + ['approved' => $approved]);

            return ['approved' => $approved, 'created' => $created, 'next' => 'post_party_openings'];
        });
    }

    /** @return array<string, mixed> */
    public function postPhaseOnePartyOpenings(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'phase1_party_openings_post', ['party_openings_ready'], function (DataMigrationRun $run) use ($actor): array {
            abort_unless($run->mode === 'party_openings_phase1', 409, 'This run is not scoped to Phase 1 party openings.');
            $preview = $this->partyOpenings->preview($run);
            if (! $preview['ok']) {
                throw RunStateMachine::refuse('phase1_party_blocked', 'Phase 1 is blocked by unresolved mappings or review issues.', ['blockers' => array_slice($preview['unresolved'], 0, 100)], 422);
            }
            foreach (['customers', 'suppliers'] as $bucket) {
                foreach ($preview['parties'][$bucket] as $party) {
                    if ($party['status'] === 'create_required') {
                        throw RunStateMachine::refuse('phase1_masters_not_created', 'Create approved party masters before posting opening balances.', ['source_key' => $party['source_key']], 409);
                    }
                }
            }
            try {
                $result = $this->partyOpenings->post($run, $actor);
            } catch (Throwable $e) {
                $this->sm->record($run, 'phase1_party_openings_failed', $run->status, $run->status, $actor, null, [], 'unexpected_error:'.class_basename($e));
                throw $e;
            }
            $this->sm->transition($run, 'party_openings_posted', 'phase1_party_openings_posted', $actor, null, $result);
            $this->sm->transition($run->fresh(), 'phase1_completed', 'phase1_completed', $actor, null, ['balances_posted' => $result['balances_posted']]);

            return $result;
        });
    }

    public function reversePhaseOnePartyOpenings(int $runId, User $actor, string $reason): array
    {
        return $this->sm->locked($runId, 'phase1_party_openings_reverse', ['party_openings_posted', 'phase1_completed'], function (DataMigrationRun $run) use ($actor, $reason): array {
            abort_unless($run->mode === 'party_openings_phase1', 409, 'This run is not scoped to Phase 1 party openings.');
            $result = $this->partyOpenings->reverse($run, $actor, $reason);
            $this->sm->transition($run, 'review', 'phase1_party_openings_reversed', $actor, $reason, $result);

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function partyPhaseOneReconciliation(DataMigrationRun $run): array
    {
        abort_unless($run->mode === 'party_openings_phase1', 409, 'This run is not scoped to Phase 1 party openings.');

        return $this->partyOpenings->reconciliation($run);
    }

    public function decidePartyPhaseOneIssue(int $runId, int $actorId, string $issueKey, string $decision, string $reason): array
    {
        return $this->sm->locked($runId, 'phase1_issue_decision', ['review', 'party_openings_ready'], function (DataMigrationRun $run) use ($actorId, $issueKey, $decision, $reason): array {
            abort_unless($run->mode === 'party_openings_phase1', 409, 'This run is not scoped to Phase 1 party openings.');
            $actor = User::query()->findOrFail($actorId);
            $preview = $this->partyOpenings->decideIssue($run, $actor, $issueKey, $decision, $reason);
            if ($run->status === 'party_openings_ready') {
                $this->sm->transition($run, 'review', 'phase1_issue_changed', $actor, $reason, ['issue_key' => $issueKey, 'decision' => $decision]);
            } else {
                $this->sm->record($run, 'phase1_issue_decided', $run->status, $run->status, $actor, $reason, ['issue_key' => $issueKey, 'decision' => $decision]);
            }

            return $preview;
        });
    }

    /** @return array<string, mixed> */
    public function preflightOpenings(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'openings_preflight', ['masters_created', 'openings_preflight', 'openings_ready'], function (DataMigrationRun $run) use ($actor): array {
            if ($run->status === 'openings_ready') {
                $this->sm->transition($run, 'masters_created', 'openings_recheck', $actor);
            }
            if ($run->status !== 'openings_preflight') {
                $this->sm->transition($run, 'openings_preflight', 'openings_preflight', $actor);
            }
            $preview = $this->openings->preview($run);
            $run->forceFill(['preflight' => ($run->preflight ?? []) + ['openings' => ['ok' => $preview['ok'], 'blockers' => $preview['blockers'], 'totals' => $preview['totals']]]])->save();
            $this->sm->transition($run, $preview['ok'] ? 'openings_ready' : 'masters_created', $preview['ok'] ? 'openings_ready' : 'openings_blocked', $actor, null, ['blockers' => count($preview['blockers'])]);

            return $preview;
        });
    }

    /** @return array<string, mixed> */
    public function postOpenings(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'openings_post', ['openings_ready'], function (DataMigrationRun $run) use ($actor): array {
            try {
                $result = $this->openings->post($run, $actor);
            } catch (Throwable $e) {
                $this->sm->transition($run, 'masters_created', 'openings_failed', $actor, null, [], $e instanceof ValidationException ? implode(' ', array_map('strval', array_merge(...array_values($e->errors())))) : 'unexpected_error:'.class_basename($e));
                throw $e;
            }
            $this->sm->transition($run, 'openings_posted', 'openings_posted', $actor, null, $result);

            return $result;
        });
    }

    public function reverseOpenings(int $runId, User $actor, string $reason): array
    {
        return $this->sm->locked($runId, 'openings_reverse', ['openings_posted'], function (DataMigrationRun $run) use ($actor, $reason): array {
            if (DataMigrationLink::query()->where('run_id', $run->id)->whereNotIn('document_type', ['opening_balance', 'opening_stock', 'opening_inventory_journal', 'opening_cash_journal'])->exists()) {
                throw RunStateMachine::refuse('documents_exist', 'Opening balances cannot be reversed after documents were executed.');
            }
            $company = Company::query()->findOrFail($run->company_id);
            $date = $run->opening_balance_as_of->toDateString();
            $reversed = 0;
            DB::transaction(function () use ($run, $actor, $company, $date, &$reversed): void {
                foreach (DataMigrationLink::query()->where('run_id', $run->id)->lockForUpdate()->get() as $link) {
                    if ($link->document_type === 'opening_balance') {
                        $party = CustomerSupplier::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($link->target_id);
                        if ($party->opening_balance_journal_entry_id) {
                            $this->accounting->reverseJournal($company, $actor, JournalEntry::query()->where('company_id', $company->id)->findOrFail($party->opening_balance_journal_entry_id), $date, true);
                        }
                        $party->forceFill(['opening_balance' => 0, 'opening_balance_journal_entry_id' => null])->save();
                    } elseif ($link->document_type === 'opening_stock') {
                        $m = StockMovement::query()->where('company_id', $company->id)->findOrFail($link->target_id);
                        $this->valuation->reverseReceipt($company, $actor, $m->warehouse_id, $m->product_id, (float) $m->quantity_delta, (float) $m->value_delta, DataMigrationRun::class, $run->id, 'opening_reverse');
                    } else {
                        $this->accounting->reverseJournal($company, $actor, JournalEntry::query()->where('company_id', $company->id)->findOrFail($link->target_id), $date, true);
                    }
                    $link->forceFill(['document_type' => substr('rev:'.$link->id.':'.$link->document_type, 0, 40)])->save();
                    $reversed++;
                }
                DataMigrationMapping::query()->where('run_id', $run->id)->update(['locked_at' => null]);
            });
            $this->sm->transition($run, 'masters_created', 'openings_reversed', $actor, $reason, ['reversed' => $reversed]);

            return ['reversed' => $reversed];
        });
    }

    /** @return array<string, int> */
    public function stageDocuments(int $runId, User $actor): array
    {
        return $this->sm->locked($runId, 'documents_stage', ['openings_posted', 'documents_ready', 'failed_recoverable', 'completed_with_review'], function (DataMigrationRun $run) use ($actor): array {
            $counts = $this->stager->stage($run, LegacyDataset::load($run));
            $this->settle($run, $actor, 'documents_staged', $counts);

            return $counts;
        });
    }

    /** After record decisions: keep the persisted status truthful. */
    private function settle(DataMigrationRun $run, User $actor, string $action, array $counts): void
    {
        $run = $run->fresh();
        $open = (int) (($counts['needs_review'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['ready'] ?? 0));
        $target = match (true) {
            $run->status === 'completed_with_review' && $open === 0 => 'completed',
            in_array($run->status, ['openings_posted', 'failed_recoverable'], true) => 'documents_ready',
            $run->status === 'documents_ready' => 'documents_ready',
            default => $run->status,
        };
        if ($target !== $run->status) {
            $this->sm->transition($run, $target, $action, $actor, null, $counts);
        } else {
            $this->sm->record($run, $action, $run->status, $run->status, $actor, null, $counts);
        }
    }

    /** @return array<string, int> */
    public function decideRecord(int $runId, int $recordId, User $actor, string $decision, ?string $reason): array
    {
        return $this->sm->locked($runId, 'record_'.$decision, ['documents_ready', 'failed_recoverable', 'completed_with_review'], function (DataMigrationRun $run) use ($recordId, $actor, $decision, $reason): array {
            $record = DataMigrationRecord::query()->where('run_id', $run->id)->whereKey($recordId)->lockForUpdate()->firstOrFail();
            if ($record->status === 'created') {
                throw RunStateMachine::refuse('record_already_created', 'A record that already created ERP documents cannot be changed.');
            }
            if ($decision === 'exclude') {
                if (trim((string) $reason) === '') {
                    throw ValidationException::withMessages(['reason' => 'An exclusion reason is required.']);
                }
                $record->forceFill(['status' => 'excluded', 'exclusion_reason' => mb_substr((string) $reason, 0, 500)])->save();
            } else {
                $record->forceFill(['status' => 'pending', 'exclusion_reason' => null])->save();
                $this->stager->stage($run, LegacyDataset::load($run));
            }
            $counts = $this->counts($run);
            $this->sm->record($run, 'record_'.$decision, $run->status, $run->status, $actor, $reason, ['record' => $record->id, 'source' => $record->source_number]);
            $this->settle($run, $actor, 'documents_staged', $counts);

            return $counts;
        });
    }

    /** @return array<string, int> */
    public function counts(DataMigrationRun $run): array
    {
        $out = [];
        foreach (DataMigrationRecord::query()->where('run_id', $run->id)->selectRaw('status, count(*) as n')->groupBy('status')->get() as $r) {
            $out[$r->status] = (int) $r->n;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function executionPreflight(DataMigrationRun $run): array
    {
        return $this->executor->preflight($run);
    }

    /** @return array<string, mixed> */
    public function execute(int $runId, User $actor, int $budget = 45): array
    {
        return $this->sm->locked($runId, 'execute', ['documents_ready', 'failed_recoverable', 'completed_with_review', 'executing'], function (DataMigrationRun $run) use ($actor, $budget): array {
            $check = $this->executor->preflight($run);
            if (! $check['ok']) {
                throw RunStateMachine::refuse('execution_blocked', 'Some records reference mappings that are not approved; nothing was executed.', ['blockers' => array_slice($check['blockers'], 0, 100)], 422);
            }
            if ($run->status === 'executing') {
                $this->sm->record($run, 'execution_resumed', 'executing', 'executing', $actor, null);
            } else {
                $this->sm->transition($run, 'executing', 'execution_started', $actor, null, ['ready' => $check['ready']]);
            }
            try {
                $counts = $this->executor->execute($run, $actor, $budget);
            } catch (Throwable $e) {
                $this->sm->transition($run->fresh(), 'failed_recoverable', 'execution_crashed', $actor, null, [], 'unexpected_error:'.class_basename($e));
                report($e);
                throw $e;
            }
            $status = $this->counts($run);
            $final = match (true) {
                $counts['remaining'] > 0 => 'executing',
                ($status['failed'] ?? 0) > 0 => 'failed_recoverable',
                ($status['needs_review'] ?? 0) > 0 || ($status['pending'] ?? 0) > 0 => 'completed_with_review',
                default => 'completed',
            };
            $run->forceFill(['summary' => array_merge($run->summary ?? [], ['execution' => $counts, 'records' => $status])])->save();
            $this->sm->transition($run->fresh(), $final, $final === 'executing' ? 'execution_paused' : 'execution_finished', $actor, null, $counts + ['records' => $status]);

            return $counts + ['status' => $final];
        });
    }

    /** @param array<string, mixed> $patch */
    public function setPolicy(int $runId, User $actor, array $patch, ?string $ack = null, ?string $reason = null): DataMigrationRun
    {
        return $this->sm->locked($runId, 'policy', ['review', 'masters_ready', 'masters_created', 'openings_ready', 'uploaded', 'parsed'], function (DataMigrationRun $run) use ($actor, $patch, $ack, $reason): DataMigrationRun {
            $policy = $run->policy ?? [];
            foreach (['customer_start_balance_sign', 'supplier_start_balance_sign', 'invoice_total_basis'] as $k) {
                if (array_key_exists($k, $patch)) {
                    $policy[$k] = $patch[$k];
                }
            }
            if ($ack !== null) {
                if (! in_array($ack, OpeningBalanceService::ACKS, true) || trim((string) $reason) === '') {
                    throw ValidationException::withMessages(['ack' => 'A known acknowledgement code and a reason are required.']);
                }
                $policy['acks'][$ack] = ['reason' => mb_substr((string) $reason, 0, 500), 'by' => $actor->id, 'at' => now()->toIso8601String()];
            }
            $run->forceFill(['policy' => $policy])->save();
            $before = $run->status;
            if (in_array($run->status, ['masters_ready', 'openings_ready'], true)) {
                $this->sm->transition($run, $run->status === 'masters_ready' ? 'review' : 'masters_created', 'policy_changed', $actor, $reason);
            } else {
                $this->sm->record($run, 'policy_changed', $before, $before, $actor, $reason, ['keys' => array_keys($patch), 'ack' => $ack]);
            }

            return $run->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function signOff(int $runId, User $actor, string $reason): array
    {
        return $this->sm->locked($runId, 'sign_off', ['completed', 'phase1_completed'], function (DataMigrationRun $run) use ($actor, $reason): array {
            $report = $run->mode === 'party_openings_phase1' ? $this->partyOpenings->reconciliation($run) : $this->reconciliation->build($run);
            $differences = ($report['parties']['differences'] ?? 0) + ($report['stock']['differences'] ?? 0) + ($report['cash']['differences'] ?? 0);
            $differences += abs((float) ($report['difference']['debit'] ?? 0)) + abs((float) ($report['difference']['credit'] ?? 0)) + (int) ($report['unresolved_count'] ?? 0);
            $balanced = $run->mode === 'party_openings_phase1' ? (bool) ($report['balanced'] ?? false) : (bool) ($report['opening']['balanced'] ?? false);
            $run->forceFill(['signed_off_by' => $actor->id, 'signed_off_at' => now(), 'source_retention_until' => now()->addDays((int) config('migration.source_retention_days'))->toDateString(), 'summary' => array_merge($run->summary ?? [], ['reconciliation_totals' => ['differences' => $differences, 'opening_balanced' => $balanced]])])->save();
            $this->sm->transition($run, 'signed_off', 'signed_off', $actor, $reason, ['differences' => $differences]);

            return $report;
        });
    }

    public function archive(int $runId, User $actor, string $reason): DataMigrationRun
    {
        return $this->sm->locked($runId, 'archive', ['signed_off', 'failed_terminal'], function (DataMigrationRun $run) use ($actor, $reason): DataMigrationRun {
            if ($run->status === 'signed_off' && $run->source_retention_until && $run->source_retention_until->isFuture()) {
                throw RunStateMachine::refuse('retention_not_elapsed', 'The source file must be retained until '.$run->source_retention_until->toDateString().'.', ['until' => $run->source_retention_until->toDateString()]);
            }
            Storage::disk($run->source_disk)->delete($run->source_path);
            DB::table('data_migration_source_rows')->where('run_id', $run->id)->delete();
            $run->forceFill(['source_removed_at' => now(), 'archived_at' => now()])->save();
            $this->sm->transition($run, 'archived', 'archived', $actor, $reason, ['source_hash' => $run->source_hash]);

            return $run->fresh();
        });
    }
}
