<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationLink;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\AuditRecorder;
use App\Services\Legacy\LegacyDataset;
use App\Services\SubscriptionNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Phase 1 only: customer/supplier masters and their party-control opening entries. */
final class PartyOpeningBalanceService
{
    private const TABLES = ['customer', 'import', 'account_import', 'sales', 'buy', 'details_buy', 'details_sales', 'categories'];

    public function __construct(
        private readonly PartyOpeningBalanceCalculator $calculator,
        private readonly AccountingService $accounting,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(DataMigrationRun $run): array
    {
        if (! $run->opening_balance_as_of) {
            throw ValidationException::withMessages(['opening_balance_as_of' => 'Select a party opening cutoff date.']);
        }
        $company = Company::query()->findOrFail($run->company_id);
        $timezone = app(SubscriptionNotice::class)->timezoneFor($company);
        $cutoff = CarbonImmutable::createFromFormat('!Y-m-d', $run->opening_balance_as_of->toDateString(), $timezone);
        $effective = $cutoff->addDay()->toDateString();
        $dataset = LegacyDataset::load($run, self::TABLES);
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = $dataset->rows($table);
        }
        $calculated = $this->calculator->calculate($tables, $cutoff->toDateString());
        $decisions = (array) data_get($run->policy, 'phase1.issue_decisions', []);
        $excludedParties = [];
        $issues = [];
        foreach ($calculated['issues'] as $issue) {
            $decision = $decisions[$issue['issue_key']] ?? null;
            $issue['decision'] = $decision['decision'] ?? null;
            $issue['reason'] = $decision['reason'] ?? null;
            $issue['resolved'] = $this->issueResolved($issue, $decision);
            if (($decision['decision'] ?? null) === 'exclude_party') {
                $entity = $issue['source_table'] === 'customer' ? 'customer' : 'supplier';
                $excludedParties[$entity.'|'.$issue['source_id']] = true;
            }
            $issues[] = $issue;
        }
        $this->applySpecialSaleDecisions($calculated, $decisions, $issues);
        $parties = ['customers' => [], 'suppliers' => []];
        foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $entity => $bucket) {
            foreach ($calculated[$bucket] as $sourceKey => $row) {
                $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $entity)->where('source_key', (string) $sourceKey)->first();
                $target = $mapping?->decision === 'matched' && $mapping->target_id
                    ? CustomerSupplier::query()->where('company_id', $company->id)->find($mapping->target_id)
                    : null;
                $status = $this->mappingStatus($mapping, $target, $entity);
                $partyExcluded = isset($excludedParties[$entity.'|'.$sourceKey]) || $mapping?->decision === 'excluded';
                $rowIssues = array_values(array_filter($issues, fn ($issue) => ($issue['source_table'] === ($entity === 'customer' ? 'customer' : 'import') && (string) $issue['source_id'] === (string) $sourceKey)
                    || ($entity === 'supplier' && $issue['source_table'] === 'buy' && $this->buyBelongsToSupplier($dataset, (string) $issue['source_id'], (string) $sourceKey))
                    || ($entity === 'customer' && $issue['source_table'] === 'sales' && $this->saleBelongsToCustomer($dataset, (string) $issue['source_id'], (string) $sourceKey))
                ));
                $conflict = $target ? $this->targetConflict($run, $company, $target, $entity, (string) $sourceKey) : null;
                if ($conflict !== null) {
                    $issue = $this->targetConflictIssue($entity, (string) $sourceKey, $target, $conflict);
                    $decision = $decisions[$issue['issue_key']] ?? null;
                    $issue['decision'] = $decision['decision'] ?? null;
                    $issue['reason'] = $decision['reason'] ?? null;
                    $issue['resolved'] = ($decision['decision'] ?? null) === 'exclude_party' && trim((string) ($decision['reason'] ?? '')) !== '';
                    $issues[] = $issue;
                    $rowIssues[] = $issue;
                    if ($issue['resolved']) {
                        $partyExcluded = true;
                    }
                }
                $row['mapping_id'] = $mapping?->id;
                $row['mapping_decision'] = $mapping?->decision;
                $row['mapping_reviewed'] = $mapping?->isReviewed() ?? false;
                $row['target_id'] = $target?->id;
                $row['target_name'] = $target?->name;
                $row['status'] = $partyExcluded ? 'excluded' : ($status ?? 'needs_review');
                $row['issues'] = $rowIssues;
                $row['source_balance'] = $row['result'];
                $row['erp_direction'] = $entity === 'customer'
                    ? ($row['result'] < 0 ? 'debit' : ($row['result'] > 0 ? 'credit' : 'zero'))
                    : ($row['result'] < 0 ? 'credit' : ($row['result'] > 0 ? 'debit' : 'zero'));
                $parties[$bucket][] = $row;
            }
        }
        $unresolved = array_values(array_filter($issues, fn ($issue) => ! $issue['resolved']));
        foreach (['customers', 'suppliers'] as $bucket) {
            foreach ($parties[$bucket] as $row) {
                if ($row['status'] === 'needs_review' || ($row['mapping_reviewed'] === false && $row['mapping_decision'] !== 'excluded')) {
                    $unresolved[] = ['code' => 'party_mapping_unresolved', 'source_table' => $row['mapping_decision'] === 'create_required' ? 'customer' : 'import', 'source_id' => $row['source_key'], 'entity' => $bucket === 'customers' ? 'customer' : 'supplier', 'mapping_id' => $row['mapping_id']];
                }
            }
        }
        $totals = $this->totals($parties);
        $createdLinks = DataMigrationLink::query()->where('run_id', $run->id)->where('document_type', 'party_opening_balance_phase1')->count();

        return [
            'phase' => 'party_openings_phase1', 'scope' => ['customers' => true, 'suppliers' => true, 'inventory' => false, 'documents' => false, 'cash' => false, 'banks' => false],
            'cutoff_date' => $cutoff->toDateString(), 'opening_effective_date' => $effective, 'timezone' => $timezone,
            'ok' => $unresolved === [], 'parties' => $parties, 'supplier_sales' => $this->resolvedSpecialSales($calculated['supplier_sales'], $decisions),
            'issues' => $issues, 'unresolved' => $unresolved, 'totals' => $totals,
            'counts' => [
                'source_customers' => $calculated['counts']['customers'], 'source_suppliers' => $calculated['counts']['suppliers'],
                'customer_mappings' => count($parties['customers']), 'supplier_mappings' => count($parties['suppliers']),
                'supplier_sales' => count($calculated['supplier_sales']), 'supplier_sales_review' => $calculated['counts']['supplier_sales_review'],
                'resolved_issues' => count($issues) - count(array_filter($issues, fn ($issue) => ! $issue['resolved'])),
                'unresolved_issues' => count(array_filter($issues, fn ($issue) => ! $issue['resolved'])),
                'existing_phase1_links' => $createdLinks,
            ],
            'legacy_comparison' => [
                'basis' => 'requested details_buy/category formula vs legacy buy header kg*price/1000; explicit owner decision required where they differ',
                'supplier_formula_mismatch_parties' => count(array_filter($calculated['suppliers'], fn ($row) => $row['formula_review_required'])),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function decideIssue(DataMigrationRun $run, User $actor, string $issueKey, string $decision, string $reason): array
    {
        $preview = $this->preview($run);
        $issue = collect($preview['issues'])->firstWhere('issue_key', $issueKey);
        if (! $issue) {
            throw ValidationException::withMessages(['issue_key' => 'The review item no longer exists for this source snapshot.']);
        }
        $allowed = $this->allowedDecisions($issue['code']);
        if (! in_array($decision, $allowed, true)) {
            throw ValidationException::withMessages(['decision' => 'This decision is not allowed for the review item.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'A review reason of at least five characters is required.']);
        }
        $policy = $run->policy ?? [];
        data_set($policy, 'phase1.issue_decisions.'.$issueKey, [
            'decision' => $decision, 'reason' => mb_substr(trim($reason), 0, 500), 'actor_id' => $actor->id, 'decided_at' => now()->toIso8601String(),
        ]);
        $run->forceFill(['policy' => $policy])->save();

        return $this->preview($run->fresh());
    }

    /** @return array<string, int> */
    public function createMasters(DataMigrationRun $run, User $actor, MasterDataService $masters): array
    {
        $preview = $this->preview($run);
        if ($preview['unresolved'] !== []) {
            throw ValidationException::withMessages(['phase1' => 'Resolve all balance-affecting source reviews and approve party mappings before creating party masters.']);
        }
        $check = $masters->preflightPhaseOne($run);
        if (! $check['ok']) {
            throw ValidationException::withMessages(['phase1' => 'Party master preflight contains unresolved mappings.']);
        }

        return $masters->createPhaseOne($run, $actor);
    }

    /** @return array<string, mixed> */
    public function post(DataMigrationRun $run, User $actor): array
    {
        $preview = $this->preview($run);
        if (! $preview['ok']) {
            throw ValidationException::withMessages(['phase1' => 'Phase 1 is blocked by unresolved source, mapping, or target conflicts.']);
        }
        $company = Company::query()->findOrFail($run->company_id);
        $result = ['customers_created' => 0, 'suppliers_created' => 0, 'customers_matched' => 0, 'suppliers_matched' => 0, 'balances_posted' => 0, 'zero_balances' => 0, 'excluded' => 0, 'debit' => 0.0, 'credit' => 0.0];
        DB::transaction(function () use ($run, $actor, $company, $preview, &$result): void {
            foreach (['customers' => 'customer', 'suppliers' => 'supplier'] as $bucket => $entity) {
                foreach ($preview['parties'][$bucket] as $row) {
                    if ($row['status'] === 'excluded') {
                        $result['excluded']++;

                        continue;
                    }
                    $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $entity)->where('source_key', (string) $row['source_key'])->lockForUpdate()->firstOrFail();
                    if ($mapping->decision !== 'matched' || ! $mapping->target_id || ! $mapping->isReviewed()) {
                        throw ValidationException::withMessages(['phase1' => 'A party mapping changed after preflight. Run preflight again.']);
                    }
                    $party = CustomerSupplier::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($mapping->target_id);
                    if ((int) $party->company_id !== (int) $company->id || ($entity === 'customer' ? ! $party->is_customer : ! $party->is_supplier)) {
                        throw ValidationException::withMessages(['phase1' => 'The mapped party is outside the selected company or has the wrong role.']);
                    }
                    $existing = DataMigrationLink::query()->where('company_id', $company->id)->where('source_system', $run->source_system)->where('source_table', $entity === 'customer' ? 'customer' : 'import')->where('source_id', (string) $row['source_key'])->where('document_type', 'party_opening_balance_phase1')->first();
                    if ($existing) {
                        continue;
                    }
                    if (abs((float) $row['amount']) <= 0.005) {
                        $result['zero_balances']++;

                        continue;
                    }
                    if ($party->opening_balance_journal_entry_id || abs((float) $party->opening_balance) > 0.005) {
                        throw ValidationException::withMessages(['phase1' => 'The target party already has an opening balance. Exclude it or use a clean target.']);
                    }
                    $party->forceFill([
                        'opening_balance' => round((float) $row['amount'], 2),
                        'opening_balance_direction' => $row['erp_direction'],
                        'opening_balance_date' => $preview['opening_effective_date'],
                    ])->save();
                    $entry = $this->accounting->postOpeningBalance($company, $actor, $party);
                    if (! $entry || $entry->status !== 'posted') {
                        throw ValidationException::withMessages(['phase1' => 'The party opening journal did not post. The Phase 1 transaction was rolled back.']);
                    }
                    DataMigrationLink::query()->create([
                        'company_id' => $company->id, 'run_id' => $run->id, 'source_system' => $run->source_system,
                        'source_table' => $entity === 'customer' ? 'customer' : 'import', 'source_id' => (string) $row['source_key'],
                        'document_type' => 'party_opening_balance_phase1', 'target_type' => CustomerSupplier::class, 'target_id' => $party->id,
                        'result' => [
                            'cutoff_date' => $preview['cutoff_date'], 'effective_date' => $preview['opening_effective_date'],
                            'signed_source_balance' => $row['source_balance'], 'amount' => $row['amount'], 'direction' => $row['erp_direction'],
                            'components' => $this->sourceComponents($row), 'supplier_sale_links' => $entity === 'supplier' ? $this->supplierSaleLinks($preview, (string) $row['source_key']) : [],
                        ],
                    ]);
                    $result[$bucket.(data_get($mapping->payload, 'created') ? '_created' : '_matched')]++;
                    $result['balances_posted']++;
                    if ($row['erp_direction'] === 'debit') {
                        $result['debit'] += (float) $row['amount'];
                    } else {
                        $result['credit'] += (float) $row['amount'];
                    }
                }
            }
            $result['debit'] = round($result['debit'], 2);
            $result['credit'] = round($result['credit'], 2);
            if (abs($result['debit'] - $result['credit']) > 0.01) {
                throw ValidationException::withMessages(['phase1' => 'Phase 1 party-control entries do not balance. No result was committed.']);
            }
            $this->audit->record('platform.data_migration.phase1_party_openings_posted', $run, (int) $company->id, $actor->id, [
                'cutoff_date' => $preview['cutoff_date'], 'effective_date' => $preview['opening_effective_date'], 'counts' => $result,
            ], null, ['severity' => 'warning', 'reason' => 'Phase 1 party opening balances posted']);
        });

        return $result + ['reconciliation' => $this->reconciliation($run->fresh())];
    }

    /** Reverse only Phase 1 party journals; inventory, cash, banks and documents are outside this method. */
    public function reverse(DataMigrationRun $run, User $actor, string $reason): array
    {
        $company = Company::query()->findOrFail($run->company_id);
        $links = DataMigrationLink::query()->where('run_id', $run->id)->where('document_type', 'party_opening_balance_phase1')->lockForUpdate()->get();
        $reversed = 0;
        DB::transaction(function () use ($links, $run, $actor, $company, $reason, &$reversed): void {
            foreach ($links as $link) {
                $party = CustomerSupplier::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($link->target_id);
                if ($party->opening_balance_journal_entry_id) {
                    $entry = JournalEntry::query()->where('company_id', $company->id)->findOrFail($party->opening_balance_journal_entry_id);
                    $this->accounting->reverseJournal($company, $actor, $entry, $party->opening_balance_date?->toDateString() ?? now()->toDateString(), true);
                }
                $party->forceFill(['opening_balance' => 0, 'opening_balance_journal_entry_id' => null])->save();
                $link->forceFill(['document_type' => substr('rev:'.$link->id.':party_opening_balance_phase1', 0, 40), 'result' => array_merge($link->result ?? [], ['reversed_at' => now()->toIso8601String(), 'reversal_reason' => $reason])])->save();
                $reversed++;
            }
            app(AuditRecorder::class)->record('platform.data_migration.phase1_party_openings_reversed', $run, (int) $company->id, $actor->id, ['reversed' => $reversed], null, ['severity' => 'warning', 'reason' => $reason]);
        });

        return ['reversed' => $reversed, 'phase' => 'party_openings_phase1'];
    }

    /** @return array<string, mixed> */
    public function reconciliation(DataMigrationRun $run): array
    {
        $preview = $this->preview($run);
        $links = DataMigrationLink::query()->where('run_id', $run->id)->where('document_type', 'party_opening_balance_phase1')->get();
        $targetDebit = 0.0;
        $targetCredit = 0.0;
        foreach ($links as $link) {
            $party = CustomerSupplier::query()->where('company_id', $run->company_id)->find($link->target_id);
            if (! $party?->opening_balance_journal_entry_id) {
                continue;
            }
            $lines = DB::table('journal_lines')->where('company_id', $run->company_id)->where('journal_entry_id', $party->opening_balance_journal_entry_id)->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')->first();
            $targetDebit += (float) ($lines->debit ?? 0);
            $targetCredit += (float) ($lines->credit ?? 0);
        }
        $expectedDebit = 0.0;
        $expectedCredit = 0.0;
        foreach (['customers', 'suppliers'] as $bucket) {
            foreach ($preview['parties'][$bucket] as $row) {
                if ($row['status'] === 'excluded' || abs((float) $row['amount']) <= 0.005) {
                    continue;
                }
                if ($row['erp_direction'] === 'debit') {
                    $expectedDebit += (float) $row['amount'];
                } else {
                    $expectedCredit += (float) $row['amount'];
                }
            }
        }
        $debitDifference = round($expectedDebit - $targetDebit, 2);
        $creditDifference = round($expectedCredit - $targetCredit, 2);
        $unresolved = $preview['unresolved'];

        return [
            'phase' => 'party_openings_phase1', 'cutoff_date' => $preview['cutoff_date'], 'opening_effective_date' => $preview['opening_effective_date'], 'timezone' => $preview['timezone'],
            'customers' => $this->mappingCounts($run, 'customer'), 'suppliers' => $this->mappingCounts($run, 'supplier'),
            'supplier_sales' => [
                'total' => count($preview['supplier_sales']),
                'classified' => count(array_filter($preview['supplier_sales'], fn ($row) => $row['classification'] === 'sale_to_supplier')),
                'review_or_excluded' => count(array_filter($preview['supplier_sales'], fn ($row) => $row['classification'] !== 'sale_to_supplier')),
                'included_total' => round(array_sum(array_column($preview['supplier_sales'], 'included_supplier_component')), 2),
            ],
            'opening_components' => $preview['totals'],
            'source_positive' => $preview['totals']['source_positive'], 'source_negative' => $preview['totals']['source_negative'],
            'target' => ['debit' => round($targetDebit, 2), 'credit' => round($targetCredit, 2)],
            'difference' => ['debit' => $debitDifference, 'credit' => $creditDifference],
            'balanced' => abs($targetDebit - $targetCredit) <= 0.01 && abs($debitDifference) <= 0.01 && abs($creditDifference) <= 0.01,
            'unresolved_count' => count($unresolved), 'unresolved' => $unresolved, 'issues' => $preview['issues'],
        ];
    }

    /** @return list<string> */
    private function allowedDecisions(string $code): array
    {
        return match ($code) {
            'supplier_formula_differs_from_legacy_runtime' => ['approve_details', 'exclude_party'],
            'supplier_category_conversion_differs_from_legacy' => ['approve_category_rule', 'exclude_party'],
            'supplier_sale_amount_mismatch', 'supplier_sale_detail_total_mismatch' => ['include_eda3', 'exclude'],
            'target_opening_balance_conflict' => ['exclude_party'],
            'invalid_customer_start_balance', 'supplier_start_amount_invalid' => ['exclude_party', 'exclude_component'],
            'sales_business_date_missing', 'customer_sale_amount_invalid', 'purchase_business_date_missing', 'supplier_payment_amount_invalid', 'supplier_header_amount_invalid' => ['exclude_component', 'exclude_party'],
            'purchase_category_flag_missing', 'purchase_line_amount_invalid' => ['exclude_component', 'exclude_party'],
            'supplier_purchase_supplier_missing', 'supplier_account_missing_supplier', 'supplier_start_without_supplier', 'supplier_sale_reference_missing', 'supplier_sale_has_customer', 'supplier_sale_details_missing', 'supplier_sale_date_missing', 'supplier_sale_date_mismatch', 'supplier_sale_account_mismatch', 'supplier_sale_amount_invalid', 'supplier_sale_detail_amount_invalid', 'duplicate_source_key' => ['exclude'],
            default => ['exclude'],
        };
    }

    private function issueResolved(array $issue, ?array $decision): bool
    {
        if (! $decision || trim((string) ($decision['reason'] ?? '')) === '') {
            return false;
        }

        return in_array($decision['decision'] ?? null, $this->allowedDecisions($issue['code']), true);
    }

    /** @param array<string, mixed> $calculated @param array<string, array<string, mixed>> $decisions @param list<array<string, mixed>> $issues */
    private function applySpecialSaleDecisions(array &$calculated, array $decisions, array &$issues): void
    {
        foreach ($calculated['supplier_sales'] as &$sale) {
            $issue = collect($issues)->first(fn ($item) => $item['source_table'] === 'buy' && $item['source_id'] === $sale['source_id'] && $item['code'] === $sale['review_code']);
            $decision = $issue ? ($decisions[$issue['issue_key']] ?? null) : null;
            $sale['decision'] = $decision['decision'] ?? null;
            $sale['reason'] = $decision['reason'] ?? null;
            if (($decision['decision'] ?? null) === 'include_eda3' && in_array($sale['review_code'], ['supplier_sale_amount_mismatch', 'supplier_sale_detail_total_mismatch'], true)) {
                $supplier = &$calculated['suppliers'][$sale['supplier_key']];
                $amount = (float) $sale['raw_buy_eda3'];
                $supplier['payment_component'] = round($supplier['payment_component'] + $amount, 2);
                $supplier['result'] = round($supplier['result'] + $amount, 2);
                $supplier['source_balance'] = $supplier['result'];
                $supplier['direction'] = $supplier['result'] < 0 ? 'company_owes_supplier' : ($supplier['result'] > 0 ? 'supplier_owes_company' : 'zero');
                $supplier['amount'] = abs($supplier['result']);
                $sale['included_supplier_component'] = $amount;
                $sale['decision_applied'] = true;
                unset($supplier);
            }
            if (($decision['decision'] ?? null) === 'exclude') {
                $sale['classification'] = 'excluded_with_reason';
            }
            if ($issue) {
                foreach ($issues as &$entry) {
                    if ($entry['issue_key'] === $issue['issue_key']) {
                        $entry['decision'] = $decision['decision'] ?? null;
                        $entry['reason'] = $decision['reason'] ?? null;
                        $entry['resolved'] = $this->issueResolved($entry, $decision);
                    }
                }
                unset($entry);
            }
        }
        unset($sale);
    }

    private function mappingStatus(?DataMigrationMapping $mapping, ?CustomerSupplier $target, string $entity): ?string
    {
        if (! $mapping) {
            return 'needs_review';
        }
        if ($mapping->decision === 'excluded') {
            return trim((string) $mapping->reason) !== '' ? 'excluded' : 'needs_review';
        }
        if ($mapping->decision === 'create_required') {
            return $mapping->isReviewed() ? 'create_required' : 'needs_review';
        }
        if ($mapping->decision !== 'matched' || ! $target || ! $mapping->isReviewed()) {
            return 'needs_review';
        }

        return 'ready';
    }

    private function targetConflict(DataMigrationRun $run, Company $company, CustomerSupplier $party, string $entity, string $sourceKey): ?string
    {
        $linked = DataMigrationLink::query()->where('run_id', $run->id)->where('company_id', $company->id)->where('source_table', $entity === 'customer' ? 'customer' : 'import')->where('source_id', $sourceKey)->where('document_type', 'party_opening_balance_phase1')->exists();
        if ($linked) {
            return null;
        }
        if ($party->opening_balance_journal_entry_id || abs((float) $party->opening_balance) > 0.005) {
            return 'opening_balance_exists';
        }
        $hasLines = DB::table('journal_lines')->where('company_id', $company->id)->where('account_id', $party->account_id)->exists();

        return $hasLines ? 'target_party_has_posted_activity' : null;
    }

    private function targetConflictIssue(string $entity, string $key, CustomerSupplier $party, string $reason): array
    {
        return [
            'issue_key' => hash('sha256', 'target_opening_balance_conflict|'.$entity.'|'.$key),
            'code' => 'target_opening_balance_conflict', 'source_table' => $entity === 'customer' ? 'customer' : 'import',
            'source_id' => $key, 'entity' => $entity, 'target_id' => $party->id, 'context' => ['reason' => $reason],
        ];
    }

    private function buyBelongsToSupplier(LegacyDataset $dataset, string $buyId, string $supplierKey): bool
    {
        $buy = $dataset->one('buy', 'id_buy', $buyId);

        return $buy !== null && (string) ($buy['id_import'] ?? '') === $supplierKey;
    }

    private function saleBelongsToCustomer(LegacyDataset $dataset, string $saleId, string $customerKey): bool
    {
        $sale = $dataset->one('sales', 'id_sales', $saleId);

        return $sale !== null && (string) ($sale['id_customer'] ?? '') === $customerKey;
    }

    /** @return array<string, float|int> */
    private function totals(array $parties): array
    {
        $totals = ['source_positive' => 0.0, 'source_negative' => 0.0, 'customer_debit' => 0.0, 'customer_credit' => 0.0, 'supplier_debit' => 0.0, 'supplier_credit' => 0.0];
        foreach (['customers' => 'customer', 'suppliers' => 'supplier'] as $bucket => $entity) {
            foreach ($parties[$bucket] as $row) {
                if ($row['status'] === 'excluded') {
                    continue;
                }
                $value = (float) $row['source_balance'];
                if ($value > 0) {
                    $totals['source_positive'] += $value;
                }
                if ($value < 0) {
                    $totals['source_negative'] += abs($value);
                }
                $key = $entity.'_'.($row['erp_direction'] === 'zero' ? 'debit' : $row['erp_direction']);
                if ($key !== $entity.'_zero') {
                    $totals[$key] += (float) $row['amount'];
                }
            }
        }
        foreach ($totals as $key => $value) {
            $totals[$key] = round((float) $value, 2);
        }

        return $totals;
    }

    private function sourceComponents(array $row): array
    {
        return array_intersect_key($row, array_flip(['start_balance', 'sales_total', 'collections', 'start_component', 'payment_component', 'purchase_cost_details', 'purchase_cost_legacy_header', 'purchase_cost_legacy_details_per_ton', 'formula_difference', 'category_conversion_difference']));
    }

    private function supplierSaleLinks(array $preview, string $supplierKey): array
    {
        return array_values(array_map(fn ($sale) => [
            'source_table' => $sale['source_table'], 'source_id' => $sale['source_id'], 'linked_sales_id' => $sale['linked_sales_id'],
            'business_date' => $sale['business_date'], 'raw_eda3' => $sale['raw_buy_eda3'],
            'included_supplier_component' => $sale['included_supplier_component'], 'classification' => $sale['classification'],
            'reason' => $sale['reason'] ?? null,
        ], array_filter($preview['supplier_sales'], fn ($sale) => $sale['supplier_key'] === $supplierKey)));
    }

    private function resolvedSpecialSales(array $sales, array $decisions): array
    {
        foreach ($sales as &$sale) {
            $sale['decision'] ??= null;
        }
        unset($sale);

        return $sales;
    }

    private function mappingCounts(DataMigrationRun $run, string $entity): array
    {
        $rows = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $entity)->get();

        return [
            'source' => $rows->count(), 'created' => $rows->filter(fn ($row) => (bool) data_get($row->payload, 'created'))->count(),
            'matched' => $rows->where('decision', 'matched')->filter(fn ($row) => ! (bool) data_get($row->payload, 'created'))->count(),
            'duplicates' => $rows->filter(fn ($row) => in_array(data_get($row->payload, 'note'), ['duplicate_source_name', 'duplicate_targets', 'target_claimed_twice'], true))->count(),
            'excluded' => $rows->where('decision', 'excluded')->count(), 'review' => $rows->where('decision', 'needs_review')->count(),
        ];
    }
}
