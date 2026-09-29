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

            $product = Product::query()->create([
                'company_id' => $company->id,
                'product_type_id' => $typeId,
                'diameter_id' => $diameterId,
                'sku' => $data['sku'],
                'name' => $data['name'],
                'unit' => $data['unit'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'status' => 'active',
            ]);
            $balance = StockBalance::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                ['company_id' => $company->id, 'quantity' => 0],
            );
            $opening = (float) ($data['opening_balance'] ?? 0);
            if ($opening !== 0.0) {
                $balance->increment('quantity', $opening);
                StockMovement::query()->create([
                    'company_id' => $company->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'user_id' => $actor->id,
                    'movement_type' => 'opening_balance',
                    'quantity_delta' => $opening,
                    'notes' => 'Opening stock balance',
                ]);
            }

            app(AuditRecorder::class)->record('products.created', $product, $company->id, $actor->id, ['warehouse_id' => $warehouse->id, 'opening_balance' => $opening]);

            return $product->load(['type', 'diameter']);
        });
    }

    public function saveTypeDiameterProfile(Company $company, User $actor, array $data): Product
    {
        return DB::transaction(function () use ($company, $actor, $data): Product {
            $warehouse = Warehouse::query()->where('company_id', $company->id)->whereNull('archived_at')->findOrFail((int) $data['warehouse_id']);
            $type = ProductType::query()->where('company_id', $company->id)->where('status', 'active')->findOrFail((int) $data['product_type_id']);
            $diameter = Diameter::query()->where('company_id', $company->id)->findOrFail((int) $data['diameter_id']);
            $sku = 'TYPE-'.$type->id.'-DIA-'.$diameter->id;
            $product = Product::query()->firstOrCreate(
                ['company_id' => $company->id, 'sku' => $sku],
                ['product_type_id' => $type->id, 'diameter_id' => $diameter->id, 'name' => $type->name.' '.$diameter->millimeters.' mm', 'unit' => 'ton', 'status' => 'active'],
            );
            $product->forceFill(['product_type_id' => $type->id, 'diameter_id' => $diameter->id, 'minimum_stock' => $data['minimum_stock'] ?? $product->minimum_stock])->save();
            $balance = StockBalance::query()->firstOrCreate(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id], ['quantity' => 0]);
            if (array_key_exists('opening_balance', $data) && $data['opening_balance'] !== null && (float) $data['opening_balance'] !== 0.0) {
                $opening = (float) $data['opening_balance'];
                $balance->increment('quantity', $opening);
                StockMovement::query()->create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'user_id' => $actor->id, 'movement_type' => 'opening_balance', 'quantity_delta' => $opening, 'notes' => 'Opening type and diameter balance']);
            }
            app(AuditRecorder::class)->record('inventory.type_diameter_profile_saved', $product, $company->id, $actor->id, ['warehouse_id' => $warehouse->id, 'opening_balance' => $data['opening_balance'] ?? null, 'minimum_stock' => $data['minimum_stock'] ?? null]);

            return $product->fresh(['type', 'diameter']);
        });
    }

    public function transfer(Company $company, User $actor, array $data): StockTransfer
    {
        return DB::transaction(function () use ($company, $actor, $data): StockTransfer {
            $from = Warehouse::query()->where('company_id', $company->id)->whereNull('archived_at')->findOrFail($data['from_warehouse_id']);
            $to = Warehouse::query()->where('company_id', $company->id)->whereNull('archived_at')->findOrFail($data['to_warehouse_id']);
            throw_if($from->is($to), ValidationException::withMessages(['to_warehouse_id' => 'Choose a different destination warehouse.']));
            $transfer = StockTransfer::query()->create([
                'company_id' => $company->id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'created_by' => $actor->id,
                'status' => 'posted',
                'transfer_number' => $data['transfer_number'],
                'notes' => $data['notes'] ?? null,
                'transferred_at' => $data['transferred_at'] ?? now(),
            ]);

            foreach ($data['lines'] as $line) {
                $product = Product::query()->where('company_id', $company->id)->whereNull('archived_at')->findOrFail($line['product_id']);
                $quantity = (float) $line['quantity'];
                $sourceBalance = StockBalance::query()->where('company_id', $company->id)->where('warehouse_id', $from->id)->where('product_id', $product->id)->lockForUpdate()->first();
                throw_if(! $sourceBalance || (float) $sourceBalance->quantity < $quantity, ValidationException::withMessages(['lines' => "Insufficient stock for {$product->sku}."]));
                $sourceBalance->decrement('quantity', $quantity);
                $destination = StockBalance::query()->firstOrCreate(
                    ['warehouse_id' => $to->id, 'product_id' => $product->id],
                    ['company_id' => $company->id, 'quantity' => 0],
                );
                $destination->increment('quantity', $quantity);
                $transfer->lines()->create(['company_id' => $company->id, 'product_id' => $product->id, 'quantity' => $quantity]);
                foreach ([[$from, -$quantity], [$to, $quantity]] as [$warehouse, $delta]) {
                    StockMovement::query()->create([
                        'company_id' => $company->id,
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $product->id,
                        'user_id' => $actor->id,
                        'movement_type' => $delta < 0 ? 'transfer_out' : 'transfer_in',
                        'quantity_delta' => $delta,
                        'source_type' => StockTransfer::class,
                        'source_id' => $transfer->id,
                    ]);
                }
            }

            app(AuditRecorder::class)->record('inventory.transfer_posted', $transfer, $company->id, $actor->id, ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id]);

            return $transfer->load('lines');
        });
    }

    public function matrix(Company $company, ?int $warehouseId = null): array
    {
        if ($warehouseId) {
            abort_unless(Warehouse::query()->where('company_id', $company->id)->whereKey($warehouseId)->exists(), 404);
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
