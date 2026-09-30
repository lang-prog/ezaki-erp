<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\Cashbox;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\FiscalPeriod;
use App\Models\FleetExpense;
use App\Models\JournalEntry;
use App\Models\MaintenanceRecord;
use App\Models\PaymentVoucher;
use App\Models\PurchaseBill;
use App\Models\Receipt;
use App\Models\SalesBill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public function __construct(private readonly AccountingFoundationService $foundation) {}

    public function createParty(Company $company, User $actor, array $data): CustomerSupplier
    {
        return DB::transaction(function () use ($company, $actor, $data): CustomerSupplier {
            $parent = Account::query()->where('company_id', $company->id)->findOrFail($data['parent_account_id']);
            $account = $this->foundation->createChild($company, $parent, $data['name']);
            $party = CustomerSupplier::query()->create([
                'company_id' => $company->id,
                'account_id' => $account->id,
                'parent_account_id' => $parent->id,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'address' => $data['address'] ?? null,
                'is_customer' => $data['is_customer'],
                'is_supplier' => $data['is_supplier'],
                'opening_balance' => $data['opening_balance'] ?? 0,
                'opening_balance_direction' => $data['opening_balance_direction'] ?? ($data['is_supplier'] && ! $data['is_customer'] ? 'credit' : 'debit'),
                'opening_balance_date' => $data['opening_balance_date'] ?? now()->startOfYear()->toDateString(),
                'credit_limit' => $data['credit_limit'] ?? null,
                'status' => 'active',
            ]);
            $this->postOpeningBalance($company, $actor, $party);
            app(AuditRecorder::class)->record('parties.created', $party, $company->id, $actor->id, ['account_code' => $account->code]);

            return $party->load('account');
        });
    }

    public function updateParty(Company $company, CustomerSupplier $party, User $actor, array $data): CustomerSupplier
    {
        abort_unless((int) $party->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($company, $party, $actor, $data): CustomerSupplier {
            $parent = Account::query()->where('company_id', $company->id)->findOrFail((int) $data['parent_account_id']);
            $account = Account::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($party->account_id);
            if ((int) $account->parent_id !== (int) $parent->id) {
                $this->foundation->moveAccount($company, $account, $parent);
            }
            $newAmount = round((float) ($data['opening_balance'] ?? 0), 2);
            $newDirection = $data['opening_balance_direction'] ?? ($data['is_supplier'] && ! $data['is_customer'] ? 'credit' : 'debit');
            if ($party->opening_balance_journal_entry_id && (round((float) $party->opening_balance, 2) !== $newAmount || $party->opening_balance_direction !== $newDirection)) {
                $oldEntry = JournalEntry::query()->where('company_id', $company->id)->find($party->opening_balance_journal_entry_id);
                if ($oldEntry?->status === 'posted') {
                    $this->reverseJournal($company, $actor, $oldEntry, now()->toDateString());
                }
                $party->forceFill(['opening_balance_journal_entry_id' => null]);
            }
            $party->forceFill([
                'parent_account_id' => $parent->id,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'address' => $data['address'] ?? null,
                'is_customer' => $data['is_customer'],
                'is_supplier' => $data['is_supplier'],
                'opening_balance' => $data['opening_balance'] ?? 0,
                'opening_balance_direction' => $newDirection,
                'opening_balance_date' => $data['opening_balance_date'] ?? $party->opening_balance_date?->toDateString() ?? now()->startOfYear()->toDateString(),
                'credit_limit' => $data['credit_limit'] ?? null,
            ])->save();
            $this->postOpeningBalance($company, $actor, $party->fresh());
            app(AuditRecorder::class)->record('parties.updated', $party, $company->id, $actor->id, ['account_code' => $account->code]);

            return $party->fresh('account');
        });
    }

    public function postOpeningBalance(Company $company, User $actor, CustomerSupplier $party): ?JournalEntry
    {
        abort_unless((int) $party->company_id === (int) $company->id, 404);
        $amount = round((float) $party->opening_balance, 2);
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($company, $actor, $party, $amount): JournalEntry {
            $party = CustomerSupplier::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($party->id);
            if ($party->opening_balance_journal_entry_id) {
                $existing = JournalEntry::query()->where('company_id', $company->id)->find($party->opening_balance_journal_entry_id);
                if ($existing?->status === 'posted') {
                    return $existing->load('lines');
                }
            }
            $date = $party->opening_balance_date?->toDateString() ?? now()->startOfYear()->toDateString();
            $period = $this->foundation->openPeriodFor($company, $date);
            $equity = $this->foundation->systemAccount($company, 'opening_balance_equity');
            $debitParty = $party->opening_balance_direction !== 'credit';
            $entry = $this->postJournal($company, $actor, $period, $date, 'Opening balance - '.$party->name, CustomerSupplier::class, $party->id, [
                ['account_id' => $party->account_id, 'party_id' => $party->id, 'debit' => $debitParty ? $amount : 0, 'credit' => $debitParty ? 0 : $amount, 'description' => 'Party opening balance'],
                ['account_id' => $equity->id, 'debit' => $debitParty ? 0 : $amount, 'credit' => $debitParty ? $amount : 0, 'description' => 'Opening balance equity'],
            ]);
            $party->forceFill(['opening_balance_journal_entry_id' => $entry->id])->save();
            app(AuditRecorder::class)->record('parties.opening_balance_posted', $party, $company->id, $actor->id, ['journal_entry_id' => $entry->id, 'direction' => $party->opening_balance_direction]);

            return $entry;
        });
    }

    public function createCashbox(Company $company, User $actor, string $name): Cashbox
    {
        return DB::transaction(function () use ($company, $actor, $name): Cashbox {
            $currentAssets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
            $account = $this->foundation->createChild($company, $currentAssets, $name);
            $cashbox = Cashbox::query()->create(['company_id' => $company->id, 'account_id' => $account->id, 'name' => $name, 'currency' => 'EGP', 'is_active' => true]);
            app(AuditRecorder::class)->record('cashboxes.created', $cashbox, $company->id, $actor->id, ['account_code' => $account->code]);

            return $cashbox;
        });
    }

    public function createBank(Company $company, User $actor, string $name, ?string $accountNumber): Bank
    {
        return DB::transaction(function () use ($company, $actor, $name, $accountNumber): Bank {
            $currentAssets = Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail();
            $account = $this->foundation->createChild($company, $currentAssets, $name);
            $bank = Bank::query()->create([
                'company_id' => $company->id, 'account_id' => $account->id,
                'name' => $name, 'account_number' => $accountNumber, 'currency' => 'EGP', 'is_active' => true,
            ]);
            app(AuditRecorder::class)->record('banks.created', $bank, $company->id, $actor->id, ['account_code' => $account->code]);

            return $bank;
        });
    }

    public function receive(Company $company, User $actor, array $data): Receipt
    {
        return DB::transaction(function () use ($company, $actor, $data): Receipt {
            $counterpartyType = $data['counterparty_type'] ?? 'customer';
            $party = $counterpartyType === 'customer' ? CustomerSupplier::query()->where('company_id', $company->id)->where('is_customer', true)->findOrFail((int) $data['party_id']) : null;
            $sourceAccount = $this->paymentSourceAccount($company, $data);
            $counterpartyAccount = $counterpartyType === 'other' ? $this->postableOtherAccount($company, (int) $data['other_account_id'], $sourceAccount) : $party?->account;
            $date = $data['receipt_date'];
            $period = $this->foundation->openPeriodFor($company, $date);
            $receiptNumber = $this->nextDocumentNumber($company, 'receipts', 'receipt_number', 'RCT');
            $receipt = Receipt::query()->create([
                'company_id' => $company->id, 'party_id' => $party?->id, 'other_account_id' => $counterpartyType === 'other' ? $counterpartyAccount->id : null, 'counterparty_type' => $counterpartyType,
                'cashbox_id' => $data['cashbox_id'] ?? null, 'bank_id' => $data['bank_id'] ?? null,
                'created_by' => $actor->id, 'receipt_number' => $receiptNumber, 'receipt_date' => $date,
                'amount' => $data['amount'], 'delivered_by' => $data['delivered_by'] ?? null,
                'received_by' => $data['received_by'] ?? null, 'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null, 'status' => 'posted',
            ]);
            $entry = $this->postJournal($company, $actor, $period, $date, 'Customer receipt '.$receiptNumber, Receipt::class, $receipt->id, [
                ['account_id' => $sourceAccount->id, 'party_id' => $party?->id, 'debit' => $data['amount'], 'credit' => 0, 'description' => 'Receipt into '.$sourceAccount->name],
                ['account_id' => $counterpartyAccount->id, 'party_id' => $party?->id, 'debit' => 0, 'credit' => $data['amount'], 'description' => $counterpartyType === 'other' ? 'Other account receipt' : 'Customer receipt'],
            ]);
            $receipt->forceFill(['journal_entry_id' => $entry->id])->save();
            app(AuditRecorder::class)->record('receipts.posted', $receipt, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);

            return $receipt;
        });
    }

    public function paySupplier(Company $company, User $actor, array $data): PaymentVoucher
    {
        return DB::transaction(function () use ($company, $actor, $data): PaymentVoucher {
            $counterpartyType = $data['counterparty_type'] ?? 'supplier';
            $party = $counterpartyType === 'supplier' ? CustomerSupplier::query()->where('company_id', $company->id)->where('is_supplier', true)->findOrFail((int) $data['party_id']) : null;
            $sourceAccount = $this->paymentSourceAccount($company, $data);
            $counterpartyAccount = $counterpartyType === 'other' ? $this->postableOtherAccount($company, (int) $data['other_account_id'], $sourceAccount) : $party?->account;
            $date = $data['voucher_date'];
            $period = $this->foundation->openPeriodFor($company, $date);
            $voucherNumber = $this->nextDocumentNumber($company, 'payment_vouchers', 'voucher_number', 'PV');
            $voucher = PaymentVoucher::query()->create([
                'company_id' => $company->id, 'party_id' => $party?->id, 'other_account_id' => $counterpartyType === 'other' ? $counterpartyAccount->id : null, 'counterparty_type' => $counterpartyType,
                'cashbox_id' => $data['cashbox_id'] ?? null, 'bank_id' => $data['bank_id'] ?? null,
                'created_by' => $actor->id, 'voucher_number' => $voucherNumber, 'voucher_date' => $date,
                'amount' => $data['amount'], 'paid_by' => $data['paid_by'] ?? null,
                'received_by' => $data['received_by'] ?? null, 'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null, 'status' => 'posted',
            ]);
            $entry = $this->postJournal($company, $actor, $period, $date, 'Supplier payment '.$voucherNumber, PaymentVoucher::class, $voucher->id, [
                ['account_id' => $counterpartyAccount->id, 'party_id' => $party?->id, 'debit' => $data['amount'], 'credit' => 0, 'description' => $counterpartyType === 'other' ? 'Other account payment' : 'Supplier payment'],
                ['account_id' => $sourceAccount->id, 'party_id' => $party?->id, 'debit' => 0, 'credit' => $data['amount'], 'description' => 'Payment from '.$sourceAccount->name],
            ]);
            $voucher->forceFill(['journal_entry_id' => $entry->id])->save();
            app(AuditRecorder::class)->record('payment_vouchers.posted', $voucher, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);

            return $voucher;
        });
    }

    public function backfillUnjournaledVouchers(Company $company, User $actor): int
    {
        return DB::transaction(function () use ($company, $actor): int {
            $count = 0;
            $receipts = Receipt::query()->where('company_id', $company->id)->where('status', 'posted')->whereNull('journal_entry_id')->lockForUpdate()->get();
            foreach ($receipts as $receipt) {
                $existingEntry = JournalEntry::query()->where('company_id', $company->id)->where('source_type', Receipt::class)->where('source_id', $receipt->id)->first();
                if ($existingEntry) {
                    $receipt->forceFill(['journal_entry_id' => $existingEntry->id])->save();
                    $count++;

                    continue;
                }
                $source = $this->paymentSourceAccount($company, ['cashbox_id' => $receipt->cashbox_id, 'bank_id' => $receipt->bank_id]);
                $party = $receipt->party_id ? CustomerSupplier::query()->where('company_id', $company->id)->where('is_customer', true)->findOrFail($receipt->party_id) : null;
                $otherAccount = $receipt->other_account_id ? Account::query()->where('company_id', $company->id)->where('is_active', true)->findOrFail($receipt->other_account_id) : null;
                $counterparty = $party?->account ?? $otherAccount;
                abort_unless($counterparty, 409, 'Legacy receipt has no counterparty account.');
                $period = $this->foundation->openPeriodFor($company, $receipt->receipt_date->toDateString());
                $entry = $this->postJournal($company, $actor, $period, $receipt->receipt_date->toDateString(), 'Customer receipt '.$receipt->receipt_number, Receipt::class, $receipt->id, [
                    ['account_id' => $source->id, 'party_id' => $party?->id, 'debit' => $receipt->amount, 'credit' => 0, 'description' => 'Receipt into '.$source->name],
                    ['account_id' => $counterparty->id, 'party_id' => $party?->id, 'debit' => 0, 'credit' => $receipt->amount, 'description' => $party ? 'Customer receipt' : 'Other account receipt'],
                ]);
                $receipt->forceFill(['journal_entry_id' => $entry->id])->save();
                app(AuditRecorder::class)->record('receipts.backfilled_to_journal', $receipt, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);
                $count++;
            }

            $vouchers = PaymentVoucher::query()->where('company_id', $company->id)->where('status', 'posted')->whereNull('journal_entry_id')->lockForUpdate()->get();
            foreach ($vouchers as $voucher) {
                $existingEntry = JournalEntry::query()->where('company_id', $company->id)->where('source_type', PaymentVoucher::class)->where('source_id', $voucher->id)->first();
                if ($existingEntry) {
                    $voucher->forceFill(['journal_entry_id' => $existingEntry->id])->save();
                    $count++;

                    continue;
                }
                $source = $this->paymentSourceAccount($company, ['cashbox_id' => $voucher->cashbox_id, 'bank_id' => $voucher->bank_id]);
                $party = $voucher->party_id ? CustomerSupplier::query()->where('company_id', $company->id)->where('is_supplier', true)->findOrFail($voucher->party_id) : null;
                $otherAccount = $voucher->other_account_id ? Account::query()->where('company_id', $company->id)->where('is_active', true)->findOrFail($voucher->other_account_id) : null;
                $counterparty = $party?->account ?? $otherAccount;
                abort_unless($counterparty, 409, 'Legacy payment voucher has no counterparty account.');
                $period = $this->foundation->openPeriodFor($company, $voucher->voucher_date->toDateString());
                $entry = $this->postJournal($company, $actor, $period, $voucher->voucher_date->toDateString(), 'Supplier payment '.$voucher->voucher_number, PaymentVoucher::class, $voucher->id, [
                    ['account_id' => $counterparty->id, 'party_id' => $party?->id, 'debit' => $voucher->amount, 'credit' => 0, 'description' => $party ? 'Supplier payment' : 'Other account payment'],
                    ['account_id' => $source->id, 'party_id' => $party?->id, 'debit' => 0, 'credit' => $voucher->amount, 'description' => 'Payment from '.$source->name],
                ]);
                $voucher->forceFill(['journal_entry_id' => $entry->id])->save();
                app(AuditRecorder::class)->record('payment_vouchers.backfilled_to_journal', $voucher, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);
                $count++;
            }

            return $count;
        });
    }

    public function reverseJournal(Company $company, User $actor, JournalEntry $entry, string $date, bool $fromSourceWorkflow = false): JournalEntry
    {
        return DB::transaction(function () use ($company, $actor, $entry, $date, $fromSourceWorkflow): JournalEntry {
            $entry = JournalEntry::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($entry->id);
            if (! $fromSourceWorkflow && in_array($entry->source_type, [PurchaseBill::class, SalesBill::class, FleetExpense::class, MaintenanceRecord::class], true)) {
                abort(409, 'Reverse the source document from its own workflow.');
            }
            abort_unless($entry->status === 'posted' && $entry->reversed_by_id === null, 409, 'Only an unreversed posted journal can be reversed.');
            $period = $this->foundation->openPeriodFor($company, $date);
            $lines = $entry->lines()->get()->map(fn ($line) => [
                'account_id' => $line->account_id,
                'party_id' => $line->party_id,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'description' => 'Reversal: '.($line->description ?? ''),
            ])->all();
            $reversal = $this->postJournal($company, $actor, $period, $date, 'Reversal '.$entry->entry_number, JournalEntry::class, $entry->id, $lines, $entry->id);
            $entry->forceFill(['status' => 'reversed', 'reversed_by_id' => $reversal->id])->save();
            app(AuditRecorder::class)->record('journals.reversed', $entry, $company->id, $actor->id, ['reversal_entry_id' => $reversal->id]);

            return $reversal;
        });
    }

    public function postOperationalJournal(Company $company, User $actor, string $date, string $description, string $sourceType, int $sourceId, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($company, $actor, $date, $description, $sourceType, $sourceId, $lines): JournalEntry {
            $period = $this->foundation->openPeriodFor($company, $date);

            return $this->postJournal($company, $actor, $period, $date, $description, $sourceType, $sourceId, $lines);
        });
    }

    public function closePeriod(Company $company, FiscalPeriod $period, User $actor): FiscalPeriod
    {
        abort_unless((int) $period->company_id === (int) $company->id, 404);
        abort_if($period->status !== 'open', 409, 'Fiscal period is already closed.');
        $period->forceFill(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()])->save();
        app(AuditRecorder::class)->record('fiscal_periods.closed', $period, $company->id, $actor->id);

        return $period;
    }

    public function paymentSourceAccount(Company $company, array $data): Account
    {
        $hasCashbox = ! empty($data['cashbox_id']);
        $hasBank = ! empty($data['bank_id']);
        throw_if($hasCashbox === $hasBank, ValidationException::withMessages(['cashbox_id' => 'Choose exactly one cashbox or bank.']));

        if ($hasCashbox) {
            return Account::query()->where('company_id', $company->id)->whereIn('id', function ($query) use ($company, $data): void {
                $query->select('account_id')->from('cashboxes')->where('company_id', $company->id)->where('is_active', true)->where('id', $data['cashbox_id']);
            })->firstOrFail();
        }

        return Account::query()->where('company_id', $company->id)->whereIn('id', function ($query) use ($company, $data): void {
            $query->select('account_id')->from('banks')->where('company_id', $company->id)->where('is_active', true)->where('id', $data['bank_id']);
        })->firstOrFail();
    }

    private function postableOtherAccount(Company $company, int $accountId, Account $cashAccount): Account
    {
        abort_if((int) $cashAccount->id === $accountId, 422, 'Choose a non-cash account for the counterparty.');
        $account = Account::query()->where('company_id', $company->id)->where('is_active', true)->findOrFail($accountId);
        abort_if(Account::query()->where('company_id', $company->id)->where('parent_id', $account->id)->exists(), 422, 'Choose a posting account, not a header account.');

        return $account;
    }

    private function postJournal(Company $company, User $actor, FiscalPeriod $period, string $date, string $description, string $sourceType, int $sourceId, array $lines, ?int $reversalOf = null): JournalEntry
    {
        $debits = round(array_sum(array_column($lines, 'debit')), 2);
        $credits = round(array_sum(array_column($lines, 'credit')), 2);
        throw_if($debits <= 0 || $debits !== $credits, ValidationException::withMessages(['amount' => 'Journal entries must balance to a positive amount.']));
        $entryNumber = $this->nextDocumentNumber($company, 'journal_entries', 'entry_number', 'JE');
        $entry = JournalEntry::query()->create([
            'company_id' => $company->id, 'fiscal_period_id' => $period->id, 'created_by' => $actor->id,
            'reversal_of_id' => $reversalOf, 'entry_number' => $entryNumber, 'entry_date' => $date,
            'description' => $description, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'status' => 'posted', 'posted_at' => now(),
        ]);
        foreach ($lines as $line) {
            Account::query()->where('company_id', $company->id)->findOrFail($line['account_id']);
            $entry->lines()->create(['company_id' => $company->id, ...$line]);
        }

        return $entry->load('lines');
    }

    private function nextDocumentNumber(Company $company, string $table, string $column, string $prefix): string
    {
        $next = DB::table($table)->where('company_id', $company->id)->lockForUpdate()->count() + 1;

        return sprintf('%s-%s-%06d', $prefix, now()->format('Y'), $next);
    }
}
