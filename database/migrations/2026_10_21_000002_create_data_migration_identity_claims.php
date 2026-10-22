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
        if (! Schema::hasTable('data_migration_identity_claims')) {
            Schema::create('data_migration_identity_claims', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('source_system', 80);
                $table->char('source_hash', 64);
                $table->unsignedBigInteger('run_id');
                $table->timestamps();
                $table->unique(['company_id', 'source_system', 'source_hash'], 'dm_identity_claim_unique');
                $table->foreign('run_id')->references('id')->on('data_migration_runs')->restrictOnDelete();
            });
        }

        DB::table('data_migration_runs')
            ->selectRaw('MIN(id) as run_id, company_id, source_system, source_hash')
            ->groupBy('company_id', 'source_system', 'source_hash')
            ->orderBy('run_id')
            ->chunk(500, function ($runs): void {
                foreach ($runs as $run) {
                    DB::table('data_migration_identity_claims')->insertOrIgnore([
                        'company_id' => $run->company_id,
                        'source_system' => $run->source_system,
                        'source_hash' => $run->source_hash,
                        'run_id' => $run->run_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        throw new LogicException('This migration is forward-only; restore a database backup to roll it back.');
    }
};
