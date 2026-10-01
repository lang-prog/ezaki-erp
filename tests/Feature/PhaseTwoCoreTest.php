<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\Diameter;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountingFoundationService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use App\Services\CompanyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseTwoCoreTest extends TestCase
{
    use RefreshDatabase;

    private array $permissions = [
        'dashboard.view', 'users.view', 'subscriptions.view',
        'branches.view', 'branches.create', 'branches.update', 'branches.archive',
        'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.archive', 'warehouses.transfer',
        'products.view', 'products.create', 'products.update', 'products.archive',
        'inventory.view', 'inventory.transfer', 'inventory.export',
        'parties.view', 'parties.create', 'parties.update',
        'accounting.view', 'accounting.create', 'accounting.post', 'accounting.reverse', 'accounting.close_period',
        'reports.view', 'reports.export',
    ];

    private function tenant(string $key, array $limits = []): array
    {
        $company = Company::query()->create(['name' => "Company {$key}", 'status' => 'active']);
        $plan = Plan::query()->create([
            'code' => "plan-{$key}", 'name' => "Plan {$key}", 'duration' => 'yearly',
            'price' => 100, 'limits' => $limits, 'is_active' => true,
        ]);
        Subscription::query()->create([
            'company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
        ]);
        app(AccountingFoundationService::class)->seedCompany($company);

        $user = User::query()->create([
            'name' => "Owner {$key}", 'email' => "owner-{$key}@example.test", 'password' => 'correct-horse-battery',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $permissions = collect($this->permissions)->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        $role = Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
        $registrar->setPermissionsTeamId(null);

        return compact('company', 'plan', 'user');
    }

    private function createBranch(User $user, string $code): array
    {
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/branches', [
            'code' => $code,
            'name' => "Branch {$code}",
        ])->assertCreated();
        $branch = Branch::query()->findOrFail($response->json('data.id'));

        return [$branch, $branch->warehouses()->firstOrFail()];
    }

    public function test_branch_creates_warehouse_and_branch_warehouse_quotas_are_enforced(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('branch-limit', ['branches' => 1, 'warehouses' => 1]);
        Sanctum::actingAs($user);
        $first = $this->postJson('/api/v1/branches', ['code' => 'HQ', 'name' => 'Head Office'])->assertCreated();
        $this->assertDatabaseHas('warehouses', [
            'company_id' => $company->id,
            'branch_id' => $first->json('data.id'),
            'code' => 'HQ-WH',
        ]);
        $this->postJson('/api/v1/branches', ['code' => 'SECOND', 'name' => 'Second'])->assertForbidden();
    }

    public function test_warehouse_quota_is_checked_separately_from_branch_quota(): void
    {
        ['user' => $user] = $this->tenant('warehouse-limit', ['branches' => 5, 'warehouses' => 1]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/branches', ['code' => 'WH1', 'name' => 'Warehouse One'])->assertCreated();
        $this->postJson('/api/v1/branches', ['code' => 'WH2', 'name' => 'Warehouse Two'])->assertForbidden();
    }

    public function test_tenant_cannot_list_or_edit_another_company_branch(): void
    {
        ['user' => $firstOwner] = $this->tenant('tenant-a');
        ['user' => $secondOwner] = $this->tenant('tenant-b');
        [$branch] = $this->createBranch($firstOwner, 'A1');

        Sanctum::actingAs($secondOwner);
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonMissing(['name' => 'Branch A1']);
        $this->putJson("/api/v1/branches/{$branch->id}", ['name' => 'Leaked'])->assertNotFound();
    }

    public function test_branch_archive_is_blocked_while_stock_remains(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('archive-stock');
        [$branch, $warehouse] = $this->createBranch($user, 'STOCK');
        $type = DB::table('product_types')->insertGetId(['company_id' => $company->id, 'name' => 'Rebar', 'kind' => 'steel', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $product = $this->postJson('/api/v1/products', [
            'warehouse_id' => $warehouse->id,
            'product_type_id' => $type,
            'sku' => 'RB-12', 'name' => 'Rebar 12', 'unit' => 'ton', 'opening_balance' => 2,
        ])->assertCreated();
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->json('data.id'), 'warehouse_id' => $warehouse->id, 'quantity' => 2]);

        $this->postJson("/api/v1/branches/{$branch->id}/archive")->assertStatus(409);
        $this->assertNull($branch->fresh()->archived_at);
    }

    public function test_zero_stock_branch_archive_retains_branch_and_warehouse_history(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('archive-empty');
        [$branch, $warehouse] = $this->createBranch($user, 'EMPTY');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/branches/{$branch->id}/archive")->assertOk();

        $this->assertNotNull($branch->fresh()->archived_at);
        $this->assertNotNull($warehouse->fresh()->archived_at);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'company_id' => $company->id]);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'company_id' => $company->id]);
    }

    public function test_stock_transfer_moves_quantity_between_company_warehouses(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('transfer');
        [, $source] = $this->createBranch($user, 'SRC');
        [, $destination] = $this->createBranch($user, 'DST');
        $type = DB::table('product_types')->insertGetId(['company_id' => $company->id, 'name' => 'Mesh', 'kind' => 'steel', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $product = $this->postJson('/api/v1/products', [
            'warehouse_id' => $source->id, 'product_type_id' => $type,
            'sku' => 'MESH-1', 'name' => 'Mesh', 'unit' => 'ton', 'opening_balance' => 10,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/transfers', [
            'from_warehouse_id' => $source->id, 'to_warehouse_id' => $destination->id,
            'transfer_number' => 'TR-0001', 'lines' => [['product_id' => $product, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_balances', ['company_id' => $company->id, 'warehouse_id' => $source->id, 'product_id' => $product, 'quantity' => 7]);
        $this->assertDatabaseHas('stock_balances', ['company_id' => $company->id, 'warehouse_id' => $destination->id, 'product_id' => $product, 'quantity' => 3]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/inventory/matrix')->assertOk()->assertJsonPath('total_quantity', 10);
        $this->getJson('/api/v1/inventory/matrix?warehouse_id='.$source->id)->assertOk()->assertJsonPath('total_quantity', 7);
        $this->getJson('/api/v1/inventory/matrix?warehouse_id='.$destination->id)->assertOk()->assertJsonPath('total_quantity', 3);
    }

    public function test_opening_balance_and_inventory_matrix_show_user_defined_diameters_and_totals(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('matrix');
        [, $warehouse] = $this->createBranch($user, 'MX');
        $type = DB::table('product_types')->insertGetId(['company_id' => $company->id, 'name' => 'Beshay', 'kind' => 'steel', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/diameters', ['millimeters' => 12])->assertCreated();
        $diameter =
            Diameter::query()->where('company_id', $company->id)->where('millimeters', 12)->firstOrFail();
        Sanctum::actingAs($user);
        $productId = $this->postJson('/api/v1/products', [
            'warehouse_id' => $warehouse->id, 'product_type_id' => $type, 'diameter_id' => $diameter->id,
            'sku' => 'BSH-12', 'name' => 'Beshay 12mm', 'unit' => 'ton', 'minimum_stock' => 1.5, 'opening_balance' => 8,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/products', [
            'warehouse_id' => $warehouse->id, 'product_type_id' => null, 'diameter_id' => null,
            'sku' => 'WIRE-01', 'name' => 'Annealed wire', 'unit' => 'kilogram', 'minimum_stock' => 25, 'opening_balance' => 0,
        ])->assertCreated();
        $response = $this->getJson('/api/v1/inventory/matrix')->assertOk();
        $response->assertJsonPath('total_quantity', 8);
        $response->assertJsonPath('column_totals.12', 8);
        $response->assertJsonFragment(['type' => 'Beshay']);
        $response->assertJsonFragment(['sku' => 'WIRE-01', 'name' => 'Annealed wire', 'quantity' => 0, 'unit' => 'kilogram']);
        $this->assertSame('1.500', Product::query()->findOrFail($productId)->minimum_stock);
        $this->assertDatabaseHas('stock_movements', ['company_id' => $company->id, 'product_id' => $productId, 'movement_type' => 'opening_balance', 'quantity_delta' => 8]);
        $this->assertSame([12.0], Diameter::query()->where('company_id', $company->id)->orderBy('millimeters')->pluck('millimeters')->map(fn ($value) => (float) $value)->all());
    }

    public function test_owner_can_add_type_and_diameter_without_a_product_sku(): void
    {
        ['user' => $user] = $this->tenant('master-data-actions');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/product-types', ['name' => 'حديد عز'])->assertCreated();
        $this->postJson('/api/v1/diameters', ['millimeters' => 10])->assertCreated();
        $this->actingAs($user)->get('/inventory')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Inventory')->where('diameter_columns.0', '10')->where('productTypes.0.name', 'حديد عز'));
        $this->getJson('/api/v1/inventory/matrix')->assertOk()->assertJsonPath('diameter_columns.0', '10')->assertJsonFragment(['type' => 'حديد عز']);
    }

    public function test_type_diameter_profile_supports_optional_opening_and_minimum_stock(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('type-diameter-profile');
        [, $warehouse] = $this->createBranch($user, 'PROFILE');
        Sanctum::actingAs($user);
        $type = $this->postJson('/api/v1/product-types', ['name' => 'Profile steel'])->assertCreated()->json('data.id');
        $diameter = $this->postJson('/api/v1/diameters', ['millimeters' => 16])->assertCreated()->json('data.id');
        $product = $this->postJson('/api/v1/inventory/type-diameter-profile', ['warehouse_id' => $warehouse->id, 'product_type_id' => $type, 'diameter_id' => $diameter, 'opening_balance' => 6, 'minimum_stock' => 2.5])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('products', ['id' => $product, 'company_id' => $company->id, 'minimum_stock' => 2.5]);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $warehouse->id, 'product_id' => $product, 'quantity' => 6]);
        $this->postJson('/api/v1/inventory/type-diameter-profile', ['warehouse_id' => $warehouse->id, 'product_type_id' => $type, 'diameter_id' => $diameter])->assertCreated();
        $this->assertSame(6.0, (float) DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('product_id', $product)->value('quantity'));
    }

    public function test_customer_supplier_uses_one_auto_coded_child_account(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('party-code');
        $parent = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/parties', [
            'name' => 'Both Customer And Supplier', 'parent_account_id' => $parent->id,
            'is_customer' => true, 'is_supplier' => true, 'opening_balance' => 100,
        ])->assertCreated();
        $party = CustomerSupplier::query()->findOrFail($response->json('data.id'));

        $this->assertSame($party->account_id, $party->account->id);
        $this->assertSame('1.2.1', $party->account->code);
        $this->assertTrue($party->is_customer);
        $this->assertTrue($party->is_supplier);
    }

    public function test_super_admin_company_provisioning_starts_with_empty_chart_until_owner_initializes_it(): void
    {
        $admin = User::query()->create(['name' => 'Setup Admin', 'email' => 'phase2-setup-admin@example.test', 'password' => 'correct-horse-battery', 'account_type' => 'super_admin', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'setup-plan', 'name' => 'Setup Plan', 'duration' => 'yearly', 'price' => 10, 'is_active' => true]);
        $company = app(CompanyProvisioningService::class)->createManually([
            'name' => 'Seeded Company', 'owner_name' => 'Setup Owner', 'owner_email' => 'phase2-setup-owner@example.test',
            'owner_password' => 'correct-horse-battery', 'notes' => null,
        ], $admin, $plan, null);

        $this->assertDatabaseMissing('accounts', ['company_id' => $company->id]);
        $this->assertDatabaseMissing('fiscal_periods', ['company_id' => $company->id]);
        $owner = User::query()->where('company_id', $company->id)->where('account_type', 'company')->firstOrFail();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/accounts/initialize')->assertOk();
        $this->assertDatabaseHas('accounts', ['company_id' => $company->id, 'code' => '1', 'name' => 'Assets']);
        $this->assertDatabaseHas('accounts', ['company_id' => $company->id, 'code' => '1.1', 'name' => 'Fixed assets']);
        $this->assertDatabaseHas('accounts', ['company_id' => $company->id, 'code' => '5.2', 'name' => 'Other revenue']);
        $this->assertSame([], Diameter::query()->where('company_id', $company->id)->orderBy('millimeters')->pluck('millimeters')->map(fn ($value) => (float) $value)->all());
        $this->assertDatabaseHas('fiscal_periods', ['company_id' => $company->id, 'status' => 'open']);
    }

    public function test_phase_two_company_pages_render_with_available_dashboard_metrics(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('pages');
        Sanctum::actingAs($user);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Dashboard')->has('metrics.stock_quantity'));
        $this->get('/branches')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Branches'));
        $this->get('/inventory')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Inventory')->has('diameter_columns'));
        $this->get('/parties')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Parties'));
        $this->get('/customers')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Parties')->where('kind', 'customer'));
        $this->get('/suppliers')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Parties')->where('kind', 'supplier'));
        $this->get('/customers/1')->assertNotFound();
        $this->get('/suppliers/1')->assertNotFound();
        $this->get('/accounting?counterparty_type=customer&party_id=1')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Accounting'));
        $this->get('/accounting')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Accounting'));
        Sanctum::actingAs($user);
        $cashbox = $this->postJson('/api/v1/cashboxes', ['name' => 'Main till'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('cashboxes', ['id' => $cashbox]);
        $this->actingAs($user)->get('/accounting')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Accounting'));
        $this->assertNotNull(Cashbox::query()->findOrFail($cashbox)->account);
        $this->assertDatabaseHas('accounts', ['company_id' => $company->id, 'code' => '1.2']);
    }

    public function test_receipts_and_supplier_vouchers_post_balanced_journals_and_closed_period_rejects_posting(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('payments');
        $parent = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $customer = app(AccountingService::class)->createParty($company, $user, ['name' => 'Customer', 'parent_account_id' => $parent->id, 'is_customer' => true, 'is_supplier' => false]);
        $supplier = app(AccountingService::class)->createParty($company, $user, ['name' => 'Supplier', 'parent_account_id' => $parent->id, 'is_customer' => false, 'is_supplier' => true]);
        Sanctum::actingAs($user);
        $cashbox = $this->postJson('/api/v1/cashboxes', ['name' => 'Main till'])->assertCreated()->json('data.id');
        $bank = $this->postJson('/api/v1/banks', ['name' => 'Company bank', 'account_number' => 'TEST-0001'])->assertCreated()->json('data.id');
        $receipt = $this->postJson('/api/v1/receipts', [
            'party_id' => $customer->id, 'cashbox_id' => $cashbox, 'receipt_date' => today()->toDateString(), 'amount' => 75,
        ])->assertCreated()->json('data');
        $voucher = $this->postJson('/api/v1/payment-vouchers', [
            'party_id' => $supplier->id, 'cashbox_id' => $cashbox, 'voucher_date' => today()->toDateString(), 'amount' => 25,
        ])->assertCreated()->json('data');

        foreach ([$receipt['journal_entry_id'], $voucher['journal_entry_id']] as $entryId) {
            $lines = DB::table('journal_lines')->where('journal_entry_id', $entryId);
            $this->assertSame((float) $lines->sum('debit'), (float) $lines->sum('credit'));
        }

        $this->postJson('/api/v1/receipts', [
            'party_id' => $customer->id, 'bank_id' => $bank, 'receipt_date' => today()->toDateString(), 'amount' => 5,
        ])->assertCreated();
        $this->getJson('/api/v1/reports/trial-balance')->assertOk();
        $this->getJson('/api/v1/reports/income-statement')->assertOk();
        $this->getJson('/api/v1/reports/balance-sheet')->assertOk();
        $this->getJson('/api/v1/reports/ledger')->assertOk();
        $reporting = app(AccountingReportService::class);
        $this->assertSame(6, $reporting->journal($company)['entries']->total());
        $this->assertSame(6, $reporting->ledger($company)['entries']->total());
        $this->getJson("/api/v1/accounts/{$customer->account_id}/statement")
            ->assertOk()
            ->assertJsonPath('lines.total', 2)
            ->assertJsonStructure(['opening_balance', 'lines' => ['data' => [['running_balance']]]]);
        $this->assertSame(2, $reporting->accountStatement($company, $customer->account)['lines']->total());
        $this->assertSame(1, $reporting->accountStatement($company, $supplier->account)['lines']->total());
        $this->assertSame(2, $reporting->accountStatement($company, Cashbox::query()->findOrFail($cashbox)->account)['lines']->total());

        $originalEntry = JournalEntry::query()->findOrFail($receipt['journal_entry_id']);
        $this->postJson("/api/v1/journals/{$originalEntry->id}/reverse", ['date' => today()->toDateString()])->assertCreated();
        $this->assertSame('reversed', $originalEntry->fresh()->status);
        $this->assertSame(4, JournalEntry::query()->where('company_id', $company->id)->count());
        $trialBalance = app(AccountingReportService::class)->trialBalance($company);
        $this->assertEquals($trialBalance['debit_total'], $trialBalance['credit_total']);

        $period = FiscalPeriod::query()->where('company_id', $company->id)->firstOrFail();
        $this->postJson("/api/v1/fiscal-periods/{$period->id}/close")->assertOk();
        $this->postJson('/api/v1/receipts', [
            'party_id' => $customer->id, 'cashbox_id' => $cashbox, 'receipt_date' => today()->toDateString(), 'amount' => 10,
        ])->assertStatus(409);
    }

    public function test_other_account_receipt_and_payment_appear_in_journal_ledger_and_account_statements(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('other-account-vouchers');
        Sanctum::actingAs($user);
        $cashboxId = $this->postJson('/api/v1/cashboxes', ['name' => 'Voucher till'])->assertCreated()->json('data.id');
        $cashbox = Cashbox::query()->findOrFail($cashboxId);
        $income = Account::query()->where('company_id', $company->id)->where('code', '5.2')->firstOrFail();
        $expense = Account::query()->where('company_id', $company->id)->where('code', '4.2')->firstOrFail();
        $receipt = $this->postJson('/api/v1/receipts', ['counterparty_type' => 'other', 'other_account_id' => (string) $income->id, 'cashbox_id' => (string) $cashboxId, 'receipt_date' => today()->toDateString(), 'amount' => 120])->assertCreated()->json('data');
        $payment = $this->postJson('/api/v1/payment-vouchers', ['counterparty_type' => 'other', 'other_account_id' => (string) $expense->id, 'cashbox_id' => (string) $cashboxId, 'voucher_date' => today()->toDateString(), 'amount' => 45])->assertCreated()->json('data');

        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $receipt['journal_entry_id'], 'account_id' => $income->id, 'credit' => 120]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $payment['journal_entry_id'], 'account_id' => $expense->id, 'debit' => 45]);
        $this->assertSame(4, app(AccountingReportService::class)->journal($company)['entries']->total());
        $this->assertSame(4, app(AccountingReportService::class)->ledger($company)['entries']->total());
        $this->assertSame(1, app(AccountingReportService::class)->accountStatement($company, $income)['lines']->total());
        $this->assertSame(1, app(AccountingReportService::class)->accountStatement($company, $expense)['lines']->total());
        $this->assertSame(2, app(AccountingReportService::class)->accountStatement($company, $cashbox->account)['lines']->total());
        $this->assertSame(4, app(AccountingReportService::class)->journal($company, today()->toDateString(), today()->toDateString())['entries']->total());
        $this->assertSame(0, app(AccountingReportService::class)->journal($company, from: today()->addDay()->toDateString(), to: today()->addDays(2)->toDateString())['entries']->total());
        $this->postJson('/api/v1/receipts', ['counterparty_type' => 'other', 'other_account_id' => (string) $cashbox->account_id, 'cashbox_id' => (string) $cashboxId, 'receipt_date' => today()->toDateString(), 'amount' => 10])->assertStatus(422);
        $currentAssets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $this->postJson('/api/v1/receipts', ['counterparty_type' => 'other', 'other_account_id' => (string) $currentAssets->id, 'cashbox_id' => (string) $cashboxId, 'receipt_date' => today()->toDateString(), 'amount' => 10])->assertStatus(422);
        $webJournal = $this->actingAs($user)->get('/reports/journal?from_date='.today()->toDateString().'&to_date='.today()->toDateString())->assertOk();
        $webJournal->assertInertia(fn ($page) => $page->where('from', today()->toDateString())->where('to', today()->toDateString())->has('entries.data', 4));
    }

    public function test_backfill_repairs_saved_posted_receipt_with_missing_journal_without_duplicate_voucher(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('legacy-voucher');
        $party = app(AccountingService::class)->createParty($company, $user, ['parent_account_id' => Account::query()->where('company_id', $company->id)->where('code', '1.2')->value('id'), 'name' => 'Legacy receipt customer', 'is_customer' => true, 'is_supplier' => false]);
        $cashbox = app(AccountingService::class)->createCashbox($company, $user, 'Legacy receipt cashbox');
        $receipt = Receipt::query()->create(['company_id' => $company->id, 'party_id' => $party->id, 'cashbox_id' => $cashbox->id, 'created_by' => $user->id, 'receipt_number' => 'LEGACY-1', 'receipt_date' => today()->toDateString(), 'amount' => 21, 'status' => 'posted']);

        $this->assertSame(1, app(AccountingService::class)->backfillUnjournaledVouchers($company, $user));
        $receipt->refresh();
        $this->assertNotNull($receipt->journal_entry_id);
        $this->assertDatabaseHas('journal_entries', ['id' => $receipt->journal_entry_id, 'source_type' => Receipt::class, 'source_id' => $receipt->id, 'status' => 'posted']);
        $this->assertSame(1, DB::table('receipts')->where('company_id', $company->id)->where('receipt_number', 'LEGACY-1')->count());
        $this->assertSame(2, app(AccountingReportService::class)->journal($company)['entries']->total());
        $this->assertSame('/accounting#receipt-'.$receipt->id, app(AccountingReportService::class)->journal($company)['entries']->first()->source_url);
        $this->assertSame(0, app(AccountingService::class)->backfillUnjournaledVouchers($company, $user));
    }

    public function test_fiscal_periods_cannot_overlap(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('periods');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/fiscal-periods', ['name' => '2027-H1', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-30'])->assertCreated();
        $this->postJson('/api/v1/fiscal-periods', ['name' => '2027-H1-overlap', 'starts_on' => '2027-06-30', 'ends_on' => '2027-12-31'])->assertStatus(409);
        $this->assertSame(2, FiscalPeriod::query()->where('company_id', $company->id)->count());
    }

    public function test_debtor_report_excludes_balances_below_fifty_egp(): void
    {
        ['company' => $company, 'user' => $user] = $this->tenant('debtor-threshold');
        $parent = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $accounting = app(AccountingService::class);
        $below = $accounting->createParty($company, $user, ['name' => 'Below threshold', 'parent_account_id' => $parent->id, 'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 49.99]);
        $at = $accounting->createParty($company, $user, ['name' => 'At threshold', 'parent_account_id' => $parent->id, 'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 50]);
        $moving = $accounting->createParty($company, $user, ['name' => 'Moving customer', 'parent_account_id' => $parent->id, 'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 0]);
        $cashbox = $accounting->createCashbox($company, $user, 'Debtor report cashbox');
        $accounting->postOperationalJournal($company, $user, today()->toDateString(), 'Debtor report movement', 'test', $moving->id, [
            ['account_id' => $moving->account_id, 'party_id' => $moving->id, 'debit' => 75, 'credit' => 0, 'description' => 'Outstanding receivable'],
            ['account_id' => $cashbox->account_id, 'party_id' => null, 'debit' => 0, 'credit' => 75, 'description' => 'Debtor report offset'],
        ]);
        $this->assertDatabaseHas('customer_suppliers', ['id' => $at->id, 'is_customer' => true, 'opening_balance' => 50]);
        $this->assertDatabaseHas('customer_suppliers', ['id' => $below->id, 'opening_balance' => 49.99]);
        $this->assertSame(1, DB::table('customer_suppliers')->where('company_id', $company->id)->where('is_customer', true)->where('status', 'active')->where('opening_balance', '>=', 50)->count());
        $this->assertSame(2, app(AccountingReportService::class)->debtors($company)['rows']->total());
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/reports/debtors')->assertOk()->assertJsonFragment(['name' => 'At threshold'])->assertJsonFragment(['name' => 'Moving customer'])->assertJsonMissing(['name' => 'Below threshold']);
        $this->getJson('/api/v1/reports/debtors?from_date='.today()->toDateString().'&to_date='.today()->toDateString())->assertOk()->assertJsonFragment(['name' => 'Moving customer']);
        $this->getJson('/api/v1/reports/debtors?from_date='.today()->addDay()->toDateString().'&to_date='.today()->addDays(2)->toDateString())
            ->assertOk()
            ->assertJsonFragment(['name' => 'Moving customer'])
            ->assertJsonPath('total_outstanding', 174.99);
    }

    public function test_inventory_identity_is_company_scoped_and_opening_is_idempotent_per_warehouse(): void
    {
        ['company' => $companyA, 'user' => $userA] = $this->tenant('inventory-hardening-a');
        ['company' => $companyB, 'user' => $userB] = $this->tenant('inventory-hardening-b');
        [, $warehouseA1] = $this->createBranch($userA, 'A1');
        [, $warehouseA2] = $this->createBranch($userA, 'A2');
        [, $warehouseB1] = $this->createBranch($userB, 'B1');
        Sanctum::actingAs($userA);
        $typeA = $this->postJson('/api/v1/product-types', ['name' => 'Shared type'])->assertCreated()->json('data.id');
        $diameterA = $this->postJson('/api/v1/diameters', ['millimeters' => 20])->assertCreated()->json('data.id');
        $payload = ['warehouse_id' => $warehouseA1->id, 'product_type_id' => $typeA, 'diameter_id' => $diameterA, 'opening_balance' => 6, 'minimum_stock' => 2];
        $productId = $this->postJson('/api/v1/inventory/type-diameter-profile', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/type-diameter-profile', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/inventory/type-diameter-profile', array_merge($payload, ['warehouse_id' => $warehouseA2->id, 'opening_balance' => 4, 'minimum_stock' => 5]))->assertCreated()->json('data.id');

        $this->assertSame($productId, $second);
        $this->assertSame(1, Product::query()->where('company_id', $companyA->id)->where('product_type_id', $typeA)->where('diameter_id', $diameterA)->count());
        $this->assertSame('TYPE-'.$typeA.'-DIA-'.$diameterA, Product::query()->findOrFail($productId)->sku);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $warehouseA1->id, 'product_id' => $productId, 'quantity' => 6, 'minimum_stock' => 2]);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $warehouseA2->id, 'product_id' => $productId, 'quantity' => 4, 'minimum_stock' => 5]);
        $this->assertSame(1, DB::table('stock_movements')->where('company_id', $companyA->id)->where('source_type', 'inventory_opening')->where('source_key', 'warehouse:'.$warehouseA1->id.':product:'.$productId)->count());

        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/inventory/type-diameter-profile', ['warehouse_id' => $warehouseA1->id, 'product_type_id' => $typeA, 'diameter_id' => $diameterA, 'opening_balance' => 1])->assertNotFound();
        $this->assertSame(0, Product::query()->where('company_id', $companyB->id)->count());
        $this->assertDatabaseMissing('stock_balances', ['warehouse_id' => $warehouseB1->id, 'product_id' => $productId]);
    }
}
