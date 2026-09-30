<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\RegistrationRequest;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CompanyProvisioningService
{
    private const PERMISSIONS = [
        'dashboard.view',
        'users.view', 'users.create', 'users.update', 'users.deactivate', 'users.reset_password',
        'users.view_activity', 'roles.view', 'roles.create', 'roles.update', 'roles.assign_permissions',
        'subscriptions.view', 'subscriptions.renew', 'subscriptions.settings',
        'branches.view', 'branches.create', 'branches.update', 'branches.archive',
        'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.archive', 'warehouses.transfer',
        'products.view', 'products.create', 'products.update', 'products.archive',
        'inventory.view', 'inventory.transfer', 'inventory.export',
        'parties.view', 'parties.create', 'parties.update',
        'accounting.view', 'accounting.create', 'accounting.post', 'accounting.reverse', 'accounting.close_period',
        'reports.view', 'reports.export',
        'purchases.view', 'purchases.create', 'purchases.update', 'purchases.approve', 'purchases.update_approved', 'purchases.cancel', 'purchases.reverse', 'purchases.print', 'purchases.export',
        'sales.view', 'sales.create', 'sales.update', 'sales.approve', 'sales.update_approved', 'sales.cancel', 'sales.reverse', 'sales.print', 'sales.export',
        'fleet.view', 'fleet.create', 'fleet.update', 'fleet.approve', 'fleet.expenses', 'fleet.maintenance', 'fleet.export',
    ];

    public function approve(RegistrationRequest $registration, User $approver, Plan $plan): Company
    {
        return DB::transaction(function () use ($registration, $approver, $plan): Company {
            $registration = RegistrationRequest::query()->lockForUpdate()->findOrFail($registration->id);
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            abort_unless($registration->status === 'pending' && $registration->verified_at, 409);
            abort_unless($plan->is_active, 409, 'The selected plan is no longer active.');
            $coupon = $registration->coupon_id ? Coupon::query()->lockForUpdate()->find($registration->coupon_id) : null;
            abort_if($registration->coupon_id && (! $coupon || ! $coupon->isAvailableFor($plan)), 409, 'The selected coupon is no longer available.');
            abort_if($plan->duration === 'trial' && ! $plan->duration_days, 409, 'Trial duration must be configured.');
            $company = $this->provision([
                'name' => $registration->company_name,
                'trade_name' => $registration->trade_name,
                'tax_number' => $registration->tax_number,
                'phone' => $registration->phone,
                'country' => $registration->country,
                'city' => $registration->city,
                'address' => $registration->address,
                'owner_name' => $registration->owner_name,
                'owner_email' => $registration->email,
                'owner_password' => $registration->getRawOriginal('password'),
                'owner_verified_at' => $registration->verified_at,
                'notes' => null,
                'registration_id' => $registration->id,
            ], $approver, $plan, $coupon);

            $registration->forceFill([
                'status' => 'approved', 'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
            ])->save();

            app(AuditRecorder::class)->record('registration.approved', $company, $company->id, $approver->id, ['plan_id' => $plan->id, 'coupon_id' => $coupon?->id]);

            return $company;
        });
    }

    public function createManually(array $data, User $approver, Plan $plan, ?Coupon $coupon): Company
    {
        return DB::transaction(function () use ($data, $approver, $plan, $coupon): Company {
            abort_if(User::query()->where('email', $data['owner_email'])->exists(), 422, 'The owner email is already in use.');
            $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            abort_unless($plan->is_active, 422, 'The selected plan is no longer active.');
            $coupon = $coupon ? Coupon::query()->lockForUpdate()->findOrFail($coupon->id) : null;
            abort_if($coupon && ! $coupon->isAvailableFor($plan), 422, 'The selected coupon is not available.');
            abort_if($plan->duration === 'trial' && ! $plan->duration_days, 422, 'Trial duration must be configured.');

            $company = $this->provision([
                ...$data,
                'owner_verified_at' => now(),
            ], $approver, $plan, $coupon);

            app(AuditRecorder::class)->record('company.created_manually', $company, null, $approver->id, [
                'owner_user_id' => $company->users()->where('account_type', 'company')->value('id'),
                'plan_id' => $plan->id,
                'coupon_id' => $coupon?->id,
            ]);

            return $company;
        });
    }

    private function provision(array $data, User $approver, Plan $plan, ?Coupon $coupon): Company
    {
        $plan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
        abort_unless($plan->is_active, 409, 'The selected plan is no longer active.');
        $company = Company::query()->create([
            'name' => $data['name'],
            'trade_name' => $data['trade_name'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'phone' => $data['phone'] ?? null,
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
            'settings' => [
                'purchase_inventory_basis' => CompanyAccountingPolicyService::ACTUAL_WEIGHT,
                'vat_enabled' => false,
                'vat_rate' => 0,
            ],
            'approved_at' => now(),
        ]);
        $owner = User::query()->create([
            'name' => $data['owner_name'],
            'email' => $data['owner_email'],
            'password' => $data['owner_password'],
            'email_verified_at' => $data['owner_verified_at'],
            'company_id' => $company->id,
            'account_type' => 'company',
            'status' => 'active',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);

        try {
            foreach (self::PERMISSIONS as $permissionName) {
                Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            }
            $ownerRole = Role::query()->create([
                'name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id,
            ]);
            $ownerRole->syncPermissions(Permission::query()->get());
            $owner->assignRole($ownerRole);

            foreach (['Accounts Manager', 'Accountant'] as $roleName) {
                $role = Role::query()->create([
                    'name' => $roleName, 'guard_name' => 'web', 'company_id' => $company->id,
                ]);
                $rolePermissions = $roleName === 'Accounts Manager'
                    ? [
                        'users.view', 'users.create', 'users.update', 'users.deactivate', 'roles.view', 'roles.create',
                        'subscriptions.view', 'branches.view', 'branches.create', 'branches.update', 'branches.archive',
                        'warehouses.view', 'warehouses.update', 'warehouses.archive', 'products.view', 'products.create',
                        'products.update', 'products.archive', 'inventory.view', 'inventory.transfer', 'parties.view',
                        'parties.create', 'parties.update', 'accounting.view', 'accounting.create', 'reports.view', 'reports.export',
                    ]
                    : ['users.view', 'roles.view', 'branches.view', 'warehouses.view', 'products.view', 'inventory.view', 'parties.view', 'accounting.view', 'reports.view'];
                $role->syncPermissions(Permission::query()->whereIn('name', $rolePermissions)->get());
            }
        } finally {
            $registrar->setPermissionsTeamId(null);
        }

        app(AccountingFoundationService::class)->seedCompany($company);
        $startsAt = now();
        $basePrice = (float) $plan->price;
        $discount = $coupon ? ($coupon->discount_type === 'percent'
            ? $basePrice * min(100, (float) $coupon->discount_value) / 100
            : min($basePrice, (float) $coupon->discount_value)) : 0.0;

        $subscription = Subscription::query()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => app(SubscriptionLifecycle::class)->expiryFor($plan, $startsAt->toImmutable()),
            'overrides' => [
                'base_price' => $basePrice,
                'discount_amount' => round($discount, 2),
                'payable_amount' => round($basePrice - $discount, 2),
                'coupon_id' => $coupon?->id,
            ],
        ]);

        if ($coupon) {
            app(CouponRedemptionService::class)->redeem(
                $coupon,
                $plan,
                $company,
                $subscription,
                $approver,
                isset($data['registration_id']) ? RegistrationRequest::query()->find($data['registration_id']) : null,
            );
        }

        app(AuditRecorder::class)->record('company.provisioned', $company, $company->id, $approver->id, [
            'plan_id' => $plan->id,
            'coupon_id' => $coupon?->id,
        ]);

        return $company;
    }
}
