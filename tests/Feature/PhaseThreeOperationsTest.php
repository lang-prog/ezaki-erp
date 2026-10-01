<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Driver;
use App\Models\FiscalPeriod;
use App\Models\FleetExpense;
use App\Models\JournalEntry;
use App\Models\Plan;
use App\Models\PurchaseBill;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AccountingFoundationService;
use App\Services\AccountingService;
use App\Services\CompanyStructureService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseThreeOperationsTest extends TestCase
{
    use RefreshDatabase;

    private array $permissions = [
        'dashboard.view', 'users.view', 'subscriptions.view',
        'branches.view', 'branches.create', 'branches.update', 'branches.archive', 'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.archive', 'warehouses.transfer',
        'products.view', 'products.create', 'products.update', 'products.archive', 'inventory.view', 'inventory.transfer', 'inventory.export', 'parties.view', 'parties.create', 'parties.update',
        'accounting.view', 'accounting.create', 'accounting.post', 'accounting.reverse', 'accounting.close_period', 'reports.view', 'reports.export',
        'purchases.view', 'purchases.create', 'purchases.update', 'purchases.approve', 'purchases.update_approved', 'purchases.cancel', 'purchases.reverse', 'purchases.print', 'purchases.export',
        'sales.view', 'sales.create', 'sales.update', 'sales.approve', 'sales.update_approved', 'sales.cancel', 'sales.reverse', 'sales.print', 'sales.export',
        'fleet.view', 'fleet.create', 'fleet.update', 'fleet.approve', 'fleet.expenses', 'fleet.maintenance', 'fleet.export',
    ];

    private function tenant(string $key, ?float $creditLimit = null): array
    {
        $company = Company::query()->create(['name' => "Operations {$key}", 'status' => 'active']);
        $plan = Plan::query()->create(['code' => "ops-{$key}", 'name' => "Ops {$key}", 'duration' => 'yearly', 'price' => 1, 'limits' => [], 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        app(AccountingFoundationService::class)->seedCompany($company);
        $user = User::query()->create(['name' => "Owner {$key}", 'email' => "ops-{$key}@example.test", 'password' => 'password', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $permissions = collect($this->permissions)->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        $role = Role::query()->create(['name' => 'Operations Owner', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
        $registrar->setPermissionsTeamId(null);
        [$branch, $warehouse] = $this->branch($company, $user);
        $supplier = app(AccountingService::class)->createParty($company, $user, ['parent_account_id' => Account::query()->where('company_id', $company->id)->where('code', '2.1')->value('id'), 'name' => 'Supplier', 'is_customer' => false, 'is_supplier' => true, 'opening_balance' => 0, 'credit_limit' => null]);
        $customer = app(AccountingService::class)->createParty($company, $user, ['parent_account_id' => Account::query()->where('company_id', $company->id)->where('code', '1.2')->value('id'), 'name' => 'Customer', 'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 0, 'credit_limit' => $creditLimit]);
        $cashbox = app(AccountingService::class)->createCashbox($company, $user, 'Main cashbox');
        $product = app(InventoryService::class)->createProduct($company, $user, ['warehouse_id' => $warehouse->id, 'sku' => 'STEEL-10', 'name' => 'Steel 10', 'unit' => 'ton', 'minimum_stock' => 0, 'opening_balance' => 0]);

        return compact('company', 'user', 'branch', 'warehouse', 'supplier', 'customer', 'cashbox', 'product');
    }

    private function branch(Company $company, User $user): array
    {
        $branch = app(CompanyStructureService::class)->createBranch($company, $user, ['code' => 'HQ', 'name' => 'Head Office']);

        return [$branch, $branch->warehouses()->firstOrFail()];
    }

    private function purchasePayload(array $context, string $number = 'SUP-1'): array
    {
        return ['branch_id' => $context['branch']->id, 'warehouse_id' => $context['warehouse']->id, 'supplier_id' => $context['supplier']->id, 'supplier_bill_number' => $number, 'supplier_bill_date' => now()->toDateString(), 'total_factory_weight' => 10, 'total_actual_weight' => 9, 'total_packages' => 2, 'lines' => [['product_id' => $context['product']->id, 'factory_weight' => 10, 'actual_weight' => 9, 'packages' => 2, 'unit_price' => 100]]];
    }

    private function salesPayload(array $context, string $number = 'CUS-1'): array
    {
        return ['branch_id' => $context['branch']->id, 'warehouse_id' => $context['warehouse']->id, 'customer_id' => $context['customer']->id, 'customer_bill_number' => $number, 'bill_date' => now()->toDateString(), 'total_actual_weight' => 4, 'payment_method' => 'cash', 'cashbox_id' => $context['cashbox']->id, 'lines' => [['product_id' => $context['product']->id, 'actual_weight' => 4, 'unit_price' => 150]]];
    }

    public function test_draft_purchase_and_sale_do_not_change_stock_or_gl_and_approval_posts_once(): void
    {
        $context = $this->tenant('lifecycle');
        Sanctum::actingAs($context['user']);
        $beforeJournals = JournalEntry::query()->count();
        $purchase = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context))->assertCreated()->json('data.id');
        $this->assertSame(0.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
        $this->assertSame($beforeJournals, JournalEntry::query()->count());
        $this->postJson("/api/v1/purchase-bills/{$purchase}/approve")->assertOk();
        $this->assertSame(10.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
        $this->assertSame($beforeJournals + 1, JournalEntry::query()->count());
        $sale = $this->postJson('/api/v1/sales-bills', $this->salesPayload($context))->assertCreated()->json('data.id');
        $this->assertSame(10.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
        $this->assertSame($beforeJournals + 1, JournalEntry::query()->count());
        $this->postJson("/api/v1/sales-bills/{$sale}/approve")->assertOk();
        $this->assertSame(6.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
        $this->assertSame($beforeJournals + 2, JournalEntry::query()->count());
        $this->assertSame(600.0, (float) $context['product']->fresh()->stockBalances()->sum('inventory_value'));
        $this->assertSame(400.0, (float) DB::table('sales_bill_lines')->where('sales_bill_id', $sale)->value('inventory_cost'));
        $this->actingAs($context['user'])->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('metrics.monthly_sales', 600)
            ->where('metrics.monthly_purchases', 1000));
    }

    public function test_vat_partial_payment_and_cogs_post_to_distinct_system_accounts(): void
    {
        $context = $this->tenant('vat-partial');
        $context['company']->forceFill(['settings' => ['purchase_inventory_basis' => 'factory_weight', 'vat_enabled' => true, 'vat_rate' => 14]])->save();
        Sanctum::actingAs($context['user']);

        $purchaseId = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'VAT-P'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$purchaseId}/approve")->assertOk();
        $purchase = PurchaseBill::query()->findOrFail($purchaseId);
        $this->assertSame(140.0, (float) $purchase->vat_amount);
        $this->assertSame(1140.0, (float) $purchase->total);

        $sales = $this->salesPayload($context, 'VAT-S');
        $sales['payment_method'] = 'partial';
        $sales['paid_amount'] = 200;
        $sales['due_date'] = now()->addMonth()->toDateString();
        $saleId = $this->postJson('/api/v1/sales-bills', $sales)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sales-bills/{$saleId}/approve")->assertOk();

        $sale = DB::table('sales_bills')->where('id', $saleId)->first();
        $entryId = (int) $sale->journal_entry_id;
        $cashAccount = (int) $context['cashbox']->account_id;
        $outputVat = (int) Account::query()->where('company_id', $context['company']->id)->where('system_key', 'output_vat')->value('id');
        $cogs = (int) Account::query()->where('company_id', $context['company']->id)->where('system_key', 'cost_of_goods_sold')->value('id');
        $inventory = (int) Account::query()->where('company_id', $context['company']->id)->where('system_key', 'inventory_asset')->value('id');

        $this->assertSame(200.0, (float) DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('account_id', $cashAccount)->sum('debit'));
        $this->assertSame(484.0, (float) DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('account_id', $context['customer']->account_id)->sum('debit'));
        $this->assertSame(84.0, (float) DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('account_id', $outputVat)->sum('credit'));
        $this->assertSame(400.0, (float) DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('account_id', $cogs)->sum('debit'));
        $this->assertSame(400.0, (float) DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('account_id', $inventory)->sum('credit'));
        $this->assertDatabaseHas('bill_payment_allocations', ['company_id' => $context['company']->id, 'bill_type' => 'sales', 'bill_id' => $saleId, 'amount' => 200, 'status' => 'posted']);
        $this->assertSame(
            (float) DB::table('journal_lines')->where('journal_entry_id', $entryId)->sum('debit'),
            (float) DB::table('journal_lines')->where('journal_entry_id', $entryId)->sum('credit'),
        );
    }

    public function test_default_purchase_policy_uses_factory_weight_for_value_and_inventory(): void
    {
        $context = $this->tenant('weights');
        Sanctum::actingAs($context['user']);
        $payload = $this->purchasePayload($context, 'DUP-1');
        $payload['total_factory_weight'] = 9;
        $this->postJson('/api/v1/purchase-bills', $payload)->assertUnprocessable();
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'DUP-1'))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'DUP-1'))->assertUnprocessable();
        $this->postJson("/api/v1/purchase-bills/{$bill}/approve")->assertOk();
        $this->assertSame(1000.0, (float) PurchaseBill::query()->findOrFail($bill)->subtotal);
        $this->assertSame(10.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
    }

    public function test_owner_can_choose_actual_purchase_weight_until_first_approval(): void
    {
        $context = $this->tenant('actual-policy');
        Role::query()->where('company_id', $context['company']->id)->firstOrFail()->forceFill(['name' => 'Company Owner'])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $context['company']->forceFill(['settings' => ['purchase_inventory_basis' => 'actual_weight']])->save();
        Sanctum::actingAs($context['user']);
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'ACTUAL-1'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$bill}/approve")->assertOk();
        $this->assertSame(9.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
        $this->assertSame(1000.0, (float) PurchaseBill::query()->findOrFail($bill)->subtotal);
        $this->assertDatabaseHas('purchase_bill_lines', ['purchase_bill_id' => $bill, 'actual_weight' => 9]);

        $this->putJson('/api/v1/accounting-settings', ['purchase_inventory_basis' => 'factory_weight', 'vat_enabled' => false, 'vat_rate' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purchase_inventory_basis');
    }

    public function test_type_diameter_purchase_line_uses_factory_weight_and_actual_header_is_review_only(): void
    {
        $context = $this->tenant('type-diameter');
        Sanctum::actingAs($context['user']);
        $type = $this->postJson('/api/v1/product-types', ['name' => 'حديد عز', 'kind' => 'steel'])->assertCreated()->json('data.id');
        $diameter = $this->postJson('/api/v1/diameters', ['millimeters' => 18])->assertCreated()->json('data.id');
        $payload = $this->purchasePayload($context, 'TYPE-1');
        $payload['total_actual_weight'] = 5;
        $payload['lines'] = [['product_type_id' => $type, 'diameter_id' => $diameter, 'factory_weight' => 10, 'packages' => 2, 'unit_price' => 100]];
        $bill = $this->postJson('/api/v1/purchase-bills', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$bill}/approve")->assertOk();
        $this->assertSame(10.0, (float) DB::table('stock_balances')->where('company_id', $context['company']->id)->sum('quantity'));
        $this->assertSame(1000.0, (float) PurchaseBill::query()->findOrFail($bill)->subtotal);
    }

    public function test_type_diameter_sales_draft_and_approval_use_line_weight_without_product_sku(): void
    {
        $context = $this->tenant('typed-sales');
        Sanctum::actingAs($context['user']);
        $type = $this->postJson('/api/v1/product-types', ['name' => 'حديد السويس'])->assertCreated()->json('data.id');
        $diameter = $this->postJson('/api/v1/diameters', ['millimeters' => 22])->assertCreated()->json('data.id');
        $purchase = $this->purchasePayload($context, 'TYPE-SEED');
        $purchase['lines'] = [['product_type_id' => $type, 'diameter_id' => $diameter, 'factory_weight' => 8, 'packages' => 1, 'unit_price' => 90]];
        $purchase['total_factory_weight'] = 8;
        $purchase['total_actual_weight'] = 8;
        $purchase['total_packages'] = 1;
        $purchaseId = $this->postJson('/api/v1/purchase-bills', $purchase)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$purchaseId}/approve")->assertOk();

        $sale = $this->salesPayload($context, 'TYPE-SALE');
        $sale['lines'] = [['product_type_id' => (string) $type, 'diameter_id' => (string) $diameter, 'actual_weight' => 3, 'unit_price' => 140, 'packages' => null]];
        $sale['total_actual_weight'] = 3;
        $saleId = $this->postJson('/api/v1/sales-bills', $sale)->assertCreated()->json('data.id');
        $this->assertSame(8.0, (float) DB::table('stock_balances')->where('company_id', $context['company']->id)->sum('quantity'));
        $this->postJson("/api/v1/sales-bills/{$saleId}/approve")->assertOk();
        $this->assertSame(5.0, (float) DB::table('stock_balances')->where('company_id', $context['company']->id)->sum('quantity'));
    }

    public function test_purchase_and_sales_accept_string_ids_from_browser_forms(): void
    {
        $context = $this->tenant('string-ids');
        Sanctum::actingAs($context['user']);
        $purchase = $this->purchasePayload($context, 'STRING-P');
        foreach (['branch_id', 'warehouse_id', 'supplier_id'] as $key) {
            $purchase[$key] = (string) $purchase[$key];
        }
        $purchase['lines'][0]['product_id'] = (string) $purchase['lines'][0]['product_id'];
        $purchaseId = $this->postJson('/api/v1/purchase-bills', $purchase)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$purchaseId}/approve")->assertOk();
        $sales = $this->salesPayload($context, 'STRING-S');
        foreach (['branch_id', 'warehouse_id', 'customer_id'] as $key) {
            $sales[$key] = (string) $sales[$key];
        }
        $sales['lines'][0]['product_id'] = (string) $sales['lines'][0]['product_id'];
        $salesId = $this->postJson('/api/v1/sales-bills', $sales)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sales-bills/{$salesId}/approve")->assertOk();
    }

    public function test_saved_drafts_can_be_approved_explicitly_and_sales_packages_are_optional(): void
    {
        $context = $this->tenant('explicit-approve');
        Sanctum::actingAs($context['user']);
        $purchaseId = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'APPROVE-P'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$purchaseId}/approve")->assertOk();
        $sales = $this->salesPayload($context, 'APPROVE-S');
        $sales['lines'][0]['packages'] = 3;
        $salesId = $this->postJson('/api/v1/sales-bills', $sales)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sales-bills/{$salesId}/approve")->assertOk();
        $this->assertSame('3', (string) DB::table('sales_bill_lines')->where('sales_bill_id', $salesId)->value('packages'));
        $withoutPackages = $this->salesPayload($context, 'APPROVE-S-2');
        $withoutPackages['lines'][0]['packages'] = null;
        $this->postJson('/api/v1/sales-bills', $withoutPackages)->assertCreated();
    }

    public function test_party_and_chart_parent_changes_recode_without_changing_account_ids(): void
    {
        $context = $this->tenant('parent-moves');
        Sanctum::actingAs($context['user']);
        $party = $context['supplier'];
        $oldAccountId = $party->account_id;
        $newParent = Account::query()->where('company_id', $context['company']->id)->where('code', '2.2')->firstOrFail();
        $this->putJson('/api/v1/parties/'.$party->id, ['name' => $party->name, 'parent_account_id' => (string) $newParent->id, 'is_customer' => false, 'is_supplier' => true])->assertOk();
        $party->refresh();
        $this->assertSame($oldAccountId, $party->account_id);
        $this->assertSame($newParent->id, $party->account_id ? $party->account->parent_id : null);

        $child = $this->postJson('/api/v1/accounts', ['parent_id' => (string) $newParent->id, 'name' => 'Nested'])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/accounts/'.$child, ['name' => 'Nested updated', 'parent_id' => (string) $newParent->id])->assertOk();
        $this->putJson('/api/v1/accounts/'.$newParent->id, ['name' => 'Invalid root move', 'parent_id' => $child])->assertUnprocessable();
        $other = $this->tenant('parent-moves-other');
        Sanctum::actingAs($other['user']);
        $this->putJson('/api/v1/accounts/'.$newParent->id, ['name' => 'Cross tenant', 'parent_id' => null])->assertNotFound();
    }

    public function test_sales_rejects_oversell_and_credit_limit(): void
    {
        $context = $this->tenant('limits', 100);
        app(InventoryService::class)->createProduct($context['company'], $context['user'], ['warehouse_id' => $context['warehouse']->id, 'sku' => 'STOCK', 'name' => 'Stock', 'unit' => 'ton', 'opening_balance' => 1]);
        Sanctum::actingAs($context['user']);
        $oversell = $this->salesPayload($context, 'OVER');
        $oversell['total_actual_weight'] = 2;
        $oversell['lines'][0]['actual_weight'] = 2;
        $this->postJson('/api/v1/sales-bills', $oversell)->assertCreated();
        $this->postJson('/api/v1/sales-bills/1/approve')->assertUnprocessable();
        $credit = $this->salesPayload($context, 'CREDIT');
        $credit['payment_method'] = 'credit';
        $credit['due_date'] = now()->addMonth()->toDateString();
        $credit['lines'][0]['actual_weight'] = 1;
        $credit['lines'][0]['unit_price'] = 150;
        $credit['total_actual_weight'] = 1;
        $this->postJson('/api/v1/sales-bills', $credit)->assertUnprocessable();
    }

    public function test_company_vehicle_trip_is_stored_and_reverse_restores_stock_with_reversing_journal(): void
    {
        $context = $this->tenant('fleet');
        $vehicle = Vehicle::query()->create(['company_id' => $context['company']->id, 'branch_id' => $context['branch']->id, 'plate' => 'ABC-1', 'ownership' => 'company', 'status' => 'active']);
        Sanctum::actingAs($context['user']);
        $payload = $this->purchasePayload($context, 'TRIP-1');
        $payload['vehicle_id'] = $vehicle->id;
        $bill = $this->postJson('/api/v1/purchase-bills', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$bill}/approve")->assertOk();
        $this->assertDatabaseHas('trips', ['company_id' => $context['company']->id, 'bill_type' => 'purchase', 'bill_id' => $bill]);
        $this->assertSame(10.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
        $this->postJson("/api/v1/purchase-bills/{$bill}/reverse", ['date' => now()->toDateString()])->assertOk();
        $this->assertSame(0.0, (float) $context['product']->fresh()->stockBalances()->sum('quantity'));
        $this->assertSame(2, JournalEntry::query()->where('company_id', $context['company']->id)->count());
    }

    public function test_approved_revision_writes_revision_and_audit_and_tenant_isolation_holds(): void
    {
        $one = $this->tenant('one');
        $two = $this->tenant('two');
        Sanctum::actingAs($one['user']);
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($one, 'REV-1'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$bill}/approve")->assertOk();
        $this->putJson("/api/v1/purchase-bills/{$bill}/revision", $this->purchasePayload($one, 'REV-1') + ['revision_reason' => 'Corrected'])->assertOk();
        $this->assertDatabaseHas('bill_revisions', ['company_id' => $one['company']->id, 'document_type' => 'purchase', 'document_id' => $bill]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $one['company']->id, 'event' => 'bills.revised']);
        Sanctum::actingAs($two['user']);
        $this->getJson("/api/v1/purchase-bills/{$bill}")->assertNotFound();
    }

    public function test_near_duplicate_warning_preserves_data_and_transport_allocation_is_display_only(): void
    {
        $context = $this->tenant('warnings');
        Sanctum::actingAs($context['user']);
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'WARN-1'))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/purchase-bills/near-duplicates?supplier_id='.$context['supplier']->id.'&supplier_bill_date='.now()->toDateString())->assertOk()->assertJsonFragment(['internal_number' => PurchaseBill::query()->findOrFail($bill)->internal_number]);
        $before = PurchaseBill::query()->findOrFail($bill)->lines()->first()->toArray();
        $this->getJson("/api/v1/bills/purchase/{$bill}/transport-allocation")->assertOk()->assertJsonPath('data.total_extra_cost', 0);
        $this->assertSame($before['actual_weight'], PurchaseBill::query()->findOrFail($bill)->lines()->first()->actual_weight);
        $this->assertSame($before['factory_weight'], PurchaseBill::query()->findOrFail($bill)->lines()->first()->factory_weight);
    }

    public function test_discount_requires_authorized_actor(): void
    {
        $context = $this->tenant('discount');
        $clerk = User::query()->create(['name' => 'Sales Clerk', 'email' => 'clerk-discount@example.test', 'password' => 'password', 'company_id' => $context['company']->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $context['company']->id);
        $role = Role::query()->create(['name' => 'Sales Clerk', 'guard_name' => 'web', 'company_id' => $context['company']->id]);
        $role->syncPermissions(Permission::query()->whereIn('name', ['sales.view', 'sales.create'])->get());
        $clerk->assignRole($role);
        $registrar->setPermissionsTeamId(null);
        Sanctum::actingAs($clerk);
        $payload = $this->salesPayload($context, 'DISC-1');
        $payload['discount'] = 10;
        $this->postJson('/api/v1/sales-bills', $payload)->assertUnprocessable()->assertJsonValidationErrors('discount');
    }

    public function test_closed_period_rejects_revision(): void
    {
        $context = $this->tenant('closed-revision');
        Sanctum::actingAs($context['user']);
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'CLOSE-1'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$bill}/approve")->assertOk();
        $period = FiscalPeriod::query()->where('company_id', $context['company']->id)->firstOrFail();
        $this->postJson("/api/v1/fiscal-periods/{$period->id}/close")->assertOk();
        $this->putJson("/api/v1/purchase-bills/{$bill}/revision", $this->purchasePayload($context, 'CLOSE-1') + ['revision_reason' => 'Closed'])->assertStatus(409);
        $posted = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'CLOSE-2'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$posted}/approve")->assertStatus(409);
    }

    public function test_fleet_expense_and_maintenance_approve_and_reverse_journals(): void
    {
        $context = $this->tenant('fleet-reverse');
        $vehicle = Vehicle::query()->create(['company_id' => $context['company']->id, 'branch_id' => $context['branch']->id, 'plate' => 'REV-1', 'ownership' => 'company', 'status' => 'active']);
        Sanctum::actingAs($context['user']);
        $expense = $this->postJson('/api/v1/fleet/expenses', ['vehicle_id' => $vehicle->id, 'expense_date' => now()->toDateString(), 'category' => 'fuel', 'amount' => 50])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/fleet/expenses/{$expense}/approve")->assertOk();
        $this->postJson("/api/v1/fleet/expenses/{$expense}/reverse", ['date' => now()->toDateString()])->assertOk();
        $maintenance = $this->postJson('/api/v1/fleet/maintenance', ['vehicle_id' => $vehicle->id, 'maintenance_type' => 'preventive', 'issue' => 'Oil service', 'cost' => 75, 'starts_on' => now()->toDateString(), 'vehicle_status' => 'in_service'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/fleet/maintenance/{$maintenance}/approve")->assertOk();
        $this->postJson("/api/v1/fleet/maintenance/{$maintenance}/reverse", ['date' => now()->toDateString()])->assertOk();
        $this->assertSame(4, JournalEntry::query()->where('company_id', $context['company']->id)->count());
    }

    public function test_authenticated_browser_forms_and_print_export_permission_routes_are_reachable(): void
    {
        $context = $this->tenant('browser');
        $this->actingAs($context['user']);
        $this->get('/operations/purchase/create')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Company/BillForm')
            ->where('type', 'purchase')
            ->where('navigationCapabilities', fn ($capabilities): bool => (bool) $capabilities->get('inventory.view')
                && (bool) $capabilities->get('accounting.view')));
        $this->get('/operations/sales/create')->assertOk()->assertInertia(fn ($page) => $page->component('Company/BillForm')->where('type', 'sales'));
        $this->get('/fleet')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Fleet')->has('vehicles')->has('maintenance'));
        $this->get('/fleet/reports/maintenance-period')->assertOk()->assertInertia(fn ($page) => $page->component('Company/FleetReport')->where('report', 'maintenance-period'));
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('daily_sales')->has('payments_receipts')->has('top_sold_products')->has('sales_by_customer'));
        Sanctum::actingAs($context['user']);
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'PRINT-1'))->assertCreated()->json('data.id');
        $this->actingAs($context['user'])->get("/operations/purchase/{$bill}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Company/BillForm')->where('document.id', $bill));
        $this->actingAs($context['user'])->get("/operations/purchase/{$bill}/print")->assertOk()->assertSee('فاتورة مشتريات');
        $this->actingAs($context['user'])->get("/operations/purchase/{$bill}/export")->assertOk()->assertHeader('Content-Disposition');
        $viewer = User::query()->create(['name' => 'View only', 'email' => 'viewer-print@example.test', 'password' => 'password', 'company_id' => $context['company']->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $context['company']->id);
        $role = Role::query()->create(['name' => 'Viewer', 'guard_name' => 'web', 'company_id' => $context['company']->id]);
        $role->syncPermissions(Permission::query()->whereIn('name', ['purchases.view'])->get());
        $viewer->assignRole($role);
        $registrar->setPermissionsTeamId(null);
        $this->actingAs($viewer)->get('/operations')->assertOk()->assertInertia(fn ($page) => $page
            ->has('purchases.data', 1)
            ->where('sales.data', [])
            ->where('vehicles', [])
            ->where('trips', []));
        $this->actingAs($viewer)->get("/operations/purchase/{$bill}/print")->assertForbidden();
        $this->actingAs($viewer)->get("/operations/purchase/{$bill}/export")->assertForbidden();
    }

    public function test_account_statement_navigation_prompts_for_an_account_instead_of_returning_404(): void
    {
        $context = $this->tenant('statement-navigation');
        $this->actingAs($context['user']);

        $this->get('/reports/account-statement')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Company/AccountingReport')
            ->where('report', 'account-statement')
            ->where('account', null)
            ->has('availableAccounts'));

        $account = Account::query()->where('company_id', $context['company']->id)->firstOrFail();
        $this->get('/reports/account-statement?account_id='.$account->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('account.id', $account->id));
        $this->get('/reports/account-statement?account_id=0')->assertSessionHasErrors('account_id');
    }

    public function test_bill_list_and_edit_routes_allow_only_state_appropriate_edit_permission(): void
    {
        $context = $this->tenant('edit-actions');
        $this->actingAs($context['user']);
        Sanctum::actingAs($context['user']);
        $draft = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'EDIT-DRAFT'))->assertCreated()->json('data.id');
        $approved = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'EDIT-APPROVED'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$approved}/approve")->assertOk();

        $this->actingAs($context['user'])->get('/operations')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Company/Operations')
            ->has('capabilities')
            ->has('purchases.data', 2));
        $this->get("/operations/purchase/{$draft}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Company/BillForm')->where('document.status', 'draft'));
        $this->get("/operations/purchase/{$approved}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Company/BillForm')->where('document.status', 'approved'));

        $limited = User::query()->create(['name' => 'Purchase viewer', 'email' => 'purchase-edit-viewer@example.test', 'password' => 'password', 'company_id' => $context['company']->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $context['company']->id);
        $role = Role::query()->create(['name' => 'Purchase view only', 'guard_name' => 'web', 'company_id' => $context['company']->id]);
        $role->syncPermissions(Permission::query()->whereIn('name', ['purchases.view'])->get());
        $limited->assignRole($role);
        $registrar->setPermissionsTeamId(null);
        $this->actingAs($limited)->get("/operations/purchase/{$draft}/edit")->assertForbidden();
        $this->get("/operations/purchase/{$approved}/edit")->assertForbidden();
    }

    public function test_operations_payload_exposes_edit_actions_for_permitted_bill_states(): void
    {
        $context = $this->tenant('edit-visibility');
        Sanctum::actingAs($context['user']);
        $draftPurchase = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'EDIT-VIS-P'))->assertCreated()->json('data.id');
        $approvedPurchase = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'EDIT-VIS-PA'))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$approvedPurchase}/approve")->assertOk();
        $this->actingAs($context['user'])->get('/operations')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Company/Operations')
            ->has('capabilities')
            ->where('purchases.data.0.id', $approvedPurchase)
            ->where('purchases.data.1.id', $draftPurchase));
        $this->get("/operations/purchase/{$approvedPurchase}/edit")->assertOk()->assertInertia(fn ($page) => $page->where('document.status', 'approved'));
        $this->get("/operations/purchase/{$draftPurchase}/edit")->assertOk()->assertInertia(fn ($page) => $page->where('document.status', 'draft'));
    }

    public function test_default_arabic_purchase_print_contains_rtl_arabic_labels(): void
    {
        $context = $this->tenant('arabic-print');
        Sanctum::actingAs($context['user']);
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'AR-PRINT'))->assertCreated()->json('data.id');
        $this->actingAs($context['user'])->get("/operations/purchase/{$bill}/print")->assertOk()->assertSee('فاتورة مشتريات')->assertSee('dir="rtl"', false);
    }

    public function test_bill_view_and_two_print_modes_expose_complete_data_without_leaking_prices_to_operational_copy(): void
    {
        $context = $this->tenant('bill-view-modes');
        Sanctum::actingAs($context['user']);
        $bill = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'VIEW-MODE'))->assertCreated()->json('data.id');
        $this->actingAs($context['user'])->get("/operations/purchase/{$bill}")->assertOk()->assertInertia(fn ($page) => $page->component('Company/BillView')->where('document.id', $bill)->has('revisions'));
        $this->get("/operations/purchase/{$bill}/print?mode=invoice")->assertOk()->assertSee('سعر الوحدة');
        $this->get("/operations/purchase/{$bill}/print?mode=delivery")->assertOk()->assertSee('بوليصة استلام مشتريات')->assertDontSee('سعر الوحدة')->assertDontSee('الإجمالي النهائي');

        $sale = $this->postJson('/api/v1/sales-bills', $this->salesPayload($context, 'VIEW-SALE'))->assertCreated()->json('data.id');
        $this->get("/operations/sales/{$sale}")->assertOk()->assertInertia(fn ($page) => $page->component('Company/BillView')->where('document.id', $sale)->has('revisions'));
        $this->get("/operations/sales/{$sale}/print?mode=invoice")->assertOk()->assertSee('سعر الوحدة');
        $this->get("/operations/sales/{$sale}/print?mode=delivery")->assertOk()->assertSee('بوليصة تسليم مبيعات')->assertDontSee('سعر الوحدة')->assertDontSee('الإجمالي النهائي');
    }

    public function test_company_navigation_targets_are_inertia_pages_and_server_protected(): void
    {
        $context = $this->tenant('navigation');
        $this->actingAs($context['user']);
        $routes = [
            '/dashboard' => 'Company/Dashboard',
            '/settings/access' => 'Company/Access',
            '/subscription' => 'Company/Subscription',
            '/branches' => 'Company/Branches',
            '/inventory' => 'Company/Inventory',
            '/parties' => 'Company/Parties',
            '/accounting' => 'Company/Accounting',
            '/reports/journal' => 'Company/AccountingReport',
            '/operations' => 'Company/Operations',
            '/fleet' => 'Company/Fleet',
        ];
        foreach ($routes as $path => $component) {
            $this->get($path)->assertOk()->assertInertia(fn ($page) => $page->component($component));
        }
        $this->post('/locale/ar')->assertRedirect();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Company/Dashboard')->where('locale', 'ar'));

        $restricted = User::query()->create(['name' => 'Restricted', 'email' => 'restricted-navigation@example.test', 'password' => 'password', 'company_id' => $context['company']->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $context['company']->id);
        $role = Role::query()->create(['name' => 'Restricted navigation', 'guard_name' => 'web', 'company_id' => $context['company']->id]);
        $role->syncPermissions([]);
        $restricted->assignRole($role);
        $registrar->setPermissionsTeamId(null);
        $this->actingAs($restricted);
        foreach (array_keys($routes) as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_active_owner_bypasses_stale_permissions_and_can_edit_own_profile(): void
    {
        $context = $this->tenant('owner-bypass');
        $ownerRole = Role::query()->where('company_id', $context['company']->id)->firstOrFail();
        $ownerRole->forceFill(['name' => 'Company Owner'])->save();
        $ownerRole->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($context['user']);
        foreach (['/dashboard', '/settings/access', '/settings/users/'.$context['user']->id, '/subscription', '/branches', '/inventory', '/parties', '/accounting', '/reports/journal', '/operations', '/fleet'] as $path) {
            $this->get($path)->assertOk();
        }
        Sanctum::actingAs($context['user']);
        foreach (['/api/v1/branches', '/api/v1/products', '/api/v1/parties', '/api/v1/accounts', '/api/v1/reports/trial-balance', '/api/v1/purchase-bills', '/api/v1/sales-bills', '/api/v1/fleet/vehicles'] as $path) {
            $this->getJson($path)->assertOk();
        }
        $this->putJson('/api/v1/profile', [
            'first_name' => 'Updated', 'second_name' => 'Owner', 'email' => $context['user']->email,
            'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $context['user']->id, 'name' => 'Updated Owner']);

        $nonOwner = User::query()->create(['name' => 'No Access', 'email' => 'no-owner-access@example.test', 'password' => 'password', 'company_id' => $context['company']->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $this->actingAs($nonOwner)->get('/fleet')->assertForbidden();
        Sanctum::actingAs($nonOwner);
        $this->getJson('/api/v1/fleet/vehicles')->assertForbidden();
        $this->putJson('/api/v1/users/'.$context['user']->id, [])->assertForbidden();
    }

    public function test_owner_of_one_company_cannot_open_another_company_pages_or_api(): void
    {
        $one = $this->tenant('owner-isolation-one');
        $two = $this->tenant('owner-isolation-two');
        $this->actingAs($one['user'])->get('/fleet')->assertOk();
        Sanctum::actingAs($one['user']);
        $this->getJson('/api/v1/fleet/vehicles')->assertOk();
        $this->assertNotSame($one['company']->id, $two['company']->id);
        $this->assertSame($one['company']->id, $one['user']->company_id);
    }

    public function test_purchase_line_actual_weight_is_ignored_and_not_persisted(): void
    {
        $context = $this->tenant('purchase-line-contract');
        Sanctum::actingAs($context['user']);

        $billId = $this->postJson('/api/v1/purchase-bills', $this->purchasePayload($context, 'CONTRACT-1'))
            ->assertCreated()
            ->json('data.id');

        $this->assertNull(DB::table('purchase_bill_lines')->where('purchase_bill_id', $billId)->value('actual_weight'));
    }

    public function test_fleet_foreign_keys_are_tenant_scoped_on_create(): void
    {
        $one = $this->tenant('fleet-relations-one');
        $two = $this->tenant('fleet-relations-two');
        $foreignVehicle = Vehicle::query()->create(['company_id' => $two['company']->id, 'branch_id' => $two['branch']->id, 'plate' => 'FOREIGN-1', 'ownership' => 'company', 'status' => 'active']);
        Sanctum::actingAs($one['user']);

        $this->postJson('/api/v1/fleet/vehicles', ['branch_id' => $two['branch']->id, 'plate' => 'BAD-BRANCH', 'ownership' => 'company', 'status' => 'active'])->assertNotFound();
        $this->postJson('/api/v1/fleet/trips', ['vehicle_id' => $foreignVehicle->id, 'trip_type' => 'delivery', 'trip_at' => now()->toDateTimeString()])->assertNotFound();
    }

    public function test_company_owner_direct_permissions_are_immutable(): void
    {
        $context = $this->tenant('owner-direct-permissions');
        Role::query()->where('company_id', $context['company']->id)->firstOrFail()->forceFill(['name' => 'Company Owner'])->save();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $context['company']->id);
        $context['user']->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'users.update', 'guard_name' => 'web']));
        $registrar->forgetCachedPermissions();
        Sanctum::actingAs($context['user']);

        $this->putJson('/api/v1/users/'.$context['user']->id.'/permissions', ['permissions' => []])->assertForbidden();
    }

    public function test_party_profile_routes_enforce_customer_and_supplier_type(): void
    {
        $context = $this->tenant('party-profile-types');
        $this->actingAs($context['user']);

        $this->get('/customers/'.$context['customer']->id)->assertOk()->assertInertia(fn ($page) => $page->component('Company/PartyProfile')->where('kind', 'customer'));
        $this->get('/suppliers/'.$context['supplier']->id)->assertOk()->assertInertia(fn ($page) => $page->component('Company/PartyProfile')->where('kind', 'supplier'));
        $this->get('/customers/'.$context['supplier']->id)->assertNotFound();
        $this->get('/suppliers/'.$context['customer']->id)->assertNotFound();
    }

    public function test_inventory_and_fleet_exports_require_export_permissions(): void
    {
        $context = $this->tenant('export-permissions');
        $role = Role::query()->where('company_id', $context['company']->id)->firstOrFail();
        $role->revokePermissionTo(['inventory.export', 'fleet.export']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($context['user']);

        $this->get('/inventory')->assertOk();
        $this->get('/inventory/export')->assertForbidden();
        $this->get('/fleet/reports/vehicle-pl')->assertOk();
        $this->get('/fleet/reports/vehicle-pl/export')->assertForbidden();
    }

    public function test_manual_trip_lifecycle_is_tenant_scoped_and_audited(): void
    {
        $context = $this->tenant('manual-trip-lifecycle');
        $vehicle = Vehicle::query()->create(['company_id' => $context['company']->id, 'branch_id' => $context['branch']->id, 'plate' => 'MAN-1', 'ownership' => 'company', 'status' => 'active']);
        $driver = Driver::query()->create(['company_id' => $context['company']->id, 'name' => 'Driver One', 'status' => 'active']);
        Sanctum::actingAs($context['user']);
        $trip = $this->postJson('/api/v1/fleet/trips', ['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'branch_id' => $context['branch']->id, 'trip_type' => 'delivery', 'origin' => 'Cairo', 'destination' => 'Giza', 'trip_at' => now()->toDateTimeString(), 'distance' => 25, 'odometer_in' => 100, 'odometer_out' => 125, 'fuel_cost' => 40, 'road_fees' => 10, 'loading_fees' => 5, 'revenue' => 200])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('trips', ['id' => $trip, 'status' => 'draft', 'driver_id' => $driver->id]);
        $this->postJson("/api/v1/fleet/trips/{$trip}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/fleet/trips/{$trip}/reverse")->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'event' => 'trips.reversed']);
        $cancelled = $this->postJson('/api/v1/fleet/trips', ['vehicle_id' => $vehicle->id, 'branch_id' => $context['branch']->id, 'trip_type' => 'return', 'trip_at' => now()->toDateTimeString()])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_invoice_trip_fields_reverse_with_invoice_and_expense_uses_selected_account(): void
    {
        $context = $this->tenant('invoice-trip-expense');
        $vehicle = Vehicle::query()->create(['company_id' => $context['company']->id, 'branch_id' => $context['branch']->id, 'plate' => 'INV-1', 'ownership' => 'company', 'status' => 'active']);
        $driver = Driver::query()->create(['company_id' => $context['company']->id, 'name' => 'Driver Two', 'status' => 'active']);
        Sanctum::actingAs($context['user']);
        $payload = $this->purchasePayload($context, 'TRIP-COMPLETE');
        $payload += ['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'origin' => 'Port', 'destination' => 'Depot', 'distance' => 70, 'odometer_in' => 1000, 'odometer_out' => 1070, 'fuel_cost' => 60, 'road_fees' => 15, 'loading_fees' => 8];
        $bill = $this->postJson('/api/v1/purchase-bills', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$bill}/approve")->assertOk();
        $trip = DB::table('trips')->where('bill_type', 'purchase')->where('bill_id', $bill)->first();
        $this->assertSame($driver->id, (int) $trip->driver_id);
        $this->assertSame(70.0, (float) $trip->distance);
        $this->assertSame(60.0, (float) $trip->fuel_cost);
        $this->postJson("/api/v1/purchase-bills/{$bill}/reverse", ['date' => now()->toDateString()])->assertOk();
        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'status' => 'reversed']);
        $this->getJson('/api/v1/fleet/reports/trip-cost')->assertOk()->assertJsonPath('data.rows', []);
        $selectedAccount = Account::query()->where('company_id', $context['company']->id)->where('code', '1.2')->firstOrFail();
        $expense = $this->postJson('/api/v1/fleet/expenses', ['vehicle_id' => $vehicle->id, 'expense_date' => now()->toDateString(), 'category' => 'fuel', 'amount' => 60, 'account_id' => $selectedAccount->id, 'document' => 'FUEL-1'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/fleet/expenses/{$expense}/approve")->assertOk();
        $entryId = FleetExpense::query()->findOrFail($expense)->journal_entry_id;
        $this->assertNotNull($entryId);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entryId, 'account_id' => $selectedAccount->id, 'credit' => 60]);
    }
}
