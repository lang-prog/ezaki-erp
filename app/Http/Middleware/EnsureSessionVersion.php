<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSessionVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && (int) $request->session()->get('session_version', 0) !== (int) $user->session_version) {
            $platform = $user->account_type === 'super_admin';
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(route($platform ? 'super-admin.login' : 'login'));
        }

        return $next($request);
    }
}
