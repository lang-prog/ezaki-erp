<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('source_system', 80)->default('old-prog');
            $table->string('source_name', 191);
            $table->string('source_disk', 80)->default('local');
            $table->string('source_path', 255);
            $table->string('source_hash', 64);
            $table->date('documents_from')->nullable();
            $table->date('documents_to')->nullable();
            $table->date('opening_balance_as_of')->nullable();
            $table->string('mode', 30)->default('draft');
            $table->string('status', 30)->default('uploaded');
            $table->json('options')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'dm_runs_company_status_idx');
            $table->index(['source_system', 'source_hash'], 'dm_runs_source_hash_idx');
        });

        Schema::create('data_migration_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('data_migration_runs')->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->string('source_table', 80);
            $table->string('source_key', 191);
            $table->string('target_type', 120)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('decision', 30)->default('suggested');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'entity_type', 'source_table', 'source_key'], 'dm_maps_source_unique');
            $table->index(['run_id', 'entity_type', 'decision'], 'dm_maps_review_idx');
        });

        Schema::create('data_migration_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('data_migration_runs')->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->string('source_table', 80);
            $table->string('source_id', 191);
            $table->date('source_date')->nullable();
            $table->string('source_number', 191)->nullable();
            $table->json('source_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('target_type', 120)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'entity_type', 'source_table', 'source_id'], 'dm_records_source_unique');
            $table->unique(['run_id', 'fingerprint'], 'dm_records_fingerprint_unique');
            $table->index(['run_id', 'entity_type', 'status'], 'dm_records_status_idx');
            $table->index(['run_id', 'source_date'], 'dm_records_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_migration_records');
        Schema::dropIfExists('data_migration_mappings');
        Schema::dropIfExists('data_migration_runs');
    }
};
