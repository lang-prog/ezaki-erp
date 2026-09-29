<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PaymentVoucher;
use App\Models\Receipt;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillVoucherJournals extends Command
{
    protected $signature = 'accounting:backfill-voucher-journals {--company= : Limit repair to one company ID}';

    protected $description = 'Link or create journals for posted receipts and payment vouchers missing journal_entry_id';

    public function handle(AccountingService $accounting): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($query, $id) => $query->whereKey((int) $id))->get();
        $total = 0;
        foreach ($companies as $company) {
            $actor = User::query()->where('company_id', $company->id)->where('account_type', 'company')->where('status', 'active')->orderBy('id')->first();
            if (! $actor) {
                $this->warn("Company {$company->id}: no active company user available; skipped.");

                continue;
            }

            $repaired = $accounting->backfillUnjournaledVouchers($company, $actor);
            $total += $repaired;
            $this->info("Company {$company->id}: repaired {$repaired} posted voucher journal link(s).");
            $this->line('  receipts: '.Receipt::query()->where('company_id', $company->id)->where('status', 'posted')->count().' posted, '.Receipt::query()->where('company_id', $company->id)->where('status', 'posted')->whereNull('journal_entry_id')->count().' unlinked');
            $this->line('  payments: '.PaymentVoucher::query()->where('company_id', $company->id)->where('status', 'posted')->count().' posted, '.PaymentVoucher::query()->where('company_id', $company->id)->where('status', 'posted')->whereNull('journal_entry_id')->count().' unlinked');
            $this->line('  receipt journal sources: '.DB::table('journal_entries')->where('company_id', $company->id)->where('source_type', Receipt::class)->whereIn('status', ['posted', 'reversed'])->count());
            $this->line('  payment journal sources: '.DB::table('journal_entries')->where('company_id', $company->id)->where('source_type', PaymentVoucher::class)->whereIn('status', ['posted', 'reversed'])->count());
            $this->line('  report-visible voucher lines: '.DB::table('journal_entries as entries')->join('journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')->join('accounts', 'accounts.id', '=', 'lines.account_id')->where('entries.company_id', $company->id)->whereIn('entries.status', ['posted', 'reversed'])->whereIn('entries.source_type', [Receipt::class, PaymentVoucher::class])->count());
        }

        $this->info("Total voucher journal links repaired: {$total}.");

        return self::SUCCESS;
    }
}
