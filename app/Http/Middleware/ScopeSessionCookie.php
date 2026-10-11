<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ScopeSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $isPlatform = $request->is('super-admin', 'super-admin/*');
        config(['session.cookie' => $isPlatform
            ? env('PLATFORM_SESSION_COOKIE', 'ezaki-platform-session')
            : env('COMPANY_SESSION_COOKIE', 'ezaki-company-session')]);

        return $next($request);
    }
}
