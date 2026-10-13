<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Diameter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function ensureStandardDiameters(Company $company): void
    {
        // Diameters are company data and start empty; keep this method for legacy callers.
    }

    public function createProduct(Company $company, User $actor, array $data): Product
    {
        return DB::transaction(function () use ($company, $actor, $data): Product {
            $warehouse = Warehouse::query()
                ->where('company_id', $company->id)
                ->whereHas('branch', fn ($query) => $query->where('company_id', $company->id))
                ->whereNull('archived_at')
                ->findOrFail($data['warehouse_id']);
            $typeId = $data['product_type_id'] ?? null;
            if ($typeId) {
                abort_unless(DB::table('product_types')->where('company_id', $company->id)->where('id', $typeId)->exists(), 404);
            }
            $diameterId = $data['diameter_id'] ?? null;
            if ($diameterId) {
                abort_unless(DB::table('diameters')->where('company_id', $company->id)->where('id', $diameterId)->exists(), 404);
            }

            $product = $typeId && $diameterId
                ? app(InventoryProductResolver::class)->resolve($company, ['product_type_id' => $typeId, 'diameter_id' => $diameterId])
                : Product::query()->create([
                    'company_id' => $company->id,
                    'product_type_id' => $typeId,
                    'diameter_id' => $diameterId,
                    'sku' => $data['sku'],
                    'name' => $data['name'],
                    'unit' => $data['unit'],
                    'minimum_stock' => $data['minimum_stock'] ?? 0,
                    'status' => 'active',
                ]);
            $minimumStock = array_key_exists('minimum_stock', $data) && $data['minimum_stock'] !== null
                ? (float) $data['minimum_stock']
                : (float) $product->minimum_stock;
            $product->forceFill(['minimum_stock' => $minimumStock])->save();
            $balance = StockBalance::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                ['company_id' => $company->id, 'quantity' => 0, 'minimum_stock' => $minimumStock],
            );
            $balance->forceFill(['minimum_stock' => $minimumStock])->save();
            $opening = (float) ($data['opening_balance'] ?? 0);
            $this->applyOpening($company, $actor, $warehouse->id, $product->id, $opening, 'Opening stock balance');

            app(AuditRecorder::class)->record('products.created', $product, $company->id, $actor->id, ['warehouse_id' => $warehouse->id, 'opening_balance' => $opening]);

            return $product->load(['type', 'diameter']);
        });
    }

    public function saveTypeDiameterProfile(Company $company, User $actor, array $data): Product
    {
        return DB::transaction(function () use ($company, $actor, $data): Product {
            $warehouse = Warehouse::query()->where('company_id', $company->id)->whereHas('branch', fn ($query) => $query->where('company_id', $company->id))->whereNull('archived_at')->findOrFail((int) $data['warehouse_id']);
            $type = ProductType::query()->where('company_id', $company->id)->where('status', 'active')->findOrFail((int) $data['product_type_id']);
            $diameter = Diameter::query()->where('company_id', $company->id)->findOrFail((int) $data['diameter_id']);
            $product = app(InventoryProductResolver::class)->resolve($company, ['product_type_id' => $type->id, 'diameter_id' => $diameter->id]);
            $minimumStock = array_key_exists('minimum_stock', $data) && $data['minimum_stock'] !== null
                ? (float) $data['minimum_stock']
                : (float) $product->minimum_stock;
            $product->forceFill(['minimum_stock' => $minimumStock])->save();
            $balance = StockBalance::query()->firstOrCreate(
                ['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                ['quantity' => 0, 'minimum_stock' => $minimumStock],
            );
            $balance->forceFill(['minimum_stock' => $minimumStock])->save();
            $opening = (float) ($data['opening_balance'] ?? 0);
            $this->applyOpening($company, $actor, $warehouse->id, $product->id, $opening, 'Opening type and diameter balance');
            app(AuditRecorder::class)->record('inventory.type_diameter_profile_saved', $product, $company->id, $actor->id, ['warehouse_id' => $warehouse->id, 'opening_balance' => $data['opening_balance'] ?? null, 'minimum_stock' => $data['minimum_stock'] ?? null]);

            return $product->fresh(['type', 'diameter']);
        });
    }

    private function applyOpening(Company $company, User $actor, int $warehouseId, int $productId, float $opening, string $notes): void
    {
        $sourceType = 'inventory_opening';
        $sourceKey = 'warehouse:'.$warehouseId.':product:'.$productId;
        $balance = StockBalance::query()
            ->where('company_id', $company->id)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
        $alreadyApplied = StockMovement::query()
            ->where('company_id', $company->id)
            ->where('source_type', $sourceType)
            ->where('source_key', $sourceKey)
            ->lockForUpdate()
            ->exists();
        if ($alreadyApplied) {
            return;
        }

        if ($opening !== 0.0) {
            $balance->forceFill(['quantity' => round((float) $balance->quantity + $opening, 3)])->save();
        }
        StockMovement::query()->create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'user_id' => $actor->id,
            'movement_type' => 'opening_balance',
            'quantity_delta' => $opening,
            'source_type' => $sourceType,
            'source_key' => $sourceKey,
            'notes' => $notes,
        ]);
    }

    public function transfer(Company $company, User $actor, array $data): StockTransfer
    {
        return DB::transaction(function () use ($company, $actor, $data): StockTransfer {
            $transferredAt = $data['transferred_at'] ?? now()->toDateTimeString();
            app(AccountingFoundationService::class)->openPeriodFor($company, substr((string) $transferredAt, 0, 10));
            $from = Warehouse::query()->where('company_id', $company->id)->whereHas('branch', fn ($query) => $query->where('company_id', $company->id))->whereNull('archived_at')->findOrFail($data['from_warehouse_id']);
            $to = Warehouse::query()->where('company_id', $company->id)->whereHas('branch', fn ($query) => $query->where('company_id', $company->id))->whereNull('archived_at')->findOrFail($data['to_warehouse_id']);
            throw_if($from->is($to), ValidationException::withMessages(['to_warehouse_id' => 'Choose a different destination warehouse.']));
            $requestHash = hash('sha256', json_encode([
                'from' => (int) $from->id,
                'to' => (int) $to->id,
                'transfer_number' => (string) $data['transfer_number'],
                'transferred_at' => $data['transferred_at'] ?? null,
                'lines' => collect($data['lines'])->map(fn (array $line): array => ['product_id' => (int) $line['product_id'], 'quantity' => round((float) $line['quantity'], 3)])->values()->all(),
            ], JSON_THROW_ON_ERROR));
            $existing = StockTransfer::query()->where('company_id', $company->id)->where('transfer_number', $data['transfer_number'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->request_hash && hash_equals($existing->request_hash, $requestHash), 409, 'Transfer number already belongs to a different request.');

                return $existing->load('lines');
            }
            $transfer = StockTransfer::query()->create([
                'company_id' => $company->id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'created_by' => $actor->id,
                'status' => 'posted',
                'transfer_number' => $data['transfer_number'],
                'request_hash' => $requestHash,
                'notes' => $data['notes'] ?? null,
                'transferred_at' => $transferredAt,
            ]);

            foreach ($data['lines'] as $line) {
                $product = Product::query()->where('company_id', $company->id)->whereNull('archived_at')->findOrFail($line['product_id']);
                $quantity = round((float) $line['quantity'], 3);
                throw_if($quantity <= 0, ValidationException::withMessages(['lines' => 'Transfer quantity must be positive.']));
                [$sourceBalance, $destinationBalance] = $this->lockTransferBalances($company, $from, $to, $product);
                $sourceQuantity = (float) $sourceBalance->quantity;
                $sourceValue = (float) $sourceBalance->inventory_value;
                throw_if($sourceQuantity + 0.0005 < $quantity, ValidationException::withMessages(['lines' => "Insufficient stock for {$product->sku}."]));
                throw_if($sourceValue < 0, ValidationException::withMessages(['lines' => "Negative stock value for {$product->sku} requires reconciliation."]));
                $transferValue = abs($sourceQuantity - $quantity) < 0.0005
                    ? round($sourceValue, 2)
                    : round(($sourceValue / $sourceQuantity) * $quantity, 2);
                throw_if($transferValue < 0 || $transferValue > $sourceValue, ValidationException::withMessages(['lines' => 'Transfer valuation exceeds the source inventory value.']));
                $unitCost = $quantity > 0 ? round($transferValue / $quantity, 6) : 0.0;
                $sourceBalance->forceFill([
                    'quantity' => round($sourceQuantity - $quantity, 3),
                    'inventory_value' => round($sourceValue - $transferValue, 2),
                ])->save();
                $destinationBalance->forceFill([
                    'quantity' => round((float) $destinationBalance->quantity + $quantity, 3),
                    'inventory_value' => round((float) $destinationBalance->inventory_value + $transferValue, 2),
                ])->save();
                $transferLine = $transfer->lines()->create([
                    'company_id' => $company->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'transferred_value' => $transferValue,
                    'unit_cost' => $unitCost,
                ]);
                $this->recordTransferMovement($company, $actor, $from, $product, -$quantity, -$transferValue, $transfer, $transferLine, 'transfer_out');
                $this->recordTransferMovement($company, $actor, $to, $product, $quantity, $transferValue, $transfer, $transferLine, 'transfer_in');
            }

            app(AuditRecorder::class)->record('inventory.transfer_posted', $transfer, $company->id, $actor->id, ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id]);

            return $transfer->load('lines');
        });
    }

    public function reverseTransfer(Company $company, User $actor, StockTransfer $transfer, string $date, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($company, $actor, $transfer, $date, $reason): StockTransfer {
            $transfer = StockTransfer::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status === 'reversed') {
                return StockTransfer::query()->where('company_id', $company->id)->where('reversal_of_id', $transfer->id)->with('lines')->firstOrFail();
            }
            abort_unless($transfer->status === 'posted' && $transfer->reversal_of_id === null, 409, 'Only original posted transfers can be reversed.');
            app(AccountingFoundationService::class)->openPeriodFor($company, $date);
            $transfer->load('lines');
            $reverse = StockTransfer::query()->create([
                'company_id' => $company->id,
                'from_warehouse_id' => $transfer->to_warehouse_id,
                'to_warehouse_id' => $transfer->from_warehouse_id,
                'created_by' => $actor->id,
                'status' => 'posted',
                'transfer_number' => substr('REV-'.$transfer->transfer_number.'-'.$transfer->id, 0, 80),
                'reversal_of_id' => $transfer->id,
                'notes' => 'Reversal of '.$transfer->transfer_number,
                'transferred_at' => $date,
            ]);

            foreach ($transfer->lines as $line) {
                $product = Product::query()->where('company_id', $company->id)->findOrFail($line->product_id);
                $reverseFrom = Warehouse::query()->where('company_id', $company->id)->findOrFail($transfer->to_warehouse_id);
                $reverseTo = Warehouse::query()->where('company_id', $company->id)->findOrFail($transfer->from_warehouse_id);
                [$sourceBalance, $destinationBalance] = $this->lockTransferBalances($company, $reverseFrom, $reverseTo, $product);
                $quantity = (float) $line->quantity;
                $value = (float) $line->transferred_value;
                throw_if((float) $sourceBalance->quantity + 0.0005 < $quantity, ValidationException::withMessages(['lines' => 'The destination no longer holds enough stock to reverse this transfer.']));
                throw_if((float) $sourceBalance->inventory_value + 0.01 < $value, ValidationException::withMessages(['lines' => 'The destination no longer holds enough inventory value to reverse this transfer.']));
                $sourceBalance->forceFill([
                    'quantity' => round((float) $sourceBalance->quantity - $quantity, 3),
                    'inventory_value' => round((float) $sourceBalance->inventory_value - $value, 2),
                ])->save();
                $destinationBalance->forceFill([
                    'quantity' => round((float) $destinationBalance->quantity + $quantity, 3),
                    'inventory_value' => round((float) $destinationBalance->inventory_value + $value, 2),
                ])->save();
                $reverseLine = $reverse->lines()->create([
                    'company_id' => $company->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'transferred_value' => $value,
                    'unit_cost' => (float) $line->unit_cost,
                ]);
                $this->recordTransferMovement($company, $actor, $reverseFrom, $product, -$quantity, -$value, $reverse, $reverseLine, 'transfer_reversal_out');
                $this->recordTransferMovement($company, $actor, $reverseTo, $product, $quantity, $value, $reverse, $reverseLine, 'transfer_reversal_in');
            }

            $transfer->forceFill(['status' => 'reversed', 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason])->save();
            app(AuditRecorder::class)->record('inventory.transfer_reversed', $transfer, $company->id, $actor->id, ['reversal_transfer_id' => $reverse->id, 'reason' => $reason]);

            return $reverse->load('lines');
        });
    }

    private function lockTransferBalances(Company $company, Warehouse $from, Warehouse $to, Product $product): array
    {
        $balances = [];
        foreach ([$from, $to] as $warehouse) {
            $balance = StockBalance::query()
                ->where('company_id', $company->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            if (! $balance) {
                $balance = StockBalance::query()->firstOrCreate(
                    ['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                    ['quantity' => 0, 'inventory_value' => 0, 'minimum_stock' => $product->minimum_stock],
                );
                $balance = StockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
            }
            $balances[$warehouse->id] = $balance;
        }

        return [$balances[$from->id], $balances[$to->id]];
    }

    private function recordTransferMovement(Company $company, User $actor, Warehouse $warehouse, Product $product, float $quantity, float $value, StockTransfer $transfer, mixed $line, string $movementType): void
    {
        StockMovement::query()->create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'user_id' => $actor->id,
            'movement_type' => $movementType,
            'quantity_delta' => $quantity,
            'value_delta' => $value,
            'source_type' => StockTransfer::class,
            'source_id' => $transfer->id,
            'source_key' => 'transfer:'.$transfer->id.':line:'.$line->id.':'.$movementType,
        ]);
    }

    public function matrix(Company $company, ?int $warehouseId = null): array
    {
        if ($warehouseId) {
            abort_unless(Warehouse::query()->where('company_id', $company->id)->whereHas('branch', fn ($query) => $query->where('company_id', $company->id))->whereKey($warehouseId)->exists(), 404);
        }

        $balances = DB::table('stock_balances as balances')
            ->join('products', 'products.id', '=', 'balances.product_id')
            ->leftJoin('product_types', 'product_types.id', '=', 'products.product_type_id')
            ->leftJoin('diameters', 'diameters.id', '=', 'products.diameter_id')
            ->where('balances.company_id', $company->id)
            ->when($warehouseId, fn ($query) => $query->where('balances.warehouse_id', $warehouseId))
            ->select('products.id as product_id', 'products.name as product_name', 'products.sku', 'products.unit', 'products.product_type_id', 'product_types.name as type_name', 'diameters.millimeters', DB::raw('SUM(balances.quantity) as quantity'))
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.unit', 'products.product_type_id', 'product_types.name', 'diameters.millimeters')
            ->orderBy('product_types.name')->orderBy('diameters.millimeters')->get();
        $diameterColumns = Diameter::query()->where('company_id', $company->id)->orderBy('millimeters')->pluck('millimeters')->map(fn ($value) => (string) (float) $value)->all();
        $grouped = $balances->groupBy(fn ($row) => $row->product_type_id ? 'type:'.$row->product_type_id : 'other:'.$row->product_id);
        $rows = $grouped->map(function ($items, $key) use ($diameterColumns) {
            $first = $items->first();
            $cells = array_fill_keys($diameterColumns, 0.0);
            $otherProducts = [];
            foreach ($items as $item) {
                $quantity = (float) $item->quantity;
                if ($item->millimeters !== null) {
                    $cells[(string) (float) $item->millimeters] = ($cells[(string) (float) $item->millimeters] ?? 0) + $quantity;
                } else {
                    $otherProducts[] = ['sku' => $item->sku, 'name' => $item->product_name, 'quantity' => $quantity, 'unit' => $item->unit];
                }
            }

            return [
                'key' => $key,
                'type' => $first->type_name ?? 'Other items',
                'cells' => $cells,
                'other_items' => $otherProducts,
                'total' => (float) array_sum($cells) + (float) collect($otherProducts)->sum('quantity'),
            ];
        })->values();
        $existingTypes = $rows->pluck('key')->all();
        ProductType::query()->where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get()->each(function ($type) use (&$rows, $existingTypes, $diameterColumns): void {
            if (! in_array('type:'.$type->id, $existingTypes, true)) {
                $rows->push(['key' => 'type:'.$type->id, 'type' => $type->name, 'cells' => array_fill_keys($diameterColumns, 0.0), 'other_items' => [], 'total' => 0.0]);
            }
        });
        $columnTotals = [];
        foreach ($diameterColumns as $column) {
            $columnTotals[$column] = (float) $rows->sum(fn ($row) => $row['cells'][$column] ?? 0);
        }

        return [
            'rows' => $rows,
            'diameter_columns' => $diameterColumns,
            'column_totals' => $columnTotals,
            'total_quantity' => (float) $rows->sum('total'),
            'warehouses' => Warehouse::query()->where('company_id', $company->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
