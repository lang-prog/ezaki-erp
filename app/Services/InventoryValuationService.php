<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class InventoryValuationService
{
    public function receive(Company $company, User $actor, int $warehouseId, int $productId, float $quantity, float $value, string $sourceType, int $sourceId, string $movementType): void
    {
        $balance = $this->lockedBalance($company, $warehouseId, $productId);
        $balance->forceFill([
            'quantity' => round((float) $balance->quantity + $quantity, 3),
            'inventory_value' => round((float) $balance->inventory_value + $value, 2),
        ])->save();
        $this->movement($company, $actor, $warehouseId, $productId, $quantity, $value, $sourceType, $sourceId, $movementType);
    }

    public function issue(Company $company, User $actor, int $warehouseId, int $productId, float $quantity, string $sourceType, int $sourceId, string $movementType): float
    {
        $balance = $this->lockedBalance($company, $warehouseId, $productId);
        $available = (float) $balance->quantity;
        if ($available + 0.0005 < $quantity) {
            throw ValidationException::withMessages(['lines' => 'Insufficient stock for sale.']);
        }
        $cost = $available > 0 ? round(((float) $balance->inventory_value / $available) * $quantity, 2) : 0.0;
        $remainingQuantity = round($available - $quantity, 3);
        $remainingValue = $remainingQuantity <= 0 ? 0.0 : round(max(0, (float) $balance->inventory_value - $cost), 2);
        $balance->forceFill(['quantity' => $remainingQuantity, 'inventory_value' => $remainingValue])->save();
        $this->movement($company, $actor, $warehouseId, $productId, -$quantity, -$cost, $sourceType, $sourceId, $movementType);

        return $cost;
    }

    public function reverseReceipt(Company $company, User $actor, int $warehouseId, int $productId, float $quantity, float $value, string $sourceType, int $sourceId, string $movementType): void
    {
        $balance = $this->lockedBalance($company, $warehouseId, $productId);
        if ((float) $balance->quantity + 0.0005 < $quantity) {
            throw ValidationException::withMessages(['lines' => 'Purchase cannot be reversed because part of its stock has already been consumed.']);
        }
        $remainingQuantity = round((float) $balance->quantity - $quantity, 3);
        $remainingValue = $remainingQuantity <= 0 ? 0.0 : round(max(0, (float) $balance->inventory_value - $value), 2);
        $balance->forceFill(['quantity' => $remainingQuantity, 'inventory_value' => $remainingValue])->save();
        $this->movement($company, $actor, $warehouseId, $productId, -$quantity, -$value, $sourceType, $sourceId, $movementType);
    }

    private function lockedBalance(Company $company, int $warehouseId, int $productId): StockBalance
    {
        $balance = StockBalance::query()->where('company_id', $company->id)->where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first();
        if (! $balance) {
            $minimumStock = (float) (Product::query()->where('company_id', $company->id)->whereKey($productId)->value('minimum_stock') ?? 0);
            $balance = StockBalance::query()->create(['company_id' => $company->id, 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 0, 'inventory_value' => 0, 'minimum_stock' => $minimumStock]);
            $balance = StockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
        }

        return $balance;
    }

    private function movement(Company $company, User $actor, int $warehouseId, int $productId, float $quantity, float $value, string $sourceType, int $sourceId, string $movementType): void
    {
        StockMovement::query()->create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'user_id' => $actor->id,
            'movement_type' => $movementType,
            'quantity_delta' => round($quantity, 3),
            'value_delta' => round($value, 2),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }
}
