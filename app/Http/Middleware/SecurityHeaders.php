<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (config('security.hsts.enabled', true) && $request->isSecure()) {
            $maxAge = (int) config('security.hsts.max_age', 31536000);
            $subdomains = config('security.hsts.include_subdomains', true) ? '; includeSubDomains' : '';
            $response->headers->set('Strict-Transport-Security', 'max-age='.$maxAge.$subdomains);
        }

        return $response;
    }
}
