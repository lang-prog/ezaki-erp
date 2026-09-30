<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep one product identity for each company/type/diameter pair. This
        // also repairs historical INTERNAL-* rows before the unique index.
        $duplicates = DB::table('products')
            ->select('company_id', 'product_type_id', 'diameter_id')
            ->whereNotNull('product_type_id')
            ->whereNotNull('diameter_id')
            ->groupBy('company_id', 'product_type_id', 'diameter_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            $products = DB::table('products')
                ->where('company_id', $group->company_id)
                ->where('product_type_id', $group->product_type_id)
                ->where('diameter_id', $group->diameter_id)
                ->orderByRaw("CASE WHEN sku LIKE 'TYPE-%' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get();
            $canonical = $products->first();
            foreach ($products->skip(1) as $duplicate) {
                $duplicateBalances = DB::table('stock_balances')->where('product_id', $duplicate->id)->get();
                foreach ($duplicateBalances as $balance) {
                    $existing = DB::table('stock_balances')
                        ->where('warehouse_id', $balance->warehouse_id)
                        ->where('product_id', $canonical->id)
                        ->first();
                    if ($existing) {
                        DB::table('stock_balances')->where('id', $existing->id)->update([
                            'quantity' => DB::raw('quantity + '.(float) $balance->quantity),
                            'inventory_value' => DB::raw('inventory_value + '.(float) ($balance->inventory_value ?? 0)),
                            'updated_at' => now(),
                        ]);
                        DB::table('stock_balances')->where('id', $balance->id)->delete();
                    } else {
                        DB::table('stock_balances')->where('id', $balance->id)->update(['product_id' => $canonical->id, 'updated_at' => now()]);
                    }
                }
                foreach (['purchase_bill_lines', 'sales_bill_lines', 'stock_movements', 'stock_transfer_lines'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->where('product_id', $duplicate->id)->update(['product_id' => $canonical->id]);
                    }
                }
                DB::table('products')->where('id', $duplicate->id)->delete();
            }
            $type = DB::table('product_types')->where('id', $canonical->product_type_id)->value('name');
            $millimeters = DB::table('diameters')->where('id', $canonical->diameter_id)->value('millimeters');
            DB::table('products')->where('id', $canonical->id)->update([
                'sku' => 'TYPE-'.$canonical->product_type_id.'-DIA-'.$canonical->diameter_id,
                'name' => trim((string) $type).' '.(float) $millimeters.' mm',
                'updated_at' => now(),
            ]);
        }

        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->decimal('minimum_stock', 16, 3)->default(0)->after('quantity');
        });
        DB::statement('UPDATE stock_balances SET minimum_stock = COALESCE((SELECT minimum_stock FROM products WHERE products.id = stock_balances.product_id), 0)');

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('source_key', 160)->nullable()->after('source_id');
            $table->index(['company_id', 'source_type', 'source_key'], 'stock_movements_source_key_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['company_id', 'product_type_id', 'diameter_id'], 'products_company_type_diameter_unique');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unique(['company_id', 'source_type', 'source_key'], 'stock_movements_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique('stock_movements_source_unique');
            $table->dropIndex('stock_movements_source_key_idx');
            $table->dropColumn('source_key');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_company_type_diameter_unique');
        });
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->dropColumn('minimum_stock');
        });
    }
};
