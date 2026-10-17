<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Requires a recent password (and TOTP when enrolled) confirmation before high-impact platform actions. */
final class PlatformStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $at = (int) $request->session()->get('platform_stepup_at', 0);
        $window = max(1, (int) config('security.platform.step_up_minutes', 10)) * 60;
        if ($at > 0 && (time() - $at) <= $window) {
            return $next($request);
        }

        $payload = ['message' => 'Recent password and multi-factor confirmation is required for this action.', 'code' => 'step_up_required', 'requires_mfa' => (bool) $request->user()?->hasMfa()];
        if ($request->expectsJson()) {
            return response()->json($payload, 423);
        }

        return back()->withErrors(['step_up' => $payload['message']]);
    }
}
