<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\LocalLicense;
use App\Models\Plan;
use App\Models\RegistrationRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\RegistrationVerificationNotification;
use App\Notifications\SubscriptionExpiryWarning;
use App\Services\CompanyLimitService;
use App\Services\CompanyProvisioningService;
use App\Services\LocalLicenseService;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PhaseOneFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_health_is_versioned_json(): void
    {
        $this->get('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'version' => 'v1'])
            ->assertHeader('content-type', 'application/json');
    }

    public function test_arabic_locale_is_shared_with_inertia_and_document_direction(): void
    {
        $this->withSession(['locale' => 'ar'])
            ->get('/')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertInertia(fn ($page) => $page->where('locale', 'ar'));
    }

    public function test_locale_switch_route_persists_arabic_for_the_next_inertia_request(): void
    {
        $this->withSession(['locale' => 'en'])
            ->post('/locale/ar')
            ->assertRedirect();

        $this->get('/')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false);
    }

    public function test_authenticated_users_are_redirected_from_home_to_their_workspace(): void
    {
        $company = Company::query()->create(['name' => 'Redirect Company', 'status' => 'active']);
        $owner = User::query()->create([
            'name' => 'Company Owner', 'email' => 'redirect-owner@example.test', 'password' => 'password',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active',
        ]);
        $admin = User::query()->create([
            'name' => 'Platform Admin', 'email' => 'redirect-admin@example.test', 'password' => 'password',
            'account_type' => 'super_admin', 'status' => 'active',
        ]);

        $this->actingAs($owner)->get('/')->assertRedirect(route('company.dashboard'));
        $this->actingAs($admin)->get('/')->assertRedirect(route('super-admin.dashboard'));
    }

    public function test_self_registration_is_not_approvable_until_signed_email_verification(): void
    {
        Notification::fake();
        config(['registration.open' => true]);
        $plan = Plan::query()->create(['code' => 'monthly', 'name' => 'Monthly', 'duration' => 'monthly', 'price' => 100, 'is_active' => true]);

        $this->post('/register', [
            'company_name' => 'North Steel',
            'owner_name' => 'Company Owner',
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'plan_id' => $plan->id,
            'terms_accepted' => '1',
        ])->assertRedirect();

        $registration = RegistrationRequest::query()->firstOrFail();
        $this->assertSame('unverified', $registration->status);
        Notification::assertSentOnDemand(RegistrationVerificationNotification::class);

        $url = URL::temporarySignedRoute('registration.verify', now()->addHour(), ['registration' => $registration->id]);
        $this->get($url)->assertRedirect('/');
        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertNotSame('correct-horse-battery', $registration->getRawOriginal('password'));

        $approver = User::query()->create([
            'name' => 'Platform Operator', 'email' => 'admin@example.test',
            'password' => 'correct-horse-battery', 'account_type' => 'super_admin', 'status' => 'active',
        ]);
        $company = app(CompanyProvisioningService::class)->approve($registration->fresh(), $approver, $plan);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame($company->id, $owner->company_id);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('correct-horse-battery', $owner->password));
        $this->assertDatabaseHas('subscriptions', ['company_id' => $company->id, 'plan_id' => $plan->id]);
        $this->assertDatabaseHas('roles', ['company_id' => $company->id, 'name' => 'Company Owner']);
        $this->assertDatabaseHas('roles', ['company_id' => $company->id, 'name' => 'Accounts Manager']);
    }

    public function test_super_admin_credentials_are_rejected_by_company_login(): void
    {
        User::query()->create([
            'name' => 'Platform Operator',
            'email' => 'operator@example.test',
            'password' => 'correct-horse-battery',
            'account_type' => 'super_admin',
            'status' => 'active',
        ]);

        $this->post('/login', ['email' => 'operator@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');
        $this->assertDatabaseHas('login_logs', ['email' => 'operator@example.test', 'successful' => false]);
    }

    public function test_company_web_login_accepts_a_company_user_with_a_tenant(): void
    {
        $company = Company::query()->create(['name' => 'Company Login Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'company-login', 'name' => 'Company Login', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        User::query()->create([
            'name' => 'Company Owner', 'email' => 'company-login@example.test', 'password' => 'correct-horse-battery',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active',
        ]);

        $this->post('/login', ['email' => 'company-login@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('company.dashboard'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'company-login@example.test')->firstOrFail());
        $this->assertDatabaseHas('login_logs', ['email' => 'company-login@example.test', 'successful' => true]);
    }

    public function test_super_admin_web_login_accepts_only_the_platform_account_type(): void
    {
        User::query()->create([
            'name' => 'Platform Operator', 'email' => 'platform-login@example.test',
            'password' => 'correct-horse-battery', 'account_type' => 'super_admin', 'status' => 'active',
        ]);

        $this->post('/super-admin/login', ['email' => 'platform-login@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('super-admin.dashboard'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'platform-login@example.test')->firstOrFail());
    }

    public function test_super_admin_can_create_a_verified_company_owner_with_roles_subscription_and_coupon(): void
    {
        $admin = User::query()->create([
            'name' => 'Platform Operator', 'email' => 'manual-create-admin@example.test',
            'password' => 'correct-horse-battery', 'account_type' => 'super_admin', 'status' => 'active',
        ]);
        $plan = Plan::query()->create([
            'code' => 'manual-plan', 'name' => 'Manual Plan', 'duration' => 'monthly', 'price' => 100,
            'limits' => ['users' => 4], 'is_active' => true,
        ]);
        $coupon = Coupon::query()->create([
            'code' => 'MANUAL10', 'discount_type' => 'percent', 'discount_value' => 10,
            'max_redemptions' => 5, 'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/super-admin/companies', [
            'name' => 'Manual Steel Ltd',
            'trade_name' => 'Manual Steel',
            'tax_number' => 'TAX-MANUAL-01',
            'phone' => '+201000000001',
            'country' => 'Egypt',
            'city' => 'Cairo',
            'address' => 'Industrial Zone',
            'owner_name' => 'Manual Owner',
            'owner_email' => 'manual-owner@example.test',
            'owner_password' => 'correct-horse-battery',
            'owner_password_confirmation' => 'correct-horse-battery',
            'plan_id' => $plan->id,
            'coupon_code' => $coupon->code,
            'notes' => 'Created directly by platform staff.',
        ])->assertRedirect();

        $company = Company::query()->where('name', 'Manual Steel Ltd')->firstOrFail();
        $owner = User::query()->where('email', 'manual-owner@example.test')->firstOrFail();
        $subscription = $company->subscriptions()->with('plan')->firstOrFail();

        $this->assertSame('active', $company->status);
        $this->assertNotNull($company->approved_at);
        $this->assertSame('Created directly by platform staff.', $company->notes);
        $this->assertSame($company->id, $owner->company_id);
        $this->assertSame('company', $owner->account_type);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('correct-horse-battery', $owner->password));
        $this->assertDatabaseHas('roles', ['company_id' => $company->id, 'name' => 'Accounts Manager']);
        $this->assertDatabaseHas('roles', ['company_id' => $company->id, 'name' => 'Accountant']);
        $this->assertDatabaseHas('model_has_roles', [
            'company_id' => $company->id,
            'model_id' => $owner->id,
            'model_type' => $owner->getMorphClass(),
        ]);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame(90.0, (float) $subscription->overrides['payable_amount']);
        $this->assertSame(1, $coupon->fresh()->redemptions_count);
        $this->assertSame(0, RegistrationRequest::query()->count());

        $otherCompany = Company::query()->create(['name' => 'Other Tenant', 'status' => 'active']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $otherCompany->id);
        Role::query()->create(['name' => 'Other Tenant Secret', 'guard_name' => 'web', 'company_id' => $otherCompany->id]);
        $registrar->setPermissionsTeamId(null);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Company Owner'])
            ->assertJsonMissing(['name' => 'Other Tenant Secret']);
    }

    public function test_super_admin_manual_company_creation_rejects_duplicate_owner_email(): void
    {
        $admin = User::query()->create([
            'name' => 'Platform Operator', 'email' => 'duplicate-admin@example.test',
            'password' => 'correct-horse-battery', 'account_type' => 'super_admin', 'status' => 'active',
        ]);
        $existingCompany = Company::query()->create(['name' => 'Existing Tenant', 'status' => 'active']);
        User::query()->create([
            'name' => 'Existing Owner', 'email' => 'duplicate-owner@example.test', 'password' => 'correct-horse-battery',
            'company_id' => $existingCompany->id, 'account_type' => 'company', 'status' => 'active',
        ]);
        $plan = Plan::query()->create(['code' => 'duplicate-plan', 'name' => 'Duplicate Plan', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);

        $this->actingAs($admin)->from('/super-admin')->post('/super-admin/companies', [
            'name' => 'Duplicate Email Company',
            'owner_name' => 'Duplicate Owner',
            'owner_email' => 'duplicate-owner@example.test',
            'owner_password' => 'correct-horse-battery',
            'owner_password_confirmation' => 'correct-horse-battery',
            'plan_id' => $plan->id,
        ])->assertRedirect('/super-admin')->assertSessionHasErrors('owner_email');

        $this->assertDatabaseMissing('companies', ['name' => 'Duplicate Email Company']);
        $this->assertSame(1, User::query()->where('email', 'duplicate-owner@example.test')->count());
    }

    public function test_subscription_status_api_requires_sanctum_authentication(): void
    {
        $this->getJson('/api/v1/subscription')->assertUnauthorized();
    }

    public function test_manually_suspended_company_can_only_reach_subscription_status_and_logout(): void
    {
        $company = Company::query()->create(['name' => 'Suspended Co', 'status' => 'suspended']);
        $plan = Plan::query()->create(['code' => 'suspended-status', 'name' => 'Suspended', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'suspended', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDays(10)]);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'suspended@example.test', 'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/subscription')->assertOk()->assertJson(['status' => 'suspended']);
        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/logout')->assertOk();
    }

    public function test_company_web_password_change_is_allowed_during_read_only_subscription(): void
    {
        $company = Company::query()->create(['name' => 'Password Change Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'password-change', 'name' => 'Password Change', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDays(10)]);
        $user = User::query()->create([
            'name' => 'Company User', 'email' => 'password-change@example.test', 'password' => 'correct-horse-battery',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active',
        ]);

        $this->actingAs($user)->post('/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('a-new-strong-password', $user->fresh()->password));
    }

    public function test_company_api_auth_issues_and_revokes_sanctum_tokens(): void
    {
        $company = Company::query()->create(['name' => 'Token Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'token-plan', 'name' => 'Token plan', 'duration' => 'monthly', 'price' => 20, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        User::query()->create([
            'name' => 'Token User', 'email' => 'token@example.test', 'password' => 'correct-horse-battery',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/login', ['email' => 'token@example.test', 'password' => 'correct-horse-battery'])->assertOk();
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => (int) strstr($token, '|', true)]);
    }

    public function test_company_role_listing_is_scoped_to_authenticated_tenant(): void
    {
        $firstCompany = Company::query()->create(['name' => 'One', 'status' => 'active']);
        $secondCompany = Company::query()->create(['name' => 'Two', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'test', 'name' => 'Test', 'duration' => 'monthly', 'price' => 0, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $firstCompany->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $user = User::query()->create([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'correct-horse-battery',
            'company_id' => $firstCompany->id, 'account_type' => 'company', 'status' => 'active',
        ]);
        $permission = Permission::query()->create(['name' => 'roles.view', 'guard_name' => 'web']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $firstCompany->id);
        $firstRole = Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $firstCompany->id]);
        $firstRole->givePermissionTo($permission);
        $user->assignRole($firstRole);
        $registrar->setPermissionsTeamId((int) $secondCompany->id);
        Role::query()->create(['name' => 'Private Role', 'guard_name' => 'web', 'company_id' => $secondCompany->id]);
        $registrar->setPermissionsTeamId(null);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Company Owner'])
            ->assertJsonMissing(['name' => 'Private Role']);
    }

    public function test_company_user_table_includes_registration_login_and_failure_metrics(): void
    {
        $company = Company::query()->create(['name' => 'User Metrics Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'metrics', 'name' => 'Metrics', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $user = User::query()->create([
            'name' => 'Manager', 'first_name' => 'Mona', 'second_name' => 'Said', 'email' => 'manager@example.test',
            'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company',
            'status' => 'active', 'last_login_at' => now()->subHour(), 'login_count' => 7, 'failed_login_attempts' => 2,
        ]);
        $permission = Permission::query()->create(['name' => 'users.view', 'guard_name' => 'web']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $role = Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $registrar->setPermissionsTeamId(null);

        $this->actingAs($user)->get('/settings/access')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/Access')
                ->where('users.data.0.login_count', 7)
                ->where('users.data.0.failed_login_attempts', 2)
                ->where('users.data.0.first_name', 'Mona')
                ->where('canViewActivity', true)
                ->has('users.data.0.created_at')
                ->has('users.data.0.last_login_at'));
        $this->get("/settings/users/{$user->id}")->assertOk()->assertInertia(fn ($page) => $page->component('Company/UserProfile')->where('profile.email', 'manager@example.test'));
    }

    public function test_company_roles_can_be_created_and_updated_without_permission_escalation(): void
    {
        $company = Company::query()->create(['name' => 'Role Admin Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'role-admin', 'name' => 'Role Admin', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'role-admin@example.test', 'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        $permissionNames = ['roles.view', 'roles.create', 'roles.assign_permissions', 'reports.view', 'users.update', 'users.deactivate'];
        $permissions = collect($permissionNames)->map(fn (string $name) => Permission::query()->create(['name' => $name, 'guard_name' => 'web']));
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $ownerRole = Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]);
        $ownerRole->syncPermissions($permissions->whereNotIn('name', ['users.deactivate'])->all());
        $user->assignRole($ownerRole);
        $this->assertTrue($user->hasPermissionTo('roles.create'));
        $registrar->setPermissionsTeamId(null);

        Sanctum::actingAs($user);
        $created = $this->postJson('/api/v1/roles', ['name' => 'Reporter', 'permissions' => ['reports.view']])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Reporter');
        $roleId = $created->json('data.id');
        $this->putJson("/api/v1/roles/{$roleId}", ['name' => 'Senior Reporter', 'permissions' => ['reports.view']])
            ->assertOk()
            ->assertJsonPath('data.name', 'Senior Reporter');

        $registrar->setPermissionsTeamId((int) $company->id);
        $target = User::query()->create(['name' => 'Staff Member', 'first_name' => 'Staff', 'second_name' => 'Member', 'email' => 'role-target@example.test', 'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        $target->assignRole(Role::query()->where('company_id', $company->id)->where('name', 'Senior Reporter')->firstOrFail());
        $elevatedRole = Role::query()->create(['name' => 'Elevated', 'guard_name' => 'web', 'company_id' => $company->id]);
        $elevatedRole->givePermissionTo($permissions->firstWhere('name', 'users.deactivate'));
        $registrar->setPermissionsTeamId(null);

        $this->putJson("/api/v1/users/{$target->id}", [
            'first_name' => 'Staff', 'second_name' => 'Member', 'email' => 'role-target@example.test',
            'roles' => [$elevatedRole->id], 'permissions' => [],
        ])->assertForbidden();
        $this->putJson("/api/v1/users/{$target->id}", [
            'first_name' => 'Updated', 'second_name' => 'Member', 'email' => 'role-target@example.test',
            'roles' => [Role::query()->where('company_id', $company->id)->where('name', 'Senior Reporter')->value('id')],
            'permissions' => ['reports.view'],
        ])->assertOk()->assertJsonPath('data.first_name', 'Updated');
    }

    public function test_company_user_limit_excludes_owner_and_honors_super_admin_addons(): void
    {
        $company = Company::query()->create(['name' => 'Limited Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'limited', 'name' => 'Limited', 'duration' => 'monthly', 'price' => 10, 'limits' => ['users' => 1], 'is_active' => true]);
        $subscription = Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $ownerRole = Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]);
        $staffRole = Role::query()->create(['name' => 'Staff', 'guard_name' => 'web', 'company_id' => $company->id]);
        $createUsers = Permission::query()->create(['name' => 'users.create', 'guard_name' => 'web']);
        $ownerRole->givePermissionTo($createUsers);
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'limit-owner@example.test', 'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        $owner->assignRole($ownerRole);
        $staff = User::query()->create(['name' => 'Staff', 'email' => 'limit-staff@example.test', 'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        $staff->assignRole($staffRole);
        $disabled = User::query()->create(['name' => 'Disabled', 'email' => 'limit-disabled@example.test', 'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'disabled', 'disabled_at' => now()]);
        $disabled->assignRole($staffRole);
        $registrar->setPermissionsTeamId(null);

        $limits = app(CompanyLimitService::class);
        $this->assertSame(1, $limits->currentCount($company, 'users'));
        $this->assertSame(1, $limits->effectiveLimit($subscription, 'users'));
        try {
            $limits->assertCanAdd($company, $subscription, 'users');
            $this->fail('A user should be blocked at the plan limit.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Sanctum::actingAs($owner);
        $newUser = [
            'first_name' => 'New', 'second_name' => 'Seat', 'email' => 'new-seat@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
            'roles' => [$staffRole->id],
        ];
        $this->postJson('/api/v1/users', $newUser)->assertForbidden();

        $subscription->forceFill(['overrides' => ['limit_addons' => ['users' => 2, 'branches' => 3, 'warehouses' => 4]]])->save();
        $this->assertSame(3, $limits->effectiveLimit($subscription->fresh(), 'users'));
        $this->assertSame(3, $limits->effectiveLimit($subscription->fresh(), 'branches'));
        $this->assertSame(4, $limits->effectiveLimit($subscription->fresh(), 'warehouses'));
        $this->assertSame(0, $limits->currentCount($company, 'branches'));
        $limits->assertCanAdd($company, $subscription->fresh(), 'users');
        $this->postJson('/api/v1/users', $newUser)->assertCreated();
    }

    public function test_super_admin_can_change_company_lifecycle_plan_renewal_and_limit_addons(): void
    {
        $company = Company::query()->create(['name' => 'Lifecycle Co', 'status' => 'active']);
        $firstPlan = Plan::query()->create(['code' => 'life-first', 'name' => 'First', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        $secondPlan = Plan::query()->create(['code' => 'life-second', 'name' => 'Second', 'duration' => 'yearly', 'price' => 20, 'is_active' => true]);
        $subscription = Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $firstPlan->id, 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDays(10)]);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'lifecycle-admin@example.test', 'password' => 'correct-horse-battery', 'account_type' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->get('/super-admin')->assertOk()->assertInertia(fn ($page) => $page
            ->component('SuperAdmin/Dashboard')
            ->where('companies.data.0.name', 'Lifecycle Co')
            ->where('companies.data.0.subscription.plan.name', 'First'));
        $this->put("/super-admin/companies/{$company->id}/limit-addons", ['users' => 2, 'branches' => 1, 'warehouses' => 3])->assertRedirect();
        $this->assertSame(['users' => 2, 'branches' => 1, 'warehouses' => 3], $subscription->fresh()->overrides['limit_addons']);
        $this->post("/super-admin/companies/{$company->id}/plan", ['plan_id' => $secondPlan->id])->assertRedirect();
        $this->assertSame($secondPlan->id, $subscription->fresh()->plan_id);
        $this->post("/super-admin/companies/{$company->id}/renew", ['amount' => 20, 'reference' => 'renewal-test'])->assertRedirect();
        $this->assertTrue($subscription->fresh()->ends_at->isFuture());
        $this->assertDatabaseHas('manual_payments', ['subscription_id' => $subscription->id, 'status' => 'paid', 'reference' => 'renewal-test']);
        $this->post("/super-admin/companies/{$company->id}/suspend")->assertRedirect();
        $this->assertSame('suspended', $company->fresh()->status);
        $this->post("/super-admin/companies/{$company->id}/activate")->assertRedirect();
        $this->assertSame('active', $company->fresh()->status);
        $this->post("/super-admin/companies/{$company->id}/archive")->assertRedirect();
        $this->assertSame('archived', $company->fresh()->status);
        $this->post("/super-admin/companies/{$company->id}/restore")->assertRedirect();
        $this->assertSame('active', $company->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'company.restored', 'user_id' => $admin->id]);
    }

    public function test_restoring_a_company_archived_for_over_six_months_keeps_it_suspended_until_renewal(): void
    {
        $company = Company::query()->create(['name' => 'Long Archived Co', 'status' => 'archived', 'archived_at' => now()->subMonths(7)]);
        $plan = Plan::query()->create(['code' => 'long-archived', 'name' => 'Long Archived', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        $subscription = Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subYear(), 'ends_at' => now()->subMonths(7)]);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'restore-admin@example.test', 'password' => 'correct-horse-battery', 'account_type' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->post("/super-admin/companies/{$company->id}/restore")->assertRedirect();

        $this->assertSame('active', $company->fresh()->status);
        $this->assertNull($company->fresh()->archived_at);
        $this->assertSame('suspended', $subscription->fresh()->status);
    }

    public function test_expiry_reminders_start_seven_days_before_and_are_deduplicated_daily(): void
    {
        NotificationFacade::fake();
        $this->travelTo(now()->startOfDay());
        $company = Company::query()->create(['name' => 'Reminder Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'reminder', 'name' => 'Reminder', 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        $subscription = Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(7)]);
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'reminder-owner@example.test', 'password' => 'correct-horse-battery', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $owner->assignRole(Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]));
        $registrar->setPermissionsTeamId(null);

        $this->artisan('subscriptions:send-expiry-warnings')->assertExitCode(0);
        $this->artisan('subscriptions:send-expiry-warnings')->assertExitCode(0);
        NotificationFacade::assertSentToTimes($owner, SubscriptionExpiryWarning::class, 1);
        $this->assertDatabaseCount('subscription_expiry_notices', 1);

        $this->travel(1)->day();
        $this->artisan('subscriptions:send-expiry-warnings')->assertExitCode(0);
        NotificationFacade::assertSentToTimes($owner, SubscriptionExpiryWarning::class, 2);
        $this->assertDatabaseCount('subscription_expiry_notices', 2);
    }

    public function test_subscription_lifecycle_has_read_only_suspension_and_archive_windows(): void
    {
        $lifecycle = app(SubscriptionLifecycle::class);
        $subscription = new Subscription(['status' => 'active', 'ends_at' => now()->subDays(2)]);
        $this->assertSame('grace', $lifecycle->status($subscription));
        $subscription->ends_at = now()->subDays(10);
        $this->assertSame('read_only', $lifecycle->status($subscription));
        $subscription->ends_at = now()->subDays(40);
        $this->assertSame('suspended', $lifecycle->status($subscription));
        $subscription->ends_at = now()->subMonths(7);
        $this->assertSame('archived', $lifecycle->status($subscription));
    }

    public function test_read_only_subscription_rejects_company_api_writes(): void
    {
        $company = Company::query()->create(['name' => 'Read Only Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'readonly', 'name' => 'Read Only', 'duration' => 'monthly', 'price' => 50, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDays(10)]);
        $user = User::query()->create([
            'name' => 'Owner', 'email' => 'readonly@example.test', 'password' => 'correct-horse-battery',
            'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active',
        ]);
        $permission = Permission::query()->create(['name' => 'users.create', 'guard_name' => 'web']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $role = Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $registrar->setPermissionsTeamId(null);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->getJson('/api/v1/subscription')->assertOk()->assertJson(['status' => 'read_only']);
        $this->putJson('/api/v1/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertOk();
    }

    public function test_local_license_activates_offline_from_a_valid_installation_bound_signature(): void
    {
        $keyPair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($keyPair === false) {
            $this->markTestSkipped('The configured PHP CLI cannot generate an ephemeral OpenSSL keypair.');
        }
        openssl_pkey_export($keyPair, $privateKey);
        $details = openssl_pkey_get_details($keyPair);
        $this->assertIsArray($details);
        config(['licensing.public_key' => $details['key']]);
        $payload = json_encode(['installation_id' => 'install-123', 'customer_binding' => 'customer-456'], JSON_THROW_ON_ERROR);
        openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $licenseKey = base64_encode($payload).'.'.base64_encode($signature);

        $license = app(LocalLicenseService::class)->activate($licenseKey, 'install-123', 'customer-456');

        $this->assertNotSame($licenseKey, $license->key_hash);
        $this->assertSame(hash('sha256', $licenseKey), $license->key_hash);
        $this->assertSame(1, LocalLicense::query()->whereNotNull('activated_at')->count());
    }

    public function test_local_license_api_fails_closed_without_a_public_verification_key(): void
    {
        config(['licensing.edition' => 'local', 'licensing.public_key' => null]);

        $this->postJson('/api/v1/license/activate', [
            'license_key' => 'not-a-license',
            'installation_id' => 'install-123',
            'customer_binding' => 'customer-456',
        ])->assertStatus(503)->assertJsonStructure(['message']);
    }
}
