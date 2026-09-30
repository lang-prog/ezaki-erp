<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('system_key', 80)->nullable()->after('account_type');
            $table->unique(['company_id', 'system_key']);
        });

        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->decimal('inventory_value', 18, 2)->default(0)->after('quantity');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->decimal('value_delta', 18, 2)->default(0)->after('quantity_delta');
        });

        Schema::table('purchase_bill_lines', function (Blueprint $table): void {
            $table->decimal('inventory_value', 18, 2)->default(0)->after('line_total');
        });

        Schema::table('sales_bill_lines', function (Blueprint $table): void {
            $table->decimal('inventory_cost', 18, 2)->default(0)->after('line_total');
        });

        foreach (['purchase_bills', 'sales_bills'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('cashbox_id')->nullable()->after('payment_method')->constrained()->restrictOnDelete();
                $table->foreignId('bank_id')->nullable()->after('cashbox_id')->constrained()->restrictOnDelete();
                $table->decimal('paid_amount', 18, 2)->default(0)->after('bank_id');
            });
        }

        Schema::create('bill_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('bill_type', 20);
            $table->unsignedBigInteger('bill_id');
            $table->foreignId('cashbox_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('posted');
            $table->timestamp('allocated_at');
            $table->timestamps();
            $table->index(['company_id', 'bill_type', 'bill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payment_allocations');

        foreach (['sales_bills', 'purchase_bills'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('bank_id');
                $table->dropConstrainedForeignId('cashbox_id');
                $table->dropColumn('paid_amount');
            });
        }

        Schema::table('sales_bill_lines', fn (Blueprint $table) => $table->dropColumn('inventory_cost'));
        Schema::table('purchase_bill_lines', fn (Blueprint $table) => $table->dropColumn('inventory_value'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('value_delta'));
        Schema::table('stock_balances', fn (Blueprint $table) => $table->dropColumn('inventory_value'));
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
