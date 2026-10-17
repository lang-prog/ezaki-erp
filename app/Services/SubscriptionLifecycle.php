<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

class SubscriptionLifecycle
{
    // Whole days elapsed after ends_at during which each phase lasts (the 4th/31st day starts the next phase).
    public const GRACE_FULL_DAYS = 3;

    public const READ_ONLY_FULL_DAYS = 30;

    /** Instant at which a lapsed subscription leaves the given phase ('grace' | 'read_only' | 'suspended'). */
    public function phaseEnd(CarbonImmutable $endsAt, string $phase): CarbonImmutable
    {
        return match ($phase) {
            'grace' => $endsAt->addDays(self::GRACE_FULL_DAYS + 1),
            'read_only' => $endsAt->addDays(self::READ_ONLY_FULL_DAYS + 1),
            default => $endsAt->addMonthsNoOverflow(6),
        };
    }

    public function status(Subscription $subscription, ?CarbonImmutable $now = null): string
    {
        if ($subscription->status !== 'active') {
            return $subscription->status;
        }

        try {
            $endsAt = $subscription->ends_at?->toImmutable();
        } catch (\Throwable) {
            // An unreadable end date fails closed instead of surfacing a 500.
            return 'suspended';
        }
        $current = $now ?? CarbonImmutable::now();
        if ($endsAt === null || $current->lessThanOrEqualTo($endsAt)) {
            return 'active';
        }

        return match (true) {
            $current->lessThan($this->phaseEnd($endsAt, 'grace')) => 'grace',
            $current->lessThan($this->phaseEnd($endsAt, 'read_only')) => 'read_only',
            $current->lessThan($this->phaseEnd($endsAt, 'suspended')) => 'suspended',
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
