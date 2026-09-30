<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $capabilities = [];
        if ($user && $user->account_type === 'company' && $user->company_id) {
            $registrar = app(PermissionRegistrar::class);
            $previousTeamId = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId((int) $user->company_id);
            try {
                $capabilities = $user->isCompanyOwner()
                    ? Permission::query()->pluck('name')->flip()->map(fn () => true)->all()
                    : $user->getAllPermissions()->pluck('name')->flip()->map(fn () => true)->all();
                $capabilities['company.owner'] = $user->isCompanyOwner();
            } finally {
                $registrar->setPermissionsTeamId($previousTeamId);
            }
        }

        return array_merge(parent::share($request), [
            'locale' => app()->getLocale(),
            'auth' => ['user' => $user],
            'isCompanyOwner' => $user?->isCompanyOwner() ?? false,
            'capabilities' => $capabilities,
            'navigationCapabilities' => $capabilities,
            'subscriptionStatus' => fn () => $request->attributes->get('subscription_status'),
            'subscriptionWarning' => fn () => $request->attributes->get('subscription_warning', false),
            'registrationOpen' => fn () => PlatformSetting::boolean('self_registration_open', config('registration.open')),
        ]);
    }
}
