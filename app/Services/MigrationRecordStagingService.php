<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

final class MigrationRecordStagingService
{
    public function __construct(private readonly LegacySqlSnapshotParser $parser) {}

    /** @return array<string, int> */
    public function stage(DataMigrationRun $run): array
    {
        $snapshot = $this->parser->parse(Storage::disk($run->source_disk)->path($run->source_path));
        $from = $run->documents_from->startOfDay();
        $to = $run->documents_to->endOfDay();
        $purchaseDetails = collect($snapshot['details_buy'])->groupBy(fn (array $row): string => (string) ($row['id_buy'] ?? ''));
        $salesDetails = collect($snapshot['details_sales'])->groupBy(fn (array $row): string => (string) ($row['id_sales'] ?? ''));
        $counts = ['ready' => 0, 'needs_review' => 0];

        foreach ($snapshot['buy'] as $row) {
            if (! $this->inRange($row['date'] ?? null, $from, $to)) {
                continue;
            }
            if ($this->isPaymentRow($row)) {
                continue;
            }
            $id = (string) ($row['id_buy'] ?? '');
            $details = $purchaseDetails->get($id, collect())->values()->all();
            $ready = $details !== [] && ! empty($row['id_import']) && ! empty($row['id_stores']) && ! empty($row['id_type']);
            $this->upsert($run, 'purchase_document', 'buy', $id, $row['date'] ?? null, 'legacy-buy-'.$id, $row, $this->normalizePurchase($row, $details), $ready);
            $counts[$ready ? 'ready' : 'needs_review']++;
        }

        foreach ($snapshot['sales'] as $row) {
            if (! $this->inRange($row['insert_date'] ?? null, $from, $to)) {
                continue;
            }
            if ($this->isPaymentRow($row)) {
                continue;
            }
            $id = (string) ($row['id_sales'] ?? '');
            $details = $salesDetails->get($id, collect())->values()->all();
            $normalized = $this->normalizeSales($row, $details);
            $calculatedTotal = round((float) $normalized['subtotal'] + (float) $normalized['transport_cost'] + (float) $normalized['loading_cost'], 2);
            $ready = $details !== [] && ! empty($row['id_customer']) && ! empty($row['id_stores']) && abs((float) $normalized['legacy_total'] - $calculatedTotal) < 0.01;
            $this->upsert($run, 'sales_document', 'sales', $id, $row['insert_date'] ?? null, 'legacy-sales-'.$id, $row, $normalized, $ready);
            $counts[$ready ? 'ready' : 'needs_review']++;
        }

        $counts = [...$counts, ...$this->stagePayments($run, $snapshot, $from, $to), ...$this->stageExpenses($run, $snapshot, $from, $to)];

        return $counts;
    }

