<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\PartyPaymentAllocation;
use App\Models\PaymentVoucher;
use App\Models\PurchaseBill;
use App\Models\Receipt;
use App\Models\SalesBill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PaymentAllocationService
{
    public function allocateReceipt(Company $company, User $actor, Receipt $receipt, ?int $billId = null): array
    {
        return $this->allocate($company, $actor, 'receipt', $receipt->id, $billId);
    }

    public function allocateVoucher(Company $company, User $actor, PaymentVoucher $voucher, ?int $billId = null): array
    {
        return $this->allocate($company, $actor, 'voucher', $voucher->id, $billId);
    }

    public function allocate(Company $company, User $actor, string $paymentType, int $paymentId, ?int $billId = null): array
    {
        abort_unless(in_array($paymentType, ['receipt', 'voucher'], true), 422);

        return DB::transaction(function () use ($company, $actor, $paymentType, $paymentId, $billId): array {
            $payment = $paymentType === 'receipt'
                ? Receipt::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($paymentId)
                : PaymentVoucher::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($paymentId);
            abort_if($payment->status !== 'posted', 409, 'Only posted payments can be allocated.');
            if (! $payment->party_id) {
                return ['allocations' => [], 'unallocated_amount' => 0.0];
            }

            $billType = $paymentType === 'receipt' ? 'sales' : 'purchase';
            $billClass = $paymentType === 'receipt' ? SalesBill::class : PurchaseBill::class;
            $partyColumn = $paymentType === 'receipt' ? 'customer_id' : 'supplier_id';
            $dateColumn = $paymentType === 'receipt' ? 'bill_date' : 'supplier_bill_date';
            $paymentDate = $paymentType === 'receipt' ? $payment->receipt_date : $payment->voucher_date;
            $activeAmount = (float) PartyPaymentAllocation::query()
                ->where('company_id', $company->id)
                ->where('payment_type', $paymentType)
                ->where('payment_id', $payment->id)
                ->where('status', 'posted')
                ->sum('amount');
            $remaining = round((float) $payment->amount - $activeAmount, 2);
            if ($remaining <= 0) {
                return ['allocations' => [], 'unallocated_amount' => 0.0];
            }

            $query = $billClass::query()
                ->where('company_id', $company->id)
                ->where($partyColumn, $payment->party_id)
                ->where('status', 'approved')
                ->whereDate($dateColumn, '<=', $paymentDate->toDateString())
                ->when($billId, fn ($bills) => $bills->whereKey($billId))
                ->orderBy($dateColumn)
                ->orderBy('id')
                ->lockForUpdate();
            $bills = $query->get();
            if ($billId && $bills->isEmpty()) {
                abort(404, 'The selected invoice is not an open invoice for this party.');
            }

            $created = [];
            foreach ($bills as $bill) {
                if ($remaining <= 0) {
                    break;
                }
                $alreadyAllocated = (float) PartyPaymentAllocation::query()
                    ->where('company_id', $company->id)
                    ->where('bill_type', $billType)
                    ->where('bill_id', $bill->id)
                    ->where('status', 'posted')
                    ->sum('amount');
                $outstanding = round(max(0, (float) $bill->total - (float) $bill->paid_amount - $alreadyAllocated), 2);
                $amount = min($remaining, $outstanding);
                if ($amount <= 0) {
                    continue;
                }

                $sequence = PartyPaymentAllocation::query()
                    ->where('company_id', $company->id)
                    ->where('payment_type', $paymentType)
                    ->where('payment_id', $payment->id)
                    ->where('bill_type', $billType)
                    ->where('bill_id', $bill->id)
                    ->count() + 1;
                $allocation = PartyPaymentAllocation::query()->create([
                    'company_id' => $company->id,
                    'party_id' => $payment->party_id,
                    'bill_type' => $billType,
                    'bill_id' => $bill->id,
                    'payment_type' => $paymentType,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'status' => 'posted',
                    'idempotency_key' => implode(':', [$paymentType, $payment->id, $billType, $bill->id, $sequence]),
                    'created_by' => $actor->id,
                    'allocated_at' => now(),
                ]);
                $created[] = $allocation;
                $remaining = round($remaining - $amount, 2);
            }

            return [
                'allocations' => $created,
                'unallocated_amount' => round(max(0, (float) $payment->amount - $activeAmount - array_sum(array_map(fn ($row) => (float) $row->amount, $created))), 2),
            ];
        });
    }

    public function unallocatedAmount(Company $company, string $paymentType, int $paymentId): float
    {
        $model = $paymentType === 'receipt' ? Receipt::class : PaymentVoucher::class;
        $payment = $model::query()->where('company_id', $company->id)->findOrFail($paymentId);
        if ($payment->status !== 'posted') {
            return 0.0;
        }
        $allocated = (float) PartyPaymentAllocation::query()
            ->where('company_id', $company->id)
            ->where('payment_type', $paymentType)
            ->where('payment_id', $paymentId)
            ->where('status', 'posted')
            ->sum('amount');

        return round(max(0, (float) $payment->amount - $allocated), 2);
    }

    public function openInvoices(Company $company, string $billType): array
    {
        abort_unless(in_array($billType, ['sales', 'purchase'], true), 422);
        $table = $billType === 'sales' ? 'sales_bills' : 'purchase_bills';
        $partyColumn = $billType === 'sales' ? 'customer_id' : 'supplier_id';
        $dateColumn = $billType === 'sales' ? 'bill_date' : 'supplier_bill_date';
        $external = DB::table('party_payment_allocations')
            ->where('company_id', $company->id)
            ->where('bill_type', $billType)
            ->where('status', 'posted')
            ->groupBy('bill_id')
            ->select('bill_id')
            ->selectRaw('SUM(amount) as allocated_amount');

        return DB::table($table.' as bills')
            ->leftJoinSub($external, 'allocations', fn ($join) => $join->on('allocations.bill_id', '=', 'bills.id'))
            ->join('customer_suppliers as parties', 'parties.id', '=', 'bills.'.$partyColumn)
            ->where('bills.company_id', $company->id)
            ->where('bills.status', 'approved')
            ->where('parties.company_id', $company->id)
            ->select('bills.id', 'bills.'.$partyColumn.' as party_id', 'bills.internal_number', 'bills.'.$dateColumn.' as bill_date')
            ->selectRaw('ROUND(bills.total - bills.paid_amount - COALESCE(allocations.allocated_amount, 0), 2) as outstanding')
            ->whereRaw('bills.total - bills.paid_amount - COALESCE(allocations.allocated_amount, 0) > 0')
            ->orderBy('bills.'.$dateColumn)
            ->orderBy('bills.id')
            ->limit(250)
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    public function reverseForPayment(Company $company, string $paymentType, int $paymentId, User $actor, int $reversalJournalId, string $reason): void
    {
        PartyPaymentAllocation::query()
            ->where('company_id', $company->id)
            ->where('payment_type', $paymentType)
            ->where('payment_id', $paymentId)
            ->where('status', 'posted')
            ->lockForUpdate()
            ->get()
            ->each(function (PartyPaymentAllocation $allocation) use ($actor, $reversalJournalId, $reason): void {
                $allocation->forceFill([
                    'status' => 'reversed',
                    'reversed_by' => $actor->id,
                    'reversed_at' => now(),
                    'reversal_reason' => $reason,
                    'reversal_journal_entry_id' => $reversalJournalId,
                ])->save();
            });
    }
}
