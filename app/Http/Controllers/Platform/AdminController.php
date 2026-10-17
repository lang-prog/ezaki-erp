<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\User;
use App\Services\PlatformAdminService;
use App\Support\PlatformPassword;
use App\Support\PlatformPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends PlatformController
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Admins', [
            'admins' => User::query()->where('account_type', 'super_admin')->orderBy('id')->get()->map(fn (User $admin): array => [
                'id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->platform_role,
                'active' => $admin->isActive(), 'mfa_enabled' => $admin->hasMfa(), 'last_login_at' => $admin->last_login_at,
            ]),
            'roles' => PlatformPermissions::roles(),
        ]);
    }

    public function store(Request $request, PlatformAdminService $admins): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', 'string', PlatformPassword::rule()],
            'role' => ['required', Rule::in(PlatformPermissions::roles())],
        ]);
        $admins->create($data + ['reason' => $request->input('reason')], $request->user());

        return $this->done($request);
    }

    public function updateRole(User $admin, Request $request, PlatformAdminService $admins): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(PlatformPermissions::roles())]]);
        $admins->changeRole($this->admin($admin), $data['role'], $request->user(), $this->reason($request));

        return $this->done($request);
    }

    public function setStatus(User $admin, Request $request, PlatformAdminService $admins): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $admins->setActive($this->admin($admin), (bool) $data['active'], $request->user(), $this->reason($request));

        return $this->done($request);
    }

    public function resetMfa(User $admin, Request $request, PlatformAdminService $admins): JsonResponse|RedirectResponse
    {
        $admins->resetMfa($this->admin($admin), $request->user(), $this->reason($request));

        return $this->done($request);
    }

    /** Route-model binding must never reach company users. */
    private function admin(User $user): User
    {
        abort_unless($user->account_type === 'super_admin', 404);

        return $user;
    }
}
