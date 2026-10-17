<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\SubscriptionLifecycle;
use App\Services\SubscriptionTransitionService;
use Illuminate\Console\Command;

class ArchiveExpiredCompanies extends Command
{
    protected $signature = 'companies:archive-expired';

    protected $description = 'Archive companies whose current subscription expired more than six months ago';

    public function handle(SubscriptionLifecycle $lifecycle, SubscriptionTransitionService $transitions): int
    {
        $cutoff = now()->subMonthsNoOverflow(6);
        $archived = 0;
        Company::query()->where('status', '!=', 'archived')->orderBy('id')->chunkById(100, function ($companies) use ($lifecycle, $transitions, $cutoff, &$archived): void {
            foreach ($companies as $company) {
                // Only the authoritative current subscription decides; superseded rows are history.
                $subscription = $lifecycle->currentFor($company);
                if (! $subscription || ! $subscription->ends_at || $subscription->ends_at->gt($cutoff)) {
                    continue;
                }
                try {
                    if ($transitions->archive($company, null, 'Subscription expired more than six months ago.', 'company.auto_archived_expired')) {
                        $archived++;
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });
        // Companies already archived keep an archived subscription row in sync (idempotent).
        $this->info("Archived {$archived} company subscription(s).");

        return self::SUCCESS;
    }
}
