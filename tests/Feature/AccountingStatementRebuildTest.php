<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\Plan;
use App\Models\Receipt;
use App\Models\SalesBill;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountingFoundationService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use App\Services\CompanyStructureService;
use App\Services\InventoryService;
use App\Services\OperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountingStatementRebuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_statement_without_date_filter_and_with_full_date_range_produce_consistent_closing_balance(): void
    {
        [$company, $actor] = $this->createCompanyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $accounting = app(AccountingService::class);

        $liabilities = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $supplier = $accounting->createParty($company, $actor, [
            'name' => 'Al-Ezz Steel',
            'parent_account_id' => $liabilities->id,
            'is_customer' => false,
            'is_supplier' => true,
            'opening_balance' => 0,
        ]);

        $cashbox = $accounting->createCashbox($company, $actor, 'Main Safe');

        // Post purchase for 900,000
        $date1 = '2026-03-01';
        $expense = Account::query()->where('company_id', $company->id)->where('code', '4.1')->firstOrFail();
        $accounting->postOperationalJournal($company, $actor, $date1, 'Steel purchase', 'purchase', 1, [
            ['account_id' => $expense->id, 'debit' => 900000, 'credit' => 0, 'description' => 'Purchase of rebar'],
            ['account_id' => $supplier->account_id, 'party_id' => $supplier->id, 'debit' => 0, 'credit' => 900000, 'description' => 'Supplier payable'],
        ]);

        // Post payment for 450,000
        $date2 = '2026-03-15';
        $accounting->postOperationalJournal($company, $actor, $date2, 'Supplier payment', 'payment', 1, [
            ['account_id' => $supplier->account_id, 'party_id' => $supplier->id, 'debit' => 450000, 'credit' => 0, 'description' => 'Partial payment'],
            ['account_id' => $cashbox->account_id, 'debit' => 0, 'credit' => 450000, 'description' => 'Safe payout'],
        ]);

        $reports = app(AccountingReportService::class);

        // Statement with explicit full date range
        $filtered = $reports->accountStatement($company, $supplier->account, '2026-01-01', '2026-12-31');
        $this->assertSame(0.0, (float) $filtered['opening_balance']);
        $this->assertSame(-450000.0, (float) $filtered['lines']->getCollection()->last()->running_balance);

        // Statement WITHOUT date filters
        $noFilter = $reports->accountStatement($company, $supplier->account);
        $this->assertSame(0.0, (float) $noFilter['opening_balance'], 'Opening balance without date filter must not contain period movements');
        $this->assertSame(-450000.0, (float) $noFilter['lines']->getCollection()->last()->running_balance, 'Closing running balance without date filter must match filtered range');
        $this->assertSame(450000.0, (float) $noFilter['summary']['period_debit']);
        $this->assertSame(900000.0, (float) $noFilter['summary']['period_credit']);
        $this->assertSame(-450000.0, (float) $noFilter['summary']['closing_balance']);
        $this->assertSame(2, $noFilter['summary']['transaction_count']);
    }

    public function test_opening_movement_strictly_before_from_is_counted_once_and_period_movement_is_not_in_opening(): void
    {
        [$company, $actor] = $this->createCompanyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $accounting = app(AccountingService::class);

        $assets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $customer = $accounting->createParty($company, $actor, [
            'name' => 'El-Nasr Trading',
            'parent_account_id' => $assets->id,
            'is_customer' => true,
            'is_supplier' => false,
            'opening_balance' => 0,
        ]);

        $revenue = Account::query()->where('company_id', $company->id)->where('code', '5.1')->firstOrFail();
        $cashbox = $accounting->createCashbox($company, $actor, 'Cash Till');

        // Jan 15: Sale of 100,000
        $accounting->postOperationalJournal($company, $actor, '2026-01-15', 'Invoice 1', 'sales', 1, [
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 100000, 'credit' => 0, 'description' => 'Sale 1'],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 100000, 'description' => 'Revenue'],
        ]);

        // Feb 10: Receipt of 40,000
        $accounting->postOperationalJournal($company, $actor, '2026-02-10', 'Receipt 1', 'receipt', 1, [
            ['account_id' => $cashbox->account_id, 'debit' => 40000, 'credit' => 0, 'description' => 'Cash received'],
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 0, 'credit' => 40000, 'description' => 'Customer payment'],
        ]);

        // Mar 05: Sale of 50,000
        $accounting->postOperationalJournal($company, $actor, '2026-03-05', 'Invoice 2', 'sales', 2, [
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 50000, 'credit' => 0, 'description' => 'Sale 2'],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 50000, 'description' => 'Revenue'],
        ]);

        $reports = app(AccountingReportService::class);

        // Filter: March only
        $march = $reports->accountStatement($company, $customer->account, '2026-03-01', '2026-03-31');
        // Opening balance should be 100,000 - 40,000 = 60,000
        $this->assertSame(60000.0, (float) $march['opening_balance']);
        $this->assertSame(50000.0, (float) $march['summary']['period_debit']);
        $this->assertSame(0.0, (float) $march['summary']['period_credit']);
        $this->assertSame(110000.0, (float) $march['summary']['closing_balance']);
        $this->assertSame(110000.0, (float) $march['lines']->getCollection()->last()->running_balance);
        $this->assertSame(1, $march['summary']['transaction_count']);

        // Filter: Feb through March
        $febMarch = $reports->accountStatement($company, $customer->account, '2026-02-01', '2026-03-31');
        // Opening balance should be Jan 15 sale = 100,000
        $this->assertSame(100000.0, (float) $febMarch['opening_balance']);
        $this->assertSame(50000.0, (float) $febMarch['summary']['period_debit']);
        $this->assertSame(40000.0, (float) $febMarch['summary']['period_credit']);
        $this->assertSame(110000.0, (float) $febMarch['summary']['closing_balance']);
        $this->assertSame(2, $febMarch['summary']['transaction_count']);
    }

    public function test_configured_opening_balance_with_journal_is_not_double_counted(): void
    {
        [$company, $actor] = $this->createCompanyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $accounting = app(AccountingService::class);

        $liabilities = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $supplier = $accounting->createParty($company, $actor, [
            'name' => 'Steel Supplier With Opening',
            'parent_account_id' => $liabilities->id,
            'is_customer' => false,
            'is_supplier' => true,
            'opening_balance' => 50000,
            'opening_balance_direction' => 'credit',
            'opening_balance_date' => '2026-01-01',
        ]);

        $this->assertNotNull($supplier->opening_balance_journal_entry_id);

        $reports = app(AccountingReportService::class);

        // Without date filter: the opening journal is a transaction line on 2026-01-01
        $noFilter = $reports->accountStatement($company, $supplier->account);
        $this->assertSame(0.0, (float) $noFilter['opening_balance']);
        $this->assertSame(-50000.0, (float) $noFilter['summary']['closing_balance']);
        $this->assertSame(-50000.0, (float) $noFilter['lines']->getCollection()->last()->running_balance);

        // With date filter after 2026-01-01: the opening journal is counted in opening balance
        $filtered = $reports->accountStatement($company, $supplier->account, '2026-02-01', '2026-12-31');
        $this->assertSame(-50000.0, (float) $filtered['opening_balance']);
        $this->assertSame(-50000.0, (float) $filtered['summary']['closing_balance']);
        $this->assertSame(0, $filtered['summary']['transaction_count']);
    }

    public function test_configured_opening_balance_without_journal_is_included_once_with_correct_sign(): void
    {
        [$company, $actor] = $this->createCompanyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);

        $liabilities = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $account = $foundation->createChild($company, $liabilities, 'Legacy Supplier Account');

        // Create legacy customer_supplier without opening journal
        $supplier = CustomerSupplier::query()->create([
            'company_id' => $company->id,
            'parent_account_id' => $liabilities->id,
            'account_id' => $account->id,
            'name' => 'Legacy Supplier',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'opening_balance' => 30000,
            'opening_balance_direction' => 'credit',
            'opening_balance_date' => '2026-01-01',
            'opening_balance_journal_entry_id' => null,
        ]);

        $reports = app(AccountingReportService::class);

        // No date filters: opening balance is -30000 (credit)
        $statement = $reports->accountStatement($company, $account);
        $this->assertSame(-30000.0, (float) $statement['opening_balance']);
        $this->assertSame(-30000.0, (float) $statement['summary']['closing_balance']);
        $this->assertSame(0, $statement['summary']['transaction_count']);
    }

    public function test_reversals_net_to_zero_and_remain_auditable(): void
    {
        [$company, $actor] = $this->createCompanyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $accounting = app(AccountingService::class);

        $assets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $customer = $accounting->createParty($company, $actor, [
            'name' => 'Reversal Test Customer',
            'parent_account_id' => $assets->id,
            'is_customer' => true,
            'is_supplier' => false,
            'opening_balance' => 0,
        ]);

        $revenue = Account::query()->where('company_id', $company->id)->where('code', '5.1')->firstOrFail();

        // Sale of 25,000
        $entry = $accounting->postOperationalJournal($company, $actor, '2026-04-01', 'Sale to be reversed', 'sales', 99, [
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 25000, 'credit' => 0, 'description' => 'Sale'],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 25000, 'description' => 'Revenue'],
        ]);

        // Reverse the journal entry
        $accounting->reverseJournal($company, $actor, $entry, '2026-04-05', true);

        $reports = app(AccountingReportService::class);
        $statement = $reports->accountStatement($company, $customer->account);

        // Both rows are in the ledger (original + reversal), netting to zero
        $this->assertSame(2, $statement['summary']['transaction_count']);
        $this->assertSame(25000.0, (float) $statement['summary']['period_debit']);
        $this->assertSame(25000.0, (float) $statement['summary']['period_credit']);
        $this->assertSame(0.0, (float) $statement['summary']['closing_balance']);
        $this->assertSame(0.0, (float) $statement['lines']->getCollection()->last()->running_balance);
    }

    public function test_pagination_running_balance_is_continuous_and_summary_totals_are_not_page_restricted(): void
    {
        [$company, $actor] = $this->createCompanyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $accounting = app(AccountingService::class);

        $assets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $customer = $accounting->createParty($company, $actor, [
            'name' => 'High Volume Customer',
            'parent_account_id' => $assets->id,
            'is_customer' => true,
            'is_supplier' => false,
            'opening_balance' => 0,
        ]);

        $revenue = Account::query()->where('company_id', $company->id)->where('code', '5.1')->firstOrFail();

        // Create 55 transactions to force two pages (page size 50)
        for ($i = 1; $i <= 55; $i++) {
            $day = str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT);
            $month = str_pad((string) (intdiv($i, 28) + 1), 2, '0', STR_PAD_LEFT);
            $accounting->postOperationalJournal($company, $actor, "2026-{$month}-{$day}", "Invoice {$i}", 'sales', $i, [
                ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 1000, 'credit' => 0, 'description' => "Item {$i}"],
                ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000, 'description' => 'Revenue'],
            ]);
        }

        $reports = app(AccountingReportService::class);

        // Page 1
        request()->merge(['page' => 1]);
        $page1 = $reports->accountStatement($company, $customer->account);
        $this->assertSame(55, $page1['summary']['transaction_count']);
        $this->assertSame(55000.0, (float) $page1['summary']['period_debit']);
        $this->assertSame(55000.0, (float) $page1['summary']['closing_balance']);
        $lastPage1Balance = (float) $page1['lines']->getCollection()->last()->running_balance;
        $this->assertSame(50000.0, $lastPage1Balance);

        // Page 2
        request()->merge(['page' => 2]);
        $page2 = $reports->accountStatement($company, $customer->account);
        // Summary totals on Page 2 must still reflect the entire statement, not just page 2
        $this->assertSame(55, $page2['summary']['transaction_count']);
        $this->assertSame(55000.0, (float) $page2['summary']['period_debit']);
        $this->assertSame(55000.0, (float) $page2['summary']['closing_balance']);
        $firstPage2Balance = (float) $page2['lines']->getCollection()->first()->running_balance;
        $this->assertSame(51000.0, $firstPage2Balance, 'Page 2 first row must continue running balance from Page 1 last row');
        $this->assertSame(55000.0, (float) $page2['lines']->getCollection()->last()->running_balance);
    }

    public function test_customer_statement_enriches_particulars_and_source_document_metadata(): void
    {
        [$company, $actor] = $this->createCompanyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $accounting = app(AccountingService::class);

        $assets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $customer = $accounting->createParty($company, $actor, [
            'name' => 'Delta Construction',
            'parent_account_id' => $assets->id,
            'is_customer' => true,
            'is_supplier' => false,
            'opening_balance' => 0,
        ]);

        $cashbox = $accounting->createCashbox($company, $actor, 'Office Safe');

        // Post a sales invoice journal
        $accounting->postOperationalJournal($company, $actor, '2026-05-01', 'Sales invoice SB-2026-000100', 'App\\Models\\SalesBill', 1, [
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 150000, 'credit' => 0, 'description' => 'Invoice SB-2026-000100'],
            ['account_id' => $assets->id, 'debit' => 0, 'credit' => 150000, 'description' => 'Contra'],
        ]);

        // Post a receipt journal
        $accounting->postOperationalJournal($company, $actor, '2026-05-15', 'Customer receipt RC-2026-000050', 'App\\Models\\Receipt', 1, [
            ['account_id' => $cashbox->account_id, 'debit' => 50000, 'credit' => 0, 'description' => 'Safe collection'],
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 0, 'credit' => 50000, 'description' => 'Receipt RC-2026-000050'],
        ]);

        $reports = app(AccountingReportService::class);
        $statement = $reports->accountStatement($company, $customer->account);

        $rows = $statement['lines']->getCollection();
        $this->assertCount(2, $rows);

        $saleRow = $rows->first();
        $this->assertStringContainsString('SB-2026-000100', $saleRow->particulars);
        $this->assertStringContainsString('SB-2026-000100', $saleRow->particulars_ar);
        $this->assertNotNull($saleRow->source_document);

        $receiptRow = $rows->last();
        $this->assertStringContainsString('RC-2026-000050', $receiptRow->particulars);
        $this->assertStringContainsString('RC-2026-000050', $receiptRow->particulars_ar);
        $this->assertNotNull($receiptRow->source_document);
    }

    public function test_cross_company_isolation_on_account_statement(): void
    {
        [$companyA, $actorA] = $this->createCompanyAndActor('Company A');
        [$companyB, $actorB] = $this->createCompanyAndActor('Company B');

        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($companyA, $actorA);
        $foundation->seedCompany($companyB, $actorB);

        $accountA = Account::query()->where('company_id', $companyA->id)->where('code', '1.2')->firstOrFail();

        $reports = app(AccountingReportService::class);

        $this->expectException(HttpException::class);
        // Company B trying to view Company A's account statement
        $reports->accountStatement($companyB, $accountA);
    }

    public function test_customer_account_statement_renders_http_200_with_sales_bill_and_receipt(): void
    {
        $context = $this->fullTenant('customer-stmt');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        $salesBill = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-INV-99',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 5,
            'payment_method' => 'partial',
            'paid_amount' => 400,
            'cashbox_id' => $context['cashbox']->id,
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 5, 'unit_price' => 200],
            ],
        ], true);

        $accounting = app(AccountingService::class);
        $receipt = $accounting->receive($context['company'], $context['user'], [
            'party_id' => $context['customer']->id,
            'cashbox_id' => $context['cashbox']->id,
            'receipt_date' => '2026-06-02',
            'amount' => 300,
            'notes' => 'Customer deposit',
        ]);

        $response = $this->get('/reports/account-statement?account_id='.$context['customer']->account_id);
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/AccountingReport')
                ->where('report', 'account-statement')
                ->where('account.id', $context['customer']->account_id)
                ->has('lines.data', 2)
                ->where('lines.data.0.source_document.kind', 'sales')
                ->where('lines.data.0.source_document.title', $salesBill->internal_number)
                ->where('lines.data.0.source_document.payment_source', $context['cashbox']->name)
                ->where('lines.data.0.source_document.view_url', route('company.sales.show', ['bill' => $salesBill->id]))
                ->where('lines.data.0.source_document.print_url', route('company.sales.print', ['bill' => $salesBill->id, 'mode' => 'invoice']))
                ->where('lines.data.1.source_document.kind', 'receipt')
                ->where('lines.data.1.source_document.title', $receipt->receipt_number)
                ->where('lines.data.1.source_document.payment_source', $context['cashbox']->name)
                ->where('lines.data.1.source_document.view_url', route('company.receipt.show', ['document' => $receipt->id]))
                ->where('lines.data.1.source_document.print_url', route('company.receipt.print', ['document' => $receipt->id])));
    }

    public function test_supplier_account_statement_renders_http_200_with_purchase_bill_and_voucher(): void
    {
        $context = $this->fullTenant('supplier-stmt');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        $purchaseBill = $operations->savePurchase($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'supplier_id' => $context['supplier']->id,
            'supplier_bill_number' => 'SUP-BILL-77',
            'supplier_bill_date' => '2026-06-01',
            'total_factory_weight' => 10,
            'total_actual_weight' => 10,
            'total_packages' => 1,
            'payment_method' => 'partial',
            'paid_amount' => 500,
            'bank_id' => $context['bank']->id,
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'factory_weight' => 10, 'actual_weight' => 10, 'packages' => 1, 'unit_price' => 150],
            ],
        ], true);

        $accounting = app(AccountingService::class);
        $voucher = $accounting->paySupplier($context['company'], $context['user'], [
            'party_id' => $context['supplier']->id,
            'bank_id' => $context['bank']->id,
            'voucher_date' => '2026-06-03',
            'amount' => 400,
            'notes' => 'Supplier payment',
        ]);

        $response = $this->get('/reports/account-statement?account_id='.$context['supplier']->account_id);
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/AccountingReport')
                ->where('report', 'account-statement')
                ->where('account.id', $context['supplier']->account_id)
                ->has('lines.data', 2)
                ->where('lines.data.0.source_document.kind', 'purchase')
                ->where('lines.data.0.source_document.title', $purchaseBill->internal_number)
                ->where('lines.data.0.source_document.payment_source', $context['bank']->name)
                ->where('lines.data.0.source_document.view_url', route('company.purchase.show', ['bill' => $purchaseBill->id]))
                ->where('lines.data.0.source_document.print_url', route('company.purchase.print', ['bill' => $purchaseBill->id, 'mode' => 'invoice']))
                ->where('lines.data.1.source_document.kind', 'voucher')
                ->where('lines.data.1.source_document.title', $voucher->voucher_number)
                ->where('lines.data.1.source_document.payment_source', $context['bank']->name)
                ->where('lines.data.1.source_document.view_url', route('company.voucher.show', ['document' => $voucher->id]))
                ->where('lines.data.1.source_document.print_url', route('company.voucher.print', ['document' => $voucher->id])));
    }

    public function test_bills_with_cashbox_and_bank_sources_expose_correct_payment_source_in_statement(): void
    {
        $context = $this->fullTenant('payment-sources');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        // Bill 1 partially paid via cashbox
        $billCashbox = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-CASH-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 2,
            'payment_method' => 'partial',
            'paid_amount' => 50,
            'due_date' => '2026-07-01',
            'cashbox_id' => $context['cashbox']->id,
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 2, 'unit_price' => 100],
            ],
        ], true);

        // Bill 2 partially paid via bank
        $billBank = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-BANK-2',
            'bill_date' => '2026-06-02',
            'total_actual_weight' => 3,
            'payment_method' => 'partial',
            'paid_amount' => 75,
            'due_date' => '2026-07-01',
            'bank_id' => $context['bank']->id,
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 3, 'unit_price' => 100],
            ],
        ], true);

        $reports = app(AccountingReportService::class);
        $statement = $reports->accountStatement($context['company'], $context['customer']->account);
        $rows = $statement['lines']->getCollection()->values();

        $this->assertCount(2, $rows);
        $this->assertSame($context['cashbox']->name, $rows[0]->source_document['payment_source']);
        $this->assertSame($context['bank']->name, $rows[1]->source_document['payment_source']);
    }

    public function test_cross_company_source_document_cannot_be_resolved_through_statement(): void
    {
        $contextA = $this->fullTenant('company-a');
        $contextB = $this->fullTenant('company-b');

        $operations = app(OperationsService::class);
        $salesBillB = $operations->saveSales($contextB['company'], $contextB['user'], [
            'branch_id' => $contextB['branch']->id,
            'warehouse_id' => $contextB['warehouse']->id,
            'customer_id' => $contextB['customer']->id,
            'customer_bill_number' => 'CUS-B-99',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 1,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $contextB['product']->id, 'actual_weight' => 1, 'unit_price' => 100],
            ],
        ], true);

        $accounting = app(AccountingService::class);
        $accounting->postOperationalJournal($contextA['company'], $contextA['user'], '2026-06-01', 'Suspicious entry', SalesBill::class, $salesBillB->id, [
            ['account_id' => $contextA['customer']->account_id, 'party_id' => $contextA['customer']->id, 'debit' => 100, 'credit' => 0, 'description' => 'Test line'],
            ['account_id' => Account::query()->where('company_id', $contextA['company']->id)->where('code', '5.1')->value('id'), 'debit' => 0, 'credit' => 100, 'description' => 'Contra'],
        ]);

        $reports = app(AccountingReportService::class);
        $statement = $reports->accountStatement($contextA['company'], $contextA['customer']->account);
        $row = $statement['lines']->getCollection()->first();

        $this->assertNull($row->source_document['view_url']);
        $this->assertNotSame($salesBillB->internal_number, $row->source_document['title']);
    }

    public function test_draft_bill_edit_updates_in_place_without_creating_journal_or_reversal(): void
    {
        $context = $this->fullTenant('draft-edit');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        $draft = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-DRAFT-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 2,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 2, 'unit_price' => 100],
            ],
        ], false);

        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->journal_entry_id);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $context['company']->id,
            'source_type' => SalesBill::class,
            'source_id' => $draft->id,
        ]);

        // Edit the draft in place
        $updated = $operations->saveSales($context['company'], $context['user'], [
            'id' => $draft->id,
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-DRAFT-1-EDITED',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 4,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 4, 'unit_price' => 100],
            ],
        ], false);

        $this->assertSame($draft->id, $updated->id);
        $this->assertSame(400.0, (float) $updated->total);
        $this->assertSame('draft', $updated->status);
        $this->assertNull($updated->journal_entry_id);
        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $context['company']->id,
            'source_type' => SalesBill::class,
            'source_id' => $draft->id,
        ]);
    }

    public function test_posted_bill_revision_creates_exact_reversal_and_replacement_linked_in_bill_revision(): void
    {
        $context = $this->fullTenant('bill-revision');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        $bill = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-REV-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 10,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 10, 'unit_price' => 100],
            ],
        ], true);

        $originalJournalId = $bill->journal_entry_id;
        $this->assertNotNull($originalJournalId);

        // Revise bill from 1000 to 1200
        $revised = $operations->revise($context['company'], $context['user'], 'sales', $bill->id, [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-REV-1-MOD',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 12,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'revision_reason' => 'Quantity amended per weight slip',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 12, 'unit_price' => 100],
            ],
        ]);

        $this->assertSame($bill->id, $revised->id);
        $this->assertSame(1200.0, (float) $revised->total);
        $this->assertTrue($revised->is_revised);
        $this->assertSame(1, $revised->revision_count);
        $this->assertNotSame($originalJournalId, $revised->journal_entry_id);

        // Check original journal is reversed
        $this->assertDatabaseHas('journal_entries', [
            'id' => $originalJournalId,
            'status' => 'reversed',
        ]);

        // Check BillRevision record
        $revision = $revised->revisions()->first();
        $this->assertNotNull($revision);
        $this->assertSame($originalJournalId, $revision->original_journal_entry_id);
        $this->assertSame($revised->journal_entry_id, $revision->replacement_journal_entry_id);
        $this->assertNotNull($revision->reversal_journal_entry_id);
        $this->assertSame(1000.0, (float) $revision->original_amount);
        $this->assertSame(1200.0, (float) $revision->revised_amount);
        $this->assertSame(200.0, (float) $revision->delta_amount);

        // Multiple revisions: revise again to 1500
        $secondRevisionJournal = $revised->journal_entry_id;
        $revisedAgain = $operations->revise($context['company'], $context['user'], 'sales', $bill->id, [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-REV-1-MOD2',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 15,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'revision_reason' => 'Further adjustment to 15 tons',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 15, 'unit_price' => 100],
            ],
        ]);

        $this->assertSame(2, $revisedAgain->revision_count);
        $this->assertSame(2, $revisedAgain->revisions()->count());
    }

    public function test_closed_period_revision_is_rejected_without_accounting_changes(): void
    {
        $context = $this->fullTenant('closed-period-rev');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        $bill = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-CLOSED-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 5,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 5, 'unit_price' => 100],
            ],
        ], true);

        // Close the fiscal period
        $period = app(AccountingFoundationService::class)->openPeriodFor($context['company'], '2026-06-01');
        app(AccountingService::class)->closePeriod($context['company'], $period, $context['user']);

        $this->expectException(HttpException::class);
        $operations->revise($context['company'], $context['user'], 'sales', $bill->id, [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-CLOSED-1-MOD',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 10,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'revision_reason' => 'Attempting edit on closed period',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 10, 'unit_price' => 100],
            ],
        ]);
    }

    public function test_operational_statement_collapses_revision_chain_while_detailed_ledger_shows_all_entries(): void
    {
        $context = $this->fullTenant('statement-modes');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        $bill = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-MODE-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 10,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 10, 'unit_price' => 100],
            ],
        ], true);

        // Revise bill from 1000 to 1200
        $operations->revise($context['company'], $context['user'], 'sales', $bill->id, [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-MODE-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 12,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'revision_reason' => 'Weight slip updated',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 12, 'unit_price' => 100],
            ],
        ]);

        // Post receipt of 1200
        $accounting = app(AccountingService::class);
        $accounting->receive($context['company'], $context['user'], [
            'party_id' => $context['customer']->id,
            'cashbox_id' => $context['cashbox']->id,
            'receipt_date' => '2026-06-05',
            'amount' => 1200,
        ]);

        $reports = app(AccountingReportService::class);

        // 1. OPERATIONAL STATEMENT (Default)
        $operational = $reports->accountStatement($context['company'], $context['customer']->account, null, null, 'operational');
        $opRows = $operational['lines']->getCollection();

        // Exactly 2 rows: 1 effective amended sales invoice + 1 receipt
        $this->assertCount(2, $opRows);
        $firstRow = $opRows->first();
        $this->assertTrue($firstRow->is_revised);
        $this->assertSame(1200.0, (float) $firstRow->debit);
        $this->assertStringContainsString('(Amended)', $firstRow->particulars);
        $this->assertCount(1, $firstRow->revisions);
        $this->assertSame(1000.0, (float) $firstRow->original_amount);
        $this->assertSame(1200.0, (float) $firstRow->current_amount);
        $this->assertSame(200.0, (float) $firstRow->revision_delta);

        $secondRow = $opRows->last();
        $this->assertSame('receipt', $secondRow->document_type);
        $this->assertSame(1200.0, (float) $secondRow->credit);

        // Running and closing balance are 0.00
        $this->assertSame(0.0, (float) $operational['summary']['closing_balance']);
        $this->assertSame(0.0, (float) $secondRow->running_balance);

        // 2. DETAILED AUDIT LEDGER
        $detailed = $reports->accountStatement($context['company'], $context['customer']->account, null, null, 'detailed');
        $detRows = $detailed['lines']->getCollection();

        // 4 rows: original bill (1000 debit), reversal (1000 credit), replacement (1200 debit), receipt (1200 credit)
        $this->assertCount(4, $detRows);
        // Closing balance in detailed mode matches operational statement to the penny
        $this->assertSame(0.0, (float) $detailed['summary']['closing_balance']);
        $this->assertSame(0.0, (float) $detRows->last()->running_balance);

        // 3. TRIAL BALANCE, INCOME STATEMENT, BALANCE SHEET RECONCILIATION
        $tb = $reports->trialBalance($context['company']);
        $this->assertEqualsWithDelta(0.0, $tb['debit_total'] - $tb['credit_total'], 0.001);

        $income = $reports->incomeStatement($context['company']);
        $salesRev = $income['rows']->firstWhere('code', '5.1');
        $this->assertNotNull($salesRev);
        // Net sales revenue should be exactly 1200 (not 1000 + 1200)
        $this->assertSame(1200.0, (float) $salesRev->balance);

        $bs = $reports->balanceSheet($context['company']);
        $this->assertSame(0.0, (float) $bs['reconciliation_difference']);
    }

    public function test_revised_bill_with_payment_allocations_rejects_reduction_below_allocated_amount(): void
    {
        $context = $this->fullTenant('alloc-guard');
        $this->actingAs($context['user']);

        $operations = app(OperationsService::class);
        $bill = $operations->saveSales($context['company'], $context['user'], [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-ALLOC-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 10,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 10, 'unit_price' => 100],
            ],
        ], true);

        // Allocate 700 to this bill
        $accounting = app(AccountingService::class);
        $receipt = $accounting->receive($context['company'], $context['user'], [
            'party_id' => $context['customer']->id,
            'cashbox_id' => $context['cashbox']->id,
            'receipt_date' => '2026-06-02',
            'amount' => 700,
            'bill_id' => $bill->id,
        ]);

        // Attempting to revise the bill total to 500 (less than 700 allocated) must be rejected
        $this->expectException(ValidationException::class);
        $operations->revise($context['company'], $context['user'], 'sales', $bill->id, [
            'branch_id' => $context['branch']->id,
            'warehouse_id' => $context['warehouse']->id,
            'customer_id' => $context['customer']->id,
            'customer_bill_number' => 'CUS-ALLOC-1',
            'bill_date' => '2026-06-01',
            'total_actual_weight' => 5,
            'payment_method' => 'credit',
            'due_date' => '2026-07-01',
            'revision_reason' => 'Trying to reduce below allocated payment',
            'lines' => [
                ['product_id' => $context['product']->id, 'actual_weight' => 5, 'unit_price' => 100],
            ],
        ]);
    }

    private function fullTenant(string $key = 'rebuild'): array
    {
        $company = Company::query()->create(['name' => "Statement Company {$key}", 'status' => 'active']);
        $plan = Plan::query()->create(['code' => "stmt-{$key}", 'name' => "Stmt {$key}", 'duration' => 'yearly', 'price' => 1, 'limits' => [], 'is_active' => true]);
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

    private function createCompanyAndActor(string $name = 'Statement Test Co'): array
    {
        $company = Company::query()->create(['name' => $name, 'status' => 'active']);
        $actor = User::query()->create([
            'name' => $name.' Actor',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'company_id' => $company->id,
            'account_type' => 'company',
            'status' => 'active',
        ]);

        return [$company, $actor];
    }
}
