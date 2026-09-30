<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SubscriptionLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionStatus
{
    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('licensing.edition') === 'local') {
            return $next($request);
        }

        $company = $request->attributes->get('company');
        $subscription = $company ? $this->lifecycle->currentFor($company) : null;
        abort_unless($subscription, 402, 'An active subscription is required.');

        $status = $this->lifecycle->status($subscription);
        $request->attributes->set('subscription_status', $status);
        $request->attributes->set('subscription_warning', $subscription->ends_at !== null
            && $subscription->ends_at->isFuture()
            && $subscription->ends_at->diffInDays(now()) <= 7);

        if ($status === 'archived') {
            $company->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        }

        if ($status === 'suspended' || $status === 'archived') {
            $allowed = $request->routeIs(
                'company.subscription', 'company.subscription.contact', 'company.subscription.renew',
                'api.v1.subscription', 'api.v1.subscription.contact', 'api.v1.subscription.renew',
                'logout', 'api.v1.logout',
            );
            abort_unless($allowed, 403, 'This company subscription is suspended.');
        }

        $readOnlyMutation = $request->is('password') || $request->is('locale/*') || $request->is('api/v1/password');
        if ($status === 'read_only' && ! $readOnlyMutation && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            abort(403, 'This subscription is read-only.');
        }

        return $next($request);
    }
}
