<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\PlatformPermissions;
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
        if ($user && $user->account_type === 'super_admin') {
            $capabilities = collect(PlatformPermissions::forRole($user->platform_role))
                ->mapWithKeys(fn (string $permission): array => ['platform.'.$permission => $user->platformCan($permission)])->all();
        }
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
            'auth' => ['user' => $user ? $this->safeUser($user) : null],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'message' => fn () => $request->session()->get('message'),
            ],
            'isCompanyOwner' => $user?->isCompanyOwner() ?? false,
            'capabilities' => $capabilities,
            'navigationCapabilities' => $capabilities,
            'subscriptionStatus' => fn () => $request->attributes->get('subscription_status'),
            'subscriptionNotice' => fn () => $request->attributes->get('subscription_notice'),
            'registrationOpen' => fn () => PlatformSetting::boolean('self_registration_open', config('registration.open')),
        ]);
    }

    /** Explicit allowlist: security counters, lock timestamps and session_version never reach the browser. */
    private function safeUser(User $user): array
    {
        $data = $user->only(['id', 'name', 'first_name', 'second_name', 'email', 'account_type', 'company_id', 'status']);
        if ($user->account_type === 'super_admin') {
            $data['platform_role'] = $user->platform_role;
            $data['mfa_enabled'] = $user->hasMfa();
        }
        if ($user->relationLoaded('company') && $user->company) {
            $data['company'] = $user->company->only(['id', 'name']);
        }

        return $data;
    }
}
