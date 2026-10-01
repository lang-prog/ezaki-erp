<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\CompanyLimitService;
use App\Services\CompanyProvisioningService;
use App\Services\SessionRevocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CompanyAccessController extends Controller
{
    public function manage(Request $request, CompanyProvisioningService $provisioning): Response
    {
        $companyId = (int) $request->user()->company_id;
        $provisioning->ensurePermissionCatalog();
        if ($request->user()->isCompanyOwner()) {
            $provisioning->syncCompanyOwnerPermissions($companyId);
        }

        return Inertia::render('Company/Access', [
            'roles' => Role::query()->where('company_id', $companyId)->with('permissions')->get(),
            'users' => User::query()->where('company_id', $companyId)->where('account_type', 'company')->with('roles')->orderBy('name')->paginate(25)->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'second_name' => $user->second_name,
                'email' => $user->email,
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
                'login_count' => $user->login_count,
                'failed_login_attempts' => $user->failed_login_attempts,
                'status' => $user->status,
                'roles' => $user->roles->pluck('name'),
                'role_ids' => $user->roles->pluck('id'),
                'direct_permissions' => $user->getDirectPermissions()->pluck('name'),
                'effective_permissions' => $user->getAllPermissions()->pluck('name'),
                'is_company_owner' => $user->roles->contains('name', 'Company Owner'),
                'is_current_user' => $user->is($request->user()),
            ]),
            'canViewActivity' => $request->user()->can('users.view_activity'),
            'permissions' => Permission::query()->where('guard_name', 'web')->orderBy('name')->get(['name']),
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        $roles = Role::query()->where('company_id', $request->user()->company_id)->with('permissions')->get();

        return response()->json(['data' => $roles]);
    }

    public function storeRole(Request $request, AuditRecorder $audit): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('company_id', $companyId)->where('guard_name', 'web')],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);
        $assignable = $request->user()->getAllPermissions()->pluck('name')->all();
        abort_if(array_diff($data['permissions'] ?? [], $assignable), 403, 'You cannot grant permissions you do not hold.');

        $role = DB::transaction(function () use ($data, $companyId): Role {
            $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web', 'company_id' => $companyId]);
            $role->syncPermissions($data['permissions'] ?? []);

            return $role->load('permissions');
        });
        $audit->record('roles.created', $role, $companyId, $request->user()->id, ['name' => $role->name], $request);

        return response()->json(['data' => $role], 201);
    }

    public function updateRole(Request $request, Role $role, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $role->company_id === (int) $request->user()->company_id, 404);
        abort_if($role->name === 'Company Owner', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->ignore($role->id)->where('company_id', $request->user()->company_id)->where('guard_name', 'web')],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);
        $assignable = $request->user()->getAllPermissions()->pluck('name')->all();
        abort_if(array_diff($data['permissions'] ?? [], $assignable), 403, 'You cannot grant permissions you do not hold.');
        $role->forceFill(['name' => $data['name']])->save();
        $role->syncPermissions($data['permissions'] ?? []);
        $audit->record('roles.updated', $role, $role->company_id, $request->user()->id, ['name' => $role->name], $request);

        return response()->json(['data' => $role->load('permissions')]);
    }

    public function users(Request $request): JsonResponse
    {
        return response()->json(['data' => User::query()->where('company_id', $request->user()->company_id)->where('account_type', 'company')->with('roles')->paginate(25)]);
    }

    public function storeUser(Request $request, AuditRecorder $audit, CompanyLimitService $limits): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'second_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $assignable = $request->user()->getAllPermissions()->pluck('name')->all();
        $rolePermissions = Role::query()->where('company_id', $companyId)->whereIn('id', $data['roles'])->with('permissions')->get()->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique()->all();
        abort_if(array_diff(array_merge($data['permissions'] ?? [], $rolePermissions), $assignable), 403, 'You cannot grant permissions you do not hold.');

        $company = $request->attributes->get('company');
        $subscription = $company->subscriptions()->with('plan')->latest('starts_at')->firstOrFail();

        $user = DB::transaction(function () use ($data, $companyId, $company, $subscription, $limits): User {
            $limits->lockAndAssertCanAdd($company, $subscription->id, 'users');
            $user = User::query()->create([
                'name' => trim($data['first_name'].' '.$data['second_name']),
                'first_name' => $data['first_name'], 'second_name' => $data['second_name'],
                'email' => $data['email'], 'password' => $data['password'],
                'company_id' => $companyId, 'account_type' => 'company', 'status' => 'active',
            ]);
            $user->syncRoles($data['roles']);
            $user->syncPermissions($data['permissions'] ?? []);

            return $user->load('roles');
        });
        $audit->record('users.created', $user, $companyId, $request->user()->id, ['role_ids' => $data['roles']], $request);

        return response()->json(['data' => $user], 201);
    }

    public function activity(Request $request, User $user): JsonResponse
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);

        return response()->json(['data' => AuditLog::query()->where('company_id', $user->company_id)->where('user_id', $user->id)->latest()->paginate(25)]);
    }

    public function profile(Request $request, User $user): Response
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);
        abort_unless($user->is($request->user()) || $request->user()->can('users.view'), 403);

        return Inertia::render('Company/UserProfile', [
            'profile' => $user->only(['id', 'name', 'first_name', 'second_name', 'email', 'status', 'created_at', 'last_login_at', 'login_count', 'failed_login_attempts']),
            'roles' => $user->roles()->where('roles.company_id', $request->user()->company_id)->pluck('name'),
        ]);
    }

    public function activityPage(Request $request, User $user): Response
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);
        abort_unless($request->user()->can('users.view_activity'), 403);

        return Inertia::render('Company/UserActivity', [
            'profile' => $user->only(['id', 'name', 'email']),
            'activity' => AuditLog::query()->where('company_id', $user->company_id)->where('user_id', $user->id)->latest()->paginate(25),
        ]);
    }

    public function loginHistoryPage(Request $request, User $user): Response
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);
        abort_unless($request->user()->can('users.view_activity'), 403);

        return Inertia::render('Company/UserLoginHistory', [
            'profile' => $user->only(['id', 'name', 'email']),
            'logins' => LoginLog::query()->where('company_id', $user->company_id)->where('user_id', $user->id)->latest()->paginate(25),
        ]);
    }

    public function loginHistory(Request $request, User $user): JsonResponse
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);

        return response()->json(['data' => LoginLog::query()
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(25)]);
    }

    public function updateUser(Request $request, User $user, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);
        abort_if($user->hasRole('Company Owner') && ! $user->is($request->user()), 403);
        abort_if($user->is($request->user()) && ! $request->user()->isCompanyOwner(), 403);
        $companyId = (int) $request->user()->company_id;
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'second_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);
        $assignable = $request->user()->getAllPermissions()->pluck('name')->all();
        $rolePermissions = Role::query()->where('company_id', $companyId)->whereIn('id', $data['roles'])->with('permissions')->get()->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique()->all();
        abort_if(array_diff($rolePermissions, $assignable), 403, 'You cannot assign a role with permissions you do not hold.');
        abort_if(array_diff($data['permissions'] ?? [], $assignable), 403, 'You cannot grant permissions you do not hold.');
        $user->forceFill([
            'first_name' => $data['first_name'],
            'second_name' => $data['second_name'],
            'name' => trim($data['first_name'].' '.$data['second_name']),
            'email' => $data['email'],
        ])->save();
        $user->syncRoles($data['roles']);
        $user->syncPermissions($data['permissions'] ?? []);
        $audit->record('users.updated', $user, $companyId, $request->user()->id, ['role_ids' => $data['roles'], 'permissions' => $data['permissions'] ?? []], $request);

        return response()->json(['data' => $user->load('roles')]);
    }

    public function setDirectPermissions(Request $request, User $user, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);
        abort_if($user->hasRole('Company Owner'), 403, 'The company owner permissions cannot be changed directly.');
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => ['string', 'exists:permissions,name']]);
        $assignable = $request->user()->getAllPermissions()->pluck('name')->all();
        abort_if(array_diff($data['permissions'] ?? [], $assignable), 403, 'You cannot grant permissions you do not hold.');
        $user->syncPermissions($data['permissions'] ?? []);
        $audit->record('users.direct_permissions_updated', $user, $user->company_id, $request->user()->id, ['permissions' => $data['permissions'] ?? []], $request);

        return response()->json(['message' => 'Permissions updated.']);
    }

    public function setStatus(Request $request, User $user, AuditRecorder $audit, CompanyLimitService $limits, SessionRevocationService $sessions): JsonResponse
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);
        abort_if($user->hasRole('Company Owner') || $user->is($request->user()), 403);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        DB::transaction(function () use ($data, $user, $request, $limits): void {
            if ($data['active']) {
                $company = $request->attributes->get('company');
                $subscription = $company->subscriptions()->latest('starts_at')->firstOrFail();
                $limits->lockAndAssertCanAdd($company, $subscription->id, 'users');
            }
            $user->forceFill([
                'status' => $data['active'] ? 'active' : 'disabled',
                'disabled_at' => $data['active'] ? null : now(),
            ])->save();

            if (! $data['active']) {
                $user->tokens()->delete();
                $sessions->revoke($user);
            }
        });
        $audit->record('users.status_changed', $user, $user->company_id, $request->user()->id, ['status' => $user->status], $request);

        return response()->json(['message' => 'User status updated.']);
    }

    public function resetPassword(Request $request, User $user, AuditRecorder $audit, SessionRevocationService $sessions): JsonResponse
    {
        abort_unless((int) $user->company_id === (int) $request->user()->company_id, 404);
        abort_if($user->hasRole('Company Owner'), 403);
        $data = $request->validate(['password' => ['required', 'confirmed', 'string', 'min:8']]);
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $user->tokens()->delete();
        $sessions->revoke($user);
        $audit->record('users.password_reset', $user, $user->company_id, $request->user()->id, [], $request);

        return response()->json(['message' => 'Password changed.']);
    }
}
