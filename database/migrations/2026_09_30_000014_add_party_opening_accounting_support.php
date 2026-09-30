<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_suppliers', function (Blueprint $table): void {
            $table->string('opening_balance_direction', 10)->default('debit')->after('opening_balance');
            $table->date('opening_balance_date')->nullable()->after('opening_balance_direction');
            $table->foreignId('opening_balance_journal_entry_id')->nullable()->after('opening_balance_date')->constrained('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'is_customer', 'is_supplier', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_suppliers', function (Blueprint $table): void {
            $table->dropForeign(['opening_balance_journal_entry_id']);
            $table->dropIndex(['customer_suppliers_company_id_is_customer_is_supplier_status_index']);
            $table->dropColumn(['opening_balance_direction', 'opening_balance_date', 'opening_balance_journal_entry_id']);
        });
    }
};
