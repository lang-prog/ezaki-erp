<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\LocalLicense;
use App\Models\LoginLog;
use App\Models\ManualPayment;
use App\Models\RegistrationRequest;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use Illuminate\Database\Eloquent\Builder;

/** KPI definitions; every figure is backed by a scope that the matching list page reuses (UTC dates). */
class PlatformMetricsService
{
    /** Subscriptions that count as "current" for reporting: not superseded by a plan change. */
    public function currentSubscriptions(): Builder
    {
        return Subscription::query()->whereNull('superseded_at');
    }

    public function expiringSoon(int $days = 7): Builder
    {
        return $this->currentSubscriptions()->where('status', 'active')->whereBetween('ends_at', [now(), now()->addDays($days)])
            ->whereHas('company', fn ($q) => $q->where('status', 'active'));
    }

    public function lapsed(): Builder
    {
        return $this->currentSubscriptions()->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<', now())
            ->whereHas('company', fn ($q) => $q->where('status', 'active'));
    }

    public function overview(): array
    {
        $companies = Company::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $revenueBase = ManualPayment::query()->where('status', 'paid');

        return [
            'companies' => [
                'total' => (int) $companies->sum(),
                'active' => (int) ($companies['active'] ?? 0),
                'suspended' => (int) ($companies['suspended'] ?? 0),
                'archived' => (int) ($companies['archived'] ?? 0),
            ],
            'subscriptions' => [
                'expiring_7d' => $this->expiringSoon()->count(),
                'lapsed' => $this->lapsed()->count(),
            ],
            'revenue' => [
                'last_30_days' => round((float) (clone $revenueBase)->where('paid_at', '>=', now()->subDays(30))->sum('amount'), 2),
                'month_to_date' => round((float) (clone $revenueBase)->where('paid_at', '>=', now()->startOfMonth())->sum('amount'), 2),
                'currency' => 'EGP',
            ],
            'pending_registrations' => RegistrationRequest::query()->where('status', 'pending')->whereNotNull('verified_at')->count(),
            'open_requests' => SubscriptionRequest::query()->where('status', 'open')->count(),
            'licenses' => LocalLicense::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'failed_logins_24h' => LoginLog::query()->where('successful', false)->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /** Urgent alerts only; informational numbers stay in overview(). */
    public function alerts(array $overview): array
    {
        $alerts = [];
        foreach ([
            ['key' => 'lapsed', 'count' => $overview['subscriptions']['lapsed'], 'href' => '/super-admin/subscriptions?state=lapsed'],
            ['key' => 'expiring', 'count' => $overview['subscriptions']['expiring_7d'], 'href' => '/super-admin/subscriptions?state=expiring'],
            ['key' => 'registrations', 'count' => $overview['pending_registrations'], 'href' => '/super-admin/registrations'],
            ['key' => 'requests', 'count' => $overview['open_requests'], 'href' => '/super-admin/requests'],
        ] as $alert) {
            if ($alert['count'] > 0) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }
}
