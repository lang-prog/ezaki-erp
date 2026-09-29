<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\PaymentVoucher;
use App\Models\PurchaseBill;
use App\Models\Receipt;
use App\Models\SalesBill;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    public function journal(Company $company, ?string $from = null, ?string $to = null): array
    {
        $entries = DB::table('journal_entries as entries')
            ->join('journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.company_id', $company->id)
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entries.entry_date', '<=', $to))
            ->select('entries.id', 'entries.entry_number', 'entries.entry_date', 'entries.description', 'entries.source_type', 'entries.source_id', 'accounts.code as account_code', 'accounts.name as account_name', 'lines.debit', 'lines.credit', 'lines.description as line_description')
            ->orderBy('entries.entry_date')->orderBy('entries.id')->orderBy('lines.id')->paginate(50);
        $entries->setCollection($entries->getCollection()->map(function ($row) {
            $row->source_url = $this->sourceUrl($row->source_type, $row->source_id);

            return $row;
        }));

        return ['entries' => $entries];
    }

    public function ledger(Company $company, ?string $from = null, ?string $to = null): array
    {
        return $this->journal($company, $from, $to);
    }

    public function trialBalance(Company $company, ?string $to = null): array
    {
        $totals = $this->lineTotals($company, null, $to);
        $rows = Account::query()->where('accounts.company_id', $company->id)->where('accounts.is_active', true)
            ->leftJoinSub($totals, 'account_totals', fn ($join) => $join->on('account_totals.account_id', '=', 'accounts.id'))
            ->select('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->selectRaw('COALESCE(account_totals.debit_total, 0) as debit_total')
            ->selectRaw('COALESCE(account_totals.credit_total, 0) as credit_total')
            ->orderBy('accounts.code')->get();

        return [
            'rows' => $rows,
            'debit_total' => (float) $rows->sum(fn ($row) => (float) $row->debit_total),
            'credit_total' => (float) $rows->sum(fn ($row) => (float) $row->credit_total),
        ];
    }

    public function accountStatement(Company $company, Account $account, ?string $from = null, ?string $to = null): array
    {
        abort_unless((int) $account->company_id === (int) $company->id, 404);
        $accountIds = Account::query()->where('company_id', $company->id)->get(['id', 'parent_id'])->filter(function (Account $candidate) use ($account, $company): bool {
            $cursor = $candidate;
            while ($cursor && $cursor->parent_id !== null) {
                if ((int) $cursor->parent_id === (int) $account->id) {
                    return true;
                }
                $cursor = Account::query()->where('company_id', $company->id)->find($cursor->parent_id);
            }

            return (int) $candidate->id === (int) $account->id;
        })->pluck('id')->all();
        $opening = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.company_id', $company->id)->whereIn('entries.status', ['posted', 'reversed'])
            ->where('lines.company_id', $company->id)->whereIn('lines.account_id', $accountIds)
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '<', $from))
            ->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) as balance')->value('balance');
        $partyOpening = CustomerSupplier::query()->where('company_id', $company->id)->where('account_id', $account->id)->value('opening_balance') ?? 0;
        $lines = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.company_id', $company->id)->whereIn('entries.status', ['posted', 'reversed'])
            ->where('lines.company_id', $company->id)->whereIn('lines.account_id', $accountIds)
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entries.entry_date', '<=', $to))
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->select('lines.id', 'accounts.code as account_code', 'accounts.name as account_name', 'entries.entry_number', 'entries.entry_date', 'entries.description', 'entries.source_type', 'entries.source_id', 'lines.description as line_description', 'lines.debit', 'lines.credit')
            ->orderBy('entries.entry_date')->orderBy('entries.id')->paginate(50);
        $lines->setCollection($lines->getCollection()->map(function ($line) {
            $line->source_url = $this->sourceUrl($line->source_type, $line->source_id);

            return $line;
        }));
        $openingBalance = (float) $opening + (float) $partyOpening;
        if ($lines->count()) {
            $first = $lines->first();
            $priorLines = DB::table('journal_lines as lines')
                ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
                ->where('entries.company_id', $company->id)->whereIn('entries.status', ['posted', 'reversed'])
                ->where('lines.company_id', $company->id)->whereIn('lines.account_id', $accountIds)
                ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
                ->where(function ($query) use ($first): void {
                    $query->whereDate('entries.entry_date', '<', $first->entry_date)
                        ->orWhere(function ($sameDay) use ($first): void {
                            $sameDay->whereDate('entries.entry_date', '=', $first->entry_date)->where('lines.id', '<', $first->id);
                        });
                })
                ->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) as balance')->value('balance');
            $running = $openingBalance + (float) $priorLines;
            $lines->setCollection($lines->getCollection()->map(function ($line) use (&$running) {
                $running += (float) $line->debit - (float) $line->credit;
                $line->running_balance = round($running, 2);

                return $line;
            }));
        }

        return ['account' => $account, 'opening_balance' => $openingBalance, 'lines' => $lines, 'from' => $from, 'to' => $to];
    }

    public function incomeStatement(Company $company, ?string $from = null, ?string $to = null): array
    {
        return ['rows' => $this->balancesByType($company, ['revenue', 'expense'], $from, $to)];
    }

    public function balanceSheet(Company $company, ?string $to = null): array
    {
        return ['rows' => $this->balancesByType($company, ['asset', 'liability', 'equity'], null, $to)];
    }

    public function debtors(Company $company, float $threshold = 50, ?string $from = null, ?string $to = null): array
    {
        $partyBalances = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('lines.company_id', $company->id)
            ->where('entries.company_id', $company->id)
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->whereNotNull('lines.party_id')
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entries.entry_date', '<=', $to))
            ->groupBy('lines.party_id')
            ->select('lines.party_id')
            ->selectRaw('SUM(lines.debit - lines.credit) as balance')
            ->pluck('balance', 'party_id');
        $qualified = CustomerSupplier::query()
            ->where('customer_suppliers.company_id', $company->id)
            ->where('customer_suppliers.is_customer', true)
            ->where('customer_suppliers.status', 'active')
            ->get(['id', 'name', 'opening_balance'])
            ->map(function (CustomerSupplier $party) use ($partyBalances): CustomerSupplier {
                $party->setAttribute('balance_due', (float) $party->opening_balance + (float) ($partyBalances[$party->id] ?? 0));

                return $party;
            })
            ->filter(fn (CustomerSupplier $party) => (float) $party->balance_due >= $threshold)
            ->sortByDesc('balance_due')
            ->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $rows = new LengthAwarePaginator(
            $qualified->forPage($page, 50)->values(),
            $qualified->count(),
            50,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()],
        );

        return ['rows' => $rows, 'threshold' => $threshold, 'total_outstanding' => round((float) $qualified->sum('balance_due'), 2)];
    }

    private function balancesByType(Company $company, array $types, ?string $from, ?string $to): Collection
    {
        $totals = $this->lineTotals($company, $from, $to);

        return Account::query()->where('accounts.company_id', $company->id)->whereIn('accounts.account_type', $types)
            ->leftJoinSub($totals, 'account_totals', fn ($join) => $join->on('account_totals.account_id', '=', 'accounts.id'))
            ->select('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->selectRaw('COALESCE(account_totals.debit_total, 0) as debit_total')
            ->selectRaw('COALESCE(account_totals.credit_total, 0) as credit_total')
            ->orderBy('accounts.code')->get()
            ->map(function ($row) {
                $debit = (float) $row->debit_total;
                $credit = (float) $row->credit_total;
                $row->balance = in_array($row->account_type, ['asset', 'expense'], true) ? $debit - $credit : $credit - $debit;

                return $row;
            });
    }

    private function lineTotals(Company $company, ?string $from = null, ?string $to = null): Builder
    {
        return DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('lines.company_id', $company->id)
            ->where('entries.company_id', $company->id)
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entries.entry_date', '<=', $to))
            ->groupBy('lines.account_id')
            ->select('lines.account_id')
            ->selectRaw('SUM(lines.debit) as debit_total')
            ->selectRaw('SUM(lines.credit) as credit_total');
    }

    private function sourceUrl(?string $sourceType, mixed $sourceId): ?string
    {
        if (! $sourceType || ! $sourceId) {
            return null;
        }

        return match ($sourceType) {
            Receipt::class => '/accounting#receipt-'.$sourceId,
            PaymentVoucher::class => '/accounting#voucher-'.$sourceId,
            PurchaseBill::class => '/operations/purchase/'.$sourceId.'/edit',
            SalesBill::class => '/operations/sales/'.$sourceId.'/edit',
            default => null,
        };
    }
}
