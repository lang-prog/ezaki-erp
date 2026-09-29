<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = DB::table('permissions')->where('guard_name', 'web')->pluck('id');
        $ownerRoles = DB::table('roles')->where('guard_name', 'web')->where('name', 'Company Owner')->get(['id', 'company_id']);

        foreach ($ownerRoles as $role) {
            $existing = DB::table('role_has_permissions')->where('role_id', $role->id)->pluck('permission_id');
            $rows = $permissions->diff($existing)->map(fn (int $permissionId): array => [
                'permission_id' => $permissionId,
                'role_id' => $role->id,
            ])->all();
            if ($rows !== []) {
                DB::table('role_has_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Owner permissions are additive and may be required by later modules.
    }
};
