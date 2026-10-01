<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartyOpeningMigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recovers_a_partially_applied_party_migration_without_readding_existing_columns(): void
    {
        $migration = require database_path('migrations/2026_09_30_000014_add_party_opening_accounting_support.php');
        $this->assertTrue(Schema::hasColumn('customer_suppliers', 'opening_balance_direction'));
        $this->assertTrue(Schema::hasColumn('customer_suppliers', 'opening_balance_date'));
        $this->assertTrue(Schema::hasColumn('customer_suppliers', 'opening_balance_journal_entry_id'));
        $this->assertTrue(Schema::hasIndex('customer_suppliers', 'cs_company_party_status_idx'));

        Schema::table('customer_suppliers', function ($table): void {
            $table->dropIndex('cs_company_party_status_idx');
        });
        $this->assertFalse(Schema::hasIndex('customer_suppliers', 'cs_company_party_status_idx'));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('customer_suppliers', 'cs_company_party_status_idx'));
        $this->assertSame(1, collect(Schema::getIndexes('customer_suppliers'))
            ->filter(fn (array $index): bool => $index['name'] === 'cs_company_party_status_idx')->count());
        $this->assertTrue(collect(Schema::getForeignKeys('customer_suppliers'))
            ->contains(fn (array $foreignKey): bool => in_array('opening_balance_journal_entry_id', $foreignKey['columns'], true)));
    }
}
