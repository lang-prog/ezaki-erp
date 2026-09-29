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
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->foreignId($table->getTable() === 'purchase_bills' ? 'supplier_id' : 'customer_id')->constrained('customer_suppliers')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->string('internal_number', 80);
                $table->string($table->getTable() === 'purchase_bills' ? 'supplier_bill_number' : 'customer_bill_number', 100);
                $table->date($table->getTable() === 'purchase_bills' ? 'supplier_bill_date' : 'bill_date');
                $table->date('warehouse_entry_date')->nullable();
                $table->string('external_vehicle_plate')->nullable();
                $table->string('external_driver_name')->nullable();
                $table->decimal('total_factory_weight', 16, 3)->default(0);
                $table->decimal('total_actual_weight', 16, 3)->default(0);
                $table->decimal('total_packages', 16, 3)->default(0);
                $table->decimal('subtotal', 16, 2)->default(0);
                $table->decimal('transport_cost', 16, 2)->default(0);
                $table->decimal('loading_cost', 16, 2)->default(0);
                $table->decimal('extra_cost', 16, 2)->default(0);
                $table->decimal('discount', 16, 2)->default(0);
                $table->decimal('vat_rate', 8, 3)->nullable();
                $table->decimal('vat_amount', 16, 2)->default(0);
                $table->decimal('total', 16, 2)->default(0);
                $table->string('payment_method')->nullable();
                $table->date('due_date')->nullable();
                $table->string('status')->default('draft')->index();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'internal_number']);
                $table->unique(['company_id', $table->getTable() === 'purchase_bills' ? 'supplier_bill_number' : 'customer_bill_number']);
                $table->index(['company_id', 'status']);
            });
        }

        Schema::create('purchase_bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('factory_weight', 16, 3);
            $table->decimal('actual_weight', 16, 3);
            $table->decimal('packages', 16, 3)->default(0);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('actual_weight', 16, 3);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('bill_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('document_type');
            $table->unsignedBigInteger('document_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->json('before_data');
            $table->json('after_data');
            $table->timestamps();
            $table->index(['company_id', 'document_type', 'document_id']);
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plate', 50);
            $table->string('type')->nullable();
            $table->string('make_model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('ownership')->default('company');
            $table->decimal('capacity', 16, 3)->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('status')->default('active');
            $table->date('license_expires_at')->nullable();
            $table->date('insurance_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'plate']);
        });
        Schema::create('drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('license_number')->nullable();
            $table->string('license_type')->nullable();
            $table->date('license_expires_at')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
        Schema::create('trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trip_type');
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->dateTime('trip_at');
            $table->string('bill_type')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->decimal('distance', 16, 3)->nullable();
            $table->decimal('odometer_in', 16, 3)->nullable();
            $table->decimal('odometer_out', 16, 3)->nullable();
            $table->decimal('fuel_cost', 16, 2)->default(0);
            $table->decimal('road_fees', 16, 2)->default(0);
            $table->decimal('loading_fees', 16, 2)->default(0);
            $table->decimal('revenue', 16, 2)->default(0);
            $table->string('status')->default('approved');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'vehicle_id', 'trip_at']);
        });
        Schema::create('fleet_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->date('expense_date');
            $table->string('category');
            $table->decimal('amount', 16, 2);
            $table->string('status')->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'category', 'expense_date']);
        });
        Schema::create('maintenance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('maintenance_type');
            $table->string('issue');
            $table->text('parts')->nullable();
            $table->decimal('labor_cost', 16, 2)->default(0);
            $table->decimal('parts_cost', 16, 2)->default(0);
            $table->string('vendor')->nullable();
            $table->decimal('cost', 16, 2)->default(0);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->string('vehicle_status')->default('in_service');
            $table->string('status')->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['maintenance_records', 'fleet_expenses', 'trips', 'drivers', 'vehicles', 'bill_revisions', 'sales_bill_lines', 'purchase_bill_lines', 'sales_bills', 'purchase_bills'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
