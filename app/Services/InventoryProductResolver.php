<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the canonical product for a type/diameter combination.
 *
 * Historical callers used both TYPE-* and INTERNAL-* SKUs. The identity is
 * the company + type + diameter pair; the SKU is only a compatibility label.
 */
final class InventoryProductResolver
{
    public function resolve(Company $company, array $line): Product
    {
        if (! empty($line['product_id'])) {
            return Product::query()
                ->where('company_id', $company->id)
                ->whereNull('archived_at')
                ->findOrFail((int) $line['product_id']);
        }

        $typeId = (int) ($line['product_type_id'] ?? 0);
        $diameterId = (int) ($line['diameter_id'] ?? 0);
        if ($typeId < 1 || $diameterId < 1) {
            throw ValidationException::withMessages(['lines' => 'Type and diameter are required for steel lines.']);
        }

        $type = DB::table('product_types')
            ->where('company_id', $company->id)
            ->where('id', $typeId)
            ->where('status', 'active')
            ->first();
        $diameter = DB::table('diameters')
            ->where('company_id', $company->id)
            ->where('id', $diameterId)
            ->first();
        abort_unless($type && $diameter, 422, 'Type and diameter are required for steel lines.');

        $canonicalSku = self::canonicalSku($typeId, $diameterId);
        $name = trim($type->name).' '.(float) $diameter->millimeters.' mm';
        $product = Product::query()
            ->where('company_id', $company->id)
            ->where('product_type_id', $typeId)
            ->where('diameter_id', $diameterId)
            ->lockForUpdate()
            ->first();

        if ($product) {
            // Normalize legacy INTERNAL products without changing their id,
            // so existing bill lines and balances keep their foreign keys.
            if ($product->sku !== $canonicalSku || $product->name !== $name) {
                $product->forceFill(['sku' => $canonicalSku, 'name' => $name])->save();
            }

            return $product->fresh(['type', 'diameter']);
        }

        return Product::query()->firstOrCreate(
            ['company_id' => $company->id, 'product_type_id' => $typeId, 'diameter_id' => $diameterId],
            ['sku' => $canonicalSku, 'name' => $name, 'unit' => 'ton', 'minimum_stock' => 0, 'status' => 'active'],
        )->load(['type', 'diameter']);
    }

    public static function canonicalSku(int $typeId, int $diameterId): string
    {
        return 'TYPE-'.$typeId.'-DIA-'.$diameterId;
    }
}