    /** @param array<string, list<array<string, mixed>>> $snapshot @return array<string, int> */
    private function stagePayments(DataMigrationRun $run, array $snapshot, CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = ['receipts_ready' => 0, 'receipts_needs_review' => 0, 'payments_ready' => 0, 'payments_needs_review' => 0];
        $treasury = collect($snapshot['treasury'])->keyBy(fn (array $row): string => (string) ($row['id_treasury'] ?? ''));
        foreach ($snapshot['sales'] as $row) {
            if (! $this->isPaymentRow($row) || ! $this->inRange($row['insert_date'] ?? null, $from, $to)) {
                continue;
            }
            $amount = (float) ($row['cash'] ?? 0);
            $treasuryRow = $treasury->get((string) ($row['id_cash'] ?? ''));
            $sourceKey = (string) ($treasuryRow['id_name_treasury'] ?? $row['id_cash'] ?? '');
            $ready = $amount > 0 && ! empty($row['id_customer']) && $sourceKey !== '';
            $normalized = ['payment_type' => 'receipt', 'customer_source_id' => (string) ($row['id_customer'] ?? ''), 'cashbox_source_id' => $sourceKey, 'amount' => abs($amount), 'date' => $row['insert_date'] ?? null, 'notes' => $row['bayan'] ?? null, 'allocation_source_id' => $row['id_sales'] ?? null];
            $this->upsert($run, 'receipt_document', 'sales', (string) ($row['id_sales'] ?? ''), $row['insert_date'] ?? null, 'legacy-receipt-'.($row['id_sales'] ?? ''), $row, $normalized, $ready);
            $counts[$ready ? 'receipts_ready' : 'receipts_needs_review']++;
        }
        foreach ($snapshot['buy'] as $row) {
            if (! $this->isPaymentRow($row) || ! $this->inRange($row['date'] ?? null, $from, $to)) {
                continue;
            }
            $amount = (float) ($row['eda3'] ?? 0);
            $treasuryRow = $treasury->get((string) ($row['id_eda3at'] ?? ''));
            $sourceKey = (string) ($treasuryRow['id_name_treasury'] ?? $row['id_eda3at'] ?? '');
            $ready = $amount > 0 && ! empty($row['id_import']) && $sourceKey !== '';
            $normalized = ['payment_type' => 'payment', 'supplier_source_id' => (string) ($row['id_import'] ?? ''), 'cashbox_source_id' => $sourceKey, 'amount' => abs($amount), 'date' => $row['date'] ?? null, 'notes' => $row['bayan'] ?? null, 'allocation_source_id' => $row['id_buy'] ?? null];
            $this->upsert($run, 'payment_document', 'buy', (string) ($row['id_buy'] ?? ''), $row['date'] ?? null, 'legacy-payment-'.($row['id_buy'] ?? ''), $row, $normalized, $ready);
            $counts[$ready ? 'payments_ready' : 'payments_needs_review']++;
        }

        return $counts;
    }

    private function isPaymentRow(array $row): bool
    {
        return str_contains((string) ($row['bayan'] ?? ''), 'دفعة');
    }

