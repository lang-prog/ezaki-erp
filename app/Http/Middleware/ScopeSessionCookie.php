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
        config(['session.cookie' => config($isPlatform
            ? 'session.platform_cookie'
            : 'session.company_cookie')]);

        return $next($request);
    }
}
