<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class CompanyLandingService
{
    public function forUser(User $user): string
    {
        if ($user->account_type === 'super_admin') {
            return route('super-admin.dashboard');
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId((int) $user->company_id);

        try {
            foreach ([
                'dashboard.view' => 'company.dashboard',
                'purchases.view' => 'company.operations',
                'sales.view' => 'company.operations',
                'fleet.view' => 'company.fleet',
                'purchases.create' => 'company.purchase.create',
                'sales.create' => 'company.sales.create',
                'inventory.view' => 'company.inventory',
                'branches.view' => 'company.branches',
                'parties.view' => 'company.parties',
                'accounting.view' => 'company.accounting',
                'reports.view' => 'company.accounting.report',
                'users.view' => 'company.access',
                'subscriptions.view' => 'company.subscription',
            ] as $permission => $route) {
                if ($user->can($permission)) {
                    return $route === 'company.accounting.report'
                        ? route($route, ['report' => 'journal'])
                        : route($route);
                }
            }

            // Every active company user may read their own profile.
            return route('company.users.profile', ['user' => $user->id]);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
