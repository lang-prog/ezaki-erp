<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invoice-level receivables/payables. Outstanding = total - direct payment - posted allocations,
 * so the aging buckets reconcile to the invoice rows by construction.
 */
final class InvoiceSettlementService
{
    private const BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'];

    public function report(Company $company, string $kind, array $filters = [], int $perPage = 25): array
    {
        abort_unless(in_array($kind, ['sales', 'purchase'], true), 404);
        $asOf = Carbon::parse($filters['as_of'] ?? now()->toDateString())->startOfDay();
        $rows = $this->rows($company, $kind, $filters, $asOf);

        $page = LengthAwarePaginator::resolveCurrentPage('page');
        $pageRows = $rows->forPage($page, $perPage)->values();
        $history = $this->history($company, $kind, $pageRows->pluck('id')->all());
        $pageRows = $pageRows->map(fn (array $row): array => $row + ['history' => $history[$row['id']] ?? []]);
        $paginator = new LengthAwarePaginator($pageRows, $rows->count(), $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]);

        $credit = $this->unallocatedCredit($company, $kind, isset($filters['party_id']) ? (int) $filters['party_id'] : null, $asOf);

        return [
            'rows' => $paginator,
            'summary' => $this->summary($rows, array_sum($credit)),
            'parties' => $this->partyAging($rows, $credit),
            'kind' => $kind,
            'as_of' => $asOf->toDateString(),
        ];
    }

    private function rows(Company $company, string $kind, array $filters, Carbon $asOf): Collection
    {
        $table = $kind === 'sales' ? 'sales_bills' : 'purchase_bills';
        $partyColumn = $kind === 'sales' ? 'customer_id' : 'supplier_id';
        $dateColumn = $kind === 'sales' ? 'bill_date' : 'supplier_bill_date';
        $externalColumn = $kind === 'sales' ? 'customer_bill_number' : 'supplier_bill_number';

        $allocated = DB::table('party_payment_allocations')
            ->where('company_id', $company->id)->where('bill_type', $kind)->where('status', 'posted')
            ->groupBy('bill_id')->select('bill_id')->selectRaw('SUM(amount) as posted_amount');
        $counts = DB::table('party_payment_allocations')
            ->where('company_id', $company->id)->where('bill_type', $kind)
            ->groupBy('bill_id')->select('bill_id')->selectRaw('COUNT(*) as payment_count');

        $query = DB::table($table.' as bills')
            ->join('customer_suppliers as parties', fn ($join) => $join->on('parties.id', '=', 'bills.'.$partyColumn)->on('parties.company_id', '=', 'bills.company_id'))
            ->leftJoin('branches', 'branches.id', '=', 'bills.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'bills.warehouse_id')
            ->leftJoinSub($allocated, 'allocated', 'allocated.bill_id', '=', 'bills.id')
            ->leftJoinSub($counts, 'counts', 'counts.bill_id', '=', 'bills.id')
            ->where('bills.company_id', $company->id)
            ->whereIn('bills.status', ['approved', 'reversed'])
            ->when($filters['party_id'] ?? null, fn ($q, $value) => $q->where('bills.'.$partyColumn, (int) $value))
            ->when($filters['branch_id'] ?? null, fn ($q, $value) => $q->where('bills.branch_id', (int) $value))
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($inner) => $inner->where('bills.internal_number', 'like', '%'.$this->escapeLike((string) $value).'%')->orWhere('bills.'.$externalColumn, 'like', '%'.$this->escapeLike((string) $value).'%')))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('bills.'.$dateColumn, '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('bills.'.$dateColumn, '<=', $value))
            ->when($filters['due_from'] ?? null, fn ($q, $value) => $q->whereDate('bills.due_date', '>=', $value))
            ->when($filters['due_to'] ?? null, fn ($q, $value) => $q->whereDate('bills.due_date', '<=', $value))
            ->orderBy('bills.'.$dateColumn)->orderBy('bills.id')
            ->select('bills.id', 'bills.internal_number', 'bills.'.$externalColumn.' as external_number', 'bills.'.$dateColumn.' as bill_date', 'bills.due_date', 'bills.total', 'bills.paid_amount', 'bills.status', 'bills.is_revised', 'bills.'.$partyColumn.' as party_id', 'parties.name as party_name', 'branches.name as branch_name', 'warehouses.name as warehouse_name')
            ->selectRaw('COALESCE(allocated.posted_amount, 0) as allocated_amount')
            ->selectRaw('COALESCE(counts.payment_count, 0) as payment_count');

        $rows = $query->get()->map(function ($bill) use ($kind, $asOf): array {
            $reversed = $bill->status === 'reversed';
            $settled = round((float) $bill->paid_amount + (float) $bill->allocated_amount, 2);
            $outstanding = $reversed ? 0.0 : round(max(0, (float) $bill->total - $settled), 2);
            $dueDate = Carbon::parse($bill->due_date ?? $bill->bill_date)->startOfDay();
            $daysOverdue = $outstanding > 0 && $dueDate->lt($asOf) ? (int) $dueDate->diffInDays($asOf) : 0;
            $state = $reversed ? 'reversed' : ($outstanding <= 0 ? 'paid' : ($settled > 0 ? 'partial' : 'unpaid'));

            return [
                'id' => (int) $bill->id,
                'kind' => $kind,
                'number' => $bill->internal_number,
                'external_number' => $bill->external_number,
                'date' => Carbon::parse($bill->bill_date)->toDateString(),
                'due_date' => $bill->due_date ? Carbon::parse($bill->due_date)->toDateString() : null,
                'party_id' => (int) $bill->party_id,
                'party_name' => $bill->party_name,
                'branch_name' => $bill->branch_name,
                'warehouse_name' => $bill->warehouse_name,
                'total' => round((float) $bill->total, 2),
                'settled' => $settled,
                'outstanding' => $outstanding,
                'days_overdue' => $daysOverdue,
                'bucket' => $outstanding > 0 ? $this->bucket($daysOverdue) : null,
                'state' => $state,
                'is_overdue' => $daysOverdue > 0,
                'is_revised' => (bool) $bill->is_revised,
                'payment_count' => (int) $bill->payment_count,
                'view_url' => route($kind === 'sales' ? 'company.sales.show' : 'company.purchase.show', ['bill' => $bill->id]),
                'print_url' => route($kind === 'sales' ? 'company.sales.print' : 'company.purchase.print', ['bill' => $bill->id, 'mode' => 'invoice']),
            ];
        });

        $state = $filters['state'] ?? null;
        if ($state && $state !== 'all') {
            $rows = $rows->filter(fn (array $row): bool => $state === 'overdue' ? $row['is_overdue'] : $row['state'] === $state);
        }
        if (! empty($filters['overdue_only'])) {
            $rows = $rows->filter(fn (array $row): bool => $row['is_overdue']);
        }

        return $rows->values();
    }

    private function history(Company $company, string $kind, array $billIds): array
    {
        if ($billIds === []) {
            return [];
        }
        $paymentType = $kind === 'sales' ? 'receipt' : 'voucher';
        $paymentTable = $kind === 'sales' ? 'receipts' : 'payment_vouchers';
        $numberColumn = $kind === 'sales' ? 'receipt_number' : 'voucher_number';
        $dateColumn = $kind === 'sales' ? 'receipt_date' : 'voucher_date';
        $viewRoute = $kind === 'sales' ? 'company.receipt.show' : 'company.voucher.show';
        $table = $kind === 'sales' ? 'sales_bills' : 'purchase_bills';

        $history = [];
        $allocations = DB::table('party_payment_allocations as allocations')
            ->join($paymentTable.' as payments', fn ($join) => $join->on('payments.id', '=', 'allocations.payment_id')->on('payments.company_id', '=', 'allocations.company_id'))
            ->where('allocations.company_id', $company->id)->where('allocations.bill_type', $kind)->where('allocations.payment_type', $paymentType)
            ->whereIn('allocations.bill_id', $billIds)
            ->orderBy('payments.'.$dateColumn)->orderBy('allocations.id')
            ->get(['allocations.bill_id', 'allocations.payment_id', 'allocations.amount', 'allocations.status', 'allocations.reversal_reason', 'allocations.reversed_at', 'payments.'.$numberColumn.' as number', 'payments.'.$dateColumn.' as payment_date']);
        foreach ($allocations as $allocation) {
            $history[(int) $allocation->bill_id][] = [
                'type' => $paymentType,
                'number' => $allocation->number,
                'date' => Carbon::parse($allocation->payment_date)->toDateString(),
                'amount' => round((float) $allocation->amount, 2),
                'status' => $allocation->status,
                'reversal_reason' => $allocation->reversal_reason,
                'reversed_at' => $allocation->reversed_at ? Carbon::parse($allocation->reversed_at)->toDateString() : null,
                'view_url' => route($viewRoute, ['document' => $allocation->payment_id]),
            ];
        }

        $initial = DB::table($table.' as bills')
            ->leftJoin('cashboxes', 'cashboxes.id', '=', 'bills.cashbox_id')
            ->leftJoin('banks', 'banks.id', '=', 'bills.bank_id')
            ->where('bills.company_id', $company->id)->whereIn('bills.id', $billIds)->where('bills.paid_amount', '>', 0)
            ->get(['bills.id', 'bills.paid_amount', 'bills.status', 'bills.'.($kind === 'sales' ? 'bill_date' : 'supplier_bill_date').' as bill_date', 'cashboxes.name as cashbox_name', 'banks.name as bank_name']);
        foreach ($initial as $row) {
            $history[(int) $row->id] ??= [];
            array_unshift($history[(int) $row->id], [
                'type' => 'invoice_payment',
                'number' => null,
                'source' => $row->cashbox_name ?? $row->bank_name,
                'date' => Carbon::parse($row->bill_date)->toDateString(),
                'amount' => round((float) $row->paid_amount, 2),
                'status' => $row->status === 'reversed' ? 'reversed' : 'posted',
                'reversal_reason' => null,
                'reversed_at' => null,
                'view_url' => null,
            ]);
        }

        return $history;
    }

    private function unallocatedCredit(Company $company, string $kind, ?int $partyId, Carbon $asOf): array
    {
        $table = $kind === 'sales' ? 'receipts' : 'payment_vouchers';
        $dateColumn = $kind === 'sales' ? 'receipt_date' : 'voucher_date';
        $paymentType = $kind === 'sales' ? 'receipt' : 'voucher';
        $allocated = DB::table('party_payment_allocations')
            ->where('company_id', $company->id)->where('payment_type', $paymentType)->where('status', 'posted')
            ->groupBy('payment_id')->select('payment_id')->selectRaw('SUM(amount) as posted_amount');

        return DB::table($table.' as payments')
            ->leftJoinSub($allocated, 'allocated', 'allocated.payment_id', '=', 'payments.id')
            ->where('payments.company_id', $company->id)->where('payments.status', 'posted')->whereNotNull('payments.party_id')
            ->whereDate('payments.'.$dateColumn, '<=', $asOf->toDateString())
            ->when($partyId, fn ($q) => $q->where('payments.party_id', $partyId))
            ->get(['payments.party_id', 'payments.amount', DB::raw('COALESCE(allocated.posted_amount, 0) as allocated_amount')])
            ->groupBy('party_id')
            ->map(fn (Collection $items): float => round((float) $items->sum(fn ($item) => max(0, (float) $item->amount - (float) $item->allocated_amount)), 2))
            ->filter(fn (float $value): bool => $value > 0)
            ->all();
    }

    private function summary(Collection $rows, float $credit): array
    {
        $open = $rows->where('outstanding', '>', 0);
        $summary = array_fill_keys(self::BUCKETS, 0.0);
        foreach ($open as $row) {
            $summary[$row['bucket']] += $row['outstanding'];
        }
        $summary = array_map(fn (float $value): float => round($value, 2), $summary);

        return $summary + [
            'total_outstanding' => round((float) $open->sum('outstanding'), 2),
            'unallocated_credit' => round($credit, 2),
            'invoice_count' => $rows->count(),
            'open_count' => $open->count(),
            'overdue_count' => $open->where('is_overdue', true)->count(),
        ];
    }

    private function partyAging(Collection $rows, array $credit): array
    {
        return $rows->where('outstanding', '>', 0)->groupBy('party_id')->map(function (Collection $items, $partyId) use ($credit): array {
            $row = ['party_id' => (int) $partyId, 'party_name' => $items->first()['party_name']] + array_fill_keys(self::BUCKETS, 0.0);
            foreach ($items as $item) {
                $row[$item['bucket']] += $item['outstanding'];
            }
            foreach (self::BUCKETS as $bucket) {
                $row[$bucket] = round($row[$bucket], 2);
            }

            return $row + ['total' => round((float) $items->sum('outstanding'), 2), 'unallocated_credit' => $credit[(int) $partyId] ?? 0.0, 'invoice_count' => $items->count()];
        })->sortByDesc('total')->values()->all();
    }

    private function bucket(int $days): string
    {
        return $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus')));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
