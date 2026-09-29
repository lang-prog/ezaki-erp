<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\LocalLicense;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalLicense
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('licensing.edition') !== 'local') {
            return $next($request);
        }

        abort_unless(LocalLicense::query()->whereNotNull('activated_at')->exists(), 402, 'Local installation is not activated.');

        return $next($request);
    }
}
