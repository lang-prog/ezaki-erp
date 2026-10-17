<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

final class RequireCompanyApiAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException;
        }

        // Session-authenticated users carry no personal access token; only bearer tokens are ability-scoped.
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token !== null && ! $token instanceof TransientToken && ! $token->can('company-api')) {
            abort(403, 'This token is not allowed to use the company API.');
        }

        return $next($request);
    }
}
