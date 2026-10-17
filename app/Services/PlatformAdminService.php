<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlatformAdminService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly MfaService $mfa) {}

    public function create(array $data, User $actor): User
    {
        abort_unless(in_array($data['role'], PlatformPermissions::roles(), true), 422);

        return DB::transaction(function () use ($data, $actor): User {
            $admin = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'email_verified_at' => now(),
                'account_type' => 'super_admin',
                'platform_role' => $data['role'],
                'status' => 'active',
            ]);
            $this->audit->record('platform.admin_created', $admin, null, $actor->id, ['role' => $admin->platform_role], null, ['reason' => $data['reason'] ?? null, 'after' => ['role' => $admin->platform_role]]);

            return $admin;
        });
    }

    public function changeRole(User $admin, string $role, User $actor, string $reason): User
    {
        abort_unless(in_array($role, PlatformPermissions::roles(), true), 422);

        return DB::transaction(function () use ($admin, $role, $actor, $reason): User {
            $admin = $this->lockAdmin($admin);
            if ($admin->platform_role === 'owner' && $role !== 'owner') {
                $this->assertAnotherOwnerRemains($admin);
            }
            $before = $admin->platform_role;
            if ($before === $role) {
                return $admin;
            }
            $admin->forceFill(['platform_role' => $role, 'session_version' => $admin->session_version + 1])->save();
            $this->audit->record('platform.admin_role_changed', $admin, null, $actor->id, [], null, ['reason' => $reason, 'severity' => 'warning', 'before' => ['role' => $before], 'after' => ['role' => $role]]);

            return $admin;
        });
    }

    public function setActive(User $admin, bool $active, User $actor, string $reason): User
    {
        return DB::transaction(function () use ($admin, $active, $actor, $reason): User {
            $admin = $this->lockAdmin($admin);
            if (! $active && $admin->platform_role === 'owner' && $admin->isActive()) {
                $this->assertAnotherOwnerRemains($admin);
            }
            if ($admin->isActive() === $active) {
                return $admin;
            }
            $admin->forceFill([
                'status' => $active ? 'active' : 'disabled',
                'disabled_at' => $active ? null : now(),
                'session_version' => $admin->session_version + 1,
            ])->save();
            $admin->tokens()->delete();
            $this->audit->record($active ? 'platform.admin_enabled' : 'platform.admin_disabled', $admin, null, $actor->id, [], null, ['reason' => $reason, 'severity' => 'warning']);

            return $admin;
        });
    }

    public function resetMfa(User $admin, User $actor, string $reason): void
    {
        $admin = $this->lockAdmin($admin);
        $this->mfa->disable($admin, $actor, $reason);
        $admin->forceFill(['session_version' => $admin->session_version + 1])->save();
    }

    private function lockAdmin(User $admin): User
    {
        $locked = User::query()->where('account_type', 'super_admin')->lockForUpdate()->find($admin->id);
        if (! $locked) {
            throw new HttpException(404);
        }

        return $locked;
    }

    private function assertAnotherOwnerRemains(User $target): void
    {
        $others = User::query()->where('account_type', 'super_admin')->where('platform_role', 'owner')
            ->where('status', 'active')->whereNull('disabled_at')->whereKeyNot($target->id)->lockForUpdate()->count();
        if ($others < 1) {
            throw ValidationException::withMessages(['admin' => __('platform.last_owner')]);
        }
    }
}
