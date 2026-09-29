<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bill_lines', function (Blueprint $table): void {
            $table->decimal('actual_weight', 16, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bill_lines', function (Blueprint $table): void {
            $table->decimal('actual_weight', 16, 3)->default(0)->nullable(false)->change();
        });
    }
};
