<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

class SubscriptionLifecycle
{
    public function status(Subscription $subscription, ?CarbonImmutable $now = null): string
    {
        if ($subscription->status !== 'active') {
            return $subscription->status;
        }

        if ($subscription->ends_at === null || ($now ?? CarbonImmutable::now())->lessThanOrEqualTo($subscription->ends_at)) {
            return 'active';
        }

        $current = $now ?? CarbonImmutable::now();
        $daysExpired = (int) $subscription->ends_at->diffInDays($current);

        return match (true) {
            $daysExpired <= 3 => 'grace',
            $daysExpired <= 30 => 'read_only',
            $current->lessThan($subscription->ends_at->toImmutable()->addMonthsNoOverflow(6)) => 'suspended',
            default => 'archived',
        };
    }

    public function currentFor(Company $company): ?Subscription
    {
        return $company->subscriptions()->with('plan')->current()->first();
    }

    /**
     * Effective access state. Administrative company suspension/archival always wins, so no edition or
     * subscription date can bypass it; otherwise the subscription dates/status decide.
     */
    public function accessState(Company $company, ?Subscription $subscription, ?CarbonImmutable $now = null): string
    {
        if (in_array($company->status, ['suspended', 'archived'], true)) {
            return $company->status;
        }

        return $subscription ? $this->status($subscription, $now) : 'none';
    }

    public function expiryFor(Plan $plan, ?CarbonImmutable $startsAt = null): ?CarbonImmutable
    {
        if ($plan->duration === 'lifetime') {
            return null;
        }

        $start = $startsAt ?? CarbonImmutable::now();
        if ($plan->duration_days) {
            return $start->addDays($plan->duration_days);
        }

        return match ($plan->duration) {
            'monthly' => $start->addMonthNoOverflow(),
            'six_months' => $start->addMonthsNoOverflow(6),
            'yearly' => $start->addYearNoOverflow(),
            default => null,
        };
    }
}
