<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Subscription;
use App\Services\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveExpiredCompanies extends Command
{
    protected $signature = 'companies:archive-expired';

    protected $description = 'Archive companies whose subscription expired more than six months ago';

    public function handle(AuditRecorder $audit): int
    {
        $cutoff = now()->subMonthsNoOverflow(6);
        $archived = 0;
        Subscription::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $cutoff)
            ->where('status', '!=', 'archived')
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($audit, &$archived): void {
                foreach ($subscriptions as $candidate) {
                    $changed = DB::transaction(function () use ($candidate, $audit): bool {
                        $subscription = Subscription::query()->lockForUpdate()->find($candidate->id);
                        if (! $subscription || ! $subscription->ends_at || $subscription->ends_at->gt(now()->subMonthsNoOverflow(6))) {
                            return false;
                        }
                        $company = Company::query()->lockForUpdate()->find($subscription->company_id);
                        if (! $company) {
                            return false;
                        }
                        $subscriptionChanged = $subscription->status !== 'archived';
                        $companyChanged = $company->status !== 'archived' || $company->archived_at === null;
                        if (! $subscriptionChanged && ! $companyChanged) {
                            return false;
                        }
                        if ($subscriptionChanged) {
                            $subscription->forceFill(['status' => 'archived'])->save();
                        }
                        if ($companyChanged) {
                            $company->forceFill(['status' => 'archived', 'archived_at' => $company->archived_at ?? now()])->save();
                        }
                        $audit->record('company.auto_archived_expired', $company, $company->id, null, [
                            'subscription_id' => $subscription->id,
                            'expired_at' => $subscription->ends_at->toIso8601String(),
                        ]);

                        return true;
                    });
                    if ($changed) {
                        $archived++;
                    }
                }
            });
        $this->info("Archived {$archived} company subscription(s).");

        return self::SUCCESS;
    }
}