    /** @param array<string, list<array<string, mixed>>> $snapshot @return array<string, int> */
    private function stageExpenses(DataMigrationRun $run, array $snapshot, CarbonInterface $from, CarbonInterface $to): array
    {
        $count = 0;
        foreach ($snapshot['all_expenses'] as $row) {
            if (! $this->inRange($row['insert_date'] ?? null, $from, $to)) {
                continue;
            }
            $sourceId = (string) ($row['id_all_expenses'] ?? '');
            $payload = ['amount' => (float) ($row['amount'] ?? 0), 'date' => $row['insert_date'] ?? null, 'description' => $row['bayan'] ?? null, 'legacy_expense_id' => $row['id_expenses'] ?? null, 'legacy_category_id' => $row['id_category_expenses'] ?? null, 'legacy_vehicle_id' => $row['id_car'] ?? null, 'requires_account_review' => true];
            $this->upsert($run, 'expense_document', 'all_expenses', $sourceId, $row['insert_date'] ?? null, 'legacy-expense-'.$sourceId, $row, $payload, false);
            $count++;
        }

        return ['expense_needs_review' => $count];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function normalizePurchase(array $header, array $rows): array
    {
        $lines = array_map(fn (array $row): array => $this->normalizeLine($row, false), $rows);
        $factoryWeight = array_sum(array_column($lines, 'quantity_ton'));
        $packages = array_sum(array_column($lines, 'packages'));
        $subtotal = array_sum(array_column($lines, 'line_total'));

        return [
            'source_document_type' => 'purchase',
            'supplier_source_id' => (string) ($header['id_import'] ?? ''),
            'warehouse_source_id' => (string) ($header['id_stores'] ?? ''),
            'supplier_bill_number' => 'LEGACY-PB-'.($header['id_buy'] ?? ''),
            'date' => $header['date'] ?? null,
            'total_factory_weight' => round($factoryWeight, 3),
            'total_actual_weight' => round($factoryWeight, 3),
            'total_packages' => round($packages, 3),
            'subtotal' => round($subtotal, 2),
            'transport_cost' => 0,
            'loading_cost' => 0,
            'extra_cost' => 0,
            'discount' => 0,
            'payment_method' => 'credit',
            'lines' => $lines,
            'legacy_note' => $header['bayan'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function normalizeSales(array $header, array $rows): array
    {
        $lines = array_map(fn (array $row): array => $this->normalizeLine($row, true), $rows);
        $headerPackages = (float) ($header['numper'] ?? 0);
        if ($headerPackages > 0 && $lines !== []) {
            $lines[array_key_last($lines)]['packages'] = $headerPackages;
            $lines[array_key_last($lines)]['packages_source_scope'] = 'header';
        }
        $actualWeight = array_sum(array_column($lines, 'quantity_ton'));
        $packages = array_sum(array_column($lines, 'packages'));
        $transport = (float) ($header['tips'] ?? 0);
        $loading = (float) ($header['mashal'] ?? 0);

        return [
            'source_document_type' => 'sales',
            'customer_source_id' => (string) ($header['id_customer'] ?? ''),
            'warehouse_source_id' => (string) ($header['id_stores'] ?? ''),
            'customer_bill_number' => 'LEGACY-SB-'.($header['id_sales'] ?? ''),
            'date' => $header['insert_date'] ?? null,
            'total_actual_weight' => round($actualWeight, 3),
            'total_packages' => round($packages, 3),
            'subtotal' => round(array_sum(array_column($lines, 'line_total')), 2),
            'transport_cost' => round($transport, 2),
            'loading_cost' => round($loading, 2),
            'extra_cost' => 0,
            'discount' => 0,
            'payment_method' => 'credit',
            'legacy_total' => (float) ($header['total'] ?? 0),
            'lines' => $lines,
            'legacy_note' => $header['bayan'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeLine(array $row, bool $sales): array
    {
        $quantityKg = (float) ($row['kg'] ?? 0);
        $quantityTon = round($quantityKg / 1000, 3);
        $unitPrice = (float) ($row['price'] ?? 0);
        $sourceTotal = $sales ? (float) ($row['total'] ?? 0) : 0;
        $lineTotal = $sourceTotal > 0 ? round($sourceTotal, 2) : round($quantityTon * $unitPrice, 2);

        return [
            'source_line_id' => $row[$sales ? 'id_details_sales' : 'id_details_buy'] ?? null,
            'source_type_id' => (string) ($row['id_type'] ?? ''),
            'source_category_id' => (string) ($row['id_categories'] ?? ''),
            'source_warehouse_id' => (string) ($row['id_stores'] ?? ''),
            'quantity_kg' => $quantityKg,
            'quantity_ton' => $quantityTon,
            'factory_weight' => $sales ? null : $quantityTon,
            'actual_weight' => $sales ? $quantityTon : $quantityTon,
            'packages' => (float) ($row['no'] ?? 0),
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'notes' => $row['note'] ?? null,
        ];
    }

    private function inRange(mixed $value, CarbonInterface $from, CarbonInterface $to): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return false;
        }
        try {
            $date = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return false;
        }

        return $date->betweenIncluded($from, $to);
    }

    /** @param array<string, mixed> $header @param array<string, mixed> $normalized */
    private function upsert(DataMigrationRun $run, string $entity, string $sourceTable, string $sourceId, mixed $date, string $sourceNumber, array $header, array $normalized, bool $ready): void
    {
        $targetType = match ($entity) {
            'purchase_document' => 'PurchaseBill',
            'sales_document' => 'SalesBill',
            'receipt_document' => 'Receipt',
            'payment_document' => 'PaymentVoucher',
            default => null,
        };
        DataMigrationRecord::query()->updateOrCreate(
            ['run_id' => $run->id, 'entity_type' => $entity, 'source_table' => $sourceTable, 'source_id' => $sourceId],
            [
                'source_date' => $date,
                'source_number' => $sourceNumber,
                'source_payload' => ['header' => $header, 'normalized' => $normalized],
                'normalized_payload' => ['source_document_type' => $entity, 'source_id' => $sourceId, 'classification' => $ready ? ($targetType ? 'financial_document_candidate' : 'needs_review') : 'needs_review', ...$normalized],
                'status' => $ready ? DataMigrationRecord::STATUS_READY : DataMigrationRecord::STATUS_NEEDS_REVIEW,
                'target_type' => $ready ? $targetType : null,
                'fingerprint' => hash('sha256', implode('|', [$run->company_id, $run->source_system, $sourceTable, $sourceId])),
                'error_message' => null,
            ],
        );
    }
}
