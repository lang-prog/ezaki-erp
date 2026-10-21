<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Bank;
use App\Models\Cashbox;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationLink;
use App\Models\DataMigrationRun;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingFoundationService;
use App\Services\AccountingService;
use App\Services\InventoryValuationService;
use App\Services\Legacy\LegacyDataset;
use App\Services\Legacy\LegacyLedger;
use App\Services\Legacy\MappingIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening balances as of the cutoff, with a read-only preview. Cost policy: weighted-average purchase cost per ton from valid
 * pre-cutoff purchase lines, or a manual cost stored on the product mapping; nothing is invented.
 */
final class OpeningBalanceService
{
    public const ACKS = ['negative_stock', 'missing_cost', 'negative_cash', 'undatable_rows', 'invoice_total_mismatch', 'unassigned_treasury'];

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly AccountingFoundationService $foundation,
        private readonly InventoryValuationService $valuation,
    ) {}

    /** @return array<string, mixed> */
    public function preview(DataMigrationRun $run): array
    {
        $map = new MappingIndex($run);
        $d = LegacyDataset::load($run, ['customer', 'import', 'account_import', 'sales', 'details_sales', 'buy', 'details_buy', 'starting_balance', 'store', 'stores', 'categories', 'type_mm', 'treasury', 'bank_accounts', 'bank_operations']);
        $ledger = new LegacyLedger($d, $run, fn (string $k) => $map->basis($k));
        $blockers = [];
        $warnings = [];
        $acks = $run->policy('acks', []);
        $block = function (string $code, array $params = [], ?string $ack = null) use (&$blockers, &$warnings, $acks): void {
            if ($ack !== null && isset($acks[$ack])) {
                $warnings[] = ['code' => $code, 'params' => $params, 'acknowledged' => true];

                return;
            }
            $blockers[] = ['code' => $code, 'params' => $params, 'ack' => $ack];
        };

        $opening = $ledger->opening();
        $issues = $opening['issues'];
        if ($issues['undatable'] !== []) {
            $block('undatable_rows', ['count' => count($issues['undatable']), 'examples' => array_slice($issues['undatable'], 0, 5)], 'undatable_rows');
        }
        if ($issues['invoice_total_mismatch'] > 0) {
            $block('invoice_total_mismatch', ['count' => $issues['invoice_total_mismatch'], 'delta' => round($issues['invoice_total_mismatch_delta'], 2)], 'invoice_total_mismatch');
        }
        if ($issues['undecided_basis_lines'] > 0) {
            $block('unit_undecided', ['count' => $issues['undecided_basis_lines']]);
        }

        $signC = $run->policy('customer_start_balance_sign');
        $signS = $run->policy('supplier_start_balance_sign');
        $parties = ['customers' => [], 'suppliers' => []];
        $needSign = ['customer' => false, 'supplier' => false];
        foreach ($opening['customers'] as $key => $c) {
            $mult = $signC === 'debit_negative' ? -1 : 1;
            if ($c['start'] != 0.0 && $signC === null) {
                $needSign['customer'] = true;
            }
            $net = round($mult * $c['start'] + $c['invoices'] - $c['receipts'], 2);
            if (abs($net) >= 0.005 || $c['start'] != 0.0) {
                $parties['customers'][] = $this->partyRow($map, 'customer', (string) $key, $net, $c, $block);
            }
        }
        foreach ($opening['suppliers'] as $key => $s) {
            $mult = $signS === 'credit_negative' ? -1 : 1;
            if ($s['start'] != 0.0 && $signS === null) {
                $needSign['supplier'] = true;
            }
            $net = round($mult * $s['start'] + $s['invoices'] - $s['payments'], 2);
            if (abs($net) >= 0.005 || $s['start'] != 0.0) {
                $parties['suppliers'][] = $this->partyRow($map, 'supplier', (string) $key, $net, $s, $block);
            }
        }
        foreach ($needSign as $kind => $needed) {
            if ($needed) {
                $block('sign_policy_missing', ['party' => $kind]);
            }
        }

        $stock = $this->stockRows($ledger, $map, $run, $block);
        $cash = $ledger->cashAndBanks();
        $cashRows = [];
        foreach ($cash['cashboxes'] as $k => $v) {
            $cashRows[] = $this->cashRow('cashbox', (string) $k, $v, $map, $block);
        }
        $bankRows = [];
        foreach ($cash['banks'] as $k => $v) {
            $bankRows[] = $this->cashRow('bank', (string) $k, $v, $map, $block);
        }
        if ($cash['undatable'] > 0) {
            $block('undatable_rows', ['count' => $cash['undatable'], 'table' => 'treasury/bank_operations'], 'undatable_rows');
        }
        if ($cash['unassigned_treasury']['rows'] > 0) {
            $block('unassigned_treasury', $cash['unassigned_treasury'], 'unassigned_treasury');
        }

        $totals = [
            'customers_debit' => round(array_sum(array_map(fn ($r) => $r['direction'] === 'debit' ? $r['amount'] : 0, $parties['customers'])), 2),
            'customers_credit' => round(array_sum(array_map(fn ($r) => $r['direction'] === 'credit' ? $r['amount'] : 0, $parties['customers'])), 2),
            'suppliers_credit' => round(array_sum(array_map(fn ($r) => $r['direction'] === 'credit' ? $r['amount'] : 0, $parties['suppliers'])), 2),
            'suppliers_debit' => round(array_sum(array_map(fn ($r) => $r['direction'] === 'debit' ? $r['amount'] : 0, $parties['suppliers'])), 2),
            'inventory_value' => round(array_sum(array_map(fn ($r) => $r['status'] === 'post' ? $r['value'] : 0, $stock)), 2),
            'cash' => round(array_sum(array_map(fn ($r) => $r['status'] === 'post' ? $r['amount'] : 0, $cashRows)), 2),
            'bank' => round(array_sum(array_map(fn ($r) => $r['status'] === 'post' ? $r['amount'] : 0, $bankRows)), 2),
        ];
        $assets = $totals['customers_debit'] + $totals['suppliers_debit'] + $totals['inventory_value'] + $totals['cash'] + $totals['bank'];
        $liabilities = $totals['customers_credit'] + $totals['suppliers_credit'];
        $totals['equity_balancing'] = round($assets - $liabilities, 2);

        return [
            'ok' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings,
            'parties' => $parties, 'stock' => $stock, 'cash' => $cashRows, 'banks' => $bankRows, 'totals' => $totals,
            'issues' => ['undatable' => count($issues['undatable']), 'invoice_total_mismatch' => $issues['invoice_total_mismatch'], 'invoice_with_cash' => $issues['invoice_with_cash'], 'invoice_with_payment' => $issues['invoice_with_payment']],
            'policy' => ['customer_start_balance_sign' => $signC, 'supplier_start_balance_sign' => $signS, 'cost' => 'weighted_average_pre_cutoff_purchases_or_manual'],
        ];
    }

    /** Locks only the mappings an opening posting actually used, so unrelated mappings stay correctable. */
    private function lockUsed(DataMigrationRun $run, array $preview): void
    {
        $used = [];
        foreach ([$preview['parties']['customers'], $preview['parties']['suppliers']] as $rows) {
            foreach ($rows as $r) {
                if ($r['status'] === 'post') {
                    $used[] = [$r['entity'], $r['source_key']];
                }
            }
        }
        foreach ($preview['stock'] as $r) {
            if ($r['status'] === 'post') {
                $used[] = ['product', $r['product_key']];
                $used[] = ['warehouse', (string) $r['warehouse_key']];
            }
        }
        foreach (array_merge($preview['cash'], $preview['banks']) as $r) {
            if ($r['status'] === 'post') {
                $used[] = [$r['entity'], $r['source_key']];
            }
        }
        foreach ($used as [$entity, $key]) {
            DB::table('data_migration_mappings')->where('run_id', $run->id)->where('entity_type', $entity)->where('source_key', $key)->update(['locked_at' => now()]);
        }
    }

    /** @param callable(string, array, ?string): void $block @return array<string, mixed> */
    private function partyRow(MappingIndex $map, string $entity, string $key, float $net, array $raw, callable $block): array
    {
        $m = $map->get($entity, $key);
        $target = $map->target($entity, $key);
        $debitSide = $entity === 'customer';
        $direction = $debitSide ? ($net >= 0 ? 'debit' : 'credit') : ($net >= 0 ? 'credit' : 'debit');
        $amount = round(abs($net), 2);
        $status = $amount < 0.005 ? 'zero' : ($target ? 'post' : ($m?->decision === 'excluded' ? 'excluded' : 'unmapped'));
        if ($status === 'unmapped') {
            $block('party_unmapped', ['entity' => $entity, 'source_key' => $key, 'mapping_id' => $m?->id], null);
        }

        return ['entity' => $entity, 'source_key' => $key, 'mapping_id' => $m?->id, 'name' => $m->payload['source_name'] ?? null, 'target_id' => $target, 'amount' => $amount, 'direction' => $direction, 'status' => $status, 'start' => $raw['start']];
    }

    /** @param callable(string, array, ?string): void $block @return list<array<string, mixed>> */
    private function stockRows(LegacyLedger $ledger, MappingIndex $map, DataMigrationRun $run, callable $block): array
    {
        $rows = [];
        $stores = $ledger->d->rows('stores');
        $multiWh = count($stores) > 1;
        $mixed = false;
        foreach ($ledger->stock() as $key => $s) {
            $m = $map->get('product', $key);
            $attrs = $m?->payload['source_attributes'] ?? [];
            $tons = $s['tons'];
            if (abs($tons) < 0.0005) {
                $rows[] = ['product_key' => $key, 'mapping_id' => $m?->id, 'name' => $m?->payload['source_name'] ?? null, 'tons' => 0.0, 'status' => 'zero', 'value' => 0.0, 'cost_per_ton' => null, 'warehouse_key' => null];

                continue;
            }
            $wh = $s['warehouses'][0] ?? '?';
            $whTarget = $map->target('warehouse', (string) $wh);
            $row = ['product_key' => $key, 'mapping_id' => $m?->id, 'name' => $m?->payload['source_name'] ?? null, 'tons' => $tons, 'warehouse_key' => $wh, 'target_product' => $map->target('product', $key), 'target_warehouse' => $whTarget, 'cost_per_ton' => null, 'value' => 0.0, 'status' => 'post'];
            if ($multiWh && ($s['opening_kg'] ?? 0) != 0.0) {
                $mixed = true;
            }
            if ($tons < 0) {
                $row['status'] = 'negative';
                $block('negative_stock', ['product_key' => $key, 'tons' => $tons], 'negative_stock');
            } elseif (($m?->decision === 'excluded') || ($map->get('warehouse', (string) $wh)?->decision === 'excluded')) {
                $row['status'] = 'excluded';
            } elseif (! $map->target('product', $key) || ! $whTarget) {
                $row['status'] = 'unmapped';
                $block($map->target('product', $key) ? 'warehouse_unmapped' : 'product_unmapped', ['product_key' => $key, 'warehouse_key' => $wh, 'mapping_id' => $m?->id], null);
            } elseif (! in_array($map->basis($key), ['ton', 'kg'], true)) {
                $row['status'] = 'unit_undecided';
                $block('unit_undecided', ['product_key' => $key, 'mapping_id' => $m?->id]);
            } else {
                $manual = $attrs['opening_cost_per_ton'] ?? null;
                $cost = is_numeric($manual) && (float) $manual > 0 ? (float) $manual : $s['cost_per_ton'];
                if ($cost === null || $cost <= 0) {
                    $row['status'] = 'no_cost';
                    $block('missing_cost', ['product_key' => $key, 'tons' => $tons, 'mapping_id' => $m?->id], 'missing_cost');
                } else {
                    $row['cost_per_ton'] = $cost;
                    $row['value'] = round($tons * $cost, 2);
                    $row['cost_source'] = is_numeric($manual) && (float) $manual > 0 ? 'manual' : 'weighted_average';
                }
            }
            if (in_array($row['status'], ['negative', 'no_cost'], true) && isset($run->policy('acks', [])[$row['status'] === 'negative' ? 'negative_stock' : 'missing_cost'])) {
                $row['status'] = 'skipped_acknowledged';
            }
            $rows[] = $row;
        }
        if ($mixed) {
            $block('warehouse_ambiguous', ['stores' => count($stores)]);
        }

        return $rows;
    }

    /** @param callable(string, array, ?string): void $block @return array<string, mixed> */
    private function cashRow(string $entity, string $key, float $amount, MappingIndex $map, callable $block): array
    {
        $m = $map->get($entity, $key);
        $target = $map->target($entity, $key);
        $status = abs($amount) < 0.005 ? 'zero' : ($amount < 0 ? 'negative' : ($target ? 'post' : ($m?->decision === 'excluded' ? 'excluded' : 'unmapped')));
        if ($status === 'negative') {
            $block('negative_cash', ['entity' => $entity, 'source_key' => $key, 'amount' => $amount], 'negative_cash');
        } elseif ($status === 'unmapped') {
            $block($entity.'_unmapped', ['source_key' => $key, 'mapping_id' => $m?->id], null);
        }

        return ['entity' => $entity, 'source_key' => $key, 'mapping_id' => $m?->id, 'name' => $m->payload['source_name'] ?? null, 'target_id' => $target, 'amount' => round($amount, 2), 'status' => $status];
    }

    /** @return array<string, int|float> */
    public function post(DataMigrationRun $run, User $actor): array
    {
        $preview = $this->preview($run);
        if (! $preview['ok']) {
            throw ValidationException::withMessages(['opening' => array_map(fn ($b) => $b['code'].': '.json_encode($b['params']), array_slice($preview['blockers'], 0, 20))]);
        }
        $company = Company::query()->findOrFail($run->company_id);
        $cutoff = $run->opening_balance_as_of->toDateString();
        $result = ['parties' => 0, 'stock_items' => 0, 'stock_tons' => 0.0, 'stock_value' => 0.0, 'cash_accounts' => 0, 'skipped_existing' => 0];

        DB::transaction(function () use ($run, $actor, $company, $cutoff, $preview, &$result): void {
            $link = fn (string $table, string $id, string $type): ?DataMigrationLink => DataMigrationLink::query()->where('company_id', $company->id)->where('source_system', $run->source_system)->where('source_table', $table)->where('source_id', $id)->where('document_type', $type)->first();
            $make = function (string $table, string $id, string $type, string $targetType, int $targetId, array $res = []) use ($run, $company): void {
                DataMigrationLink::query()->create(['company_id' => $company->id, 'run_id' => $run->id, 'source_system' => $run->source_system, 'source_table' => $table, 'source_id' => $id, 'document_type' => $type, 'target_type' => $targetType, 'target_id' => $targetId, 'result' => $res ?: null]);
            };
            foreach (['customers' => 'customer', 'suppliers' => 'import'] as $kind => $table) {
                foreach ($preview['parties'][$kind] as $row) {
                    if ($row['status'] !== 'post') {
                        continue;
                    }
                    if ($link($table, $row['source_key'], 'opening_balance')) {
                        $result['skipped_existing']++;

                        continue;
                    }
                    $party = CustomerSupplier::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($row['target_id']);
                    if ($party->opening_balance_journal_entry_id || (float) $party->opening_balance > 0) {
                        throw ValidationException::withMessages(['opening' => "target_not_empty: party {$party->id} already has an opening balance."]);
                    }
                    $party->forceFill(['opening_balance' => $row['amount'], 'opening_balance_direction' => $row['direction'], 'opening_balance_date' => $cutoff])->save();
                    $this->accounting->postOpeningBalance($company, $actor, $party);
                    $make($table, $row['source_key'], 'opening_balance', CustomerSupplier::class, $party->id, ['amount' => $row['amount'], 'direction' => $row['direction']]);
                    $result['parties']++;
                }
            }
            $inventoryValue = 0.0;
            foreach ($preview['stock'] as $row) {
                if ($row['status'] !== 'post') {
                    continue;
                }
                $id = $row['product_key'].'@'.$row['warehouse_key'];
                if ($link('store', $id, 'opening_stock')) {
                    $result['skipped_existing']++;

                    continue;
                }
                $product = Product::query()->where('company_id', $company->id)->findOrFail($row['target_product']);
                $warehouse = Warehouse::query()->where('company_id', $company->id)->findOrFail($row['target_warehouse']);
                $balance = StockBalance::query()->where('company_id', $company->id)->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->lockForUpdate()->first();
                if ($balance && ((float) $balance->quantity !== 0.0 || (float) $balance->inventory_value !== 0.0)) {
                    throw ValidationException::withMessages(['opening' => "target_not_empty: stock of {$product->name} in {$warehouse->name} is not empty."]);
                }
                $this->valuation->receive($company, $actor, $warehouse->id, $product->id, $row['tons'], $row['value'], DataMigrationRun::class, $run->id, 'opening_balance');
                $movement = StockMovement::query()->where('company_id', $company->id)->where('product_id', $product->id)->where('movement_type', 'opening_balance')->latest('id')->firstOrFail();
                $make('store', $id, 'opening_stock', StockMovement::class, $movement->id, ['tons' => $row['tons'], 'value' => $row['value']]);
                $inventoryValue += $row['value'];
                $result['stock_items']++;
                $result['stock_tons'] += $row['tons'];
            }
            if ($inventoryValue > 0 && ! $link('run', (string) $run->id, 'opening_inventory_journal')) {
                $entry = $this->accounting->postOperationalJournal($company, $actor, $cutoff, 'Legacy opening inventory', 'legacy_opening_inventory', $run->id, [
                    ['account_id' => $this->foundation->systemAccount($company, 'inventory_asset')->id, 'debit' => round($inventoryValue, 2), 'credit' => 0, 'description' => 'Opening inventory'],
                    ['account_id' => $this->foundation->systemAccount($company, 'opening_balance_equity')->id, 'debit' => 0, 'credit' => round($inventoryValue, 2), 'description' => 'Opening balance equity'],
                ]);
                $make('run', (string) $run->id, 'opening_inventory_journal', JournalEntry::class, $entry->id, ['value' => round($inventoryValue, 2)]);
                $result['stock_value'] = round($inventoryValue, 2);
            }
            $lines = [];
            $total = 0.0;
            foreach (array_merge($preview['cash'], $preview['banks']) as $row) {
                if ($row['status'] !== 'post') {
                    continue;
                }
                $account = $row['entity'] === 'cashbox'
                    ? Cashbox::query()->where('company_id', $company->id)->findOrFail($row['target_id'])->account_id
                    : Bank::query()->where('company_id', $company->id)->findOrFail($row['target_id'])->account_id;
                $lines[] = ['account_id' => $account, 'debit' => $row['amount'], 'credit' => 0, 'description' => 'Opening '.$row['entity']];
                $total += $row['amount'];
                $result['cash_accounts']++;
            }
            if ($lines !== [] && ! $link('run', (string) $run->id, 'opening_cash_journal')) {
                $lines[] = ['account_id' => $this->foundation->systemAccount($company, 'opening_balance_equity')->id, 'debit' => 0, 'credit' => round($total, 2), 'description' => 'Opening balance equity'];
                $entry = $this->accounting->postOperationalJournal($company, $actor, $cutoff, 'Legacy opening cash and bank', 'legacy_opening_cash', $run->id, $lines);
                $make('run', (string) $run->id, 'opening_cash_journal', JournalEntry::class, $entry->id, ['value' => round($total, 2)]);
            }
            $this->lockUsed($run, $preview);
        });
        $result['stock_tons'] = round($result['stock_tons'], 3);

        return $result;
    }
}
