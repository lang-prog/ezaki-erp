<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class LegacyAnalysisService
{
    public function __construct(private readonly LegacySqlSnapshotParser $parser) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $path, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages(['documents_from' => 'The start date must be before the end date.']);
        }

        $snapshot = $this->parser->parse($path);
        $purchaseDetails = collect($snapshot['details_buy'])->groupBy(fn (array $row): string => (string) ($row['id_buy'] ?? ''));
        $salesDetails = collect($snapshot['details_sales'])->groupBy(fn (array $row): string => (string) ($row['id_sales'] ?? ''));
        $purchases = collect($snapshot['buy'])->filter(fn (array $row): bool => $this->inRange($row['date'] ?? null, $start, $end));
        $sales = collect($snapshot['sales'])->filter(fn (array $row): bool => $this->inRange($row['insert_date'] ?? null, $start, $end));
        $paymentPurchases = $purchases->filter(fn (array $row): bool => $this->isPaymentRow($row));
        $paymentSales = $sales->filter(fn (array $row): bool => $this->isPaymentRow($row));

        $purchaseClassified = $purchases->filter(function (array $row) use ($purchaseDetails): bool {
            $details = $purchaseDetails->get((string) ($row['id_buy'] ?? ''), collect());

            return $details->isNotEmpty() && ! empty($row['id_import']) && ! empty($row['id_stores']) && ! empty($row['id_type']);
        });
        $salesClassified = $sales->filter(function (array $row) use ($salesDetails): bool {
            $details = $salesDetails->get((string) ($row['id_sales'] ?? ''), collect());

            return $details->isNotEmpty() && ! empty($row['id_customer']) && ! empty($row['id_stores']);
        });

        return [
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'source_tables' => collect($snapshot)->map(fn (array $rows): int => count($rows))->all(),
            'master_counts' => [
                'customers' => count($snapshot['customer']),
                'suppliers' => count($snapshot['import']),
                'product_types' => count($snapshot['type_mm']),
                'categories' => count($snapshot['categories']),
                'stores' => count($snapshot['stores']),
            ],
            'documents' => [
                'purchase_candidates' => $purchaseClassified->reject(fn (array $row): bool => $this->isPaymentRow($row))->count(),
                'purchase_candidate_lines' => $purchaseClassified->reject(fn (array $row): bool => $this->isPaymentRow($row))->sum(fn (array $row): int => $purchaseDetails->get((string) ($row['id_buy'] ?? ''), collect())->count()),
                'purchase_needs_review' => $purchases->reject(fn (array $row): bool => $this->isPaymentRow($row))->count() - $purchaseClassified->reject(fn (array $row): bool => $this->isPaymentRow($row))->count(),
                'sales_candidates' => $salesClassified->reject(fn (array $row): bool => $this->isPaymentRow($row))->count(),
                'sales_candidate_lines' => $salesClassified->reject(fn (array $row): bool => $this->isPaymentRow($row))->sum(fn (array $row): int => $salesDetails->get((string) ($row['id_sales'] ?? ''), collect())->count()),
                'sales_needs_review' => $sales->reject(fn (array $row): bool => $this->isPaymentRow($row))->count() - $salesClassified->reject(fn (array $row): bool => $this->isPaymentRow($row))->count(),
                'receipt_candidates' => $paymentSales->count(),
                'payment_candidates' => $paymentPurchases->count(),
                'expense_candidates' => collect($snapshot['all_expenses'])->filter(fn (array $row): bool => $this->inRange($row['insert_date'] ?? null, $start, $end))->count(),
                'bank_operation_candidates' => collect($snapshot['bank_operations'])->filter(fn (array $row): bool => $this->inRange($row['inserted_date'] ?? null, $start, $end))->count(),
            ],
            'samples' => [
                'purchase_review' => $purchases->reject(fn (array $row): bool => $purchaseClassified->contains('id_buy', $row['id_buy'] ?? null))->take(10)->values()->all(),
                'sales_review' => $sales->reject(fn (array $row): bool => $salesClassified->contains('id_sales', $row['id_sales'] ?? null))->take(10)->values()->all(),
            ],
            'warnings' => $this->warnings($snapshot, $purchases, $sales),
        ];
    }

    private function inRange(mixed $value, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return false;
        }
        try {
            $date = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return false;
        }

        return $date->betweenIncluded($start, $end);
    }

    private function isPaymentRow(array $row): bool
    {
        return str_contains((string) ($row['bayan'] ?? ''), 'دفعة');
    }

    /** @return list<string> */
    private function warnings(array $snapshot, $purchases, $sales): array
    {
        $warnings = [];
        $mixedPurchases = $purchases->filter(fn (array $row): bool => empty($row['id_stores']) || empty($row['id_type']))->count();
        $mixedSales = $sales->filter(fn (array $row): bool => empty($row['id_customer']) || empty($row['id_stores']))->count();
        if ($mixedPurchases > 0) {
            $warnings[] = "{$mixedPurchases} purchase records need manual classification.";
        }
        if ($mixedSales > 0) {
            $warnings[] = "{$mixedSales} sales records need manual classification.";
        }
        $nonSteel = collect($snapshot['categories'])->filter(fn (array $row): bool => ! is_numeric($row['categories'] ?? null))->count();
        if ($nonSteel > 0) {
            $warnings[] = 'The legacy category table mixes diameters with non-steel items; item units and diameter applicability require review.';
        }
        if (collect($snapshot['all_expenses'])->isNotEmpty()) {
            $warnings[] = 'Legacy expenses are staged for review only; each expense requires an explicit target expense account and payment source before posting.';
        }
        if (collect($snapshot['bank_operations'])->isNotEmpty()) {
            $warnings[] = 'Legacy bank operations are analyzed as review evidence only; automatic posting is disabled until each operation is classified as a receipt, payment, or transfer.';
        }

        return $warnings;
    }
}
