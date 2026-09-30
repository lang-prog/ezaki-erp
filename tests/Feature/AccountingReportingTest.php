<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountingFoundationService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_balances_are_directional_and_statements_carry_prior_movements_through_descendants(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $service = app(AccountingService::class);
        $currentAssets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $currentLiabilities = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $customer = $service->createParty($company, $actor, [
            'name' => 'Customer Carry', 'parent_account_id' => $currentAssets->id,
            'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 100,
            'opening_balance_direction' => 'debit', 'opening_balance_date' => now()->startOfYear()->toDateString(),
        ]);
        $supplier = $service->createParty($company, $actor, [
            'name' => 'Supplier Carry', 'parent_account_id' => $currentLiabilities->id,
            'is_customer' => false, 'is_supplier' => true, 'opening_balance' => 200,
            'opening_balance_direction' => 'credit', 'opening_balance_date' => now()->startOfYear()->toDateString(),
        ]);
        $revenue = Account::query()->where('company_id', $company->id)->where('code', '5.1')->firstOrFail();
        $expense = Account::query()->where('company_id', $company->id)->where('code', '4.1')->firstOrFail();
        $cash = Account::query()->where('company_id', $company->id)->where('code', '1.2')->where('id', '!=', $currentAssets->id)->first() ?? $currentAssets;
        $before = now()->startOfYear()->addMonths(1)->toDateString();
        $inside = now()->startOfYear()->addMonths(2)->toDateString();
        $service->postOperationalJournal($company, $actor, $before, 'Prior sale', 'test', 1, [
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 300, 'credit' => 0, 'description' => 'Prior customer invoice'],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 300, 'description' => 'Revenue'],
        ]);
        $service->postOperationalJournal($company, $actor, $inside, 'Customer receipt', 'test', 2, [
            ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0, 'description' => 'Cash'],
            ['account_id' => $customer->account_id, 'party_id' => $customer->id, 'debit' => 0, 'credit' => 100, 'description' => 'Receipt'],
        ]);
        $service->postOperationalJournal($company, $actor, $before, 'Prior purchase', 'test', 3, [
            ['account_id' => $expense->id, 'debit' => 500, 'credit' => 0, 'description' => 'Purchase'],
            ['account_id' => $supplier->account_id, 'party_id' => $supplier->id, 'debit' => 0, 'credit' => 500, 'description' => 'Supplier payable'],
        ]);
        $service->postOperationalJournal($company, $actor, $inside, 'Supplier payment', 'test', 4, [
            ['account_id' => $supplier->account_id, 'party_id' => $supplier->id, 'debit' => 150, 'credit' => 0, 'description' => 'Payment'],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 150, 'description' => 'Cash'],
        ]);

        $reports = app(AccountingReportService::class);
        $statement = $reports->accountStatement($company, $currentAssets, $inside, $inside);
        $this->assertContains($customer->account_id, $statement['account_ids']);
        $this->assertSame(400.0, (float) $statement['opening_balance']);
        $this->assertSame(250.0, (float) $statement['lines']->getCollection()->last()->running_balance);
        $this->assertSame(-700.0, (float) $reports->accountStatement($company, $supplier->account, $inside, $inside)['opening_balance']);
        $this->assertEqualsWithDelta(0.0, $reports->trialBalance($company, $inside)['debit_total'] - $reports->trialBalance($company, $inside)['credit_total'], 0.001);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'App\\Models\\CustomerSupplier', 'source_id' => $customer->id]);
    }

    public function test_customer_kpi_is_not_reduced_by_the_display_threshold_and_suppliers_have_payables_aging(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $foundation = app(AccountingFoundationService::class);
        $foundation->seedCompany($company, $actor);
        $service = app(AccountingService::class);
        $customer = $service->createParty($company, $actor, [
            'name' => 'Small Customer', 'parent_account_id' => Account::query()->where('company_id', $company->id)->where('code', '1.2')->value('id'),
            'is_customer' => true, 'is_supplier' => false, 'opening_balance' => 25,
        ]);
        $supplier = $service->createParty($company, $actor, [
            'name' => 'Supplier Aging', 'parent_account_id' => Account::query()->where('company_id', $company->id)->where('code', '2.1')->value('id'),
            'is_customer' => false, 'is_supplier' => true, 'opening_balance' => 75, 'opening_balance_direction' => 'credit',
        ]);
        $reports = app(AccountingReportService::class);
        $debtors = $reports->debtors($company, 50);
        $this->assertSame(25.0, (float) $debtors['total_outstanding']);
        $this->assertSame(0, $debtors['rows']->total());
        $payables = $reports->payables($company);
        $row = $payables['rows']->getCollection()->firstWhere('id', $supplier->id);
        $this->assertNotNull($row);
        $this->assertSame(75.0, (float) $row->balance_due);
        $this->assertSame(75.0, (float) $row->aging['current']);
    }

    private function companyAndActor(): array
    {
        $company = Company::query()->create(['name' => 'Accounting Test Co', 'status' => 'active']);
        $actor = User::query()->create([
            'name' => 'Accounting Actor', 'email' => fake()->unique()->safeEmail(), 'password' => 'password',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active',
        ]);

        return [$company, $actor];
    }
}
