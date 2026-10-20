<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Models\PaymentVoucher;
use App\Models\PurchaseBill;
use App\Models\Receipt;
use App\Models\SalesBill;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MigrationInvoiceExecutionService
{
    public function __construct(private readonly OperationsService $operations, private readonly AccountingService $accounting, private readonly CompanyAccountingPolicyService $policies) {}

    /** @return array<string, int> */
    public function execute(DataMigrationRun $run, User $actor): array
    {
        $this->assertReady($run);
        $records = $run->records()->whereIn('status', [DataMigrationRecord::STATUS_READY, DataMigrationRecord::STATUS_FAILED])->orderBy('source_date')->orderBy('id')->get();
        $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        $company = $run->company()->firstOrFail();
        if ($records->contains('entity_type', 'sales_document') && $this->policies->vatRate($company) > 0) {
            throw ValidationException::withMessages(['execution' => 'Disable or map the target VAT policy before importing sales invoices; the legacy sales totals do not contain a separate VAT amount.']);
        }

        foreach ($records as $record) {
            if ($record->status === DataMigrationRecord::STATUS_CREATED) {
                $counts['skipped']++;

                continue;
            }
            try {
                $targetId = DB::transaction(function () use ($run, $record, $actor, $company): int {
                    $record = DataMigrationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                    $payload = $record->normalized_payload ?? [];
                    if ($record->entity_type === 'receipt_document') {
                        $customer = $this->mappingTarget($run, 'customer', (string) ($payload['customer_source_id'] ?? ''));
                        $cashbox = $this->cashbox($run, (string) ($payload['cashbox_source_id'] ?? ''));
                        $receipt = $this->accounting->receive($company, $actor, ['counterparty_type' => 'customer', 'party_id' => $customer, 'cashbox_id' => $cashbox->id, 'receipt_date' => $payload['date'], 'amount' => $payload['amount'], 'notes' => 'Imported from legacy system. '.(string) ($payload['notes'] ?? '')]);
                        $record->forceFill(['status' => DataMigrationRecord::STATUS_CREATED, 'target_type' => Receipt::class, 'target_id' => $receipt->id, 'error_message' => null])->save();

                        return $receipt->id;
                    }
                    if ($record->entity_type === 'payment_document') {
                        $supplier = $this->mappingTarget($run, 'supplier', (string) ($payload['supplier_source_id'] ?? ''));
                        $cashbox = $this->cashbox($run, (string) ($payload['cashbox_source_id'] ?? ''));
                        $voucher = $this->accounting->paySupplier($company, $actor, ['counterparty_type' => 'supplier', 'party_id' => $supplier, 'cashbox_id' => $cashbox->id, 'voucher_date' => $payload['date'], 'amount' => $payload['amount'], 'notes' => 'Imported from legacy system. '.(string) ($payload['notes'] ?? '')]);
                        $record->forceFill(['status' => DataMigrationRecord::STATUS_CREATED, 'target_type' => PaymentVoucher::class, 'target_id' => $voucher->id, 'error_message' => null])->save();

                        return $voucher->id;
                    }
                    $warehouse = $this->warehouse($run, (string) ($payload['warehouse_source_id'] ?? ''));
                    $branch = Branch::query()->where('company_id', $company->id)->whereKey($warehouse->branch_id)->firstOrFail();
                    $lines = $this->lines($run, $payload['lines'] ?? []);
                    if ($record->entity_type === 'purchase_document') {
                        $supplier = $this->mappingTarget($run, 'supplier', (string) ($payload['supplier_source_id'] ?? ''));
                        $bill = $this->operations->savePurchase($company, $actor, $this->purchaseData($payload, $supplier, $warehouse, $branch, $lines), true);
                        $record->forceFill(['status' => DataMigrationRecord::STATUS_CREATED, 'target_type' => PurchaseBill::class, 'target_id' => $bill->id, 'error_message' => null])->save();

                        return $bill->id;
                    }
                    $customer = $this->mappingTarget($run, 'customer', (string) ($payload['customer_source_id'] ?? ''));
                    $bill = $this->operations->saveSales($company, $actor, $this->salesData($payload, $customer, $warehouse, $branch, $lines), true);
                    $record->forceFill(['status' => DataMigrationRecord::STATUS_CREATED, 'target_type' => SalesBill::class, 'target_id' => $bill->id, 'error_message' => null])->save();

                    return $bill->id;
                });
                if ($targetId > 0) {
                    $counts['created']++;
                }
            } catch (\Throwable $exception) {
                $record->forceFill(['status' => DataMigrationRecord::STATUS_FAILED, 'error_message' => substr($exception->getMessage(), 0, 2000)])->save();
                $counts['failed']++;
            }
        }

        return $counts;
    }

    private function assertReady(DataMigrationRun $run): void
    {
        $unresolved = $run->records()->whereIn('status', [DataMigrationRecord::STATUS_READY, DataMigrationRecord::STATUS_FAILED])->get()->map(function (DataMigrationRecord $record) use ($run): ?string {
            $payload = $record->normalized_payload ?? [];
            $partyEntity = in_array($record->entity_type, ['purchase_document', 'payment_document'], true) ? 'supplier' : 'customer';
            $partyKey = in_array($record->entity_type, ['purchase_document', 'payment_document'], true) ? ($payload['supplier_source_id'] ?? '') : ($payload['customer_source_id'] ?? '');
            if (! $this->mappingTargetId($run, $partyEntity, (string) $partyKey)) {
                return "{$record->entity_type} {$record->source_id}: {$partyEntity} source {$partyKey}";
            }
            if (in_array($record->entity_type, ['purchase_document', 'sales_document'], true) && ! $this->mappingTargetId($run, 'warehouse', (string) ($payload['warehouse_source_id'] ?? ''))) {
                return "{$record->entity_type} {$record->source_id}: warehouse source ".($payload['warehouse_source_id'] ?? '');
            }
            if (in_array($record->entity_type, ['receipt_document', 'payment_document'], true) && ! $this->mappingTargetId($run, 'cashbox', (string) ($payload['cashbox_source_id'] ?? ''))) {
                return "{$record->entity_type} {$record->source_id}: cashbox source ".($payload['cashbox_source_id'] ?? '');
            }
            foreach ($payload['lines'] ?? [] as $line) {
                $productKey = (string) ($line['source_type_id'] ?? '').':'.(string) ($line['source_category_id'] ?? '');
                if (! $this->mappingTargetId($run, 'product', $productKey)) {
                    return "{$record->entity_type} {$record->source_id}: product source {$productKey}";
                }
            }

            return null;
        })->filter()->unique()->values();
        if ($unresolved->isNotEmpty()) {
            throw ValidationException::withMessages(['execution' => [
                'Every invoice, party, warehouse, and product mapping must be approved before execution.',
                'Unresolved mappings: '.$unresolved->take(20)->implode('; ').($unresolved->count() > 20 ? '; …' : ''),
            ]]);
        }
    }

    private function mappingTarget(DataMigrationRun $run, string $entity, string $sourceKey): int
    {
        $target = $this->mappingTargetId($run, $entity, $sourceKey);
        if (! $target) {
            throw ValidationException::withMessages(['mapping' => "Missing approved {$entity} mapping for source {$sourceKey}."]);
        }

        return $target;
    }

    private function mappingTargetId(DataMigrationRun $run, string $entity, string $sourceKey): ?int
    {
        return DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $entity)->where('source_key', $sourceKey)->where('decision', 'matched')->value('target_id');
    }

    private function warehouse(DataMigrationRun $run, string $sourceKey): Warehouse
    {
        return Warehouse::query()->where('company_id', $run->company_id)->findOrFail($this->mappingTarget($run, 'warehouse', $sourceKey));
    }

    private function cashbox(DataMigrationRun $run, string $sourceKey): Cashbox
    {
        return Cashbox::query()->where('company_id', $run->company_id)->where('is_active', true)->findOrFail($this->mappingTarget($run, 'cashbox', $sourceKey));
    }

    /** @param list<array<string, mixed>> $sourceLines @return list<array<string, mixed>> */
    private function lines(DataMigrationRun $run, array $sourceLines): array
    {
        return array_map(function (array $line) use ($run): array {
            return [
                'product_id' => $this->mappingTarget($run, 'product', (string) ($line['source_type_id'] ?? '').':'.(string) ($line['source_category_id'] ?? '')),
                'factory_weight' => $line['factory_weight'] ?? null,
                'actual_weight' => $line['actual_weight'] ?? null,
                'packages' => $line['packages'] ?? 0,
                'unit_price' => $line['unit_price'] ?? 0,
                'notes' => $line['notes'] ?? null,
            ];
        }, $sourceLines);
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private function purchaseData(array $payload, int $supplier, Warehouse $warehouse, Branch $branch, array $lines): array
    {
        return [
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier,
            'supplier_bill_number' => $payload['supplier_bill_number'],
            'supplier_bill_date' => $payload['date'],
            'warehouse_entry_date' => $payload['date'],
            'total_factory_weight' => $payload['total_factory_weight'],
            'total_actual_weight' => $payload['total_actual_weight'],
            'total_packages' => $payload['total_packages'],
            'transport_cost' => 0,
            'loading_cost' => 0,
            'extra_cost' => 0,
            'discount' => 0,
            'payment_method' => 'credit',
            'notes' => $this->migrationNote($payload),
            'lines' => $lines,
        ];
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private function salesData(array $payload, int $customer, Warehouse $warehouse, Branch $branch, array $lines): array
    {
        return [
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer,
            'customer_bill_number' => $payload['customer_bill_number'],
            'bill_date' => $payload['date'],
            'total_actual_weight' => $payload['total_actual_weight'],
            'total_packages' => $payload['total_packages'],
            'transport_cost' => $payload['transport_cost'] ?? 0,
            'loading_cost' => $payload['loading_cost'] ?? 0,
            'extra_cost' => $payload['extra_cost'] ?? 0,
            'discount' => 0,
            'payment_method' => 'credit',
            'due_date' => $payload['date'],
            'notes' => $this->migrationNote($payload),
            'lines' => $lines,
        ];
    }

    private function migrationNote(array $payload): string
    {
        return trim('Imported from legacy system. '.(string) ($payload['legacy_note'] ?? ''));
    }
}
