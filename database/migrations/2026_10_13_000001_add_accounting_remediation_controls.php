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
        Schema::create('party_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained('customer_suppliers')->restrictOnDelete();
            $table->string('bill_type', 20);
            $table->unsignedBigInteger('bill_id');
            $table->string('payment_type', 20);
            $table->unsignedBigInteger('payment_id');
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('posted');
            $table->string('idempotency_key', 160);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('party_payment_allocations')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'party_payment_allocations_idempotency_unique');
            $table->index(['company_id', 'party_id', 'status', 'allocated_at'], 'party_payment_allocations_party_date_idx');
            $table->index(['company_id', 'bill_type', 'bill_id', 'status'], 'party_payment_allocations_bill_status_idx');
            $table->index(['company_id', 'payment_type', 'payment_id', 'status'], 'party_payment_allocations_payment_idx');
        });

        Schema::table('stock_transfer_lines', function (Blueprint $table): void {
            $table->decimal('transferred_value', 18, 2)->default(0)->after('quantity');
            $table->decimal('unit_cost', 18, 6)->default(0)->after('transferred_value');
        });

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->foreignId('reversal_of_id')->nullable()->after('status')->constrained('stock_transfers')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('transferred_at');
            $table->text('reversal_reason')->nullable()->after('notes');
            $table->string('request_hash', 64)->nullable()->after('transfer_number');
            $table->unique(['company_id', 'request_hash'], 'stock_transfers_request_hash_unique');
            $table->unique('reversal_of_id', 'stock_transfers_reversal_unique');
        });

        foreach (['receipts', 'payment_vouchers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->text('reversal_reason')->nullable();
            });
        }

        Schema::table('local_licenses', function (Blueprint $table): void {
            $table->longText('signed_payload_raw')->nullable()->after('signed_payload');
            $table->text('signature')->nullable()->after('signed_payload_raw');
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->foreignId('cashbox_id')->nullable()->after('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->nullable()->after('cashbox_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->after('bank_id')->constrained()->restrictOnDelete();
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ([
                ['fleet_expense', '4.2', '4.2.9001', 'Fleet operating expenses', 'expense'],
                ['maintenance_expense', '4.2', '4.2.9002', 'Vehicle maintenance expense', 'expense'],
                ['fleet_payable', '2.1', '2.1.9001', 'Fleet accrued payable', 'liability'],
            ] as [$key, $parentCode, $code, $name, $type]) {
                $parentId = DB::table('accounts')->where('company_id', $companyId)->where('code', $parentCode)->value('id');
                if ($parentId && ! DB::table('accounts')->where('company_id', $companyId)->where('system_key', $key)->exists()) {
                    DB::table('accounts')->insert([
                        'company_id' => $companyId,
                        'parent_id' => $parentId,
                        'code' => $code,
                        'name' => $name,
                        'account_type' => $type,
                        'system_key' => $key,
                        'is_system' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('local_licenses', function (Blueprint $table): void {
            $table->dropColumn(['signed_payload_raw', 'signature']);
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('bank_id');
            $table->dropConstrainedForeignId('cashbox_id');
        });

        foreach (['payment_vouchers', 'receipts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reversal_journal_entry_id');
                $table->dropConstrainedForeignId('reversed_by');
                $table->dropColumn(['reversed_at', 'reversal_reason']);
            });
        }

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropUnique('stock_transfers_request_hash_unique');
            $table->dropUnique('stock_transfers_reversal_unique');
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason', 'request_hash']);
        });

        Schema::table('stock_transfer_lines', function (Blueprint $table): void {
            $table->dropColumn(['transferred_value', 'unit_cost']);
        });

        Schema::dropIfExists('party_payment_allocations');
    }
};
