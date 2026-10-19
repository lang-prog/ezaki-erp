<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Bank;
use App\Models\Cashbox;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\Diameter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Warehouse;
use App\Services\AuditRecorder;
use App\Services\LegacyAnalysisService;
use App\Services\MigrationInvoiceExecutionService;
use App\Services\MigrationMappingService;
use App\Services\MigrationMasterDataService;
use App\Services\MigrationOpeningBalanceService;
use App\Services\MigrationRecordStagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DataMigrationController extends PlatformController
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/DataMigration', [
            'companies' => Company::query()
                ->where('status', '!=', 'archived')
                ->orderBy('name')
                ->get(['id', 'name', 'trade_name', 'status']),
            'runs' => DataMigrationRun::query()
                ->with(['company:id,name', 'creator:id,name'])
                ->latest('id')
                ->paginate(15)
                ->through(fn (DataMigrationRun $run): array => $this->runPayload($run)),
        ]);
    }

    public function store(Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'documents_from' => ['required', 'date'],
            'documents_to' => ['required', 'date', 'after_or_equal:documents_from'],
            'opening_balance_as_of' => ['required', 'date', 'before:documents_from'],
            'mode' => ['required', 'in:draft'],
            'source' => ['required', 'file', 'max:131072'],
        ]);
        $file = $request->file('source');
        $originalName = (string) $file->getClientOriginalName();
        $lowerName = strtolower($originalName);
        if (! str_ends_with($lowerName, '.sql') && ! str_ends_with($lowerName, '.sql.gz') && ! str_ends_with($lowerName, '.txt')) {
            throw ValidationException::withMessages(['source' => 'Only SQL or compressed SQL source files are accepted.']);
        }

        $company = Company::query()->where('status', '!=', 'archived')->findOrFail((int) $data['company_id']);
        $compressed = str_ends_with($lowerName, '.gz');
        $storedName = Str::uuid()->toString().($compressed ? '.sql.gz' : '.sql');
        $storedPath = $file->storeAs('data-migrations/'.$company->id, $storedName, 'local');
        abort_unless(is_string($storedPath), 500, 'The source file could not be stored.');

        try {
            $sourceHash = hash_file('sha256', Storage::disk('local')->path($storedPath));
            if ($sourceHash === false) {
                throw new \RuntimeException('The source file hash could not be calculated.');
            }
            $run = DataMigrationRun::query()->create([
                'company_id' => $company->id,
                'created_by' => $request->user()->id,
                'source_name' => Str::limit($originalName, 191, ''),
                'source_disk' => 'local',
                'source_path' => $storedPath,
                'source_hash' => $sourceHash,
                'documents_from' => $data['documents_from'],
                'documents_to' => $data['documents_to'],
                'opening_balance_as_of' => $data['opening_balance_as_of'],
                'mode' => 'draft',
                'status' => DataMigrationRun::STATUS_UPLOADED,
                'options' => ['units' => 'per_item_review', 'manual_review_for_uncertain' => true],
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }
        $audit->record('platform.data_migration_uploaded', $run, $company->id, $request->user()->id, [
            'source_name' => $run->source_name,
            'source_hash' => $run->source_hash,
            'documents_from' => $run->documents_from?->toDateString(),
            'documents_to' => $run->documents_to?->toDateString(),
        ], $request, ['severity' => 'warning', 'reason' => 'Legacy data migration source uploaded.']);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'run' => $this->runPayload($run)])
            : redirect()->route('super-admin.data-migrations.show', $run);
    }

    public function show(DataMigrationRun $run): Response
    {
        $run->load(['company:id,name', 'creator:id,name', 'mappings' => fn ($query) => $query->orderBy('entity_type')->orderBy('source_key')]);

        return Inertia::render('SuperAdmin/DataMigration', [
            'companies' => Company::query()->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name', 'trade_name', 'status']),
            'runs' => DataMigrationRun::query()->with(['company:id,name', 'creator:id,name'])->latest('id')->paginate(15)->through(fn (DataMigrationRun $item): array => $this->runPayload($item)),
            'selectedRun' => $this->runPayload($run),
            'mappingOptions' => $this->mappingOptions($run->company_id),
        ]);
    }

    public function updateMapping(DataMigrationRun $run, DataMigrationMapping $mapping, Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        abort_unless($mapping->run_id === $run->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['matched', 'create_required', 'needs_review', 'excluded'])],
            'target_id' => ['nullable', 'integer'],
            'unit' => ['nullable', 'string', Rule::in(['ton', 'kg', 'gram', 'piece', 'liter', 'meter', 'box', 'bag', 'other'])],
        ]);
        $targetId = isset($data['target_id']) ? (int) $data['target_id'] : null;
        if ($data['decision'] === 'matched') {
            abort_unless($targetId !== null && $this->targetBelongsToRun($run, $mapping, $targetId), 422);
        } else {
            $targetId = null;
        }
        $before = ['decision' => $mapping->decision, 'target_id' => $mapping->target_id];
        $payload = $mapping->payload ?? [];
        $payload['reviewed'] = true;
        if ($mapping->entity_type === 'product' && array_key_exists('unit', $data)) {
            data_set($payload, 'source_attributes.unit', $data['unit'] ?: null);
        }
        $mapping->forceFill(['decision' => $data['decision'], 'target_id' => $targetId, 'confidence' => $data['decision'] === 'matched' ? 1 : $mapping->confidence, 'payload' => $payload])->save();
        $audit->record('platform.data_migration_mapping_updated', $run, $run->company_id, $request->user()->id, ['mapping_id' => $mapping->id], $request, [
            'severity' => 'warning', 'reason' => 'Legacy data migration mapping reviewed.', 'before' => $before, 'after' => ['decision' => $mapping->decision, 'target_id' => $mapping->target_id],
        ]);

        return $this->done($request, ['mapping' => $mapping->fresh()]);
    }

    public function analyze(DataMigrationRun $run, Request $request, LegacyAnalysisService $analysis, MigrationMappingService $mapping, MigrationRecordStagingService $staging, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $run->load('company');
        $run->forceFill(['status' => DataMigrationRun::STATUS_ANALYZING, 'error_message' => null, 'started_at' => now()])->save();

        try {
            $summary = $analysis->analyze(
                Storage::disk($run->source_disk)->path($run->source_path),
                $run->documents_from->toDateString(),
                $run->documents_to->toDateString(),
            );
            $summary['mappings'] = $mapping->suggest($run);
            $summary['records'] = $staging->stage($run);
            $run->forceFill([
                'status' => DataMigrationRun::STATUS_REVIEW,
                'summary' => $summary,
                'completed_at' => now(),
            ])->save();
            $audit->record('platform.data_migration_analyzed', $run, $run->company_id, $request->user()->id, [
                'summary' => $summary['documents'] ?? [],
                'source_hash' => $run->source_hash,
            ], $request, ['severity' => 'warning', 'reason' => 'Legacy data migration source analyzed.']);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => DataMigrationRun::STATUS_FAILED,
                'error_message' => Str::limit($exception->getMessage(), 2000, ''),
                'completed_at' => now(),
            ])->save();
            $audit->record('platform.data_migration_analysis_failed', $run, $run->company_id, $request->user()->id, ['error' => $exception->getMessage()], $request, ['severity' => 'critical', 'reason' => 'Legacy data migration analysis failed.']);
            throw $exception;
        }

        return $this->done($request, ['run' => $this->runPayload($run->fresh(['company', 'creator']))]);
    }

    public function prepareMasters(DataMigrationRun $run, Request $request, MigrationMasterDataService $masters, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($run->status, [DataMigrationRun::STATUS_REVIEW, DataMigrationRun::STATUS_READY], true), 409, 'The migration must be analyzed before master data can be prepared.');
        $counts = $masters->createApproved($run, $request->user());
        $summary = $run->summary ?? [];
        $summary['master_data_created'] = $counts;
        $run->forceFill(['summary' => $summary])->save();
        $audit->record('platform.data_migration_masters_prepared', $run, $run->company_id, $request->user()->id, ['counts' => $counts], $request, ['severity' => 'warning', 'reason' => 'Approved legacy master data created in target company.']);

        return $this->done($request, ['run' => $this->runPayload($run->fresh(['company', 'creator']))]);
    }

    public function executeInvoices(DataMigrationRun $run, Request $request, MigrationInvoiceExecutionService $execution, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($run->status, [DataMigrationRun::STATUS_REVIEW, DataMigrationRun::STATUS_READY, DataMigrationRun::STATUS_FAILED], true), 409, 'The migration is not ready for invoice execution.');
        $run->forceFill(['status' => DataMigrationRun::STATUS_EXECUTING, 'started_at' => now(), 'error_message' => null])->save();
        try {
            $counts = $execution->execute($run, $request->user());
            $summary = $run->summary ?? [];
            $summary['invoice_execution'] = $counts;
            $run->forceFill(['status' => $counts['failed'] > 0 ? DataMigrationRun::STATUS_FAILED : DataMigrationRun::STATUS_REVIEW, 'summary' => $summary, 'completed_at' => now(), 'error_message' => $counts['failed'] > 0 ? 'Some invoices require review after the execution attempt.' : null])->save();
            $audit->record('platform.data_migration_invoices_executed', $run, $run->company_id, $request->user()->id, ['counts' => $counts], $request, ['severity' => $counts['failed'] > 0 ? 'critical' : 'warning', 'reason' => 'Legacy invoice execution completed as a separate controlled step.']);
        } catch (Throwable $exception) {
            $run->forceFill(['status' => DataMigrationRun::STATUS_FAILED, 'error_message' => Str::limit($exception->getMessage(), 2000, ''), 'completed_at' => now()])->save();
            throw $exception;
        }

        return $this->done($request, ['run' => $this->runPayload($run->fresh(['company', 'creator']))]);
    }

    public function prepareOpeningBalances(DataMigrationRun $run, Request $request, MigrationOpeningBalanceService $opening, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($run->status, [DataMigrationRun::STATUS_REVIEW, DataMigrationRun::STATUS_READY], true), 409, 'Analyze and review the migration before posting opening balances.');
        $result = $opening->post($run, $request->user());
        $summary = $run->summary ?? [];
        $summary['opening_balances'] = $result;
        $run->forceFill(['summary' => $summary])->save();
        $audit->record('platform.data_migration_openings_posted', $run, $run->company_id, $request->user()->id, ['result' => $result], $request, ['severity' => 'warning', 'reason' => 'Legacy opening balances and stock were posted after review.']);

        return $this->done($request, ['run' => $this->runPayload($run->fresh(['company', 'creator']))]);
    }

    /** @return array<string, mixed> */
    private function runPayload(DataMigrationRun $run): array
    {
        return [
            'id' => $run->id,
            'company' => $run->company?->only(['id', 'name']),
            'created_by' => $run->creator?->only(['id', 'name']),
            'source_name' => $run->source_name,
            'source_hash' => $run->source_hash,
            'documents_from' => $run->documents_from?->toDateString(),
            'documents_to' => $run->documents_to?->toDateString(),
            'opening_balance_as_of' => $run->opening_balance_as_of?->toDateString(),
            'mode' => $run->mode,
            'status' => $run->status,
            'summary' => $run->summary,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'mappings' => $run->relationLoaded('mappings') ? $run->mappings->map(fn (DataMigrationMapping $mapping): array => [
                'id' => $mapping->id, 'entity_type' => $mapping->entity_type, 'source_table' => $mapping->source_table,
                'source_key' => $mapping->source_key, 'target_type' => $mapping->target_type, 'target_id' => $mapping->target_id,
                'decision' => $mapping->decision, 'confidence' => $mapping->confidence, 'payload' => $mapping->payload,
            ])->values()->all() : null,
        ];
    }

    /** @return array<string, list<array{id:int,label:string}>> */
    private function mappingOptions(int $companyId): array
    {
        return [
            'customer' => CustomerSupplier::query()->where('company_id', $companyId)->where('is_customer', true)->orderBy('name')->get(['id', 'name'])->map(fn (CustomerSupplier $item): array => ['id' => $item->id, 'label' => $item->name])->all(),
            'supplier' => CustomerSupplier::query()->where('company_id', $companyId)->where('is_supplier', true)->orderBy('name')->get(['id', 'name'])->map(fn (CustomerSupplier $item): array => ['id' => $item->id, 'label' => $item->name])->all(),
            'product_type' => ProductType::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])->map(fn (ProductType $item): array => ['id' => $item->id, 'label' => $item->name])->all(),
            'category' => Diameter::query()->where('company_id', $companyId)->orderBy('millimeters')->get(['id', 'millimeters'])->map(fn (Diameter $item): array => ['id' => $item->id, 'label' => (string) $item->millimeters])->all(),
            'product' => Product::query()->where('company_id', $companyId)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->map(fn (Product $item): array => ['id' => $item->id, 'label' => $item->name])->all(),
            'warehouse' => Warehouse::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])->map(fn (Warehouse $item): array => ['id' => $item->id, 'label' => $item->name])->all(),
            'cashbox' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn (Cashbox $item): array => ['id' => $item->id, 'label' => $item->name])->all(),
            'bank' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'account_number'])->map(fn (Bank $item): array => ['id' => $item->id, 'label' => trim($item->name.' / '.($item->account_number ?? ''))])->all(),
        ];
    }

    private function targetBelongsToRun(DataMigrationRun $run, DataMigrationMapping $mapping, int $targetId): bool
    {
        return match ($mapping->entity_type) {
            'customer' => CustomerSupplier::query()->where('company_id', $run->company_id)->where('is_customer', true)->whereKey($targetId)->exists(),
            'supplier' => CustomerSupplier::query()->where('company_id', $run->company_id)->where('is_supplier', true)->whereKey($targetId)->exists(),
            'product_type' => ProductType::query()->where('company_id', $run->company_id)->whereKey($targetId)->exists(),
            'category' => Diameter::query()->where('company_id', $run->company_id)->whereKey($targetId)->exists(),
            'product' => Product::query()->where('company_id', $run->company_id)->whereNull('archived_at')->whereKey($targetId)->exists(),
            'warehouse' => Warehouse::query()->where('company_id', $run->company_id)->whereKey($targetId)->exists(),
            'cashbox' => Cashbox::query()->where('company_id', $run->company_id)->where('is_active', true)->whereKey($targetId)->exists(),
            'bank' => Bank::query()->where('company_id', $run->company_id)->where('is_active', true)->whereKey($targetId)->exists(),
            default => false,
        };
    }
}
