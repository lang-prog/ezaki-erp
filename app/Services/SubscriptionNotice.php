<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * Single server-side source of the subscription notice shown to company users (banner, subscription page,
 * API status and expiry emails). It reuses SubscriptionLifecycle for the state, so the notice can never
 * disagree with the access middleware.
 *
 * Day policy: remaining/elapsed days are CALENDAR days in the company's timezone, computed with integer
 * date arithmetic (no floats). Under 24 hours the notice says "less than one day" instead of "0 days".
 * Between 24 and 48 hours it still counts calendar dates, so it can read "2 days" late in the evening.
 */
final class SubscriptionNotice
{
    private const DAY = 86400;

    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    public function timezoneFor(Company $company): string
    {
        $known = timezone_identifiers_list();
        $configured = is_array($company->settings) ? ($company->settings['timezone'] ?? null) : null;
        if (is_string($configured) && in_array($configured, $known, true)) {
            return $configured;
        }
        $default = (string) config('subscription.timezone', 'UTC');

        return in_array($default, $known, true) ? $default : 'UTC';
    }

    /** trial (plan duration), test (explicit overrides.period_type), lifetime (plan duration) or paid. */
    public function categoryFor(Subscription $subscription): string
    {
        $duration = $subscription->plan?->duration;
        if ($duration === 'lifetime') {
            return 'lifetime';
        }
        $overrides = is_array($subscription->overrides) ? $subscription->overrides : [];
        if (($overrides['period_type'] ?? null) === 'test') {
            return 'test';
        }

        return $duration === 'trial' ? 'trial' : 'paid';
    }

    public function accessFor(string $state): string
    {
        return match ($state) {
            'active' => 'normal',
            'grace' => 'warning',
            'read_only' => 'read_only',
            default => 'blocked',
        };
    }

    /** @return array<string, mixed> */
    public function for(Company $company, ?Subscription $subscription, ?string $state = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $timezone = $this->timezoneFor($company);
        // A subscription from another tenant is never described to this company.
        if ($subscription !== null && (int) $subscription->company_id !== (int) $company->id) {
            $subscription = null;
        }
        $state ??= $this->lifecycle->accessState($company, $subscription, $now);
        $base = ['state' => $state, 'category' => null, 'access' => $this->accessFor($state), 'show' => false, 'key' => null, 'params' => [], 'severity' => 'info',
            'ends_at' => null, 'ends_on' => null, 'timezone' => $timezone, 'days_remaining' => null, 'days_since_end' => null];

        if ($subscription === null) {
            return $state === 'none' ? $base : [...$base, 'show' => true, 'key' => $this->administrative($company), 'severity' => 'danger'];
        }

        try {
            $endsAt = $subscription->ends_at?->toImmutable();
            $category = $this->categoryFor($subscription);
        } catch (\Throwable) {
            return [...$base, 'show' => true, 'key' => 'unavailable', 'severity' => 'warning'];
        }
        $base['category'] = $category;
        if ($endsAt !== null) {
            $base['ends_at'] = $endsAt->utc()->toIso8601String();
            $base['ends_on'] = $endsAt->setTimezone($timezone)->format('Y-m-d');
        }

        if (in_array($state, ['suspended', 'archived', 'cancelled'], true)) {
            return $this->blocked($base, $company, $state, $category, $endsAt, $now, $timezone);
        }
        if ($category === 'lifetime' || $endsAt === null) {
            return $base;
        }

        $secondsLeft = $endsAt->getTimestamp() - $now->getTimestamp();
        if ($state === 'grace') {
            $left = $this->lifecycle->phaseEnd($endsAt, 'grace')->getTimestamp() - $now->getTimestamp();
            $days = $left < self::DAY ? 0 : max(1, $this->calendarDays($now, $this->lifecycle->phaseEnd($endsAt, 'grace'), $timezone));

            return [...$base, 'show' => true, 'severity' => 'warning', 'key' => "{$category}.".($days === 0 ? 'grace_today' : 'grace'), 'params' => $days === 0 ? [] : ['days' => $days],
                'days_since_end' => $this->calendarDays($endsAt, $now, $timezone)];
        }
        if ($state === 'read_only') {
            return [...$base, 'show' => true, 'severity' => 'danger', 'key' => "{$category}.read_only", 'days_since_end' => $this->calendarDays($endsAt, $now, $timezone)];
        }

        // Active: the end date is still in the future.
        $days = $secondsLeft < self::DAY ? 0 : max(1, $this->calendarDays($now, $endsAt, $timezone));
        $window = max(0, (int) config('subscription.notice_days', 7));
        $withinWindow = $days <= $window;
        $show = $category !== 'paid' || $withinWindow;

        return [...$base, 'show' => $show, 'access' => $withinWindow ? 'warning' : 'normal', 'severity' => $withinWindow ? 'warning' : 'info',
            'key' => "{$category}.".($days === 0 ? 'today' : 'ends'), 'params' => $days === 0 ? [] : ['days' => $days], 'days_remaining' => $days];
    }

    /** @return array<string, mixed> */
    private function blocked(array $base, Company $company, string $state, string $category, ?CarbonImmutable $endsAt, CarbonImmutable $now, string $timezone): array
    {
        $base = [...$base, 'show' => true, 'severity' => 'danger'];
        if (in_array($company->status, ['suspended', 'archived'], true)) {
            return [...$base, 'key' => $this->administrative($company)];
        }
        if ($state === 'cancelled') {
            return [...$base, 'key' => 'cancelled'];
        }
        if ($endsAt !== null && $endsAt->lessThan($now)) {
            $days = $now->getTimestamp() - $endsAt->getTimestamp() < self::DAY ? 0 : $this->calendarDays($endsAt, $now, $timezone);

            return [...$base, 'key' => "{$category}.".($days === 0 ? 'expired_today' : 'expired'), 'params' => $days === 0 ? [] : ['days' => $days], 'days_since_end' => $days];
        }

        return [...$base, 'key' => $state === 'archived' ? 'archived_admin' : 'suspended_admin'];
    }

    private function administrative(Company $company): string
    {
        return $company->status === 'archived' ? 'archived_admin' : 'suspended_admin';
    }

    private function calendarDays(CarbonImmutable $from, CarbonImmutable $to, string $timezone): int
    {
        $a = $from->setTimezone($timezone);
        $b = $to->setTimezone($timezone);
        $start = CarbonImmutable::create($a->year, $a->month, $a->day, 0, 0, 0, 'UTC');
        $end = CarbonImmutable::create($b->year, $b->month, $b->day, 0, 0, 0, 'UTC');

        return intdiv($end->getTimestamp() - $start->getTimestamp(), self::DAY);
    }
}
