<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_bill_lines', function (Blueprint $table): void {
            $table->decimal('packages', 16, 3)->nullable()->after('actual_weight');
        });
    }

    public function down(): void
    {
        Schema::table('sales_bill_lines', function (Blueprint $table): void {
            $table->dropColumn('packages');
        });
    }
};
