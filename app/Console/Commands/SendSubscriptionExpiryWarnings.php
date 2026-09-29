<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionExpiryWarning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SendSubscriptionExpiryWarnings extends Command
{
    protected $signature = 'subscriptions:send-expiry-warnings';

    protected $description = 'Send daily subscription expiry reminders to company owners';

    public function handle(): int
    {
        $today = today()->toDateString();
        $windowEnd = now()->addDays(7);
        $sent = 0;

        Subscription::query()
            ->with('company')
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), $windowEnd])
            ->whereHas('company', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($today, &$sent): void {
                foreach ($subscriptions as $subscription) {
                    $owners = User::query()
                        ->where('company_id', $subscription->company_id)
                        ->where('account_type', 'company')
                        ->where('status', 'active')
                        ->whereIn('users.id', function ($query) use ($subscription): void {
                            $query->select('model_has_roles.model_id')
                                ->from('model_has_roles')
                                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                                ->where('model_has_roles.company_id', $subscription->company_id)
                                ->where('roles.company_id', $subscription->company_id)
                                ->where('roles.name', 'Company Owner')
                                ->where('model_has_roles.model_type', (new User)->getMorphClass());
                        })
                        ->get();

                    foreach ($owners as $owner) {
                        $alreadySent = DB::table('subscription_expiry_notices')
                            ->where('subscription_id', $subscription->id)
                            ->where('user_id', $owner->id)
                            ->where('notice_date', $today)
                            ->exists();
                        if ($alreadySent) {
                            continue;
                        }

                        Notification::send($owner, new SubscriptionExpiryWarning($subscription));
                        DB::table('subscription_expiry_notices')->insertOrIgnore([
                            'subscription_id' => $subscription->id,
                            'user_id' => $owner->id,
                            'notice_date' => $today,
                            'sent_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} subscription expiry reminder(s).");

        return self::SUCCESS;
    }
}
