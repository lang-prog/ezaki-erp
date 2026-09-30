<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\LocalLicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalLicense
{
    public function __construct(private readonly LocalLicenseService $licenses) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('licensing.edition') !== 'local') {
            return $next($request);
        }

        $company = $request->attributes->get('company');
        abort_unless($company, 402, 'Local installation is not bound to a company.');
        $this->licenses->verifyForCompany($company);

        return $next($request);
    }
}
