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
        $this->ensureColumns('data_migration_runs', [
            'state_version' => fn (Blueprint $t) => $t->unsignedInteger('state_version')->default(0),
            'lock_token' => fn (Blueprint $t) => $t->string('lock_token', 64)->nullable(),
            'lock_expires_at' => fn (Blueprint $t) => $t->timestamp('lock_expires_at')->nullable(),
            'lock_action' => fn (Blueprint $t) => $t->string('lock_action', 40)->nullable(),
            'integrity' => fn (Blueprint $t) => $t->json('integrity')->nullable(),
            'preflight' => fn (Blueprint $t) => $t->json('preflight')->nullable(),
            'policy' => fn (Blueprint $t) => $t->json('policy')->nullable(),
            'source_bytes' => fn (Blueprint $t) => $t->unsignedBigInteger('source_bytes')->nullable(),
            'source_retention_until' => fn (Blueprint $t) => $t->date('source_retention_until')->nullable(),
            'source_removed_at' => fn (Blueprint $t) => $t->timestamp('source_removed_at')->nullable(),
            'gap_acknowledged_at' => fn (Blueprint $t) => $t->timestamp('gap_acknowledged_at')->nullable(),
            'gap_acknowledged_reason' => fn (Blueprint $t) => $t->string('gap_acknowledged_reason', 500)->nullable(),
            'signed_off_by' => fn (Blueprint $t) => $t->unsignedBigInteger('signed_off_by')->nullable(),
            'signed_off_at' => fn (Blueprint $t) => $t->timestamp('signed_off_at')->nullable(),
            'archived_at' => fn (Blueprint $t) => $t->timestamp('archived_at')->nullable(),
        ]);
        $this->ensureIndex('data_migration_runs', 'dm_runs_company_source_idx', ['company_id', 'source_system', 'source_hash']);

        $this->ensureColumns('data_migration_mappings', [
            'reason' => fn (Blueprint $t) => $t->string('reason', 500)->nullable(),
            'reviewed_by' => fn (Blueprint $t) => $t->unsignedBigInteger('reviewed_by')->nullable(),
            'reviewed_at' => fn (Blueprint $t) => $t->timestamp('reviewed_at')->nullable(),
            'locked_at' => fn (Blueprint $t) => $t->timestamp('locked_at')->nullable(),
        ]);
        $this->ensureIndex('data_migration_mappings', 'dm_maps_decision_idx', ['run_id', 'decision', 'entity_type']);

        $this->ensureColumns('data_migration_records', [
            'review_code' => fn (Blueprint $t) => $t->string('review_code', 60)->nullable(),
            'exclusion_reason' => fn (Blueprint $t) => $t->string('exclusion_reason', 500)->nullable(),
            'attempts' => fn (Blueprint $t) => $t->unsignedSmallInteger('attempts')->default(0),
            'result' => fn (Blueprint $t) => $t->json('result')->nullable(),
            'executed_at' => fn (Blueprint $t) => $t->timestamp('executed_at')->nullable(),
        ]);
        $this->ensureIndex('data_migration_records', 'dm_records_review_idx', ['run_id', 'status', 'review_code']);

        if (! Schema::hasTable('data_migration_source_rows')) {
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
        }
        $this->assertTableShape('data_migration_source_rows', ['id', 'run_id', 'source_table', 'row_number', 'source_key', 'payload'], ['dm_src_rows_unique', 'dm_src_rows_key_idx']);

        if (! Schema::hasTable('data_migration_events')) {
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
        }
        $this->assertTableShape('data_migration_events', ['id', 'run_id', 'action', 'from_state', 'to_state', 'actor_id', 'reason', 'counts', 'error', 'created_at'], ['dm_events_run_idx']);

        if (! Schema::hasTable('data_migration_links')) {
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
        }
        $this->assertTableShape('data_migration_links', ['id', 'company_id', 'run_id', 'source_system', 'source_table', 'source_id', 'document_type', 'target_type', 'target_id', 'result', 'created_at', 'updated_at'], ['dm_links_source_unique', 'dm_links_target_unique', 'dm_links_run_idx']);

        $this->ensureColumns('products', [
            'material_category' => fn (Blueprint $t) => $t->string('material_category', 80)->nullable(),
            'quantity_basis' => fn (Blueprint $t) => $t->string('quantity_basis', 20)->default('weight'),
        ]);
        if (! $this->isPretending() && DB::table('products')->whereNotNull('material_category')->select('company_id', 'product_type_id', 'material_category')->groupBy('company_id', 'product_type_id', 'material_category')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate non-null product material identities prevent the required unique index; no product rows were changed.');
        }
        $this->ensureUniqueIndex('products', 'products_company_type_material_unique', ['company_id', 'product_type_id', 'material_category']);
    }

    /** @param array<string, Closure(Blueprint): void> $columns */
    private function ensureColumns(string $tableName, array $columns): void
    {
        $missing = array_filter($columns, fn ($definition, string $column): bool => ! Schema::hasColumn($tableName, $column), ARRAY_FILTER_USE_BOTH);
        foreach ($missing as $column => $definition) {
            Schema::table($tableName, fn (Blueprint $table) => $definition($table));
        }
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $tableName, string $name, array $columns): void
    {
        $indexes = Schema::getIndexes($tableName);
        foreach ($indexes as $index) {
            if ($index['name'] === $name) {
                if ($index['columns'] !== $columns || $index['unique']) {
                    throw new RuntimeException("Existing index {$name} on {$tableName} is incompatible with the migration.");
                }

                return;
            }
        }
        Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
    }

    /** @param list<string> $columns */
    private function ensureUniqueIndex(string $tableName, string $name, array $columns): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['name'] === $name) {
                if ($index['columns'] !== $columns || ! $index['unique']) {
                    throw new RuntimeException("Existing index {$name} on {$tableName} is incompatible with the migration.");
                }

                return;
            }
        }
        Schema::table($tableName, fn (Blueprint $table) => $table->unique($columns, $name));
    }

    /** @param list<string> $columns
     * @param  list<string>  $indexes
     */
    private function assertTableShape(string $tableName, array $columns, array $indexes): void
    {
        if ($this->isPretending()) {
            return;
        }
        $missingColumns = array_diff($columns, Schema::getColumnListing($tableName));
        $existingIndexes = array_column(Schema::getIndexes($tableName), 'name');
        $missingIndexes = array_diff($indexes, $existingIndexes);
        if ($missingColumns !== [] || $missingIndexes !== []) {
            throw new RuntimeException('Existing migration table '.$tableName.' is incompatible; missing required columns or indexes. No existing objects were removed.');
        }
    }

    private function isPretending(): bool
    {
        return DB::connection()->pretending();
    }

    public function down(): void
    {
        throw new LogicException('This migration is forward-only because it reconciles pre-existing migration runs. Restore a database backup to roll it back.');
    }
};
