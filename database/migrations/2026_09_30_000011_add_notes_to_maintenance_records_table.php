<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('maintenance_records', 'notes')) {
            Schema::table('maintenance_records', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('journal_entry_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('maintenance_records', 'notes')) {
            Schema::table('maintenance_records', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }
    }
};
