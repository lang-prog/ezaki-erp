<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Models\Diameter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Warehouse;
use App\Services\Migration\MigrationWorkflow;
use App\Services\Migration\OpeningBalanceService;
use App\Services\Migration\PartyOpeningBalanceService;
use App\Services\Migration\ReconciliationService;
use App\Services\Migration\ReportExporter;
use App\Services\Migration\RunStateMachine;
use App\Services\SubscriptionNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataMigrationController extends PlatformController
{
    private const STAGES = ['upload', 'parse', 'dates', 'mapping', 'masters', 'openings', 'staging', 'review', 'execution', 'reconciliation', 'archive'];

    /** Index of the stage currently awaiting action for each persisted status. */
    private const CURRENT = [
        'uploaded' => 1, 'parsing' => 1, 'parsed' => 3, 'review' => 3, 'masters_preflight' => 4, 'masters_ready' => 4, 'masters_created' => 5,
        'openings_preflight' => 5, 'openings_ready' => 5, 'openings_posted' => 6, 'documents_ready' => 7, 'failed_recoverable' => 7,
        'executing' => 8, 'completed_with_review' => 7, 'completed' => 9, 'signed_off' => 10, 'failed_terminal' => 1, 'archived' => 11,
        'party_openings_preflight' => 5, 'party_openings_ready' => 5, 'party_openings_posted' => 6, 'phase1_completed' => 9,
    ];

    public function __construct(private readonly MigrationWorkflow $workflow) {}

    public function index(): Response
    {
        return Inertia::render('SuperAdmin/DataMigration', [
            'companies' => Company::query()->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name', 'trade_name', 'status']),
            'runs' => DataMigrationRun::query()->with(['company:id,name', 'creator:id,name'])->latest('id')->paginate(15)->through(fn (DataMigrationRun $r): array => $this->runPayload($r, false)),
            'limits' => ['max_upload_kb' => (int) config('migration.max_upload_kb'), 'retention_days' => (int) config('migration.source_retention_days')],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        if ($response = $this->uploadSchemaPreflight($request)) {
            return $response;
        }

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'migration_scope' => ['nullable', Rule::in(['party_openings_phase1', 'full'])],
            'documents_from' => ['nullable', 'required_if:migration_scope,full', 'date'],
            'documents_to' => ['nullable', 'required_if:migration_scope,full', 'date', 'after_or_equal:documents_from'],
            'opening_balance_as_of' => ['required', 'date'],
            'gap_reason' => ['nullable', 'string', 'min:5', 'max:500'],
            'customer_start_balance_sign' => ['nullable', Rule::in(['debit_positive', 'debit_negative'])],
            'supplier_start_balance_sign' => ['nullable', Rule::in(['credit_positive', 'credit_negative'])],
            'source' => ['required', 'file', 'max:'.(int) config('migration.max_upload_kb')],
        ]);
        $company = Company::query()->where('status', '!=', 'archived')->findOrFail((int) $data['company_id']);
        $scope = $data['migration_scope'] ?? 'full';
        $timezone = app(SubscriptionNotice::class)->timezoneFor($company);
        $cutoff = CarbonImmutable::createFromFormat('!Y-m-d', $data['opening_balance_as_of'], $timezone);
        $effectiveDate = $cutoff->addDay()->toDateString();
        if ($scope === 'party_openings_phase1') {
            $data['documents_from'] = $effectiveDate;
            $data['documents_to'] = $effectiveDate;
            $gap = false;
        } else {
            if (! CarbonImmutable::createFromFormat('!Y-m-d', $data['documents_from'], $timezone)->greaterThan($cutoff)) {
                throw ValidationException::withMessages(['documents_from' => 'The document period must start after the opening cutoff date.']);
            }
            $gap = ! CarbonImmutable::createFromFormat('!Y-m-d', $data['documents_from'], $timezone)->isSameDay($cutoff->addDay());
        }
        if ($scope === 'full' && $gap && trim((string) ($data['gap_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['gap_reason' => 'documents_from must be the day after the cutoff, or a reason for the gap must be given.']);
        }
        $file = $request->file('source');
        if (! $file || ! $file->isValid() || ! is_readable($file->getRealPath())) {
            throw ValidationException::withMessages(['source' => 'The uploaded file is not readable. Please choose it again.']);
        }
        $name = strtolower((string) $file->getClientOriginalName());
        if (! str_ends_with($name, '.sql') && ! str_ends_with($name, '.sql.gz')) {
            throw ValidationException::withMessages(['source' => 'Only .sql or .sql.gz files are accepted.']);
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 8192);
        $gz = str_starts_with($head, "\x1f\x8b");
        if (str_ends_with($name, '.gz') !== $gz || (! $gz && (str_contains($head, "\0") || ! preg_match('/INSERT|CREATE TABLE|phpMyAdmin|mysqldump|^--/im', $head)))) {
            throw ValidationException::withMessages(['source' => 'The file is not a recognisable SQL dump (type and content do not match).']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['source' => 'The uploaded file could not be fingerprinted. Please choose it again.']);
        }
        $stored = 'data-migrations/'.$company->id.'/'.Str::uuid()->toString().($gz ? '.sql.gz' : '.sql');
        try {
            $written = $file->storeAs('data-migrations/'.$company->id, basename($stored), 'local');
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
        if (! is_string($written)) {
            Storage::disk('local')->delete($stored);
            abort(500, 'The source file could not be stored. Check the private storage disk permissions.');
        }
        $policy = array_filter(['customer_start_balance_sign' => $data['customer_start_balance_sign'] ?? null, 'supplier_start_balance_sign' => $data['supplier_start_balance_sign'] ?? null]);
        $policy['migration_scope'] = $scope;
        $identity = ['company_id' => $company->id, 'source_system' => config('migration.source_system'), 'source_hash' => $hash];
        try {
            [$run, $duplicate] = DB::transaction(function () use ($identity, $request, $file, $stored, $data, $policy, $gap, $scope): array {
                $claim = DB::table('data_migration_identity_claims')->where($identity)->lockForUpdate()->first();
                if ($claim) {
                    return [DataMigrationRun::query()->findOrFail($claim->run_id), true];
                }

                $existing = DataMigrationRun::query()
                    ->where('company_id', $identity['company_id'])
                    ->where('source_system', $identity['source_system'])
                    ->where('source_hash', $identity['source_hash'])
                    ->orderBy('id')
                    ->first();
                if ($existing) {
                    DB::table('data_migration_identity_claims')->insert($identity + [
                        'run_id' => $existing->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);

                    return [$existing, true];
                }

                $run = DataMigrationRun::query()->create([
                    'company_id' => $identity['company_id'], 'created_by' => $request->user()->id, 'source_system' => $identity['source_system'],
                    'source_name' => Str::limit((string) $file->getClientOriginalName(), 191, ''), 'source_disk' => 'local', 'source_path' => $stored,
                    'source_hash' => $identity['source_hash'], 'source_bytes' => $file->getSize(), 'documents_from' => $data['documents_from'], 'documents_to' => $data['documents_to'],
                    'opening_balance_as_of' => $data['opening_balance_as_of'], 'mode' => $scope, 'status' => DataMigrationRun::STATUS_UPLOADED,
                    'policy' => $policy ?: null, 'gap_acknowledged_at' => $gap ? now() : null, 'gap_acknowledged_reason' => $gap ? $data['gap_reason'] : null,
                ]);
                DB::table('data_migration_identity_claims')->insert($identity + [
                    'run_id' => $run->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->workflow->machine()->record($run, 'uploaded', null, 'uploaded', $request->user(), $gap ? $data['gap_reason'] : null, ['bytes' => $run->source_bytes, 'gap' => $gap]);

                return [$run, false];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $claim = DB::table('data_migration_identity_claims')->where($identity)->first();
            if (! $claim) {
                Storage::disk('local')->delete($stored);
                throw $exception;
            }
            $run = DataMigrationRun::query()->findOrFail($claim->run_id);
            $duplicate = true;
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
        if ($duplicate) {
            Storage::disk('local')->delete($stored);
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'duplicate' => $duplicate, 'run' => $this->runPayload($run)])
            : redirect()->route('super-admin.data-migrations.show', $run);
    }

    private function uploadSchemaPreflight(Request $request): JsonResponse|RedirectResponse|null
    {
        $required = [
            'data_migration_runs' => ['source_bytes', 'policy', 'integrity', 'preflight', 'state_version', 'lock_token', 'lock_expires_at', 'gap_acknowledged_at', 'gap_acknowledged_reason'],
            'data_migration_mappings' => ['reason', 'reviewed_by', 'reviewed_at', 'locked_at'],
            'data_migration_records' => ['review_code', 'exclusion_reason', 'attempts', 'result', 'executed_at'],
            'products' => ['material_category', 'quantity_basis'],
            'data_migration_source_rows' => ['run_id', 'source_table', 'row_number', 'source_key', 'payload'],
            'data_migration_events' => ['run_id', 'action', 'actor_id', 'reason', 'counts', 'error'],
            'data_migration_links' => ['company_id', 'run_id', 'source_system', 'source_table', 'source_id', 'document_type', 'target_type', 'target_id', 'result'],
            'data_migration_identity_claims' => ['company_id', 'source_system', 'source_hash', 'run_id'],
        ];
        $missing = [];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }
        $migrationRecorded = Schema::hasTable('migrations') && DB::table('migrations')->whereIn('migration', [
            '2026_10_21_000001_remediate_data_migration_center', '2026_10_21_000002_create_data_migration_identity_claims',
        ])->count() === 2;
        if ($missing === [] && $migrationRecorded) {
            return null;
        }

        $message = __('migration.schema_pending');
        if ($request->headers->has('X-Inertia') || ! $request->expectsJson()) {
            return back()->withErrors(['source' => $message]);
        }

        return response()->json(['message' => $message, 'code' => 'migration_schema_pending'], 503);
    }

    public function show(DataMigrationRun $run): Response
    {
        return Inertia::render('SuperAdmin/DataMigrationRun', ['run' => $this->runPayload($run), 'options' => $this->options($run)]);
    }

    public function state(DataMigrationRun $run): JsonResponse
    {
        return response()->json(['run' => $this->runPayload($run), 'options' => $this->options($run)]);
    }

    public function parse(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $result = $this->workflow->parse($run->id, $request->user());

        return $this->done($request, ['result' => ['mappings' => $result['mappings']], 'run' => $this->runPayload($run->fresh())]);
    }

    public function mappings(DataMigrationRun $run, Request $request): JsonResponse
    {
        $q = DataMigrationMapping::query()->where('run_id', $run->id);
        foreach (['entity' => 'entity_type', 'decision' => 'decision'] as $param => $col) {
            if ($request->filled($param)) {
                $q->where($col, (string) $request->query($param));
            }
        }
        if ($request->filled('note')) {
            $q->where('payload', 'like', '%"note":"'.addcslashes((string) $request->query('note'), '%_\\"').'"%');
        }
        if ($request->filled('q')) {
            $like = $this->like((string) $request->query('q'));
            $q->where(fn ($w) => $w->where('source_key', 'like', $like)->orWhere('payload', 'like', $like));
        }
        $page = $q->orderBy('entity_type')->orderBy('id')->paginate((int) config('migration.mappings_per_page'));

        return response()->json(['data' => $page->getCollection()->map(fn (DataMigrationMapping $m) => $this->mappingPayload($m))->values(), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'counts' => $this->mappingCounts($run)]);
    }

    public function updateMapping(DataMigrationRun $run, DataMigrationMapping $mapping, Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($mapping->run_id === $run->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['matched', 'create_required', 'needs_review', 'excluded'])],
            'target_id' => ['nullable', 'integer'],
            'target_kind' => ['nullable', Rule::in(['cashbox', 'bank'])],
            'reason' => ['nullable', 'string', 'max:500'],
            'unit_basis' => ['nullable', Rule::in(['ton_weight', 'kg_weight', 'count', 'bag', 'piece', 'box', 'meter', 'volume', 'other'])],
            'opening_cost_per_ton' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
        ]);
        $this->workflow->machine()->locked($run->id, 'mapping_update', DataMigrationRun::MAPPING_EDITABLE, function (DataMigrationRun $fresh) use ($mapping, $data, $request): void {
            $m = DataMigrationMapping::query()->whereKey($mapping->id)->where('run_id', $fresh->id)->lockForUpdate()->firstOrFail();
            if ($m->locked_at !== null) {
                throw RunStateMachine::refuse('mapping_locked', 'This mapping was used by a posted financial effect and can no longer be changed.');
            }
            if (in_array($fresh->status, ['documents_ready', 'failed_recoverable', 'openings_posted'], true) && $m->entity_type === 'category' && $m->decision === 'matched') {
                throw RunStateMachine::refuse('mapping_locked', 'Dimension mappings are frozen once openings are posted.');
            }
            $decision = $data['decision'];
            $targetId = $decision === 'matched' ? ($data['target_id'] ?? null) : null;
            if ($decision === 'matched') {
                if ($targetId === null || ! $this->targetValid($fresh, $m, (int) $targetId, $data['target_kind'] ?? null)) {
                    throw ValidationException::withMessages(['target_id' => 'The target does not exist in the selected company or does not fit this entity type.']);
                }
            }
            if ($decision === 'create_required' && ! in_array($m->entity_type, ['customer', 'supplier', 'product_type', 'warehouse', 'cashbox', 'bank', 'product', 'branch'], true)) {
                throw ValidationException::withMessages(['decision' => 'This entity cannot be created automatically.']);
            }
            if ($decision === 'create_required' && $m->entity_type === 'category' && ($m->payload['source_kind'] ?? null) !== 'diameter') {
                throw ValidationException::withMessages(['decision' => 'Text materials are never created as diameters.']);
            }
            if ($decision === 'excluded' && trim((string) ($data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['reason' => 'An exclusion reason is required.']);
            }
            $payload = $m->payload ?? [];
            $payload['reviewed'] = true;
            if ($m->entity_type === 'product') {
                if (array_key_exists('unit_basis', $data) && $data['unit_basis'] !== null) {
                    data_set($payload, 'source_attributes.unit_basis', $data['unit_basis']);
                }
                if (array_key_exists('opening_cost_per_ton', $data)) {
                    data_set($payload, 'source_attributes.opening_cost_per_ton', $data['opening_cost_per_ton']);
                }
            }
            $targetType = $m->target_type;
            if ($m->entity_type === 'payment_source' && $decision === 'matched') {
                $targetType = ucfirst((string) ($data['target_kind'] ?? 'cashbox'));
            }
            $before = ['decision' => $m->decision, 'target_id' => $m->target_id];
            $m->forceFill(['decision' => $decision, 'target_id' => $targetId, 'target_type' => $targetType, 'confidence' => $decision === 'matched' ? 1 : $m->confidence, 'payload' => $payload, 'reason' => $data['reason'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
            $this->workflow->machine()->record($fresh, 'mapping_updated', $fresh->status, $fresh->status, $request->user(), $data['reason'] ?? null, ['mapping_id' => $m->id, 'entity' => $m->entity_type, 'before' => $before, 'after' => ['decision' => $decision, 'target_id' => $targetId]]);
            if ($fresh->status === 'masters_ready') {
                $this->workflow->machine()->transition($fresh, 'review', 'mapping_changed', $request->user());
            } elseif ($fresh->status === 'openings_ready') {
                $this->workflow->machine()->transition($fresh, 'masters_created', 'mapping_changed', $request->user());
            }
        });

        return $this->done($request, ['mapping' => $this->mappingPayload($mapping->fresh())]);
    }

    public function approveSafe(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $entity = $request->validate(['entity' => ['nullable', 'string', 'max:40']])['entity'] ?? null;
        $count = $this->workflow->machine()->locked($run->id, 'mappings_bulk', ['review', 'masters_ready'], function (DataMigrationRun $fresh) use ($entity, $request): int {
            $n = 0;
            $q = DataMigrationMapping::query()->where('run_id', $fresh->id)->where('decision', 'matched')->whereNull('locked_at');
            if ($entity) {
                $q->where('entity_type', $entity);
            }
            foreach ($q->lockForUpdate()->get() as $m) {
                if (! $m->isReviewed() && in_array($m->payload['note'] ?? null, ['name_exact', 'existing_product', 'diameter_exact', 'single_active_branch'], true)) {
                    $p = $m->payload;
                    $p['reviewed'] = true;
                    $m->forceFill(['payload' => $p, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
                    $n++;
                }
            }
            $this->workflow->machine()->record($fresh, 'mappings_bulk_approved', $fresh->status, $fresh->status, $request->user(), null, ['approved' => $n, 'entity' => $entity]);

            return $n;
        });

        return $this->done($request, ['approved' => $count]);
    }

    public function preflightMasters(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $result = $this->workflow->preflightMasters($run->id, $request->user());

        return $this->done($request, ['preflight' => ['ok' => $result['ok'], 'blockers' => array_slice($result['blockers'], 0, 200), 'counts' => $result['counts']], 'run' => $this->runPayload($run->fresh())]);
    }

    public function createMasters(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $counts = $this->workflow->createMasters($run->id, $request->user());

        return $this->done($request, ['created' => $counts, 'run' => $this->runPayload($run->fresh())]);
    }

    public function openingsPreview(DataMigrationRun $run): JsonResponse
    {
        abort_unless(in_array($run->status, ['masters_created', 'openings_preflight', 'openings_ready', 'openings_posted', 'documents_ready', 'failed_recoverable', 'executing', 'completed_with_review', 'completed', 'signed_off'], true), 409, 'Create master data before previewing opening balances.');

        return response()->json(['preview' => app(OpeningBalanceService::class)->preview($run)]);
    }

    public function preflightOpenings(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $preview = $this->workflow->preflightOpenings($run->id, $request->user());

        return $this->done($request, ['ready' => $preview['ok'], 'blockers' => $preview['blockers'], 'totals' => $preview['totals'], 'run' => $this->runPayload($run->fresh())]);
    }

    public function phaseOnePreview(DataMigrationRun $run): JsonResponse
    {
        abort_unless($run->mode === 'party_openings_phase1', 409, 'This migration is not a Phase 1 party-opening run.');

        return response()->json(['phase1' => app(PartyOpeningBalanceService::class)->preview($run)]);
    }

    public function phaseOnePreflight(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $result = $this->workflow->preflightPartyPhaseOne($run->id, $request->user());

        return $this->done($request, ['ready' => $result['ok'], 'phase1' => $result]);
    }

    public function phaseOneCreateMasters(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['created' => $this->workflow->createPhaseOnePartyMasters($run->id, $request->user()), 'run' => $this->runPayload($run->fresh())]);
    }

    public function phaseOneCreateAllPartyMasters(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['result' => $this->workflow->createAllPhaseOnePartyMasters($run->id, $request->user()), 'run' => $this->runPayload($run->fresh())]);
    }

    public function phaseOneIssue(Request $request, DataMigrationRun $run): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'issue_key' => ['required', 'string', 'size:64'],
            'decision' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->done($request, ['phase1' => $this->workflow->decidePartyPhaseOneIssue($run->id, $request->user()->id, $data['issue_key'], $data['decision'], $data['reason'])]);
    }

    public function phaseOnePost(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['result' => $this->workflow->postPhaseOnePartyOpenings($run->id, $request->user()), 'run' => $this->runPayload($run->fresh())]);
    }

    public function phaseOneReverse(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['result' => $this->workflow->reversePhaseOnePartyOpenings($run->id, $request->user(), $this->reason($request)), 'run' => $this->runPayload($run->fresh())]);
    }

    public function phaseOneReconciliation(DataMigrationRun $run): JsonResponse
    {
        return response()->json(['reconciliation' => $this->workflow->partyPhaseOneReconciliation($run)]);
    }

    public function postOpenings(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['result' => $this->workflow->postOpenings($run->id, $request->user()), 'run' => $this->runPayload($run->fresh())]);
    }

    public function reverseOpenings(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['result' => $this->workflow->reverseOpenings($run->id, $request->user(), $this->reason($request)), 'run' => $this->runPayload($run->fresh())]);
    }

    public function policy(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'customer_start_balance_sign' => ['nullable', Rule::in(['debit_positive', 'debit_negative'])],
            'supplier_start_balance_sign' => ['nullable', Rule::in(['credit_positive', 'credit_negative'])],
            'ack' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $patch = array_filter(['customer_start_balance_sign' => $data['customer_start_balance_sign'] ?? null, 'supplier_start_balance_sign' => $data['supplier_start_balance_sign'] ?? null], fn ($v) => $v !== null);
        $updated = $this->workflow->setPolicy($run->id, $request->user(), $patch, $data['ack'] ?? null, $data['reason'] ?? null);

        return $this->done($request, ['run' => $this->runPayload($updated)]);
    }

    public function stageDocuments(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['counts' => $this->workflow->stageDocuments($run->id, $request->user()), 'run' => $this->runPayload($run->fresh())]);
    }

    public function records(DataMigrationRun $run, Request $request): JsonResponse
    {
        $q = DataMigrationRecord::query()->where('run_id', $run->id);
        foreach (['entity' => 'entity_type', 'status' => 'status', 'code' => 'review_code'] as $param => $col) {
            if ($request->filled($param)) {
                $q->where($col, (string) $request->query($param));
            }
        }
        if ($request->filled('q')) {
            $like = $this->like((string) $request->query('q'));
            $q->where(fn ($w) => $w->where('source_number', 'like', $like)->orWhere('source_id', 'like', $like)->orWhere('normalized_payload', 'like', $like));
        }
        $page = $q->orderBy('source_date')->orderBy('id')->paginate((int) config('migration.records_per_page'));

        return response()->json(['data' => $page->getCollection()->map(fn (DataMigrationRecord $r) => $this->recordPayload($r))->values(), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'counts' => $this->workflow->counts($run)]);
    }

    public function excludeRecord(DataMigrationRun $run, DataMigrationRecord $record, Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($record->run_id === $run->id, 404);
        $reason = $this->reason($request);

        return $this->done($request, ['counts' => $this->workflow->decideRecord($run->id, $record->id, $request->user(), 'exclude', $reason)]);
    }

    public function includeRecord(DataMigrationRun $run, DataMigrationRecord $record, Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($record->run_id === $run->id, 404);

        return $this->done($request, ['counts' => $this->workflow->decideRecord($run->id, $record->id, $request->user(), 'include', null)]);
    }

    public function executionPreflight(DataMigrationRun $run): JsonResponse
    {
        abort_unless(in_array($run->status, ['documents_ready', 'failed_recoverable', 'completed_with_review', 'executing'], true), 409, 'Stage the documents before checking execution.');
        $r = $this->workflow->executionPreflight($run);

        return response()->json(['ok' => $r['ok'], 'ready' => $r['ready'], 'blockers' => array_slice($r['blockers'], 0, 200)]);
    }

    public function execute(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $result = $this->workflow->execute($run->id, $request->user());

        return $this->done($request, ['result' => $result, 'run' => $this->runPayload($run->fresh())]);
    }

    public function reconciliation(DataMigrationRun $run): JsonResponse
    {
        abort_unless(in_array($run->status, DataMigrationRun::FINANCIAL, true), 409, 'Nothing was posted yet.');

        return response()->json(['reconciliation' => app(ReconciliationService::class)->build($run)]);
    }

    public function signOff(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        $report = $this->workflow->signOff($run->id, $request->user(), $this->reason($request));

        return $this->done($request, ['reconciliation' => $report, 'run' => $this->runPayload($run->fresh())]);
    }

    public function archive(DataMigrationRun $run, Request $request): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['run' => $this->runPayload($this->workflow->archive($run->id, $request->user(), $this->reason($request)))]);
    }

    public function events(DataMigrationRun $run): JsonResponse
    {
        return response()->json(['events' => $run->events()->latest('id')->limit(200)->get(['id', 'action', 'from_state', 'to_state', 'actor_id', 'reason', 'counts', 'error', 'created_at'])]);
    }

    public function report(DataMigrationRun $run, string $type, ReportExporter $exporter): StreamedResponse
    {
        [$header, $rows] = $exporter->build($run, $type);

        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map([ReportExporter::class, 'cell'], $row));
            }
            fclose($out);
        }, "migration-{$run->id}-{$type}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function runPayload(DataMigrationRun $run, bool $detail = true): array
    {
        $run->loadMissing(['company:id,name', 'creator:id,name']);
        $base = [
            'id' => $run->id, 'company' => $run->company?->only(['id', 'name']), 'created_by' => $run->creator?->only(['id', 'name']),
            'source_name' => $run->source_name, 'source_hash' => $run->source_hash, 'source_bytes' => $run->source_bytes,
            'documents_from' => $run->documents_from?->toDateString(), 'documents_to' => $run->documents_to?->toDateString(), 'opening_balance_as_of' => $run->opening_balance_as_of?->toDateString(),
            'status' => $run->status, 'mode' => $run->mode, 'scope' => $run->policy('migration_scope', $run->mode), 'error_message' => $run->error_message, 'created_at' => $run->created_at?->toISOString(), 'locked' => $run->lock_token !== null && $run->lock_expires_at?->isFuture(),
        ];
        if (! $detail) {
            return $base;
        }
        $integrity = $run->integrity ?? [];
        unset($integrity['errors']);

        return $base + [
            'summary' => $run->summary, 'integrity' => $integrity + ['errors' => array_slice($run->integrity['errors'] ?? [], 0, 20)], 'preflight' => $run->preflight, 'policy' => $run->policy,
            'gap_reason' => $run->gap_acknowledged_reason, 'source_retention_until' => $run->source_retention_until?->toDateString(), 'source_removed' => $run->source_removed_at !== null,
            'counts' => ['mappings' => $this->mappingCounts($run), 'records' => $this->workflow->counts($run)],
            'stages' => $this->stages($run),
            'allowed_next' => array_values(array_filter(RunStateMachine::ALLOWED[$run->status] ?? [])),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function stages(DataMigrationRun $run): array
    {
        $current = self::CURRENT[$run->status] ?? 0;
        $failed = in_array($run->status, ['failed_terminal'], true);
        $next = [
            'uploaded' => 'parse', 'parsing' => 'wait', 'parsed' => 'wait', 'review' => 'review_mappings', 'masters_preflight' => 'wait', 'masters_ready' => 'create_masters',
            'masters_created' => $run->mode === 'party_openings_phase1' ? 'phase1_preflight' : 'preview_openings', 'openings_preflight' => 'wait', 'openings_ready' => 'post_openings', 'openings_posted' => 'stage_documents',
            'party_openings_preflight' => 'wait', 'party_openings_ready' => 'post_party_openings', 'party_openings_posted' => 'phase1_complete', 'phase1_completed' => 'sign_off',
            'documents_ready' => 'execute', 'failed_recoverable' => 'retry', 'executing' => 'resume', 'completed_with_review' => 'review_records', 'completed' => 'sign_off',
            'signed_off' => 'archive', 'failed_terminal' => 'reupload', 'archived' => 'none',
        ][$run->status] ?? 'none';
        $stages = [];
        foreach (self::STAGES as $i => $id) {
            $state = $i < $current ? 'done' : ($i === $current ? ($failed ? 'blocked' : 'current') : 'todo');
            $stages[] = ['id' => $id, 'state' => $state, 'next_action' => $i === $current ? $next : null, 'irreversible' => in_array($id, ['openings', 'execution'], true), 'stepup' => in_array($id, ['masters', 'openings', 'execution', 'reconciliation', 'archive'], true), 'retry' => in_array($id, ['parse', 'masters', 'execution'], true)];
        }

        return $stages;
    }

    /** @return array<string, array<string, int>> */
    private function mappingCounts(DataMigrationRun $run): array
    {
        $out = [];
        foreach (DataMigrationMapping::query()->where('run_id', $run->id)->selectRaw('entity_type, decision, count(*) as n')->groupBy('entity_type', 'decision')->get() as $r) {
            $out[$r->entity_type][$r->decision] = (int) $r->n;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function mappingPayload(DataMigrationMapping $m): array
    {
        $p = $m->payload ?? [];

        return [
            'id' => $m->id, 'entity' => $m->entity_type, 'source_key' => $m->source_key, 'name' => $p['source_name'] ?? $p['source_value'] ?? null,
            'decision' => $m->decision, 'note' => $p['note'] ?? null, 'target_id' => $m->target_id, 'target_type' => $m->target_type, 'candidates' => $p['candidates'] ?? [],
            'reviewed' => $m->isReviewed(), 'locked' => $m->locked_at !== null, 'reason' => $m->reason, 'missing_in_source' => (bool) ($p['missing_in_source'] ?? false),
            'unit_basis' => $p['source_attributes']['unit_basis'] ?? null, 'price_evidence' => $p['source_attributes']['price_evidence'] ?? null, 'steel' => $p['source_attributes']['steel'] ?? null,
            'opening_cost_per_ton' => $p['source_attributes']['opening_cost_per_ton'] ?? null, 'lines' => $p['lines'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function recordPayload(DataMigrationRecord $r): array
    {
        $p = $r->normalized_payload ?? [];

        return [
            'id' => $r->id, 'entity' => $r->entity_type, 'number' => $r->source_number, 'source_id' => $r->source_id, 'date' => $r->source_date?->toDateString(),
            'status' => $r->status, 'code' => $r->review_code, 'error' => $r->error_message, 'exclusion_reason' => $r->exclusion_reason,
            'amount' => $p['amount'] ?? ($p['legacy_total'] ?? null), 'party_key' => $p['customer_key'] ?? $p['supplier_key'] ?? null, 'lines' => isset($p['lines']) ? count($p['lines']) : null,
            'target' => $r->target_type ? class_basename($r->target_type).'#'.$r->target_id : null, 'result' => $r->result,
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function options(DataMigrationRun $run): array
    {
        $c = (int) $run->company_id;

        return [
            'customer' => CustomerSupplier::query()->where('company_id', $c)->where('is_customer', true)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name])->all(),
            'supplier' => CustomerSupplier::query()->where('company_id', $c)->where('is_supplier', true)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name])->all(),
            'product_type' => ProductType::query()->where('company_id', $c)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name])->all(),
            'category' => Diameter::query()->where('company_id', $c)->orderBy('millimeters')->get(['id', 'millimeters'])->map(fn ($x) => ['id' => $x->id, 'label' => rtrim(rtrim((string) $x->millimeters, '0'), '.').' mm'])->all(),
            'product' => Product::query()->where('company_id', $c)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name])->all(),
            'warehouse' => Warehouse::query()->where('company_id', $c)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name])->all(),
            'cashbox' => Cashbox::query()->where('company_id', $c)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name])->all(),
            'bank' => Bank::query()->where('company_id', $c)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'account_number'])->map(fn ($x) => ['id' => $x->id, 'label' => trim($x->name.' / '.($x->account_number ?? ''))])->all(),
            'branch' => Branch::query()->where('company_id', $c)->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name])->all(),
        ];
    }

    private function targetValid(DataMigrationRun $run, DataMigrationMapping $m, int $id, ?string $kind = null): bool
    {
        $c = $run->company_id;

        return match ($m->entity_type) {
            'customer' => CustomerSupplier::query()->where('company_id', $c)->where('is_customer', true)->whereKey($id)->exists(),
            'supplier' => CustomerSupplier::query()->where('company_id', $c)->where('is_supplier', true)->whereKey($id)->exists(),
            'product_type' => ProductType::query()->where('company_id', $c)->whereKey($id)->exists(),
            'category' => Diameter::query()->where('company_id', $c)->whereKey($id)->exists() && ($m->payload['source_kind'] ?? null) === 'diameter',
            'product' => Product::query()->where('company_id', $c)->whereNull('archived_at')->whereKey($id)->exists(),
            'warehouse' => Warehouse::query()->where('company_id', $c)->whereKey($id)->exists(),
            'cashbox' => Cashbox::query()->where('company_id', $c)->where('is_active', true)->whereKey($id)->exists(),
            'bank' => Bank::query()->where('company_id', $c)->where('is_active', true)->whereKey($id)->exists(),
            'branch' => Branch::query()->where('company_id', $c)->where('status', 'active')->whereKey($id)->exists(),
            'payment_source' => $kind === 'bank'
                ? Bank::query()->where('company_id', $c)->where('is_active', true)->whereKey($id)->exists()
                : Cashbox::query()->where('company_id', $c)->where('is_active', true)->whereKey($id)->exists(),
            default => false,
        };
    }
}
