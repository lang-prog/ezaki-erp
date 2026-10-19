<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomerSupplier;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class MigrationOpeningBalanceService
{
    public function __construct(private readonly LegacySqlSnapshotParser $parser, private readonly AccountingService $accounting) {}

    /** @return array<string, mixed> */
    public function post(DataMigrationRun $run, User $actor): array
    {
        $company = $run->company()->firstOrFail();
        $snapshot = $this->parser->parse(Storage::disk($run->source_disk)->path($run->source_path));
        $cutoff = CarbonImmutable::parse($run->opening_balance_as_of)->endOfDay();
        $balances = $this->partyBalances($snapshot, $cutoff);
        $stock = $this->stockBalances($snapshot, $cutoff);
        $this->assertReadyForPosting($run, $balances, $stock);
        $result = ['parties_posted' => 0, 'parties_zero' => 0, 'parties_skipped' => 0, 'stock_items_posted' => 0, 'stock_quantity' => 0.0, 'stock_value' => 0.0, 'warnings' => [...$balances['warnings'], ...$stock['warnings']]];

        DB::transaction(function () use ($run, $actor, $company, $balances, $stock, &$result, $cutoff): void {
            foreach ($balances['items'] as $item) {
                $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $item['entity'])->where('source_key', $item['source_key'])->where('decision', 'matched')->first();
                if (! $mapping?->target_id) {
                    $result['parties_skipped']++;
                    $result['warnings'][] = "No approved {$item['entity']} mapping for opening balance source {$item['source_key']}.";

                    continue;
                }
                $party = CustomerSupplier::query()->where('company_id', $company->id)->lockForUpdate()->find($mapping->target_id);
                if (! $party) {
                    throw ValidationException::withMessages(['opening' => "Opening balance target {$mapping->target_id} is outside the target company."]);
                }
                $amount = round(abs($item['balance']), 2);
                if ($amount <= 0) {
                    $result['parties_zero']++;

                    continue;
                }
                if ($party->opening_balance_journal_entry_id || (float) $party->opening_balance > 0) {
                    throw ValidationException::withMessages(['opening' => "Opening balance for target party {$party->id} already exists; it was not overwritten."]);
                }
                $customer = $item['entity'] === 'customer';
                $direction = $customer ? ($item['balance'] >= 0 ? 'debit' : 'credit') : ($item['balance'] >= 0 ? 'credit' : 'debit');
                $party->forceFill(['opening_balance' => $amount, 'opening_balance_direction' => $direction, 'opening_balance_date' => $cutoff->toDateString()])->save();
                $this->accounting->postOpeningBalance($company, $actor, $party);
                $result['parties_posted']++;
            }

            $inventoryValue = 0.0;
            foreach ($stock['items'] as $item) {
                $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'product')->where('source_key', $item['product_source_key'])->where('decision', 'matched')->first();
                $warehouseMapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'warehouse')->where('source_key', $item['warehouse_source_key'])->where('decision', 'matched')->first();
                if (! $mapping?->target_id || ! $warehouseMapping?->target_id) {
                    throw ValidationException::withMessages(['opening' => "Opening stock mapping is incomplete for {$item['product_source_key']} / {$item['warehouse_source_key']}."]);
                }
                if ($item['quantity'] <= 0) {
                    throw ValidationException::withMessages(['opening' => "Opening stock quantity is not positive for {$item['product_source_key']} / {$item['warehouse_source_key']}."]);
                }
                $product = Product::query()->where('company_id', $company->id)->find($mapping->target_id);
                $warehouse = Warehouse::query()->where('company_id', $company->id)->find($warehouseMapping->target_id);
                if (! $product || ! $warehouse) {
                    throw ValidationException::withMessages(['opening' => "Opening stock target is outside the target company for {$item['product_source_key']}."]);
                }
                $sourceKey = 'opening:'.$run->id.':'.$warehouse->id.':'.$product->id;
                if (StockMovement::query()->where('company_id', $company->id)->where('source_type', DataMigrationRun::class)->where('source_key', $sourceKey)->exists()) {
                    continue;
                }
                $balance = StockBalance::query()->where('company_id', $company->id)->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->lockForUpdate()->first();
                if (! $balance) {
                    $balance = StockBalance::query()->create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 0, 'inventory_value' => 0, 'minimum_stock' => $product->minimum_stock ?? 0]);
                    $balance = StockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
                }
                if ((float) $balance->quantity !== 0.0 || (float) $balance->inventory_value !== 0.0) {
                    throw ValidationException::withMessages(['opening' => "Target stock balance already exists for {$product->name} in {$warehouse->name}; opening stock was not merged automatically."]);
                }
                $value = round($item['quantity'] * $item['average_cost'], 2);
                $balance->forceFill(['quantity' => round((float) $balance->quantity + $item['quantity'], 3), 'inventory_value' => round((float) $balance->inventory_value + $value, 2)])->save();
                StockMovement::query()->create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'user_id' => $actor->id, 'movement_type' => 'opening_balance', 'quantity_delta' => $item['quantity'], 'value_delta' => $value, 'source_type' => DataMigrationRun::class, 'source_id' => $run->id, 'source_key' => $sourceKey, 'notes' => 'Imported opening stock as of '.$cutoff->toDateString()]);
                $result['stock_items_posted']++;
                $result['stock_quantity'] += $item['quantity'];
                $inventoryValue += $value;
            }
            if ($inventoryValue > 0 && ! DB::table('journal_entries')->where('company_id', $company->id)->where('source_type', DataMigrationRun::class)->where('source_id', $run->id)->exists()) {
                $inventory = app(AccountingFoundationService::class)->systemAccount($company, 'inventory_asset');
                $equity = app(AccountingFoundationService::class)->systemAccount($company, 'opening_balance_equity');
                $this->accounting->postOperationalJournal($company, $actor, $cutoff->toDateString(), 'Legacy opening inventory '.$run->source_name, DataMigrationRun::class, $run->id, [['account_id' => $inventory->id, 'debit' => round($inventoryValue, 2), 'credit' => 0, 'description' => 'Imported opening inventory'], ['account_id' => $equity->id, 'debit' => 0, 'credit' => round($inventoryValue, 2), 'description' => 'Opening inventory equity']]);
            }
            $result['stock_value'] = round($inventoryValue, 2);
        });

        return $result;
    }

    /** @return array{items:list<array<string,mixed>>,warnings:list<string>} */
    private function partyBalances(array $snapshot, CarbonImmutable $cutoff): array
    {
        $items = [];
        $warnings = [];
        $totals = [];
        $add = function (string $entity, string $sourceKey, float $amount) use (&$totals): void {
            $key = $entity.'|'.$sourceKey;
            $totals[$key] = ($totals[$key] ?? ['entity' => $entity, 'source_key' => $sourceKey, 'balance' => 0.0]);
            $totals[$key]['balance'] += $amount;
        };
        $detailsBuy = collect($snapshot['details_buy'])->groupBy(fn (array $row): string => (string) ($row['id_buy'] ?? ''));
        $detailsSales = collect($snapshot['details_sales'])->groupBy(fn (array $row): string => (string) ($row['id_sales'] ?? ''));
        foreach ($snapshot['buy'] as $row) {
            $date = $this->date($row['date'] ?? null);
            if (! $date || $date->greaterThan($cutoff)) {
                continue;
            }
            $sourceKey = (string) ($row['id_import'] ?? '');
            if ($sourceKey === '') {
                continue;
            }
            if ($this->isPaymentRow($row)) {
                $add('supplier', $sourceKey, -1 * abs((float) ($row['eda3'] ?? 0)));

                continue;
            }
            $amount = 0.0;
            foreach ($detailsBuy->get((string) ($row['id_buy'] ?? ''), collect()) as $line) {
                $amount += ((float) ($line['kg'] ?? 0) / 1000) * (float) ($line['price'] ?? 0);
            }
            $add('supplier', $sourceKey, $amount + (float) ($row['exp'] ?? 0));
        }
        foreach ($snapshot['sales'] as $row) {
            $date = $this->date($row['insert_date'] ?? null);
            if (! $date || $date->greaterThan($cutoff)) {
                continue;
            }
            $sourceKey = (string) ($row['id_customer'] ?? '');
            if ($sourceKey === '') {
                continue;
            }
            if ($this->isPaymentRow($row)) {
                $add('customer', $sourceKey, -1 * (float) ($row['cash'] ?? 0));

                continue;
            }
            $amount = (float) ($row['total'] ?? 0);
            if ($amount <= 0) {
                foreach ($detailsSales->get((string) ($row['id_sales'] ?? ''), collect()) as $line) {
                    $amount += (float) ($line['total'] ?? 0);
                }
                $amount += (float) ($row['tips'] ?? 0) + (float) ($row['mashal'] ?? 0);
            }
            $add('customer', $sourceKey, $amount);
        }
        foreach ($totals as $item) {
            $items[] = $item;
        }

        return ['items' => $items, 'warnings' => $warnings];
    }

    /** @return array{items:list<array<string,mixed>>,warnings:list<string>} */
    private function stockBalances(array $snapshot, CarbonImmutable $cutoff): array
    {
        $detailsBuy = collect($snapshot['details_buy'])->keyBy(fn (array $row): string => (string) ($row['id_details_buy'] ?? ''));
        $detailsSales = collect($snapshot['details_sales'])->keyBy(fn (array $row): string => (string) ($row['id_details_sales'] ?? ''));
        $buyDates = collect($snapshot['buy'])->keyBy(fn (array $row): string => (string) ($row['id_buy'] ?? ''));
        $costs = [];
        foreach ($snapshot['details_buy'] as $line) {
            $buyHeader = $buyDates->get((string) ($line['id_buy'] ?? '')) ?? [];
            $buyDate = $this->date($buyHeader['date'] ?? null);
            if (! $buyDate || $buyDate->greaterThan($cutoff)) {
                continue;
            }
            $qty = (float) ($line['kg'] ?? 0) / 1000;
            $key = (string) ($line['id_type'] ?? '').':'.(string) ($line['id_categories'] ?? '');
            $costs[$key]['qty'] = ($costs[$key]['qty'] ?? 0) + $qty;
            $costs[$key]['value'] = ($costs[$key]['value'] ?? 0) + $qty * (float) ($line['price'] ?? 0);
        }
        $stock = [];
        $warnings = [];
        foreach ($snapshot['store'] as $row) {
            $date = $this->date($row['date_add'] ?? null);
            if (! $date || $date->greaterThan($cutoff)) {
                continue;
            }
            $detail = ! empty($row['id_details_buy']) ? $detailsBuy->get((string) $row['id_details_buy']) : $detailsSales->get((string) ($row['id_details_sales'] ?? ''));
            if (! $detail) {
                continue;
            }
            $key = (string) ($detail['id_type'] ?? '').':'.(string) ($detail['id_categories'] ?? '');
            $deltaKg = ! empty($row['buy']) ? (float) $row['buy'] : -1 * (float) ($row['sale'] ?? 0);
            $compound = $key.'|'.(string) ($row['id_stores'] ?? '');
            $stock[$compound] ??= ['product_source_key' => $key, 'warehouse_source_key' => (string) ($row['id_stores'] ?? ''), 'quantity' => 0.0, 'average_cost' => 0.0];
            $stock[$compound]['quantity'] += $deltaKg / 1000;
            $stock[$compound]['average_cost'] = ($costs[$key]['qty'] ?? 0) > 0 ? ($costs[$key]['value'] / $costs[$key]['qty']) : 0.0;
        }
        foreach ($stock as $item) {
            if ($item['average_cost'] <= 0 && $item['quantity'] > 0) {
                $warnings[] = "Opening stock {$item['product_source_key']} has no reliable purchase cost; it was not posted.";
            }
        }

        return ['items' => array_values($stock), 'warnings' => $warnings];
    }

    /** @param array{items:list<array<string,mixed>>,warnings:list<string>} $balances @param array{items:list<array<string,mixed>>,warnings:list<string>} $stock */
    private function assertReadyForPosting(DataMigrationRun $run, array $balances, array $stock): void
    {
        $errors = [];
        foreach ($balances['items'] as $item) {
            if (abs((float) $item['balance']) <= 0.01) {
                continue;
            }
            $exists = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $item['entity'])->where('source_key', $item['source_key'])->where('decision', 'matched')->whereNotNull('target_id')->exists();
            if (! $exists) {
                $errors[] = "Missing approved {$item['entity']} mapping for opening balance source {$item['source_key']}.";
            }
        }
        foreach ($stock['items'] as $item) {
            if ((float) $item['quantity'] <= 0 || (float) $item['average_cost'] <= 0) {
                $errors[] = "Opening stock requires a positive quantity and reliable cost for {$item['product_source_key']}.";

                continue;
            }
            foreach ([['warehouse', $item['warehouse_source_key']], ['product', $item['product_source_key']]] as [$entity, $key]) {
                if (! DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $entity)->where('source_key', $key)->where('decision', 'matched')->whereNotNull('target_id')->exists()) {
                    $errors[] = "Missing approved {$entity} mapping for opening stock source {$key}.";
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['opening' => array_slice($errors, 0, 20)]);
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value)->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isPaymentRow(array $row): bool
    {
        return str_contains((string) ($row['bayan'] ?? ''), 'دفعة');
    }
}
