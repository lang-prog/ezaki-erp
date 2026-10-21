<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_migration_runs', function (Blueprint $table): void {
            $table->unsignedInteger('state_version')->default(0)->after('status');
            $table->string('lock_token', 64)->nullable()->after('state_version');
            $table->timestamp('lock_expires_at')->nullable()->after('lock_token');
            $table->string('lock_action', 40)->nullable()->after('lock_expires_at');
            $table->json('integrity')->nullable()->after('summary');
            $table->json('preflight')->nullable()->after('integrity');
            $table->json('policy')->nullable()->after('preflight');
            $table->unsignedBigInteger('source_bytes')->nullable()->after('source_hash');
            $table->date('source_retention_until')->nullable()->after('source_bytes');
            $table->timestamp('source_removed_at')->nullable()->after('source_retention_until');
            $table->timestamp('gap_acknowledged_at')->nullable()->after('source_removed_at');
            $table->string('gap_acknowledged_reason', 500)->nullable()->after('gap_acknowledged_at');
            $table->unsignedBigInteger('signed_off_by')->nullable()->after('gap_acknowledged_reason');
            $table->timestamp('signed_off_at')->nullable()->after('signed_off_by');
            $table->timestamp('archived_at')->nullable()->after('signed_off_at');
            $table->unique(['company_id', 'source_system', 'source_hash'], 'dm_runs_company_source_unique');
        });

        Schema::table('data_migration_mappings', function (Blueprint $table): void {
            $table->string('reason', 500)->nullable()->after('payload');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reason');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->timestamp('locked_at')->nullable()->after('reviewed_at');
            $table->index(['run_id', 'decision', 'entity_type'], 'dm_maps_decision_idx');
        });

        Schema::table('data_migration_records', function (Blueprint $table): void {
            $table->string('review_code', 60)->nullable()->after('status');
            $table->string('exclusion_reason', 500)->nullable()->after('review_code');
            $table->unsignedSmallInteger('attempts')->default(0)->after('exclusion_reason');
            $table->json('result')->nullable()->after('attempts');
            $table->timestamp('executed_at')->nullable()->after('result');
            $table->index(['run_id', 'status', 'review_code'], 'dm_records_review_idx');
        });

        Schema::create('data_migration_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('data_migration_runs')->cascadeOnDelete();
            $table->string('source_table', 60);
            $table->unsignedInteger('row_number');
            $table->string('source_key', 100)->nullable();
            $table->longText('payload');
            $table->unique(['run_id', 'source_table', 'row_number'], 'dm_src_rows_unique');
            $table->index(['run_id', 'source_table', 'source_key'], 'dm_src_rows_key_idx');
        });

        Schema::create('data_migration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('data_migration_runs')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->json('counts')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['run_id', 'id'], 'dm_events_run_idx');
        });

        Schema::create('data_migration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('run_id')->constrained('data_migration_runs')->restrictOnDelete();
            $table->string('source_system', 80);
            $table->string('source_table', 60);
            $table->string('source_id', 120);
            $table->string('document_type', 40);
            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_system', 'source_table', 'source_id', 'document_type'], 'dm_links_source_unique');
            $table->unique(['company_id', 'target_type', 'target_id'], 'dm_links_target_unique');
            $table->index(['run_id', 'document_type'], 'dm_links_run_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('material_category', 80)->nullable()->after('diameter_id');
            $table->string('quantity_basis', 20)->default('weight')->after('unit');
            $table->unique(['company_id', 'product_type_id', 'material_category'], 'products_company_type_material_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_company_type_material_unique');
            $table->dropColumn(['material_category', 'quantity_basis']);
        });
        Schema::dropIfExists('data_migration_links');
        Schema::dropIfExists('data_migration_events');
        Schema::dropIfExists('data_migration_source_rows');
        Schema::table('data_migration_records', function (Blueprint $table): void {
            $table->dropIndex('dm_records_review_idx');
            $table->dropColumn(['review_code', 'exclusion_reason', 'attempts', 'result', 'executed_at']);
        });
        Schema::table('data_migration_mappings', function (Blueprint $table): void {
            $table->dropIndex('dm_maps_decision_idx');
            $table->dropColumn(['reason', 'reviewed_by', 'reviewed_at', 'locked_at']);
        });
        Schema::table('data_migration_runs', function (Blueprint $table): void {
            $table->dropUnique('dm_runs_company_source_unique');
            $table->dropColumn(['state_version', 'lock_token', 'lock_expires_at', 'lock_action', 'integrity', 'preflight', 'policy', 'source_bytes', 'source_retention_until', 'source_removed_at', 'gap_acknowledged_at', 'gap_acknowledged_reason', 'signed_off_by', 'signed_off_at', 'archived_at']);
        });
    }
};
