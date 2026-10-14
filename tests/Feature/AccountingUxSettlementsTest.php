<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountingFoundationService;
use App\Services\AccountingService;
use App\Services\CompanyAccountingPolicyService;
use App\Services\CompanyStructureService;
use App\Services\InventoryService;
use App\Services\InvoiceSettlementService;
use App\Services\OperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountingUxSettlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_and_split_pages_render_for_the_owner_and_are_forbidden_without_permission(): void
    {
        $context = $this->tenant('pages');
        $limited = $this->limitedUser($context['company'], 'pages');

        $this->actingAs($context['user']);
        foreach ([
            '/accounting' => 'Company/Accounting',
            '/accounting/chart' => 'Company/ChartOfAccounts',
            '/accounting/periods' => 'Company/FiscalPeriods',
            '/accounting/cashboxes' => 'Company/Cashboxes',
            '/accounting/banks' => 'Company/Banks',
            '/accounting/receipts' => 'Company/ReceiptsList',
            '/accounting/payment-vouchers' => 'Company/PaymentVouchersList',
            '/reports/invoice-settlements' => 'Company/InvoiceSettlementReport',
        ] as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
        }

        $this->actingAs($limited);
        foreach (['/accounting', '/accounting/chart', '/accounting/periods', '/accounting/cashboxes', '/accounting/banks', '/accounting/receipts', '/reports/invoice-settlements'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_creditors_report_is_a_permanent_redirect_to_the_payables_report(): void
    {
        $context = $this->tenant('creditors');
        $this->actingAs($context['user']);

        $this->get('/reports/creditors?from_date=2026-01-01')->assertStatus(301)->assertRedirect(route('company.accounting.report', ['report' => 'payables', 'from_date' => '2026-01-01']));
        $this->get('/reports/payables')->assertOk()->assertInertia(fn ($page) => $page->component('Company/AccountingReport')->where('report', 'payables'));
    }

    public function test_every_system_account_has_both_names_while_codes_and_keys_stay_stable(): void
    {
        $context = $this->tenant('names');
        $accounts = Account::query()->where('company_id', $context['company']->id)->where('is_system', true)->get();

        $this->assertGreaterThan(10, $accounts->count());
        foreach ($accounts as $account) {
            $this->assertNotEmpty($account->name_ar, "Account {$account->code} has no Arabic name");
            $this->assertNotEmpty($account->name_en, "Account {$account->code} has no English name");
        }
        $current = $accounts->firstWhere('code', '1.2');
        $this->assertSame('الأصول المتداولة', $current->name_ar);
        $this->assertSame('Current assets', $current->name_en);
        $this->assertSame('Current assets', $current->name, 'The legacy name used as a lookup key must not change.');
    }

    public function test_account_rename_persists_and_system_accounts_cannot_be_reparented(): void
    {
        $context = $this->tenant('rename');
        $this->actingAs($context['user']);
        $parent = Account::query()->where('company_id', $context['company']->id)->where('code', '1.1')->firstOrFail();
        $system = Account::query()->where('company_id', $context['company']->id)->where('code', '1.2')->firstOrFail();

        $created = $this->postJson('/api/v1/accounts', ['parent_id' => $parent->id, 'name_ar' => 'عهدة الموظفين', 'name_en' => 'Staff custody'])->assertCreated()->json('data');
        $this->putJson("/api/v1/accounts/{$created['id']}", ['name_ar' => 'عهد شخصية', 'name_en' => 'Personal custody'])->assertOk();
        $this->assertDatabaseHas('accounts', ['id' => $created['id'], 'name_ar' => 'عهد شخصية', 'name_en' => 'Personal custody', 'code' => $created['code']]);

        $this->putJson("/api/v1/accounts/{$system->id}", ['name_ar' => 'الأصول قصيرة الأجل', 'name_en' => 'Short-term assets'])->assertOk();
        $system->refresh();
        $this->assertSame('1.2', $system->code);
        $this->assertSame('الأصول قصيرة الأجل', $system->name_ar);
        $this->assertTrue((bool) $system->is_system);

        $originalParent = $system->parent_id;
        $this->putJson("/api/v1/accounts/{$system->id}", ['name_en' => 'Short-term assets', 'parent_id' => $parent->id])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->assertSame($originalParent, $system->fresh()->parent_id);

        $this->putJson("/api/v1/accounts/{$created['id']}", ['name_ar' => '', 'name_en' => ''])->assertStatus(422);
    }

    public function test_duplicate_names_are_rejected_and_other_companies_cannot_touch_accounts(): void
    {
        $context = $this->tenant('dupes');
        $foreign = $this->tenant('dupes-foreign');
        $parent = Account::query()->where('company_id', $context['company']->id)->where('code', '1.1')->firstOrFail();

        $this->actingAs($context['user']);
        $this->postJson('/api/v1/accounts', ['parent_id' => $parent->id, 'name_ar' => 'مخزن فرعي', 'name_en' => 'Sub store'])->assertCreated();
        $this->postJson('/api/v1/accounts', ['parent_id' => $parent->id, 'name_ar' => 'مخزن فرعي', 'name_en' => 'Another'])->assertStatus(422)->assertJsonValidationErrors('name_ar');

        $foreignAccount = Account::query()->where('company_id', $foreign['company']->id)->where('code', '1.1')->firstOrFail();
        $this->putJson("/api/v1/accounts/{$foreignAccount->id}", ['name_en' => 'Hijacked'])->assertNotFound();
        $this->postJson('/api/v1/accounts', ['parent_id' => $foreignAccount->id, 'name_en' => 'Under foreign parent'])->assertNotFound();

        $this->actingAs($this->limitedUser($context['company'], 'rename'));
        $this->putJson("/api/v1/accounts/{$parent->id}", ['name_en' => 'Nope'])->assertForbidden();
    }

    public function test_receipt_and_voucher_support_other_account_counterparty_with_optional_people(): void
    {
        $context = $this->tenant('money');
        $this->actingAs($context['user']);
        $revenue = Account::query()->where('company_id', $context['company']->id)->where('code', '5.2')->firstOrFail();
        $expense = Account::query()->where('company_id', $context['company']->id)->where('code', '4.1')->firstOrFail();
        $income = $this->postJson('/api/v1/accounts', ['parent_id' => $revenue->id, 'name_ar' => 'إيراد متنوع', 'name_en' => 'Misc income'])->assertCreated()->json('data');
        $cost = $this->postJson('/api/v1/accounts', ['parent_id' => $expense->id, 'name_ar' => 'مستلزمات مكتبية', 'name_en' => 'Office supplies'])->assertCreated()->json('data');

        $bare = $this->postJson('/api/v1/receipts', ['counterparty_type' => 'other', 'other_account_id' => $income['id'], 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-06-02', 'amount' => 75])->assertCreated()->json('data');
        $this->assertNull($bare['delivered_by']);
        $this->assertNull($bare['received_by']);

        $full = $this->postJson('/api/v1/receipts', ['counterparty_type' => 'other', 'other_account_id' => $income['id'], 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-06-03', 'amount' => 25, 'delivered_by' => 'Mona Ali', 'received_by' => 'Hassan', 'location' => 'Main office'])->assertCreated()->json('data');
        $this->assertDatabaseHas('receipts', ['id' => $full['id'], 'delivered_by' => 'Mona Ali', 'received_by' => 'Hassan', 'location' => 'Main office', 'other_account_id' => $income['id']]);

        $voucher = $this->postJson('/api/v1/payment-vouchers', ['counterparty_type' => 'other', 'other_account_id' => $cost['id'], 'cashbox_id' => $context['cashbox']->id, 'voucher_date' => '2026-06-04', 'amount' => 10, 'paid_by' => 'Cashier', 'received_by' => 'Vendor'])->assertCreated()->json('data');
        $this->assertDatabaseHas('payment_vouchers', ['id' => $voucher['id'], 'paid_by' => 'Cashier', 'received_by' => 'Vendor']);

        $this->postJson('/api/v1/receipts', ['counterparty_type' => 'other', 'other_account_id' => $income['id'], 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-06-05', 'amount' => 5, 'delivered_by' => '<script>alert(1)</script>'])->assertStatus(422)->assertJsonValidationErrors('delivered_by');

        $this->get('/accounting/receipts')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Company/ReceiptsList')
            ->has('documents.data', 2)
            ->where('documents.data.0.other_account.code', $income['code'])
            ->where('documents.data.0.other_account.name_en', 'Misc income'));

        $this->putJson("/api/v1/receipts/{$bare['id']}/details", ['delivered_by' => 'Late entry', 'received_by' => 'Sara', 'notes' => 'Updated'])->assertOk();
        $this->assertDatabaseHas('receipts', ['id' => $bare['id'], 'delivered_by' => 'Late entry', 'received_by' => 'Sara', 'notes' => 'Updated']);
        $this->assertEquals(75.0, (float) Receipt::query()->findOrFail($bare['id'])->amount, 'Detail edits never change the posted amount.');
        $this->putJson("/api/v1/payment-vouchers/{$voucher['id']}/details", ['paid_by' => 'New payer'])->assertOk();
        $this->assertDatabaseHas('payment_vouchers', ['id' => $voucher['id'], 'paid_by' => 'New payer']);
    }

    public function test_money_document_details_and_pages_respect_company_isolation_and_permissions(): void
    {
        $context = $this->tenant('money-iso');
        $foreign = $this->tenant('money-iso-b');
        $receipt = app(AccountingService::class)->receive($foreign['company'], $foreign['user'], ['party_id' => $foreign['customer']->id, 'cashbox_id' => $foreign['cashbox']->id, 'receipt_date' => '2026-06-02', 'amount' => 50]);

        $this->actingAs($context['user']);
        $this->putJson("/api/v1/receipts/{$receipt->id}/details", ['received_by' => 'Intruder'])->assertNotFound();
        $this->get("/accounting/receipts/{$receipt->id}")->assertNotFound();
        $this->get("/accounting/receipts/{$receipt->id}/print")->assertNotFound();
        $this->assertNull(Receipt::query()->find($receipt->id)->received_by);

        $mine = app(AccountingService::class)->receive($context['company'], $context['user'], ['party_id' => $context['customer']->id, 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-06-02', 'amount' => 50]);
        $this->actingAs($this->limitedUser($context['company'], 'money-iso'));
        $this->putJson("/api/v1/receipts/{$mine->id}/details", ['received_by' => 'No rights'])->assertForbidden();
    }

    public function test_print_shows_the_other_account_and_a_friendly_date_without_iso_formatting(): void
    {
        $context = $this->tenant('print');
        $this->actingAs($context['user']);
        $revenue = Account::query()->where('company_id', $context['company']->id)->where('code', '5.2')->firstOrFail();
        $income = $this->postJson('/api/v1/accounts', ['parent_id' => $revenue->id, 'name_ar' => 'إيراد متنوع', 'name_en' => 'Misc income'])->assertCreated()->json('data');
        $receipt = $this->postJson('/api/v1/receipts', ['counterparty_type' => 'other', 'other_account_id' => $income['id'], 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-06-02', 'amount' => 75])->assertCreated()->json('data');

        $this->get("/accounting/receipts/{$receipt['id']}/print")->assertOk()->assertSee($income['code'], false)->assertSee('02/06/2026', false)->assertDontSee('2026-06-02', false);
    }

    public function test_sales_header_totals_must_match_lines_with_decimal_safe_comparison(): void
    {
        $context = $this->tenant('sales-totals');
        $operations = app(OperationsService::class);

        $ok = $operations->saveSales($context['company'], $context['user'], $this->salesPayload($context, 'S-DEC', [['actual_weight' => 0.1, 'packages' => 1.1], ['actual_weight' => 0.2, 'packages' => 2.2]], ['total_actual_weight' => 0.3, 'total_packages' => 3.3]));
        $this->assertEquals(0.3, (float) $ok->total_actual_weight);
        $this->assertEquals(3.3, (float) $ok->total_packages);

        try {
            $operations->saveSales($context['company'], $context['user'], $this->salesPayload($context, 'S-BAD-W', [['actual_weight' => 2]], ['total_actual_weight' => 2.001]));
            $this->fail('A weight mismatch of 0.001 must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total_actual_weight', $exception->errors());
            $this->assertStringContainsString('calculated 2.000, entered 2.001', $exception->errors()['total_actual_weight'][0]);
        }

        try {
            $operations->saveSales($context['company'], $context['user'], $this->salesPayload($context, 'S-BAD-P', [['actual_weight' => 1, 'packages' => 4]], ['total_actual_weight' => 1, 'total_packages' => 5]));
            $this->fail('A packages mismatch must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total_packages', $exception->errors());
        }

        $derived = $operations->saveSales($context['company'], $context['user'], $this->salesPayload($context, 'S-DERIVED', [['actual_weight' => 1, 'packages' => 2], ['actual_weight' => 1, 'packages' => 3]], ['total_actual_weight' => 2]));
        $this->assertEquals(5.0, (float) $derived->total_packages, 'An omitted header packages value is derived from the lines.');
        $this->assertDatabaseCount('sales_bills', 2); // The two rejected bills were never stored.
    }

    public function test_sales_lines_need_positive_weights_and_the_api_reports_mismatches_per_field(): void
    {
        $context = $this->tenant('sales-api');
        $this->actingAs($context['user']);

        $this->postJson('/api/v1/sales-bills', $this->salesPayload($context, 'API-BAD', [['actual_weight' => 2]], ['total_actual_weight' => 3]))->assertStatus(422)->assertJsonValidationErrors('total_actual_weight');
        $this->postJson('/api/v1/sales-bills', $this->salesPayload($context, 'API-ZERO', [['actual_weight' => 0]], ['total_actual_weight' => 1]))->assertStatus(422);
        $this->postJson('/api/v1/sales-bills', $this->salesPayload($context, 'API-OK', [['actual_weight' => 2, 'packages' => 1]], ['total_actual_weight' => 2, 'total_packages' => 1]))->assertCreated();
        $this->assertDatabaseCount('sales_bills', 1);
    }

    public function test_purchase_header_weights_and_packages_are_validated_and_rechecked_on_approval(): void
    {
        $context = $this->tenant('purchase-totals');
        $operations = app(OperationsService::class);
        $payload = fn (string $number, array $header): array => array_merge([
            'branch_id' => $context['branch']->id, 'warehouse_id' => $context['warehouse']->id, 'supplier_id' => $context['supplier']->id,
            'supplier_bill_number' => $number, 'supplier_bill_date' => '2026-06-01', 'payment_method' => 'credit', 'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'factory_weight' => 0.1, 'actual_weight' => 0.1, 'packages' => 1, 'unit_price' => 100],
                ['product_id' => $context['product']->id, 'factory_weight' => 0.2, 'actual_weight' => 0.2, 'packages' => 2, 'unit_price' => 100],
            ],
        ], $header);
        $valid = ['total_factory_weight' => 0.3, 'total_actual_weight' => 0.3, 'total_packages' => 3];

        $draft = $operations->savePurchase($context['company'], $context['user'], $payload('P-1', $valid));
        $this->assertSame('draft', $draft->status);

        foreach ([['total_factory_weight' => 0.301], ['total_packages' => 4]] as $index => $override) {
            try {
                $operations->savePurchase($context['company'], $context['user'], $payload("P-BAD-{$index}", $override + $valid));
                $this->fail('A mismatched header must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($override), $exception->errors());
            }
        }

        DB::table('purchase_bills')->where('id', $draft->id)->update(['total_factory_weight' => 0.5]);
        try {
            $operations->approvePurchase($context['company'], $context['user'], $draft->fresh());
            $this->fail('Approval must recheck the stored header totals.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('total_factory_weight', $exception->errors());
        }
        $this->assertSame('draft', $draft->fresh()->status);

        DB::table('purchase_bills')->where('id', $draft->id)->update(['total_factory_weight' => 0.3]);
        $this->assertSame('approved', $operations->approvePurchase($context['company'], $context['user'], $draft->fresh())->status);
    }

    public function test_sales_approval_rechecks_header_totals(): void
    {
        $context = $this->tenant('sales-recheck');
        $operations = app(OperationsService::class);
        $draft = $operations->saveSales($context['company'], $context['user'], $this->salesPayload($context, 'S-RE', [['actual_weight' => 2, 'packages' => 1]], ['total_actual_weight' => 2, 'total_packages' => 1]));
        DB::table('sales_bills')->where('id', $draft->id)->update(['total_actual_weight' => 9]);

        $this->expectException(ValidationException::class);
        $operations->approveSales($context['company'], $context['user'], $draft->fresh());
    }

    public function test_weight_policy_defaults_to_factory_weight_and_locks_after_the_first_approved_purchase(): void
    {
        $context = $this->tenant('policy');
        $policies = app(CompanyAccountingPolicyService::class);
        $this->actingAs($context['user']);
        $settings = fn (string $basis) => $this->putJson('/api/v1/accounting-settings', ['purchase_inventory_basis' => $basis, 'vat_enabled' => false, 'vat_rate' => 0]);

        $initial = $policies->get($context['company']);
        $this->assertSame('factory_weight', $initial['purchase_inventory_basis']);
        $this->assertFalse($initial['weight_policy_locked']);

        $settings('actual_weight')->assertOk()->assertJsonPath('data.purchase_inventory_basis', 'actual_weight');
        $settings('factory_weight')->assertOk()->assertJsonPath('data.weight_policy_locked', false);

        app(OperationsService::class)->savePurchase($context['company']->fresh(), $context['user'], [
            'branch_id' => $context['branch']->id, 'warehouse_id' => $context['warehouse']->id, 'supplier_id' => $context['supplier']->id,
            'supplier_bill_number' => 'POL-1', 'supplier_bill_date' => '2026-06-01', 'total_factory_weight' => 1, 'total_actual_weight' => 1, 'total_packages' => 1,
            'payment_method' => 'credit', 'due_date' => '2026-07-01',
            'lines' => [['product_id' => $context['product']->id, 'factory_weight' => 1, 'actual_weight' => 1, 'packages' => 1, 'unit_price' => 100]],
        ], true);

        $this->assertTrue($policies->get($context['company']->fresh())['weight_policy_locked']);
        $settings('actual_weight')->assertStatus(422)->assertJsonValidationErrors('purchase_inventory_basis');
        $this->assertSame('factory_weight', $policies->get($context['company']->fresh())['purchase_inventory_basis']);
    }

    public function test_settlements_use_names_reconcile_aging_and_track_payment_history(): void
    {
        $context = $this->tenant('settle');
        $operations = app(OperationsService::class);
        $accounting = app(AccountingService::class);
        $company = $context['company'];
        $user = $context['user'];

        $a = $operations->saveSales($company, $user, $this->salesPayload($context, 'SET-A', [['actual_weight' => 10]], ['total_actual_weight' => 10, 'bill_date' => '2026-01-01', 'due_date' => '2026-01-10']), true);
        $b = $operations->saveSales($company, $user, $this->salesPayload($context, 'SET-B', [['actual_weight' => 5]], ['total_actual_weight' => 5, 'bill_date' => '2026-01-02', 'due_date' => '2026-01-12']), true);
        $c = $operations->saveSales($company, $user, $this->salesPayload($context, 'SET-C', [['actual_weight' => 4]], ['total_actual_weight' => 4, 'bill_date' => '2026-01-03', 'due_date' => '2026-01-13']), true);
        $d = $operations->saveSales($company, $user, $this->salesPayload($context, 'SET-D', [['actual_weight' => 3]], ['total_actual_weight' => 3, 'bill_date' => '2026-02-01', 'due_date' => '2026-03-01', 'payment_method' => 'partial', 'paid_amount' => 100, 'cashbox_id' => $context['cashbox']->id]), true);
        $operations->reverseSales($company, $user, $c, '2026-01-20');

        $partial = $accounting->receive($company, $user, ['party_id' => $context['customer']->id, 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-01-15', 'amount' => 300, 'bill_id' => $a->id]);
        $accounting->receive($company, $user, ['party_id' => $context['customer']->id, 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-01-16', 'amount' => 500, 'bill_id' => $b->id]);

        $second = $accounting->createParty($company, $user, ['parent_account_id' => Account::query()->where('company_id', $company->id)->where('code', '1.2')->value('id'), 'name' => 'Second Customer', 'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 0]);
        $accounting->receive($company, $user, ['party_id' => $second->id, 'cashbox_id' => $context['cashbox']->id, 'receipt_date' => '2026-01-17', 'amount' => 150]);

        $report = app(InvoiceSettlementService::class)->report($company, 'sales', ['as_of' => '2026-02-05']);
        $rows = collect($report['rows']->items())->keyBy('number');

        $this->assertCount(4, $rows);
        $this->assertSame('Customer Co', $rows[$a->internal_number]['party_name']);
        $this->assertSame('partial', $rows[$a->internal_number]['state']);
        $this->assertEquals(300.0, $rows[$a->internal_number]['settled']);
        $this->assertEquals(700.0, $rows[$a->internal_number]['outstanding']);
        $this->assertSame('1_30', $rows[$a->internal_number]['bucket']);
        $this->assertSame(26, $rows[$a->internal_number]['days_overdue']);
        $this->assertSame('paid', $rows[$b->internal_number]['state']);
        $this->assertSame('reversed', $rows[$c->internal_number]['state']);
        $this->assertEquals(0.0, $rows[$c->internal_number]['outstanding']);
        $this->assertSame('partial', $rows[$d->internal_number]['state']);
        $this->assertEquals(200.0, $rows[$d->internal_number]['outstanding']);
        $this->assertSame('current', $rows[$d->internal_number]['bucket']);

        $this->assertEquals(900.0, $report['summary']['total_outstanding']);
        $this->assertEquals(150.0, $report['summary']['unallocated_credit']);
        $buckets = $report['summary']['current'] + $report['summary']['1_30'] + $report['summary']['31_60'] + $report['summary']['61_90'] + $report['summary']['90_plus'];
        $this->assertEqualsWithDelta($report['summary']['total_outstanding'], $buckets, 0.001);
        $this->assertEqualsWithDelta($report['summary']['total_outstanding'], collect($report['parties'])->sum('total'), 0.001);
        $this->assertEqualsWithDelta($report['summary']['total_outstanding'], $rows->sum('outstanding'), 0.001);

        $history = collect($rows[$a->internal_number]['history']);
        $this->assertCount(1, $history);
        $this->assertSame($partial->receipt_number, $history->first()['number']);
        $this->assertSame('posted', $history->first()['status']);
        $this->assertSame('invoice_payment', collect($rows[$d->internal_number]['history'])->first()['type']);

        $this->actingAs($user);
        $this->postJson("/api/v1/receipts/{$partial->id}/reverse", ['date' => '2026-01-18', 'reason' => 'Bounced'])->assertCreated();
        $after = collect(app(InvoiceSettlementService::class)->report($company, 'sales', ['as_of' => '2026-02-05'])['rows']->items())->keyBy('number');
        $this->assertEquals(1000.0, $after[$a->internal_number]['outstanding']);
        $this->assertSame('unpaid', $after[$a->internal_number]['state']);
        $this->assertSame('reversed', collect($after[$a->internal_number]['history'])->first()['status']);

        $this->get('/reports/invoice-settlements?tab=receivables&as_of=2026-02-05&state=unpaid')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Company/InvoiceSettlementReport')
            ->where('tab', 'receivables')
            ->where('report.rows.data.0.party_name', 'Customer Co')
            ->where('report.rows.data.0.number', $a->internal_number)
            ->has('report.rows.data.0.history'));
    }

    public function test_settlements_are_scoped_to_the_company_and_payables_use_supplier_data(): void
    {
        $context = $this->tenant('settle-a');
        $foreign = $this->tenant('settle-b');
        $operations = app(OperationsService::class);
        $service = app(InvoiceSettlementService::class);

        $operations->saveSales($foreign['company'], $foreign['user'], $this->salesPayload($foreign, 'FOREIGN-1', [['actual_weight' => 1]], ['total_actual_weight' => 1]), true);
        $bill = $operations->savePurchase($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id, 'warehouse_id' => $context['warehouse']->id, 'supplier_id' => $context['supplier']->id,
            'supplier_bill_number' => 'SUP-SET-1', 'supplier_bill_date' => '2026-06-01', 'total_factory_weight' => 2, 'total_actual_weight' => 2, 'total_packages' => 1,
            'payment_method' => 'credit', 'due_date' => '2026-07-01',
            'lines' => [['product_id' => $context['product']->id, 'factory_weight' => 2, 'actual_weight' => 2, 'packages' => 1, 'unit_price' => 100]],
        ], true);
        $voucher = app(AccountingService::class)->paySupplier($context['company'], $context['user'], ['party_id' => $context['supplier']->id, 'cashbox_id' => $context['cashbox']->id, 'voucher_date' => '2026-06-05', 'amount' => 50, 'bill_id' => $bill->id]);

        $this->assertSame(0, $service->report($context['company'], 'sales', [])['summary']['invoice_count']);
        $this->assertSame(0, $service->report($context['company'], 'sales', ['party_id' => $foreign['customer']->id])['summary']['invoice_count']);

        $payables = $service->report($context['company'], 'purchase', ['as_of' => '2026-06-10']);
        $row = $payables['rows']->items()[0];
        $this->assertSame('Supplier Co', $row['party_name']);
        $this->assertEquals(150.0, $row['outstanding']);
        $this->assertSame($voucher->voucher_number, $row['history'][0]['number']);
        $this->assertSame('current', $row['bucket']);
    }

    private function salesPayload(array $context, string $number, array $lines, array $header = []): array
    {
        return array_merge([
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => $number,
            'bill_date' => '2026-06-01',
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => array_map(fn (array $line): array => $line + ['product_id' => $context['product']->id, 'unit_price' => 100], $lines),
        ], $header);
    }

    private function limitedUser(Company $company, string $key): User
    {
        return User::query()->create(['name' => 'Limited '.$key, 'email' => "limited-{$key}@example.test", 'password' => 'password', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
    }

    private function tenant(string $key): array
    {
        $company = Company::query()->create(['name' => "UX Company {$key}", 'status' => 'active']);
        $plan = Plan::query()->create(['code' => "ux-{$key}", 'name' => "UX {$key}", 'duration' => 'yearly', 'price' => 1, 'limits' => [], 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        app(AccountingFoundationService::class)->seedCompany($company);
        $user = User::query()->create(['name' => "Owner {$key}", 'email' => "owner-{$key}@example.test", 'password' => 'password', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $role = Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]);
        $user->assignRole($role);
        $registrar->setPermissionsTeamId(null);

        $branch = app(CompanyStructureService::class)->createBranch($company, $user, ['code' => 'HQ', 'name' => 'Main Branch']);
        $warehouse = $branch->warehouses()->firstOrFail();
        $accounting = app(AccountingService::class);
        $liabilities = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $assets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();

        $supplier = $accounting->createParty($company, $user, ['parent_account_id' => $liabilities->id, 'name' => 'Supplier Co', 'is_customer' => false, 'is_supplier' => true, 'opening_balance' => 0]);
        $customer = $accounting->createParty($company, $user, ['parent_account_id' => $assets->id, 'name' => 'Customer Co', 'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 0]);
        $cashbox = $accounting->createCashbox($company, $user, 'Main Cashbox');
        $bank = $accounting->createBank($company, $user, 'Main Bank', 'EG123456');
        $product = app(InventoryService::class)->createProduct($company, $user, ['warehouse_id' => $warehouse->id, 'sku' => "SKU-{$key}", 'name' => 'Rebar Steel', 'unit' => 'ton', 'minimum_stock' => 0, 'opening_balance' => 500]);

        return compact('company', 'user', 'branch', 'warehouse', 'supplier', 'customer', 'cashbox', 'bank', 'product');
    }
}
