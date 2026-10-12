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
        $name = config($isPlatform ? 'session.platform_cookie' : 'session.company_cookie');
        config(['session.cookie' => $name]);
        // The session store caches its name when first resolved, so update it too.
        app('session')->driver()->setName($name);

        return $next($request);
    }
}
