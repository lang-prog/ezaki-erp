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
use Illuminate\Support\Carbon;
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
        $accountIds = $this->descendantIds($company, $account->id);
        $opening = $this->balanceForAccounts($company, $accountIds, $from);
        $legacyPartyOpening = CustomerSupplier::query()->where('company_id', $company->id)->where('account_id', $account->id)->whereNull('opening_balance_journal_entry_id')->sum('opening_balance');
        $lines = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.company_id', $company->id)->whereIn('entries.status', ['posted', 'reversed'])
            ->where('lines.company_id', $company->id)->whereIn('lines.account_id', $accountIds)
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entries.entry_date', '<=', $to))
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->select('lines.id', 'accounts.code as account_code', 'accounts.name as account_name', 'entries.entry_number', 'entries.entry_date', 'entries.description', 'entries.source_type', 'entries.source_id', 'lines.description as line_description', 'lines.debit', 'lines.credit')
            ->orderBy('entries.entry_date')->orderBy('entries.id')->orderBy('lines.id')->paginate(50);
        $lines->setCollection($lines->getCollection()->map(function ($line) {
            $line->source_url = $this->sourceUrl($line->source_type, $line->source_id);

            return $line;
        }));
        $this->attachInvoiceDetails($lines);
        $openingBalance = (float) $opening + (float) $legacyPartyOpening;
        if ($lines->count()) {
            $first = $lines->first();
            $priorLines = $this->balanceBeforeLine($company, $accountIds, $first->entry_date, (int) $first->id, $from);
            $running = $openingBalance + (float) $priorLines;
            $lines->setCollection($lines->getCollection()->map(function ($line) use (&$running) {
                $running += (float) $line->debit - (float) $line->credit;
                $line->running_balance = round($running, 2);

                return $line;
            }));
        }

        return ['account' => $account, 'account_ids' => $accountIds, 'opening_balance' => round($openingBalance, 2), 'lines' => $lines, 'from' => $from, 'to' => $to];
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
        return $this->partyAging($company, true, $threshold, $from, $to);
    }

    public function receivables(Company $company, float $threshold = 0, ?string $from = null, ?string $to = null): array
    {
        return $this->partyAging($company, true, $threshold, $from, $to);
    }

    public function payables(Company $company, float $threshold = 0, ?string $from = null, ?string $to = null): array
    {
        return $this->partyAging($company, false, $threshold, $from, $to);
    }

    public function creditors(Company $company, float $threshold = 0, ?string $from = null, ?string $to = null): array
    {
        return $this->payables($company, $threshold, $from, $to);
    }

    private function partyAging(Company $company, bool $customer, float $threshold, ?string $from, ?string $to): array
    {
        $partyBalances = DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('lines.company_id', $company->id)
            ->where('entries.company_id', $company->id)
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->whereNotNull('lines.party_id')
            ->when($to, fn ($query) => $query->whereDate('entries.entry_date', '<=', $to))
            ->groupBy('lines.party_id')
            ->select('lines.party_id')
            ->selectRaw('SUM(lines.debit - lines.credit) as balance')
            ->pluck('balance', 'party_id');
        $opening = CustomerSupplier::query()
            ->where('company_id', $company->id)
            ->whereNull('opening_balance_journal_entry_id')
            ->where($customer ? 'is_customer' : 'is_supplier', true)
            ->pluck('opening_balance', 'id');
        $direction = CustomerSupplier::query()->where('company_id', $company->id)->pluck('opening_balance_direction', 'id');
        $parties = CustomerSupplier::query()
            ->where('customer_suppliers.company_id', $company->id)
            ->where('customer_suppliers.'.($customer ? 'is_customer' : 'is_supplier'), true)
            ->where('customer_suppliers.status', 'active')
            ->get(['id', 'name', 'opening_balance', 'opening_balance_direction', 'opening_balance_journal_entry_id'])
            ->map(function (CustomerSupplier $party) use ($company, $partyBalances, $opening, $direction, $customer, $to): CustomerSupplier {
                $balance = (float) ($partyBalances[$party->id] ?? 0);
                if (isset($opening[$party->id]) && ! $party->opening_balance_journal_entry_id) {
                    $value = (float) $opening[$party->id];
                    $balance += ($direction[$party->id] ?? ($customer ? 'debit' : 'credit')) === 'debit' ? $value : -$value;
                }
                $due = $customer ? $balance : -$balance;
                $party->setAttribute('balance_due', round(max($due, 0), 2));
                $party->setAttribute('balance_signed', round($balance, 2));
                $party->setAttribute('aging', $this->agingBuckets($company, $party->id, $customer, $to, (float) $party->balance_due));

                return $party;
            })
            ->filter(fn (CustomerSupplier $party) => (float) $party->balance_due > 0)
            ->sortByDesc('balance_due')
            ->values();
        $totalOutstanding = round((float) $parties->sum('balance_due'), 2);
        $qualified = $parties->filter(fn (CustomerSupplier $party) => (float) $party->balance_due >= $threshold)->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $rows = new LengthAwarePaginator(
            $qualified->forPage($page, 50)->values(),
            $qualified->count(),
            50,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()],
        );

        return ['rows' => $rows, 'threshold' => $threshold, 'total_outstanding' => $totalOutstanding, 'total_debt' => $totalOutstanding, 'qualified_total' => round((float) $qualified->sum('balance_due'), 2), 'party_type' => $customer ? 'customer' : 'supplier'];
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

    private function descendantIds(Company $company, int $accountId): array
    {
        $accounts = Account::query()->where('company_id', $company->id)->get(['id', 'parent_id']);
        $children = $accounts->groupBy('parent_id');
        $ids = [$accountId];
        $stack = [$accountId];
        while ($stack) {
            $parent = array_pop($stack);
            foreach ($children->get($parent, collect()) as $child) {
                if (! in_array((int) $child->id, $ids, true)) {
                    $ids[] = (int) $child->id;
                    $stack[] = (int) $child->id;
                }
            }
        }

        return $ids;
    }

    private function balanceForAccounts(Company $company, array $accountIds, ?string $before): float
    {
        return (float) DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.company_id', $company->id)
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->where('lines.company_id', $company->id)
            ->whereIn('lines.account_id', $accountIds)
            ->when($before, fn ($query) => $query->whereDate('entries.entry_date', '<', $before))
            ->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) as balance')
            ->value('balance');
    }

    private function balanceBeforeLine(Company $company, array $accountIds, string $date, int $lineId, ?string $from = null): float
    {
        $date = Carbon::parse($date)->toDateString();

        return (float) DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.company_id', $company->id)
            ->whereIn('entries.status', ['posted', 'reversed'])
            ->where('lines.company_id', $company->id)
            ->whereIn('lines.account_id', $accountIds)
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from))
            ->where(function ($query) use ($date, $lineId): void {
                $query->whereDate('entries.entry_date', '<', $date)
                    ->orWhere(function ($sameDay) use ($date, $lineId): void {
                        $sameDay->whereDate('entries.entry_date', '=', $date)->where('lines.id', '<', $lineId);
                    });
            })
            ->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) as balance')
            ->value('balance');
    }

    private function agingBuckets(Company $company, int $partyId, bool $customer, ?string $to, float $balance): array
    {
        $asOf = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $query = $customer
            ? SalesBill::query()->where('company_id', $company->id)->where('customer_id', $partyId)->where('status', 'approved')
            : PurchaseBill::query()->where('company_id', $company->id)->where('supplier_id', $partyId)->where('status', 'approved');
        $query->whereDate($customer ? 'bill_date' : 'supplier_bill_date', '<=', $asOf->toDateString());
        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0];
        foreach ($query->get(['total', 'paid_amount', 'due_date', $customer ? 'bill_date' : 'supplier_bill_date']) as $bill) {
            $due = max((float) $bill->total - (float) $bill->paid_amount, 0);
            if ($due <= 0) {
                continue;
            }
            $date = $bill->due_date ?? $bill->{$customer ? 'bill_date' : 'supplier_bill_date'};
            $days = max(0, Carbon::parse($date)->diffInDays($asOf, false));
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus')));
            $buckets[$bucket] += $customer ? $due : $due;
        }
        $buckets['total'] = round(array_sum($buckets), 2);
        if ($buckets['total'] < $balance) {
            $buckets['current'] += $balance - $buckets['total'];
        }

        return array_map(fn (float $amount): float => round($amount, 2), $buckets);
    }

    private function attachInvoiceDetails(LengthAwarePaginator $lines): void
    {
        $salesIds = $lines->getCollection()->filter(fn ($line) => $line->source_type === SalesBill::class)->pluck('source_id')->filter()->unique();
        $purchaseIds = $lines->getCollection()->filter(fn ($line) => $line->source_type === PurchaseBill::class)->pluck('source_id')->filter()->unique();
        $sales = SalesBill::query()->whereIn('id', $salesIds)->with('lines.product:id,name')->get()->keyBy('id');
        $purchases = PurchaseBill::query()->whereIn('id', $purchaseIds)->with('lines.product:id,name')->get()->keyBy('id');
        $lines->setCollection($lines->getCollection()->map(function ($line) use ($sales, $purchases) {
            $bill = $line->source_type === SalesBill::class ? $sales->get($line->source_id) : $purchases->get($line->source_id);
            if (! $bill) {
                return $line;
            }
            $details = $bill->lines->map(fn ($item) => [
                'product' => $item->product?->name,
                'weight' => (float) ($item->actual_weight ?? $item->factory_weight ?? 0),
                'factory_weight' => (float) ($item->factory_weight ?? 0),
                'actual_weight' => (float) ($item->actual_weight ?? 0),
                'unit_price' => (float) ($item->unit_price ?? 0),
                'value' => (float) ($item->line_total ?? 0),
                'packages' => (float) ($item->packages ?? 0),
            ])->values()->all();
            $line->invoice_details = $details;
            $line->details = $details;
            $line->weight = round(array_sum(array_column($details, 'weight')), 3);
            $line->unit_price = count($details) === 1 ? $details[0]['unit_price'] : null;
            $line->value = round(array_sum(array_column($details, 'value')), 2);

            return $line;
        }));
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
