<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAccountType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        abort_unless($user && $user->account_type === $type && $user->isActive(), 403);

        return $next($request);
    }
}
