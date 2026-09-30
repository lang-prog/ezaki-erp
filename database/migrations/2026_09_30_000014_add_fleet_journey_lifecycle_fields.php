<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_bills', 'sales_bills'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('trip_driver_id')->nullable()->after('vehicle_id')->constrained('drivers')->nullOnDelete();
                $table->string('trip_origin')->nullable()->after('trip_driver_id');
                $table->string('trip_destination')->nullable()->after('trip_origin');
                $table->decimal('trip_distance', 16, 3)->nullable()->after('trip_destination');
                $table->decimal('trip_odometer_in', 16, 3)->nullable()->after('trip_distance');
                $table->decimal('trip_odometer_out', 16, 3)->nullable()->after('trip_odometer_in');
                $table->decimal('trip_fuel_cost', 16, 2)->default(0)->after('trip_odometer_out');
                $table->decimal('trip_road_fees', 16, 2)->default(0)->after('trip_fuel_cost');
                $table->decimal('trip_loading_fees', 16, 2)->default(0)->after('trip_road_fees');
            });
        }

        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('reversed_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->foreignId('cancelled_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->index(['company_id', 'status', 'trip_at']);
        });

        Schema::table('fleet_expenses', function (Blueprint $table): void {
            $table->foreignId('cashbox_id')->nullable()->after('amount')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->nullable()->after('cashbox_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->after('bank_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->after('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('document')->nullable()->after('expense_account_id');
            $table->foreignId('created_by')->nullable()->after('document')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('document');
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('bank_id');
            $table->dropConstrainedForeignId('cashbox_id');
        });
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status', 'trip_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn('reversed_at');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
        foreach (['purchase_bills', 'sales_bills'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('trip_driver_id');
                $table->dropColumn(['trip_origin', 'trip_destination', 'trip_distance', 'trip_odometer_in', 'trip_odometer_out', 'trip_fuel_cost', 'trip_road_fees', 'trip_loading_fees']);
            });
        }
    }
};
