<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DataMigrationLink;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Models\PaymentVoucher;
use App\Models\PurchaseBill;
use App\Models\Receipt;
use App\Models\SalesBill;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\Legacy\MappingIndex;
use App\Services\OperationsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class DocumentExecutionService
{
    public function __construct(private readonly OperationsService $operations, private readonly AccountingService $accounting) {}

    /** @return array{ok: bool, blockers: list<array<string, mixed>>, ready: int} */
    public function preflight(DataMigrationRun $run): array
    {
        $map = new MappingIndex($run);
        $blockers = [];
        $ready = 0;
        $add = function (string $code, string $entity, string $key, ?DataMigrationMapping $m) use (&$blockers): void {
            $id = $code.'|'.$entity.'|'.$key;
            $blockers[$id] ??= ['code' => $code, 'entity' => $entity, 'source_key' => $key, 'mapping_id' => $m?->id, 'name' => $m?->payload['source_name'] ?? null, 'records' => 0];
            $blockers[$id]['records']++;
        };
        foreach (DataMigrationRecord::query()->where('run_id', $run->id)->whereIn('status', ['ready', 'failed'])->cursor() as $r) {
            $ready++;
            foreach ($this->requirements($r) as [$entity, $key]) {
                if ($entity === 'payment_source') {
                    $this->checkPaymentSource($map, $key, $add);

                    continue;
                }
                $m = $map->get($entity, $key);
                if (! $map->target($entity, $key)) {
                    $add($m?->decision === 'excluded' ? 'mapping_excluded' : $entity.'_unmapped', $entity, $key, $m);
                }
            }
        }

        return ['ok' => $blockers === [], 'blockers' => array_values($blockers), 'ready' => $ready];
    }

    /** @param callable(string, string, string, ?DataMigrationMapping): void $add */
    private function checkPaymentSource(MappingIndex $map, string $source, callable $add): void
    {
        if (str_starts_with($source, 'treasury:')) {
            $key = substr($source, 9);
            if (! $map->target('cashbox', $key)) {
                $add('cashbox_unmapped', 'cashbox', $key, $map->get('cashbox', $key));
            }

            return;
        }
        $m = $map->get('payment_source', 'none');
        if (! $m || $m->decision !== 'matched' || ! $m->target_id) {
            $add($m?->decision === 'excluded' ? 'mapping_excluded' : 'payment_source_unmapped', 'payment_source', 'none', $m);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function requirements(DataMigrationRecord $r): array
    {
        return DocumentStager::requirements($r->entity_type, $r->normalized_payload ?? []);
    }

    /**
     * Processes ready/failed records in deterministic order. Stops when $budgetSeconds is used up so a long run can be resumed.
     *
     * @return array<string, int>
     */
    public function execute(DataMigrationRun $run, User $actor, int $budgetSeconds = 45): array
    {
        $company = Company::query()->findOrFail($run->company_id);
        $map = new MappingIndex($run);
        $counts = ['created' => 0, 'already_done' => 0, 'failed' => 0, 'remaining' => 0];
        $deadline = microtime(true) + $budgetSeconds;
        $ids = DataMigrationRecord::query()->where('run_id', $run->id)->whereIn('status', ['ready', 'failed'])
            ->get(['id', 'entity_type', 'source_id', 'source_date'])
            ->sortBy(fn ($r) => sprintf('%s|%d|%012d', $r->source_date?->toDateString() ?? '9999', DocumentStager::PRIORITY[$r->entity_type] ?? 9, (int) $r->source_id))
            ->pluck('id');
        foreach ($ids as $i => $id) {
            if (microtime(true) > $deadline) {
                $counts['remaining'] = $ids->count() - $i;

                break;
            }
            $outcome = $this->one($run, $company, $actor, $map, (int) $id);
            $counts[$outcome]++;
        }

        return $counts;
    }

    private function one(DataMigrationRun $run, Company $company, User $actor, MappingIndex $map, int $recordId): string
    {
        try {
            return DB::transaction(function () use ($run, $company, $actor, $map, $recordId): string {
                $record = DataMigrationRecord::query()->whereKey($recordId)->lockForUpdate()->firstOrFail();
                if (! in_array($record->status, ['ready', 'failed'], true)) {
                    return 'already_done';
                }
                $existing = $this->link($run, $company, $record);
                if ($existing) {
                    $record->forceFill(['status' => 'created', 'target_type' => $existing->target_type, 'target_id' => $existing->target_id, 'error_message' => null, 'review_code' => null, 'executed_at' => now()])->save();

                    return 'already_done';
                }
                [$type, $id, $result] = $this->post($run, $company, $actor, $map, $record);
                DataMigrationLink::query()->create(['company_id' => $company->id, 'run_id' => $run->id, 'source_system' => $run->source_system, 'source_table' => $record->source_table, 'source_id' => $record->source_id, 'document_type' => $record->entity_type, 'target_type' => $type, 'target_id' => $id, 'result' => $result ?: null]);
                $record->forceFill(['status' => 'created', 'target_type' => $type, 'target_id' => $id, 'error_message' => null, 'review_code' => null, 'attempts' => $record->attempts + 1, 'result' => $result ?: null, 'executed_at' => now()])->save();
                $this->lockMappings($run, $record);

                return 'created';
            });
        } catch (UniqueConstraintViolationException) {
            $record = DataMigrationRecord::query()->find($recordId);
            $link = $record ? $this->link($run, $company, $record) : null;
            if ($record && $link) {
                $record->forceFill(['status' => 'created', 'target_type' => $link->target_type, 'target_id' => $link->target_id, 'error_message' => null, 'executed_at' => now()])->save();

                return 'already_done';
            }

            return $this->fail($recordId, 'duplicate_conflict');
        } catch (Throwable $e) {
            return $this->fail($recordId, $this->safeMessage($e));
        }
    }

    private function link(DataMigrationRun $run, Company $company, DataMigrationRecord $record): ?DataMigrationLink
    {
        return DataMigrationLink::query()->where('company_id', $company->id)->where('source_system', $run->source_system)->where('source_table', $record->source_table)->where('source_id', $record->source_id)->where('document_type', $record->entity_type)->first();
    }

    private function fail(int $recordId, string $message): string
    {
        $r = DataMigrationRecord::query()->find($recordId);
        $r?->forceFill(['status' => 'failed', 'error_message' => mb_substr($message, 0, 1000), 'attempts' => ($r->attempts ?? 0) + 1])->save();

        return 'failed';
    }

    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(' ', array_slice(array_map(fn ($m) => (string) $m, array_merge(...array_values($e->errors()))), 0, 3));
        }
        if ($e instanceof HttpException) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'http_'.$e->getStatusCode();
        }
        report($e);

        return 'unexpected_error:'.class_basename($e);
    }

    /** @return array{0: string, 1: int, 2: array<string, mixed>} */
    private function post(DataMigrationRun $run, Company $company, User $actor, MappingIndex $map, DataMigrationRecord $record): array
    {
        $p = $record->normalized_payload ?? [];
        $note = 'Imported from legacy '.$record->source_number;
        $date = (string) $p['date'];
        switch ($record->entity_type) {
            case 'receipt_document':
                $target = $this->cashOrBank($map, (string) $p['payment_source']);
                $receipt = $this->accounting->receive($company, $actor, ['counterparty_type' => 'customer', 'party_id' => $map->target('customer', (string) $p['customer_key']), ...$target, 'receipt_date' => $date, 'amount' => $p['amount'], 'notes' => $note]);

                return [Receipt::class, $receipt->id, ['unallocated_amount' => (float) ($receipt->unallocated_amount ?? 0)]];
            case 'payment_document':
                $target = $this->cashOrBank($map, (string) $p['payment_source']);
                $voucher = $this->accounting->paySupplier($company, $actor, ['counterparty_type' => 'supplier', 'party_id' => $map->target('supplier', (string) $p['supplier_key']), ...$target, 'voucher_date' => $date, 'amount' => $p['amount'], 'notes' => $note]);

                return [PaymentVoucher::class, $voucher->id, ['unallocated_amount' => (float) ($voucher->unallocated_amount ?? 0)]];
            case 'purchase_document':
                return $this->purchase($company, $actor, $map, $record, $p, $date, $note);
            case 'sales_document':
                return $this->sales($company, $actor, $map, $record, $p, $date, $note);
        }
        throw ValidationException::withMessages(['record' => 'This record type needs an explicit decision and cannot be executed automatically.']);
    }

    /** @return array{cashbox_id?: int, bank_id?: int} */
    private function cashOrBank(MappingIndex $map, string $source): array
    {
        if (str_starts_with($source, 'treasury:')) {
            return ['cashbox_id' => (int) $map->target('cashbox', substr($source, 9))];
        }
        $m = $map->get('payment_source', 'none');

        return $m->target_type === 'Bank' ? ['bank_id' => (int) $m->target_id] : ['cashbox_id' => (int) $m->target_id];
    }

    /** @param array<string, mixed> $p @return array{0: string, 1: int, 2: array<string, mixed>} */
    private function purchase(Company $company, User $actor, MappingIndex $map, DataMigrationRecord $record, array $p, string $date, string $note): array
    {
        [$warehouse, $branch] = $this->warehouse($company, $map, (string) $p['warehouse_key']);
        $lines = $this->lines($map, $p['lines']);
        $weight = round(array_sum(array_column($lines, 'factory_weight')), 3);
        $packages = round(array_sum(array_column($lines, 'packages')), 3);
        $expected = round(array_sum(array_map(fn ($l) => (float) $l['legacy_value'], $p['lines'])) + (float) $p['extra_cost'], 2);
        $bill = $this->operations->savePurchase($company, $actor, [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'supplier_id' => $map->target('supplier', (string) $p['supplier_key']),
            'supplier_bill_number' => $record->source_number, 'supplier_bill_date' => $date, 'warehouse_entry_date' => $date,
            'total_factory_weight' => $weight, 'total_actual_weight' => $weight, 'total_packages' => $packages,
            'transport_cost' => 0, 'loading_cost' => 0, 'extra_cost' => $p['extra_cost'], 'discount' => 0, 'payment_method' => 'credit',
            'notes' => $note, 'lines' => $lines,
        ], true);
        if (abs((float) $bill->total - $expected) > 0.01) {
            throw ValidationException::withMessages(['total' => "total_mismatch: legacy {$expected} vs ERP {$bill->total}"]);
        }

        return [PurchaseBill::class, $bill->id, ['total' => (float) $bill->total]];
    }

    /** @param array<string, mixed> $p @return array{0: string, 1: int, 2: array<string, mixed>} */
    private function sales(Company $company, User $actor, MappingIndex $map, DataMigrationRecord $record, array $p, string $date, string $note): array
    {
        [$warehouse, $branch] = $this->warehouse($company, $map, (string) $p['warehouse_key']);
        $lines = $this->lines($map, $p['lines']);
        $weight = round(array_sum(array_column($lines, 'actual_weight')), 3);
        $bill = $this->operations->saveSales($company, $actor, [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'customer_id' => $map->target('customer', (string) $p['customer_key']),
            'customer_bill_number' => $record->source_number, 'bill_date' => $date, 'total_actual_weight' => $weight,
            'transport_cost' => $p['transport_cost'], 'loading_cost' => $p['loading_cost'], 'extra_cost' => 0, 'discount' => 0,
            'payment_method' => 'credit', 'due_date' => $date, 'notes' => $note, 'lines' => $lines,
        ], true);
        if (abs((float) $bill->total - (float) $p['legacy_total']) > 0.01) {
            throw ValidationException::withMessages(['total' => "total_mismatch: legacy {$p['legacy_total']} vs ERP {$bill->total}"]);
        }

        return [SalesBill::class, $bill->id, ['total' => (float) $bill->total]];
    }

    /** @return array{0: Warehouse, 1: Branch} */
    private function warehouse(Company $company, MappingIndex $map, string $key): array
    {
        $warehouse = Warehouse::query()->where('company_id', $company->id)->findOrFail($map->target('warehouse', $key));
        $branch = Branch::query()->where('company_id', $company->id)->findOrFail($warehouse->branch_id);

        return [$warehouse, $branch];
    }

    /**
     * Legacy quantities are kg. ERP lines are tons with price per ton: a per-kg price becomes price x 1000 so the line value is unchanged.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function lines(MappingIndex $map, array $lines): array
    {
        return array_map(function (array $l) use ($map): array {
            $basis = $map->basis((string) $l['product_key']);
            if ($basis === null) {
                throw ValidationException::withMessages(['lines' => 'unit_not_supported: '.$l['product_key']]);
            }
            $tons = round($l['kg'] / 1000, 3);

            return [
                'product_id' => $map->target('product', (string) $l['product_key']),
                'factory_weight' => $tons, 'actual_weight' => $tons, 'packages' => $l['packages'] ?? null,
                'unit_price' => $basis === 'kg' ? round($l['price'] * 1000, 2) : $l['price'], 'notes' => $l['note'] ?: null,
            ];
        }, $lines);
    }

    private function lockMappings(DataMigrationRun $run, DataMigrationRecord $record): void
    {
        foreach ($this->requirements($record) as [$entity, $key]) {
            DB::table('data_migration_mappings')->where('run_id', $run->id)->where('entity_type', $entity)->where('source_key', $key)->whereNull('locked_at')->update(['locked_at' => now()]);
        }
    }
}
