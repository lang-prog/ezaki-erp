<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Models\Diameter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingFoundationService;
use App\Services\AccountingService;
use App\Services\MigrationInvoiceExecutionService;
use App\Services\MigrationMappingService;
use App\Services\MigrationMasterDataService;
use App\Services\MigrationOpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DataMigrationCenterTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function admin(string $role): User
    {
        $this->sequence++;

        return User::query()->create([
            'name' => "Migration Admin {$this->sequence}",
            'email' => "migration-{$this->sequence}@example.test",
            'password' => 'Correct-horse-1',
            'account_type' => 'super_admin',
            'platform_role' => $role,
            'status' => 'active',
        ]);
    }

    public function test_only_platform_owner_can_view_and_analyze_a_source(): void
    {
        Storage::fake('local');
        $company = Company::query()->create(['name' => 'Target company', 'status' => 'active']);
        $account = Account::query()->create(['company_id' => $company->id, 'code' => '1201', 'name' => 'Customers', 'account_type' => 'asset']);
        $targetParty = CustomerSupplier::query()->create(['company_id' => $company->id, 'account_id' => $account->id, 'parent_account_id' => $account->id, 'name' => 'Customer', 'is_customer' => true, 'status' => 'active']);
        $owner = $this->admin('owner');
        $support = $this->admin('support');

        $this->actingAs($support)->get('/super-admin/data-migrations')->assertForbidden();
        $this->actingAs($owner)->get('/super-admin/data-migrations')->assertOk();

        $sourceSql = <<<'SQL'
INSERT INTO `customer` (`id_customer`, `name_customer`) VALUES (1, 'Customer');
INSERT INTO `buy` (`id_buy`, `date`, `id_import`, `id_type`, `id_stores`) VALUES (10, '2026-10-02', 2, 3, 5);
INSERT INTO `details_buy` (`id_details_buy`, `id_buy`, `id_categories`, `id_type`, `id_stores`, `kg`, `no`, `price`, `note`) VALUES (100, 10, 4, 3, 5, 1000, 1, 20, 'ok');
SQL;
        $source = UploadedFile::fake()->createWithContent('legacy.sql', $sourceSql);
        $this->actingAs($owner)->post('/super-admin/data-migrations', [
            'company_id' => $company->id,
            'documents_from' => '2026-10-01',
            'documents_to' => '2026-10-18',
            'opening_balance_as_of' => '2026-09-30',
            'mode' => 'draft',
            'source' => $source,
        ])->assertRedirect();

        $run = DataMigrationRun::query()->firstOrFail();
        $this->assertSame(DataMigrationRun::STATUS_UPLOADED, $run->status);
        Storage::disk('local')->assertExists($run->source_path);
        $this->assertDatabaseMissing('data_migration_records', ['run_id' => $run->id]);

        $this->actingAs($owner)->postJson("/super-admin/data-migrations/{$run->id}/analyze")
            ->assertOk()
            ->assertJsonPath('run.status', DataMigrationRun::STATUS_REVIEW)
            ->assertJsonPath('run.summary.master_counts.customers', 1)
            ->assertJsonPath('run.summary.mappings.customers.matched', 1);

        $this->actingAs($owner)->postJson("/super-admin/data-migrations/{$run->id}/analyze")->assertOk();
        $this->assertDatabaseCount('data_migration_records', 1);
        $this->assertDatabaseCount('data_migration_mappings', 1);

        $mapping = DataMigrationMapping::query()->firstOrFail();
        $foreignCompany = Company::query()->create(['name' => 'Foreign company', 'status' => 'active']);
        $foreignAccount = Account::query()->create(['company_id' => $foreignCompany->id, 'code' => '1201', 'name' => 'Customers', 'account_type' => 'asset']);
        $foreignParty = CustomerSupplier::query()->create(['company_id' => $foreignCompany->id, 'account_id' => $foreignAccount->id, 'parent_account_id' => $foreignAccount->id, 'name' => 'Customer', 'is_customer' => true, 'status' => 'active']);
        $this->actingAs($owner)->putJson("/super-admin/data-migrations/{$run->id}/mappings/{$mapping->id}", ['decision' => 'matched', 'target_id' => $foreignParty->id])->assertStatus(422);
        $this->actingAs($owner)->putJson("/super-admin/data-migrations/{$run->id}/mappings/{$mapping->id}", ['decision' => 'matched', 'target_id' => $targetParty->id])->assertOk();

        $parent = Account::query()->create(['company_id' => $company->id, 'code' => '1.2', 'name' => 'Current assets', 'account_type' => 'asset', 'is_system' => true, 'is_active' => true]);
        $createMapping = DataMigrationMapping::query()->create([
            'run_id' => $run->id, 'entity_type' => 'customer', 'source_table' => 'customer', 'source_key' => '2',
            'target_type' => 'CustomerSupplier', 'decision' => 'create_required', 'confidence' => 0,
            'payload' => ['source_name' => 'New customer', 'source_attributes' => ['phone' => '01000000000']],
        ]);
        $counts = app(MigrationMasterDataService::class)->createApproved($run, $owner);
        $this->assertSame(1, $counts['customers']);
        $this->assertSame('matched', $createMapping->fresh()->decision);
        $this->assertDatabaseHas('customer_suppliers', ['company_id' => $company->id, 'name' => 'New customer', 'is_customer' => 1]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'platform.data_migration_uploaded']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'platform.data_migration_analyzed']);
    }

    public function test_legacy_diameter_with_mm_suffix_is_created_as_numeric_value(): void
    {
        $company = Company::query()->create(['name' => 'Diameter company', 'status' => 'active']);
        $owner = $this->admin('owner');
        $run = DataMigrationRun::query()->create(['company_id' => $company->id, 'created_by' => $owner->id, 'source_name' => 'legacy.sql', 'source_disk' => 'local', 'source_path' => 'unused.sql', 'source_hash' => str_repeat('d', 64), 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30', 'mode' => 'draft', 'status' => DataMigrationRun::STATUS_REVIEW]);
        $mapping = DataMigrationMapping::query()->create(['run_id' => $run->id, 'entity_type' => 'category', 'source_table' => 'categories', 'source_key' => '7', 'target_type' => 'Diameter', 'decision' => 'create_required', 'confidence' => 0, 'payload' => ['source_value' => '٨مم']]);

        $counts = app(MigrationMasterDataService::class)->createApproved($run, $owner);

        $this->assertSame(1, $counts['diameters']);
        $this->assertDatabaseHas('diameters', ['company_id' => $company->id, 'millimeters' => 8]);
        $this->assertSame('matched', $mapping->fresh()->decision);
    }

    public function test_non_steel_product_can_be_created_without_a_diameter_mapping(): void
    {
        Storage::fake('local');
        $company = Company::query()->create(['name' => 'Non steel company', 'status' => 'active']);
        $owner = $this->admin('owner');
        ProductType::query()->create(['company_id' => $company->id, 'name' => 'مواد بناء', 'kind' => 'other', 'status' => 'active']);
        $sourceSql = <<<'SQL'
INSERT INTO `type_mm` (`id_type`, `name`) VALUES (3, 'مواد بناء');
INSERT INTO `categories` (`id_categories`, `categories`, `steel_or_other`) VALUES (10, 'سلك رباط', 2);
INSERT INTO `buy` (`id_buy`, `date`, `id_import`, `id_stores`, `id_type`) VALUES (1, '2026-10-02', 2, 5, 3);
INSERT INTO `details_buy` (`id_details_buy`, `id_buy`, `id_categories`, `id_type`, `kg`, `no`, `price`) VALUES (1, 1, 10, 3, 100, 1, 20);
SQL;
        $path = 'data-migrations/'.$company->id.'/non-steel.sql';
        Storage::disk('local')->put($path, $sourceSql);
        $run = DataMigrationRun::query()->create(['company_id' => $company->id, 'created_by' => $owner->id, 'source_name' => 'non-steel.sql', 'source_disk' => 'local', 'source_path' => $path, 'source_hash' => hash('sha256', $sourceSql), 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30', 'mode' => 'draft', 'status' => DataMigrationRun::STATUS_REVIEW]);

        $summary = app(MigrationMappingService::class)->suggest($run);

        $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'product')->firstOrFail();
        $category = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'category')->firstOrFail();
        $this->assertSame(1, $summary['products']['create_required']);
        $this->assertSame('create_required', $mapping->decision);
        $this->assertSame('excluded', $category->decision);
        $this->assertNull($mapping->payload['source_attributes']['unit']);
    }

    public function test_master_data_preflight_reports_missing_non_steel_unit_without_partial_writes(): void
    {
        $company = Company::query()->create(['name' => 'Master preflight company', 'status' => 'active']);
        $owner = $this->admin('owner');
        $run = DataMigrationRun::query()->create(['company_id' => $company->id, 'created_by' => $owner->id, 'source_name' => 'legacy.sql', 'source_disk' => 'local', 'source_path' => 'unused.sql', 'source_hash' => str_repeat('e', 64), 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30', 'mode' => 'draft', 'status' => DataMigrationRun::STATUS_REVIEW]);
        DataMigrationMapping::query()->insert([
            ['run_id' => $run->id, 'entity_type' => 'product_type', 'source_table' => 'type_mm', 'source_key' => '3', 'target_type' => 'ProductType', 'decision' => 'create_required', 'confidence' => 0, 'payload' => json_encode(['source_name' => 'سلك رباط', 'source_attributes' => ['kind' => 'other']], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()],
            ['run_id' => $run->id, 'entity_type' => 'category', 'source_table' => 'categories', 'source_key' => '10', 'target_type' => null, 'decision' => 'create_required', 'confidence' => 0, 'payload' => json_encode(['source_value' => 'سلك رباط'], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()],
            ['run_id' => $run->id, 'entity_type' => 'product', 'source_table' => 'legacy_product', 'source_key' => '3:10', 'target_type' => 'Product', 'decision' => 'create_required', 'confidence' => 0, 'payload' => json_encode(['source_name' => 'سلك رباط / سلك رباط', 'source_attributes' => ['source_type_id' => '3', 'source_category_id' => '10', 'diameter_value' => null, 'unit' => null]], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()],
        ]);

        try {
            app(MigrationMasterDataService::class)->createApproved($run, $owner);
            $this->fail('Expected master-data preflight to reject a product without a unit.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('choose a unit of measure', implode(' ', $exception->errors()['mapping'] ?? []));
        }

        $this->assertDatabaseMissing('product_types', ['company_id' => $company->id, 'name' => 'سلك رباط']);
        $this->assertSame('excluded', DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'category')->value('decision'));
    }

    public function test_payment_rows_are_staged_as_receipts_without_writing_accounting_data(): void
    {
        Storage::fake('local');
        $company = Company::query()->create(['name' => 'Payment staging company', 'status' => 'active']);
        $owner = $this->admin('owner');
        $sourceSql = <<<'SQL'
INSERT INTO `customer` (`id_customer`, `name_customer`) VALUES (1, 'Customer');
INSERT INTO `name_treasury` (`id_name_treasury`, `name_treasury`) VALUES (1, 'Main cashbox');
INSERT INTO `treasury` (`id_treasury`, `id_name_treasury`) VALUES (2, 1);
INSERT INTO `sales` (`id_sales`, `insert_date`, `id_customer`, `bayan`, `cash`, `id_cash`) VALUES (50, '2026-10-05', 1, 'دفعة من العميل', 1250, 2);
SQL;
        $source = UploadedFile::fake()->createWithContent('payments.sql', $sourceSql);

        $this->actingAs($owner)->post('/super-admin/data-migrations', ['company_id' => $company->id, 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30', 'mode' => 'draft', 'source' => $source])->assertRedirect();
        $run = DataMigrationRun::query()->firstOrFail();
        $this->actingAs($owner)->postJson("/super-admin/data-migrations/{$run->id}/analyze")->assertOk()->assertJsonPath('run.summary.documents.receipt_candidates', 1);

        $record = DataMigrationRecord::query()->where('entity_type', 'receipt_document')->firstOrFail();
        $this->assertSame(DataMigrationRecord::STATUS_READY, $record->status);
        $this->assertSame('1', $record->normalized_payload['cashbox_source_id']);
        $this->assertDatabaseMissing('receipts', ['company_id' => $company->id]);
    }

    public function test_approved_purchase_record_is_executed_once_with_journal_and_stock(): void
    {
        $company = Company::query()->create(['name' => 'Execution company', 'status' => 'active']);
        $owner = $this->admin('owner');
        app(AccountingFoundationService::class)->seedCompany($company, $owner);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'MAIN', 'name' => 'Main', 'status' => 'active']);
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH1', 'name' => 'Main warehouse', 'status' => 'active']);
        $supplierParent = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $supplier = app(AccountingService::class)->createParty($company, $owner, ['parent_account_id' => $supplierParent->id, 'name' => 'Imported supplier', 'is_customer' => false, 'is_supplier' => true]);
        $type = ProductType::query()->create(['company_id' => $company->id, 'name' => 'Imported steel', 'kind' => 'steel', 'status' => 'active']);
        $diameter = Diameter::query()->create(['company_id' => $company->id, 'millimeters' => 12]);
        $product = Product::query()->create(['company_id' => $company->id, 'product_type_id' => $type->id, 'diameter_id' => $diameter->id, 'sku' => 'LEGACY-1-1:1', 'name' => 'Imported steel 12 mm', 'unit' => 'ton', 'minimum_stock' => 0, 'status' => 'active']);
        $run = DataMigrationRun::query()->create(['company_id' => $company->id, 'created_by' => $owner->id, 'source_name' => 'legacy.sql', 'source_disk' => 'local', 'source_path' => 'unused.sql', 'source_hash' => str_repeat('a', 64), 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30', 'mode' => 'draft', 'status' => DataMigrationRun::STATUS_READY]);
        DataMigrationMapping::query()->insert([
            ['run_id' => $run->id, 'entity_type' => 'supplier', 'source_table' => 'import', 'source_key' => '7', 'target_type' => 'CustomerSupplier', 'target_id' => $supplier->id, 'decision' => 'matched', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['run_id' => $run->id, 'entity_type' => 'warehouse', 'source_table' => 'stores', 'source_key' => '2', 'target_type' => 'Warehouse', 'target_id' => $warehouse->id, 'decision' => 'matched', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['run_id' => $run->id, 'entity_type' => 'product', 'source_table' => 'legacy_product', 'source_key' => '3:4', 'target_type' => 'Product', 'target_id' => $product->id, 'decision' => 'matched', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DataMigrationRecord::query()->create(['run_id' => $run->id, 'entity_type' => 'purchase_document', 'source_table' => 'buy', 'source_id' => '10', 'source_date' => '2026-10-02', 'source_number' => 'legacy-buy-10', 'normalized_payload' => ['source_document_type' => 'purchase', 'supplier_source_id' => '7', 'warehouse_source_id' => '2', 'supplier_bill_number' => 'LEGACY-PB-10', 'date' => '2026-10-02', 'total_factory_weight' => 1, 'total_actual_weight' => 1, 'total_packages' => 1, 'lines' => [['source_type_id' => '3', 'source_category_id' => '4', 'factory_weight' => 1, 'actual_weight' => 1, 'packages' => 1, 'unit_price' => 100, 'notes' => 'legacy']], 'legacy_note' => 'Legacy purchase'], 'status' => DataMigrationRecord::STATUS_READY, 'target_type' => 'PurchaseBill', 'fingerprint' => str_repeat('b', 64)]);

        $counts = app(MigrationInvoiceExecutionService::class)->execute($run, $owner);
        $this->assertSame(1, $counts['created']);
        $this->assertDatabaseHas('purchase_bills', ['company_id' => $company->id, 'status' => 'approved', 'supplier_bill_number' => 'LEGACY-PB-10']);
        $this->assertDatabaseHas('journal_entries', ['company_id' => $company->id, 'source_type' => PurchaseBill::class]);
        $this->assertDatabaseHas('stock_movements', ['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'purchase_in']);
        $this->assertSame(DataMigrationRecord::STATUS_CREATED, DataMigrationRecord::query()->firstOrFail()->status);
    }

    public function test_opening_balances_post_party_and_stock_once_from_cutoff_snapshot(): void
    {
        Storage::fake('local');
        $company = Company::query()->create(['name' => 'Opening company', 'status' => 'active']);
        $owner = $this->admin('owner');
        app(AccountingFoundationService::class)->seedCompany($company, $owner);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'MAIN', 'name' => 'Main', 'status' => 'active']);
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH1', 'name' => 'Main warehouse', 'status' => 'active']);
        $supplierParent = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $supplier = app(AccountingService::class)->createParty($company, $owner, ['parent_account_id' => $supplierParent->id, 'name' => 'Opening supplier', 'is_customer' => false, 'is_supplier' => true]);
        $type = ProductType::query()->create(['company_id' => $company->id, 'name' => 'Opening steel', 'kind' => 'steel', 'status' => 'active']);
        $diameter = Diameter::query()->create(['company_id' => $company->id, 'millimeters' => 12]);
        $product = Product::query()->create(['company_id' => $company->id, 'product_type_id' => $type->id, 'diameter_id' => $diameter->id, 'sku' => 'OPENING-1', 'name' => 'Opening steel 12 mm', 'unit' => 'ton', 'minimum_stock' => 0, 'status' => 'active']);
        $sourceSql = <<<'SQL'
INSERT INTO `buy` (`id_buy`, `date`, `id_import`, `id_stores`, `bayan`, `exp`) VALUES (1, '2026-09-30', 7, 2, 'purchase', 0);
INSERT INTO `details_buy` (`id_details_buy`, `id_buy`, `id_categories`, `id_type`, `kg`, `price`) VALUES (10, 1, 4, 3, 1000, 100);
INSERT INTO `store` (`id_stoer`, `id_stores`, `id_details_buy`, `buy`, `date_add`) VALUES (1, 2, 10, '1000', '2026-09-30');
SQL;
        $path = 'data-migrations/'.$company->id.'/opening.sql';
        Storage::disk('local')->put($path, $sourceSql);
        $run = DataMigrationRun::query()->create(['company_id' => $company->id, 'created_by' => $owner->id, 'source_name' => 'opening.sql', 'source_disk' => 'local', 'source_path' => $path, 'source_hash' => hash('sha256', $sourceSql), 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30', 'mode' => 'draft', 'status' => DataMigrationRun::STATUS_REVIEW]);
        DataMigrationMapping::query()->insert([
            ['run_id' => $run->id, 'entity_type' => 'supplier', 'source_table' => 'import', 'source_key' => '7', 'target_type' => 'CustomerSupplier', 'target_id' => $supplier->id, 'decision' => 'matched', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['run_id' => $run->id, 'entity_type' => 'warehouse', 'source_table' => 'stores', 'source_key' => '2', 'target_type' => 'Warehouse', 'target_id' => $warehouse->id, 'decision' => 'matched', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['run_id' => $run->id, 'entity_type' => 'product', 'source_table' => 'legacy_product', 'source_key' => '3:4', 'target_type' => 'Product', 'target_id' => $product->id, 'decision' => 'matched', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = app(MigrationOpeningBalanceService::class)->post($run, $owner);
        $this->assertSame(1, $result['parties_posted']);
        $this->assertSame(1, $result['stock_items_posted']);
        $this->assertDatabaseHas('customer_suppliers', ['id' => $supplier->id, 'opening_balance' => 100, 'opening_balance_direction' => 'credit']);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 1, 'inventory_value' => 100]);
        $this->assertDatabaseHas('stock_movements', ['source_type' => DataMigrationRun::class, 'source_id' => $run->id, 'movement_type' => 'opening_balance']);
        $this->assertDatabaseHas('journal_entries', ['source_type' => DataMigrationRun::class, 'source_id' => $run->id]);
    }

    public function test_reanalysis_is_blocked_after_opening_balances_are_posted(): void
    {
        Storage::fake('local');
        $company = Company::query()->create(['name' => 'Locked migration company', 'status' => 'active']);
        $owner = $this->admin('owner');
        $path = 'data-migrations/'.$company->id.'/locked.sql';
        Storage::disk('local')->put($path, 'SELECT 1');
        $run = DataMigrationRun::query()->create([
            'company_id' => $company->id,
            'created_by' => $owner->id,
            'source_name' => 'locked.sql',
            'source_disk' => 'local',
            'source_path' => $path,
            'source_hash' => str_repeat('c', 64),
            'documents_from' => '2026-10-01',
            'documents_to' => '2026-10-18',
            'opening_balance_as_of' => '2026-09-30',
            'mode' => 'draft',
            'status' => DataMigrationRun::STATUS_REVIEW,
            'summary' => ['opening_balances' => ['parties_posted' => 1]],
        ]);

        $this->actingAs($owner)->postJson("/super-admin/data-migrations/{$run->id}/analyze")
            ->assertStatus(409);
        $this->assertSame(DataMigrationRun::STATUS_REVIEW, $run->fresh()->status);
    }
}
