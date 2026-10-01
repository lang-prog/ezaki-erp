<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'customer_suppliers';

    private const INDEX = 'cs_company_party_status_idx';

    private const JOURNAL_COLUMN = 'opening_balance_journal_entry_id';

    public function up(): void
    {
        // MySQL DDL is not transactional: an earlier failed attempt may have
        // created the columns and foreign key before the index failed.
        if (! Schema::hasColumn(self::TABLE, 'opening_balance_direction')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('opening_balance_direction', 10)->default('debit')->after('opening_balance');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'opening_balance_date')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->date('opening_balance_date')->nullable()->after('opening_balance_direction');
            });
        }

        if (! Schema::hasColumn(self::TABLE, self::JOURNAL_COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreignId(self::JOURNAL_COLUMN)->nullable()->after('opening_balance_date')
                    ->constrained('journal_entries')->nullOnDelete();
            });
        }

        if ($this->openingJournalForeignKey() === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign(self::JOURNAL_COLUMN, 'cs_opening_journal_fk')
                    ->references('id')->on('journal_entries')->nullOnDelete();
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['company_id', 'is_customer', 'is_supplier', 'status'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        $foreignKey = $this->openingJournalForeignKey();
        if ($foreignKey !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign($foreignKey['name'] ?? [self::JOURNAL_COLUMN]);
            });
        }

        foreach ([self::JOURNAL_COLUMN, 'opening_balance_date', 'opening_balance_direction'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function openingJournalForeignKey(): ?array
    {
        if (! Schema::hasColumn(self::TABLE, self::JOURNAL_COLUMN)) {
            return null;
        }

        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (in_array(self::JOURNAL_COLUMN, $foreignKey['columns'], true)) {
                return $foreignKey;
            }
        }

        return null;
    }
};
