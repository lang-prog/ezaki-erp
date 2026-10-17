<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SubscriptionLifecycle;
use App\Services\SubscriptionNotice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionStatus
{
    private const ALWAYS_ALLOWED = [
        'company.subscription', 'company.subscription.contact', 'company.subscription.renew',
        'api.v1.subscription', 'api.v1.subscription.contact', 'api.v1.subscription.renew',
        'logout', 'api.v1.logout',
    ];

    public function __construct(private readonly SubscriptionLifecycle $lifecycle, private readonly SubscriptionNotice $notices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->attributes->get('company');

        // Administrative suspension/archival is enforced in every edition; local installs skip only subscription dates.
        if ($company && in_array($company->status, ['suspended', 'archived'], true)) {
            $request->attributes->set('subscription_status', $company->status);
            $request->attributes->set('subscription_notice', $this->notices->for($company, $this->lifecycle->currentFor($company), $company->status));
            abort_unless($request->routeIs(...self::ALWAYS_ALLOWED), 403, 'This company subscription is suspended.');

            return $next($request);
        }

        if (config('licensing.edition') === 'local') {
            return $next($request);
        }

        $subscription = $company ? $this->lifecycle->currentFor($company) : null;
        abort_unless($subscription, 402, 'An active subscription is required.');

        $status = $this->lifecycle->accessState($company, $subscription);
        $request->attributes->set('subscription_status', $status);
        $request->attributes->set('subscription_notice', $this->notices->for($company, $subscription, $status));

        if (in_array($status, ['suspended', 'archived', 'cancelled'], true)) {
            abort_unless($request->routeIs(...self::ALWAYS_ALLOWED), 403, 'This company subscription is suspended.');
        }

        $readOnlyMutation = $request->is('password') || $request->is('locale/*') || $request->is('api/v1/password')
            || $request->routeIs(...self::ALWAYS_ALLOWED);
        if ($status === 'read_only' && ! $readOnlyMutation && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            abort(403, 'This subscription is read-only.');
        }

        return $next($request);
    }
}
