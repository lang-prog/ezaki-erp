<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Account;
use App\Models\CustomerSupplier;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\SimplePartyImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SimplePartyImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_it_imports_customers_and_suppliers_with_balanced_opening_entries(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $result = app(SimplePartyImportService::class)->import($company, $actor, $this->file([
            'schema_version' => 1,
            'cutoff_date' => '2026-09-30',
            'parties' => [
                ['name' => 'Imported Customer', 'type' => 'customer', 'opening_balance' => 1250, 'opening_balance_direction' => 'debit'],
                ['name' => 'Imported Supplier', 'type' => 'supplier', 'opening_balance' => 830, 'opening_balance_direction' => 'credit'],
            ],
        ]));

        $this->assertSame(2, $result['created']);
        $this->assertSame('2026-10-01', $result['effective_date']);
        $customer = CustomerSupplier::query()->where('company_id', $company->id)->where('name', 'Imported Customer')->firstOrFail();
        $supplier = CustomerSupplier::query()->where('company_id', $company->id)->where('name', 'Imported Supplier')->firstOrFail();
        $this->assertSame('2026-10-01', $customer->opening_balance_date->toDateString());
        $this->assertSame('2026-10-01', $supplier->opening_balance_date->toDateString());
        $this->assertSame('debit', $customer->opening_balance_direction);
        $this->assertSame('credit', $supplier->opening_balance_direction);
        $this->assertSame('customer_control', Account::query()->findOrFail($customer->account_id)->parent()->value('system_key'));
        $this->assertSame('supplier_control', Account::query()->findOrFail($supplier->account_id)->parent()->value('system_key'));
        $this->assertSame(2, JournalEntry::query()->where('company_id', $company->id)->count());
        $totals = DB::table('journal_lines')->where('company_id', $company->id)->selectRaw('SUM(debit) as debit, SUM(credit) as credit')->first();
        $this->assertEqualsWithDelta((float) $totals->debit, (float) $totals->credit, 0.001);
    }

    public function test_existing_names_are_skipped_and_reimport_is_idempotent(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $importer = app(SimplePartyImportService::class);
        $payload = ['schema_version' => 1, 'cutoff_date' => '2026-09-30', 'parties' => [['name' => 'Repeat Customer', 'type' => 'customer', 'opening_balance' => 100, 'opening_balance_direction' => 'debit']]];

        $first = $importer->import($company, $actor, $this->file($payload));
        $second = $importer->import($company, $actor, $this->file($payload));

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, CustomerSupplier::query()->where('company_id', $company->id)->where('name', 'Repeat Customer')->count());
        $this->assertSame(1, JournalEntry::query()->where('company_id', $company->id)->count());
    }

    public function test_zero_balances_and_independent_source_accounts_are_imported_under_selected_parents(): void
    {
        [$company, $actor] = $this->companyAndActor();
        app(\App\Services\AccountingFoundationService::class)->seedCompany($company, $actor);
        $assetParent = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
        $liabilityParent = Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail();
        $customerParent = Account::query()->create(['company_id' => $company->id, 'parent_id' => $assetParent->id, 'code' => '1.2.77', 'name' => 'Imported customers', 'account_type' => 'asset', 'is_active' => true]);
        $supplierParent = Account::query()->create(['company_id' => $company->id, 'parent_id' => $liabilityParent->id, 'code' => '2.1.77', 'name' => 'Imported suppliers', 'account_type' => 'liability', 'is_active' => true]);

        $result = app(SimplePartyImportService::class)->import($company, $actor, $this->file([
            'schema_version' => 1,
            'cutoff_date' => '2026-09-30',
            'parties' => [
                ['name' => 'Zero Supplier', 'type' => 'supplier', 'opening_balance' => 0, 'opening_balance_direction' => 'debit', 'source_id' => 101],
                ['name' => 'Zero Supplier', 'type' => 'supplier', 'opening_balance' => 0, 'opening_balance_direction' => 'debit', 'source_id' => 102],
            ],
        ]), $customerParent->id, $supplierParent->id);

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, CustomerSupplier::query()->where('company_id', $company->id)->where('name', 'Zero Supplier')->count());
        $this->assertSame(2, Account::query()->where('company_id', $company->id)->where('parent_id', $supplierParent->id)->count());
    }

    public function test_invalid_rows_abort_without_partial_import(): void
    {
        [$company, $actor] = $this->companyAndActor();
        $this->expectException(ValidationException::class);
        try {
            app(SimplePartyImportService::class)->import($company, $actor, $this->file([
                'schema_version' => 1,
                'cutoff_date' => '2026-09-30',
                'parties' => [
                    ['name' => 'Valid Before Failure', 'type' => 'customer', 'opening_balance' => 50, 'opening_balance_direction' => 'debit'],
                    ['name' => 'Invalid', 'type' => 'unknown', 'opening_balance' => 20, 'opening_balance_direction' => 'debit'],
                ],
            ]));
        } finally {
            $this->assertSame(0, CustomerSupplier::query()->where('company_id', $company->id)->count());
            $this->assertSame(0, JournalEntry::query()->where('company_id', $company->id)->count());
        }
    }

    private function file(array $payload): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ezaki-party-import-');
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, 'parties.json', 'application/json', UPLOAD_ERR_OK, true);
    }

    private function companyAndActor(): array
    {
        $company = Company::query()->create(['name' => 'Simple Import Test Co', 'status' => 'active']);
        $actor = User::query()->create([
            'name' => 'Simple Import Actor', 'email' => fake()->unique()->safeEmail(), 'password' => 'password',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active',
        ]);

        return [$company, $actor];
    }
}
