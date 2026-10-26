<?php

declare(strict_types=1);

namespace App\Services\Migration;

use Carbon\CarbonImmutable;
use Throwable;

/** Pure Phase 1 calculator. It consumes staged source rows and never reads/writes target accounting tables. */
final class PartyOpeningBalanceCalculator
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array<string, mixed>
     */
    public function calculate(array $tables, string $cutoff): array
    {
        $cutoffDate = CarbonImmutable::parse($cutoff)->startOfDay();
        $issues = [];
        $customers = [];
        $suppliers = [];
        $specialSales = [];
        $salesById = $this->indexUnique($tables['sales'] ?? [], 'id_sales', 'sales', $issues);
        $customerById = $this->indexUnique($tables['customer'] ?? [], 'id_customer', 'customer', $issues);
        $supplierById = $this->indexUnique($tables['import'] ?? [], 'id_import', 'import', $issues);
        $supplierAccounts = $this->indexUnique($tables['account_import'] ?? [], 'id_account_import', 'account_import', $issues);
        $categoryById = $this->indexUnique($tables['categories'] ?? [], 'id_categories', 'categories', $issues);
        $detailSales = $this->groupBy($tables['details_sales'] ?? [], 'id_sales');
        $detailBuys = $this->groupBy($tables['details_buy'] ?? [], 'id_buy');

        foreach ($tables['customer'] ?? [] as $party) {
            $key = $this->key($party['id_customer'] ?? null);
            if ($key === '0') {
                $this->issue($issues, 'walk_in_customer_excluded', 'customer', $key);

                continue;
            }
            $start = $this->amount($party['start_balance'] ?? null);
            $customerIssues = [];
            if ($start === null) {
                $customerIssues[] = $this->issue($issues, 'invalid_customer_start_balance', 'customer', $key);
                $start = 0.0;
            }
            $salesTotal = 0.0;
            $collections = 0.0;
            foreach ($tables['sales'] ?? [] as $sale) {
                if ($this->key($sale['id_customer'] ?? null) !== $key) {
                    continue;
                }
                $saleId = $this->key($sale['id_sales'] ?? null);
                $hasDetails = ($detailSales[$saleId] ?? []) !== [];
                $totalRaw = $sale['total'] ?? null;
                $cashRaw = $sale['cash'] ?? null;
                $total = $this->amount($totalRaw);
                $cash = $this->amount($cashRaw);
                if ($total === null && $this->isEmptyAmount($totalRaw) && ! $hasDetails) {
                    $total = 0.0;
                }
                if ($cash === null && $this->isEmptyAmount($cashRaw) && $hasDetails) {
                    $cash = 0.0;
                }
                $date = $this->businessDate($sale['insert_date'] ?? null);
                if ($date === null) {
                    if (($total ?? 0.0) != 0.0 || ($cash ?? 0.0) != 0.0) {
                        $customerIssues[] = $this->issue($issues, 'sales_business_date_missing', 'sales', $this->key($sale['id_sales'] ?? null));
                    }

                    continue;
                }
                if ($date->greaterThan($cutoffDate)) {
                    continue;
                }
                if ($total === null || $cash === null) {
                    $customerIssues[] = $this->issue($issues, 'customer_sale_amount_invalid', 'sales', $this->key($sale['id_sales'] ?? null));

                    continue;
                }
                $salesTotal += $total;
                $collections += $cash;
            }
            $result = round($start + $collections - $salesTotal, 2);
            $customers[$key] = [
                'source_key' => $key,
                'source_name' => trim((string) ($party['name_customer'] ?? '')),
                'start_balance' => round($start, 2),
                'sales_total' => round($salesTotal, 2),
                'collections' => round($collections, 2),
                'result' => $result,
                'direction' => $result < 0 ? 'customer_owes_company' : ($result > 0 ? 'company_owes_customer' : 'zero'),
                'amount' => abs($result),
                'issues' => $customerIssues,
            ];
        }

        $supplierStart = [];
        foreach ($tables['account_import'] ?? [] as $account) {
            $key = $this->key($account['id_import'] ?? null);
            $start = $this->amount($account['start_cash'] ?? null);
            if ($key === '0') {
                if (($start ?? 0.0) != 0.0) {
                    $this->issue($issues, 'supplier_start_without_supplier', 'account_import', $this->key($account['id_account_import'] ?? null));
                }

                continue;
            }
            if (! isset($supplierById[$key])) {
                $this->issue($issues, 'supplier_account_missing_supplier', 'account_import', $this->key($account['id_account_import'] ?? null));
            }
            if ($start === null) {
                $this->issue($issues, 'supplier_start_amount_invalid', 'account_import', $this->key($account['id_account_import'] ?? null));

                continue;
            }
            $supplierStart[$key] = ($supplierStart[$key] ?? 0.0) + $start;
        }
        foreach (array_keys($supplierById) as $key) {
            $supplierStart[$key] ??= 0.0;
        }

        $supplierPayments = [];
        $detailPurchaseCosts = [];
        $legacyDetailsPerTonCosts = [];
        $runtimeHeaderCosts = [];
        $supplierIssues = [];
        foreach ($tables['buy'] ?? [] as $buy) {
            $supplierKey = $this->key($buy['id_import'] ?? null);
            if ($supplierKey === '0' || ! isset($supplierById[$supplierKey])) {
                if ($supplierKey !== '0' && $this->hasFinancialBuyData($buy, $detailBuys[$this->key($buy['id_buy'] ?? null)] ?? [])) {
                    $this->issue($issues, 'supplier_purchase_supplier_missing', 'buy', $this->key($buy['id_buy'] ?? null));
                }

                continue;
            }
            $buyId = $this->key($buy['id_buy'] ?? null);
            $lines = $detailBuys[$buyId] ?? [];
            $date = $this->businessDate($buy['date'] ?? null);
            $payment = $this->amount($buy['eda3'] ?? null);
            if ($payment === null && $this->isEmptyAmount($buy['eda3'] ?? null) && $lines !== [] && $this->key($buy['id_sales'] ?? null) === '0') {
                $payment = 0.0;
            }
            $headerKg = $this->amount($buy['kg'] ?? null);
            $headerPrice = $this->amount($buy['price'] ?? null);
            if ($date === null) {
                if ($this->hasFinancialBuyData($buy, $lines)) {
                    $supplierIssues[$supplierKey][] = $this->issue($issues, 'purchase_business_date_missing', 'buy', $buyId);
                }

                continue;
            }
            if ($date->greaterThan($cutoffDate)) {
                continue;
            }
            if ($payment === null) {
                $supplierIssues[$supplierKey][] = $this->issue($issues, 'supplier_payment_amount_invalid', 'buy', $buyId);
                $payment = 0.0;
            }
            if ($headerKg === null || $headerPrice === null) {
                if (($headerKg ?? 0.0) != 0.0 || ($headerPrice ?? 0.0) != 0.0) {
                    $supplierIssues[$supplierKey][] = $this->issue($issues, 'supplier_header_amount_invalid', 'buy', $buyId);
                }
            } else {
                $runtimeHeaderCosts[$supplierKey] = ($runtimeHeaderCosts[$supplierKey] ?? 0.0) + ($headerKg * $headerPrice / 1000);
            }

            $salesId = $this->key($buy['id_sales'] ?? null);
            $isSpecialReference = $salesId !== '0';
            if ($isSpecialReference) {
                $sale = $salesById[$salesId] ?? null;
                $saleLines = $detailSales[$salesId] ?? [];
                $specialCode = null;
                if ($sale === null) {
                    $specialCode = 'supplier_sale_reference_missing';
                } elseif ($this->key($sale['id_customer'] ?? null) !== '0') {
                    $specialCode = 'supplier_sale_has_customer';
                } elseif ($saleLines === []) {
                    $specialCode = 'supplier_sale_details_missing';
                }
                $saleAmount = $sale === null ? null : $this->amount($sale['total'] ?? null);
                if ($sale !== null && $saleAmount === null) {
                    $specialCode = 'supplier_sale_amount_invalid';
                }
                $buyAccountId = $this->key($buy['id_account_import'] ?? null);
                $saleAccountId = $this->key($sale['id_account_import'] ?? null);
                $account = $supplierAccounts[$buyAccountId] ?? null;
                if ($account === null || $this->key($account['id_import'] ?? null) !== $supplierKey || $saleAccountId !== $buyAccountId) {
                    $specialCode = 'supplier_sale_account_mismatch';
                }
                $saleDetailsTotal = 0.0;
                foreach ($saleLines as $saleLine) {
                    $lineTotal = $this->amount($saleLine['total'] ?? null);
                    if ($lineTotal === null) {
                        $specialCode = 'supplier_sale_detail_amount_invalid';

                        continue;
                    }
                    $saleDetailsTotal += $lineTotal;
                }
                if ($sale !== null && $this->businessDate($sale['insert_date'] ?? null) === null) {
                    $specialCode = 'supplier_sale_date_missing';
                } elseif ($sale !== null && ! $this->businessDate($sale['insert_date'] ?? null)?->isSameDay($date)) {
                    $specialCode = 'supplier_sale_date_mismatch';
                }
                if ($lines !== []) {
                    $specialCode = 'supplier_sale_has_purchase_lines';
                }
                if ($saleAmount !== null && abs($saleAmount - $payment) > 0.005) {
                    $specialCode = 'supplier_sale_amount_mismatch';
                }
                if ($saleAmount !== null && abs($saleAmount - round($saleDetailsTotal, 2)) > 0.50) {
                    $specialCode = 'supplier_sale_detail_total_mismatch';
                }
                $specialSales[] = [
                    'source_table' => 'buy', 'source_id' => $buyId, 'linked_sales_id' => $salesId,
                    'supplier_key' => $supplierKey, 'business_date' => $date->toDateString(),
                    'raw_buy_eda3' => $payment, 'linked_sales_total' => $saleAmount,
                    'linked_sales_details_total' => round($saleDetailsTotal, 2), 'buy_account_key' => $buyAccountId,
                    'included_supplier_component' => $specialCode === null ? $payment : 0.0,
                    'excluded_from_purchase_cost' => $specialCode === null,
                    'classification' => $specialCode === null ? 'sale_to_supplier' : 'needs_review',
                    'review_code' => $specialCode, 'details_sales_count' => count($saleLines), 'details_buy_count' => count($lines),
                ];
                if ($specialCode !== null) {
                    $supplierIssues[$supplierKey][] = $this->issue($issues, $specialCode, 'buy', $buyId, ['linked_sales_id' => $salesId]);
                }
                if ($specialCode === null) {
                    $supplierPayments[$supplierKey] = ($supplierPayments[$supplierKey] ?? 0.0) + $payment;

                    continue;
                }

                continue;
            }

            $supplierPayments[$supplierKey] = ($supplierPayments[$supplierKey] ?? 0.0) + $payment;

            foreach ($lines as $line) {
                $lineId = $this->key($line['id_details_buy'] ?? null);
                $categoryKey = $this->key($line['id_categories'] ?? null);
                $category = $categoryById[$categoryKey] ?? null;
                $flag = $category['steel_or_other'] ?? null;
                $kg = $this->amount($line['kg'] ?? null);
                $price = $this->amount($line['price'] ?? null);
                if ($category === null || ! in_array((string) $flag, ['1', '2'], true)) {
                    $supplierIssues[$supplierKey][] = $this->issue($issues, 'purchase_category_flag_missing', 'details_buy', $lineId, ['category_key' => $categoryKey]);

                    continue;
                }
                if ($kg === null || $price === null) {
                    $supplierIssues[$supplierKey][] = $this->issue($issues, 'purchase_line_amount_invalid', 'details_buy', $lineId);

                    continue;
                }
                $raw = $kg * $price;
                $value = round((string) $flag === '2' ? $raw : $raw / 1000, 2);
                $detailPurchaseCosts[$supplierKey] = ($detailPurchaseCosts[$supplierKey] ?? 0.0) + $value;
                $legacyDetailsPerTonCosts[$supplierKey] = ($legacyDetailsPerTonCosts[$supplierKey] ?? 0.0) + round($raw / 1000, 2);
            }
        }

        foreach ($supplierById as $key => $supplier) {
            $start = round($supplierStart[$key] ?? 0.0, 2);
            $payments = round($supplierPayments[$key] ?? 0.0, 2);
            $purchaseCost = round($detailPurchaseCosts[$key] ?? 0.0, 2);
            $legacyDetailsPerTonCost = round($legacyDetailsPerTonCosts[$key] ?? 0.0, 2);
            $runtimeCost = round($runtimeHeaderCosts[$key] ?? 0.0, 2);
            $result = round($payments + $start - $purchaseCost, 2);
            $legacyDetailsPerTonResult = round($payments + $start - $legacyDetailsPerTonCost, 2);
            $runtimeResult = round($payments + $start - $runtimeCost, 2);
            $difference = round($result - $runtimeResult, 2);
            $categoryDifference = round($result - $legacyDetailsPerTonResult, 2);
            $supplierPartyIssues = $supplierIssues[$key] ?? [];
            if (abs($difference) > 0.005) {
                $supplierPartyIssues[] = $this->issue($issues, 'supplier_formula_differs_from_legacy_runtime', 'import', $key, [
                    'requested_details_result' => $result,
                    'legacy_header_result' => $runtimeResult,
                    'difference' => $difference,
                ]);
            }
            if (abs($categoryDifference) > 0.005) {
                $supplierPartyIssues[] = $this->issue($issues, 'supplier_category_conversion_differs_from_legacy', 'import', $key, [
                    'requested_category_result' => $result,
                    'legacy_per_ton_detail_result' => $legacyDetailsPerTonResult,
                    'difference' => $categoryDifference,
                ]);
            }
            $suppliers[$key] = [
                'source_key' => $key, 'source_name' => trim((string) ($supplier['name'] ?? '')),
                'start_component' => $start, 'payment_component' => $payments,
                'purchase_cost_details' => $purchaseCost, 'purchase_cost_legacy_header' => $runtimeCost,
                'purchase_cost_legacy_details_per_ton' => $legacyDetailsPerTonCost,
                'result' => $result, 'legacy_runtime_result_at_cutoff' => $runtimeResult,
                'formula_difference' => $difference, 'category_conversion_difference' => $categoryDifference,
                'formula_review_required' => abs($difference) > 0.005 || abs($categoryDifference) > 0.005,
                'direction' => $result < 0 ? 'company_owes_supplier' : ($result > 0 ? 'supplier_owes_company' : 'zero'),
                'amount' => abs($result), 'issues' => $supplierPartyIssues,
            ];
        }

        return [
            'cutoff_date' => $cutoffDate->toDateString(),
            'opening_effective_date' => $cutoffDate->addDay()->toDateString(),
            'customers' => $customers, 'suppliers' => $suppliers,
            'supplier_sales' => $specialSales, 'issues' => $issues,
            'counts' => [
                'customers' => count($customerById), 'suppliers' => count($supplierById),
                'customer_zero_balance' => count(array_filter($customers, fn ($row) => abs($row['result']) <= 0.005)),
                'supplier_zero_balance' => count(array_filter($suppliers, fn ($row) => abs($row['result']) <= 0.005)),
                'supplier_sales' => count($specialSales),
                'supplier_sales_review' => count(array_filter($specialSales, fn ($row) => $row['classification'] === 'needs_review')),
                'unresolved' => count($issues),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $issues @return array<string, array<string, mixed>> */
    private function indexUnique(array $rows, string $column, string $table, array &$issues): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $key = $this->key($row[$column] ?? null);
            if ($key === '0') {
                continue;
            }
            if (isset($indexed[$key])) {
                $this->issue($issues, 'duplicate_source_key', $table, $key);

                continue;
            }
            $indexed[$key] = $row;
        }

        return $indexed;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, list<array<string, mixed>>> */
    private function groupBy(array $rows, string $column): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$this->key($row[$column] ?? null)][] = $row;
        }

        return $grouped;
    }

    private function amount(mixed $value): ?float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || trim((string) $value) === '' || ! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function isEmptyAmount(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function businessDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $issues @param array<string, mixed> $context */
    private function issue(array &$issues, string $code, string $table, string|int $sourceId, array $context = []): array
    {
        $sourceId = (string) $sourceId;
        $issue = ['issue_key' => hash('sha256', $code.'|'.$table.'|'.$sourceId), 'code' => $code, 'source_table' => $table, 'source_id' => $sourceId, 'context' => $context];
        $issues[] = $issue;

        return $issue;
    }

    /** @param list<array<string, mixed>> $lines */
    private function hasFinancialBuyData(array $buy, array $lines): bool
    {
        return count($lines) > 0 || ($this->amount($buy['eda3'] ?? null) ?? 0) != 0.0 || ($this->amount($buy['kg'] ?? null) ?? 0) != 0.0;
    }

    private function key(mixed $value): string
    {
        $key = trim((string) ($value ?? ''));

        return $key === '' ? '0' : $key;
    }
}
