<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->account_type === 'company' && $user->company_id, 403);
        abort_unless($user->company && in_array($user->company->status, ['active', 'suspended', 'archived'], true), 403);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $user->company_id);
        $request->attributes->set('company', $user->company);

        try {
            return $next($request);
        } finally {
            $registrar->setPermissionsTeamId(null);
        }
    }
}
