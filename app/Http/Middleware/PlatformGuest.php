<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PlatformGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->account_type === 'super_admin') {
            return redirect()->route('super-admin.dashboard');
        }

        return $next($request);
    }
}
