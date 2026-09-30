<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountingFoundationService
{
    private const ROOTS = [
        ['1', 'Assets', 'asset'], ['2', 'Liabilities', 'liability'], ['3', 'Equity', 'equity'],
        ['4', 'Expenses', 'expense'], ['5', 'Revenue', 'revenue'],
    ];

    private const CHILDREN = [
        '1' => [['1', 'Fixed assets'], ['2', 'Current assets']],
        '2' => [['1', 'Current liabilities'], ['2', 'Long-term liabilities']],
        '3' => [['1', 'Capital'], ['2', 'Partners current']],
        '4' => [['1', 'G&A'], ['2', 'Other expenses']],
        '5' => [['1', 'Operating revenue'], ['2', 'Other revenue']],
    ];

    private const SYSTEM_POSTING_ACCOUNTS = [
        ['inventory_asset', '1', '1.INV', 'Inventory'],
        ['input_vat', '1', '1.VAT-IN', 'Recoverable input VAT'],
        ['output_vat', '2', '2.VAT-OUT', 'Output VAT payable'],
        ['cost_of_goods_sold', '4', '4.COGS', 'Cost of goods sold'],
    ];

    public function seedCompany(Company $company, ?User $actor = null): void
    {
        DB::transaction(function () use ($company): void {
            $roots = [];
            foreach (self::ROOTS as [$code, $name, $type]) {
                $roots[$code] = Account::query()->firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    ['name' => $name, 'account_type' => $type, 'is_system' => true, 'is_active' => true],
                );
            }

            foreach (self::CHILDREN as $parentCode => $children) {
                foreach ($children as [$suffix, $name]) {
                    $root = $roots[$parentCode];
                    Account::query()->firstOrCreate(
                        ['company_id' => $company->id, 'code' => $parentCode.'.'.$suffix],
                        ['parent_id' => $root->id, 'name' => $name, 'account_type' => $root->account_type, 'is_system' => true, 'is_active' => true],
                    );
                }
            }

            foreach (self::SYSTEM_POSTING_ACCOUNTS as [$key, $parentCode, $code, $name]) {
                $parent = Account::query()->where('company_id', $company->id)->where('code', $parentCode)->firstOrFail();
                Account::query()->firstOrCreate(
                    ['company_id' => $company->id, 'system_key' => $key],
                    [
                        'parent_id' => $parent->id,
                        'code' => $code,
                        'name' => $name,
                        'account_type' => $parent->account_type,
                        'is_system' => true,
                        'is_active' => true,
                    ],
                );
            }

            $year = now()->year;
            FiscalPeriod::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => (string) $year],
                ['starts_on' => "$year-01-01", 'ends_on' => "$year-12-31", 'status' => 'open'],
            );
        });
    }

    public function systemAccount(Company $company, string $key): Account
    {
        $this->seedCompany($company);

        return Account::query()->where('company_id', $company->id)->where('system_key', $key)->firstOrFail();
    }

    public function nextChildCode(Company $company, Account $parent): string
    {
        abort_unless((int) $parent->company_id === (int) $company->id, 404);
        $lastSuffix = Account::query()
            ->where('company_id', $company->id)
            ->where('parent_id', $parent->id)
            ->lockForUpdate()
            ->get(['code'])
            ->map(fn (Account $account): string => substr($account->code, strlen($parent->code) + 1))
            ->filter(fn (string $suffix): bool => ctype_digit($suffix))
            ->map(fn (string $suffix): int => (int) $suffix)
            ->max() ?? 0;

        return $parent->code.'.'.($lastSuffix + 1);
    }

    public function createChild(Company $company, Account $parent, string $name): Account
    {
        return DB::transaction(function () use ($company, $parent, $name): Account {
            $parent = Account::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($parent->id);
            $code = $this->nextChildCode($company, $parent);

            return Account::query()->create([
                'company_id' => $company->id,
                'parent_id' => $parent->id,
                'code' => $code,
                'name' => $name,
                'account_type' => $parent->account_type,
                'is_system' => false,
                'is_active' => true,
            ]);
        });
    }

    public function moveAccount(Company $company, Account $account, ?Account $parent): Account
    {
        return DB::transaction(function () use ($company, $account, $parent): Account {
            abort_unless((int) $account->company_id === (int) $company->id, 404);
            abort_if($account->parent_id === null, 422, 'Root accounts cannot be moved.');
            abort_if($parent === null, 422, 'A parent account is required.');
            abort_unless((int) $parent->company_id === (int) $company->id, 404);
            abort_if((int) $parent->id === (int) $account->id, 422, 'An account cannot parent itself.');

            $cursor = $parent;
            while ($cursor) {
                abort_if((int) $cursor->id === (int) $account->id, 422, 'An account cannot move under its descendant.');
                $cursor = $cursor->parent;
            }

            $account->forceFill(['parent_id' => $parent->id, 'account_type' => $parent->account_type, 'code' => $this->nextChildCode($company, $parent)])->save();
            $this->recodeDescendants($company, $account);

            return $account->fresh('parent');
        });
    }

    private function recodeDescendants(Company $company, Account $parent): void
    {
        $children = Account::query()->where('company_id', $company->id)->where('parent_id', $parent->id)->orderBy('id')->get();
        foreach ($children as $index => $child) {
            $child->forceFill(['code' => $parent->code.'.'.($index + 1), 'account_type' => $parent->account_type])->save();
            $this->recodeDescendants($company, $child);
        }
    }

    public function openPeriodFor(Company $company, string $date): FiscalPeriod
    {
        $dateValue = now()->parse($date)->toDateString();
        $period = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->whereDate('starts_on', '<=', $dateValue)
            ->whereDate('ends_on', '>=', $dateValue)
            ->first();
        abort_unless($period, 422, 'No fiscal period covers this date.');
        throw_if($period->status !== 'open', new HttpException(409, 'The fiscal period is closed.'));

        return $period;
    }

    public function createPeriod(Company $company, string $name, string $startsOn, string $endsOn): FiscalPeriod
    {
        abort_if($startsOn > $endsOn, 422, 'The fiscal period end must be on or after its start.');

        return DB::transaction(function () use ($company, $name, $startsOn, $endsOn): FiscalPeriod {
            $company->newQuery()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $overlaps = FiscalPeriod::query()->where('company_id', $company->id)
                ->whereDate('starts_on', '<=', $endsOn)
                ->whereDate('ends_on', '>=', $startsOn)
                ->exists();
            abort_if($overlaps, 409, 'Fiscal periods cannot overlap.');

            return FiscalPeriod::query()->create([
                'company_id' => $company->id,
                'name' => $name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => 'open',
            ]);
        });
    }
}
