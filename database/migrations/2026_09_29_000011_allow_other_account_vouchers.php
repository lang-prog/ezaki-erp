<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            $table->foreignId('party_id')->nullable()->change();
            $table->foreignId('other_account_id')->nullable()->after('party_id')->constrained('accounts')->restrictOnDelete();
            $table->string('counterparty_type')->default('customer')->after('other_account_id');
        });

        Schema::table('payment_vouchers', function (Blueprint $table): void {
            $table->foreignId('party_id')->nullable()->change();
            $table->foreignId('other_account_id')->nullable()->after('party_id')->constrained('accounts')->restrictOnDelete();
            $table->string('counterparty_type')->default('supplier')->after('other_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('other_account_id');
            $table->dropColumn('counterparty_type');
        });
        Schema::table('receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('other_account_id');
            $table->dropColumn('counterparty_type');
        });
    }
};
