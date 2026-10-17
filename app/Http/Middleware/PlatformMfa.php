<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MfaService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Platform second factor: enrolled admins must pass the challenge each session; enforcement for the rest is date/flag driven. */
final class PlatformMfa
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && Auth::viaRemember()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('super-admin.login');
        }

        if ($user && $user->hasMfa()) {
            if (! $request->session()->has('platform_mfa_at')) {
                return $this->deny($request, 'super-admin.mfa.challenge', 'mfa_required');
            }
        } elseif ($user && $this->mfa->isEnforced()) {
            return $this->deny($request, 'super-admin.security', 'mfa_enrollment_required');
        }

        return $next($request);
    }

    private function deny(Request $request, string $route, string $code): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Multi-factor authentication is required.', 'code' => $code], 423);
        }

        return redirect()->route($route);
    }
}
