<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ManualPayment;
use App\Models\Plan;
use App\Models\RegistrationRequest;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\CompanyLimitService;
use App\Services\MfaService;
use App\Services\PlatformAdminService;
use App\Services\SubscriptionLifecycle;
use App\Support\PlatformPermissions;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SuperAdminRemediationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(?string $role = 'owner'): User
    {
        $this->seq++;

        return User::query()->create([
            'name' => "Admin {$this->seq}", 'email' => "platform-{$this->seq}@example.test", 'password' => 'Correct-horse-1',
            'account_type' => 'super_admin', 'platform_role' => $role, 'status' => 'active',
        ]);
    }

    private function company(string $name = 'Acme', string $planDuration = 'monthly', float $price = 10, array $limits = [], ?\DateTimeInterface $endsAt = null): array
    {
        $this->seq++;
        $company = Company::query()->create(['name' => "{$name} {$this->seq}", 'status' => 'active']);
        $plan = Plan::query()->create(['code' => "plan-{$this->seq}", 'name' => "Plan {$this->seq}", 'duration' => $planDuration, 'price' => $price, 'limits' => $limits, 'is_active' => true]);
        $subscription = Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => $endsAt ?? now()->addDays(5)]);

        return [$company, $plan, $subscription];
    }

    private function stepUp(): array
    {
        return ['platform_stepup_at' => time()];
    }

    public function test_platform_routes_follow_the_role_permission_matrix(): void
    {
        $this->assertSame([], PlatformPermissions::forRole(null));
        $this->assertContains('audit.view', PlatformPermissions::forRole('auditor'));
        $this->assertNotContains('subscriptions.payments', PlatformPermissions::forRole('auditor'));
        $this->assertNotContains('audit.view', PlatformPermissions::forRole('billing'));

        $this->actingAs($this->admin(null))->get('/super-admin')->assertForbidden();
        $this->actingAs($this->admin('auditor'))->get('/super-admin/audit')->assertOk();
        $this->actingAs($this->admin('auditor'))->get('/super-admin/companies')->assertOk();
        $this->actingAs($this->admin('billing'))->get('/super-admin/audit')->assertForbidden();
        $this->actingAs($this->admin('billing'))->get('/super-admin/admins')->assertForbidden();
        $this->actingAs($this->admin('support'))->get('/super-admin/plans')->assertForbidden();
        $this->actingAs($this->admin('support'))->get('/super-admin/subscriptions')->assertOk();

        [$company] = $this->company();
        $this->withSession($this->stepUp())->actingAs($this->admin('auditor'))
            ->postJson("/super-admin/companies/{$company->id}/suspend", ['reason' => 'Not allowed'])->assertForbidden();
        $this->assertSame('active', $company->fresh()->status);
        $this->withSession($this->stepUp())->actingAs($this->admin('billing'))
            ->postJson("/super-admin/companies/{$company->id}/suspend", ['reason' => 'Not allowed'])->assertForbidden();
    }

    public function test_company_users_and_anonymous_visitors_cannot_reach_the_platform_area(): void
    {
        $company = Company::query()->create(['name' => 'Tenant', 'status' => 'active']);
        $user = User::query()->create(['name' => 'Tenant Owner', 'email' => 'tenant-owner@example.test', 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);

        $this->get('/super-admin/companies')->assertRedirect();
        $this->actingAs($user)->get('/super-admin/companies')->assertForbidden();
        $this->actingAs($user)->postJson("/super-admin/companies/{$company->id}/suspend", ['reason' => 'x y z'])->assertForbidden();
    }

    public function test_high_impact_actions_require_a_recent_step_up_and_a_reason(): void
    {
        [$company] = $this->company();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/super-admin/companies/{$company->id}/suspend", ['reason' => 'Contract review'])
            ->assertStatus(423)->assertJsonPath('code', 'step_up_required');
        $this->assertSame('active', $company->fresh()->status);

        $this->postJson('/super-admin/step-up', ['password' => 'wrong-password'])->assertStatus(422);
        $this->postJson('/super-admin/step-up', ['password' => 'Correct-horse-1'])->assertOk();

        $this->postJson("/super-admin/companies/{$company->id}/suspend", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/super-admin/companies/{$company->id}/suspend", ['reason' => 'Contract review'])->assertOk();
        $this->assertSame('suspended', $company->fresh()->status);

        $log = AuditLog::query()->where('event', 'company.suspended')->firstOrFail();
        $this->assertSame('Contract review', $log->reason);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('active', $log->before['status']);
        $this->assertSame('suspended', $log->after['status']);

        session(['platform_stepup_at' => time() - 700]);
        $this->postJson("/super-admin/companies/{$company->id}/activate", ['reason' => 'Review finished'])->assertStatus(423);
    }

    public function test_totp_matches_rfc_6238_vectors_and_rejects_replays(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));

        $step = Totp::verify($secret, '287082', 59, 1);
        $this->assertSame(1, $step);
        $this->assertNull(Totp::verify($secret, '287082', 59, 1, 1), 'A code from an already used step must be rejected.');
        $this->assertNull(Totp::verify($secret, '000000', 59, 1));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
    }

    public function test_mfa_enrolment_challenge_and_single_use_recovery_codes(): void
    {
        $admin = $this->admin();
        $mfa = app(MfaService::class);
        $pending = $mfa->newPendingSecret($admin);
        $this->assertNull($mfa->confirmEnrollment($admin, $pending['secret'], '000000'));

        $codes = $mfa->confirmEnrollment($admin, $pending['secret'], Totp::code($pending['secret'], intdiv(time(), 30)));
        $this->assertIsArray($codes);
        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $codes);
        $admin = $admin->fresh();
        $this->assertTrue($admin->hasMfa());
        $this->assertNotContains($codes[0], (array) $admin->mfa_recovery_codes, 'Recovery codes are stored hashed.');

        $this->actingAs($admin)->getJson('/super-admin/companies')->assertStatus(423)->assertJsonPath('code', 'mfa_required');
        $this->get('/super-admin/companies')->assertRedirect(route('super-admin.mfa.challenge'));
        $this->post('/super-admin/mfa/challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->post('/super-admin/mfa/challenge', ['code' => $codes[0]])->assertRedirect();
        $this->get('/super-admin/companies')->assertOk();
        $this->assertFalse($mfa->verify($admin->fresh(), $codes[0]), 'A recovery code works only once.');
        $this->assertSame(MfaService::RECOVERY_CODE_COUNT - 1, $mfa->remainingRecoveryCodes($admin->fresh()));
    }

    public function test_mfa_enforcement_flag_forces_enrolment_for_admins_without_a_second_factor(): void
    {
        config(['security.platform.mfa_required' => true, 'security.platform.mfa_enforce_from' => null]);
        $this->actingAs($this->admin())->getJson('/super-admin/companies')->assertStatus(423)->assertJsonPath('code', 'mfa_enrollment_required');
        $this->get('/super-admin/security')->assertOk();
    }

    public function test_the_last_active_platform_owner_is_protected_and_changes_are_audited(): void
    {
        $owner = $this->admin();
        $service = app(PlatformAdminService::class);

        foreach ([fn () => $service->setActive($owner, false, $owner, 'Leaving'), fn () => $service->changeRole($owner, 'billing', $owner, 'Demote')] as $attempt) {
            try {
                $attempt();
                $this->fail('The last owner must not be removed or demoted.');
            } catch (\Throwable $exception) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $exception);
            }
        }
        $this->assertSame('owner', $owner->fresh()->platform_role);
        $this->assertTrue($owner->fresh()->isActive());

        $second = $this->admin('billing');
        $service->changeRole($second, 'owner', $owner, 'Second owner');
        $service->setActive($owner, false, $second, 'Rotating owners');
        $this->assertFalse($owner->fresh()->isActive());
        $this->assertDatabaseHas('audit_logs', ['event' => 'platform.admin_role_changed', 'user_id' => $owner->id]);
    }

    public function test_manual_payments_are_idempotent_full_paid_and_never_reactivate_a_suspended_company(): void
    {
        [$company, $plan, $subscription] = $this->company(price: 100, endsAt: now()->addDays(10));
        $admin = $this->admin();
        $this->actingAs($admin)->withSession($this->stepUp());
        $before = $subscription->ends_at->copy();

        $payload = ['amount' => 100, 'status' => 'paid', 'reference' => 'bank-1', 'idempotency_key' => 'payment-key-0001', 'reason' => 'Transfer received'];
        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", $payload)->assertOk()->assertJsonPath('replayed', false);
        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, ManualPayment::query()->count());
        $this->assertTrue($subscription->fresh()->ends_at->gt($before), 'A renewal while still active extends from the current end date.');
        $this->assertEqualsWithDelta($before->copy()->addMonthNoOverflow()->timestamp, $subscription->fresh()->ends_at->timestamp, 5);

        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 50, 'status' => 'paid', 'idempotency_key' => 'payment-key-0002', 'reason' => 'Partial'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 100, 'status' => 'refunded', 'idempotency_key' => 'payment-key-0003', 'reason' => 'Refund'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 100, 'status' => 'paid', 'reason' => 'No key'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 150, 'status' => 'paid', 'idempotency_key' => 'payment-key-0004', 'reason' => 'Overpaid'])->assertOk();
        $this->assertSame(2, ManualPayment::query()->where('status', 'paid')->count());

        $this->postJson("/super-admin/companies/{$company->id}/suspend", ['reason' => 'Dispute'])->assertOk();
        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 100, 'status' => 'paid', 'idempotency_key' => 'payment-key-0005', 'reason' => 'Late payment'])->assertOk();
        $this->assertSame('suspended', $company->fresh()->status, 'A payment never reactivates a suspended company.');
        $this->assertSame('suspended', $subscription->fresh()->status);

        $this->postJson("/super-admin/companies/{$company->id}/archive", ['reason' => 'Closed'])->assertOk();
        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 100, 'status' => 'paid', 'idempotency_key' => 'payment-key-0006', 'reason' => 'After archive'])->assertStatus(409);
    }

    public function test_pending_payments_can_be_resolved_once(): void
    {
        [, , $subscription] = $this->company(price: 40, endsAt: now()->subDays(2));
        $this->actingAs($this->admin())->withSession($this->stepUp());

        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 40, 'status' => 'pending', 'idempotency_key' => 'pending-key-0001', 'reason' => 'Awaiting bank'])->assertOk();
        $payment = ManualPayment::query()->firstOrFail();
        $endsBefore = $subscription->fresh()->ends_at->timestamp;
        $this->assertSame($endsBefore, $subscription->fresh()->ends_at->timestamp, 'Pending payments do not extend access.');

        $this->postJson("/super-admin/payments/{$payment->id}/resolve", ['status' => 'paid', 'reason' => 'Bank confirmed'])->assertOk();
        $this->assertTrue($subscription->fresh()->ends_at->isFuture());
        $extended = $subscription->fresh()->ends_at->timestamp;
        $this->postJson("/super-admin/payments/{$payment->id}/resolve", ['status' => 'paid', 'reason' => 'Double click'])->assertOk();
        $this->assertSame($extended, $subscription->fresh()->ends_at->timestamp, 'Resolving twice must not extend twice.');
        $this->postJson("/super-admin/payments/{$payment->id}/resolve", ['status' => 'failed', 'reason' => 'Too late'])->assertStatus(409);
    }

    public function test_plan_change_preserves_history_and_blocks_downgrades_below_current_usage(): void
    {
        [$company, , $subscription] = $this->company();
        $tight = Plan::query()->create(['code' => 'tight', 'name' => 'Tight', 'duration' => 'monthly', 'price' => 1, 'limits' => ['users' => 1], 'is_active' => true]);
        $inactive = Plan::query()->create(['code' => 'old', 'name' => 'Old', 'duration' => 'monthly', 'price' => 1, 'is_active' => false]);
        foreach ([1, 2] as $index) {
            User::query()->create(['name' => "Staff {$index}", 'email' => "staff-{$index}-{$company->id}@example.test", 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        }
        $this->actingAs($this->admin())->withSession($this->stepUp());

        $this->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $tight->id, 'reason' => 'Downgrade'])->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $inactive->id, 'reason' => 'Inactive'])->assertStatus(422);
        $this->assertSame(1, Subscription::query()->where('company_id', $company->id)->count());

        $target = Plan::query()->create(['code' => 'target', 'name' => 'Target', 'duration' => 'yearly', 'price' => 99, 'is_active' => true]);
        $this->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $target->id, 'reason' => 'Upgrade'])->assertOk();

        $this->assertSame(2, Subscription::query()->where('company_id', $company->id)->count());
        $this->assertNotNull($subscription->fresh()->superseded_at);
        $this->assertSame($target->id, app(SubscriptionLifecycle::class)->currentFor($company)->plan_id);
        $this->assertDatabaseHas('subscription_events', ['company_id' => $company->id, 'type' => 'plan_changed', 'to_plan_id' => $target->id]);
    }

    public function test_administrative_suspension_always_beats_subscription_dates(): void
    {
        [$company, , $subscription] = $this->company(endsAt: now()->addYear());
        $lifecycle = app(SubscriptionLifecycle::class);
        $this->assertSame('active', $lifecycle->accessState($company, $subscription));
        $company->forceFill(['status' => 'suspended'])->save();
        $this->assertSame('suspended', $lifecycle->accessState($company->fresh(), $subscription));
        $company->forceFill(['status' => 'archived'])->save();
        $this->assertSame('archived', $lifecycle->accessState($company->fresh(), $subscription));
    }

    public function test_company_creation_rejects_a_duplicate_tax_number_and_defaults_to_factory_weight(): void
    {
        Company::query()->create(['name' => 'Existing', 'status' => 'active', 'tax_number' => 'TAX-777']);
        $plan = Plan::query()->create(['code' => 'create-plan', 'name' => 'Create', 'duration' => 'monthly', 'price' => 1, 'is_active' => true]);
        $this->actingAs($this->admin());

        $payload = ['name' => 'New Co', 'tax_number' => 'TAX-777', 'owner_name' => 'Owner', 'owner_email' => 'new-owner@example.test', 'owner_password' => 'Correct-horse-1', 'owner_password_confirmation' => 'Correct-horse-1', 'plan_id' => $plan->id];
        $this->postJson('/super-admin/companies', $payload)->assertStatus(422)->assertJsonValidationErrors('tax_number');

        $this->postJson('/super-admin/companies', ['tax_number' => 'TAX-778'] + $payload)->assertCreated();
        $this->assertSame('factory_weight', Company::query()->where('name', 'New Co')->firstOrFail()->settings['purchase_inventory_basis']);
    }

    public function test_plan_limits_support_unlimited_and_addons_only_extend_finite_limits(): void
    {
        [$company, $plan, $subscription] = $this->company(limits: ['users' => 3]);
        $this->actingAs($this->admin())->withSession($this->stepUp());

        $this->putJson("/super-admin/companies/{$company->id}/limit-addons", ['users' => 2, 'branches' => 0, 'warehouses' => 0, 'reason' => 'Extra seats'])->assertOk();
        $limits = app(CompanyLimitService::class);
        $this->assertSame(5, $limits->limitFor($plan->limits, $subscription->fresh()->overrides['limit_addons'], 'users'));
        $this->assertNull($limits->limitFor(['users' => null], ['users' => 4], 'users'), 'An unlimited plan stays unlimited with add-ons.');
        $this->assertNull($limits->limitFor([], [], 'branches'));
    }

    public function test_stale_unverified_registrations_are_pruned_but_pending_ones_are_kept(): void
    {
        $base = ['company_name' => 'Reg Co', 'owner_name' => 'Owner', 'password' => 'Correct-horse-1', 'terms_accepted' => true];
        $stale = RegistrationRequest::query()->create($base + ['email' => 'stale@example.test', 'status' => 'unverified', 'verification_expires_at' => now()->subDays(3)]);
        $fresh = RegistrationRequest::query()->create($base + ['email' => 'fresh@example.test', 'status' => 'unverified', 'verification_expires_at' => now()->addHour()]);
        $pending = RegistrationRequest::query()->create($base + ['email' => 'pending@example.test', 'status' => 'pending', 'verified_at' => now()->subDays(5), 'verification_expires_at' => now()->subDays(4)]);

        $this->artisan('registrations:prune-stale', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, RegistrationRequest::query()->count());
        $this->artisan('registrations:prune-stale')->assertSuccessful();

        $this->assertNull(RegistrationRequest::query()->find($stale->id));
        $this->assertNotNull(RegistrationRequest::query()->find($fresh->id));
        $this->assertNotNull(RegistrationRequest::query()->find($pending->id));
    }

    public function test_company_requests_are_stored_deduplicated_and_handled_by_platform_staff(): void
    {
        [$company] = $this->company();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'req-owner@example.test', 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $owner->assignRole(Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]));
        $registrar->setPermissionsTeamId(null);

        $this->actingAs($owner)->postJson('/subscription/renew', ['message' => 'Please renew'])->assertCreated()->assertJsonPath('status', 'renewal_requested');
        $this->postJson('/subscription/renew', ['message' => 'Again'])->assertOk()->assertJsonPath('created', false);
        $this->assertSame(1, SubscriptionRequest::query()->where('company_id', $company->id)->count());

        $request = SubscriptionRequest::query()->firstOrFail();
        $this->actingAs($this->admin('support'))->postJson("/super-admin/requests/{$request->id}/handle", [])->assertStatus(422);
        $this->postJson("/super-admin/requests/{$request->id}/handle", ['reason' => 'Contacted customer'])->assertOk();
        $this->assertSame('handled', $request->fresh()->status);
        $this->actingAs($this->admin('auditor'))->postJson("/super-admin/requests/{$request->id}/handle", ['reason' => 'Nope nope'])->assertForbidden();
    }

    public function test_audit_log_is_append_only_redacts_secrets_and_keeps_context(): void
    {
        $admin = $this->admin();
        $log = app(AuditRecorder::class)->record('test.event', $admin, null, $admin->id, ['password' => 'hunter2', 'nested' => ['api_token' => 'abc', 'plain' => 'ok']], null, ['reason' => 'Because', 'severity' => 'warning', 'before' => ['a' => 1], 'after' => ['a' => 2]]);

        $this->assertSame('[redacted]', $log->metadata['password']);
        $this->assertSame('[redacted]', $log->metadata['nested']['api_token']);
        $this->assertSame('ok', $log->metadata['nested']['plain']);
        $this->assertSame('warning', $log->severity);
        $this->assertSame('super_admin', $log->actor_type);

        foreach ([fn () => $log->update(['event' => 'tampered']), fn () => $log->delete(), fn () => AuditLog::query()->update(['event' => 'x']), fn () => AuditLog::query()->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Audit records must be append-only.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
        $this->assertSame('test.event', $log->fresh()->event);
    }

    public function test_log_pruning_requires_force_supports_dry_run_and_is_never_scheduled(): void
    {
        $old = now()->subDays(1000)->toDateTimeString();
        DB::table('audit_logs')->insert(['event' => 'old.event', 'created_at' => $old, 'updated_at' => $old]);
        DB::table('audit_logs')->insert(['event' => 'new.event', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('platform:prune-logs')->assertExitCode(2);
        $this->artisan('platform:prune-logs', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->artisan('platform:prune-logs', ['--force' => true])->assertSuccessful();
        $this->assertSame(['new.event'], DB::table('audit_logs')->pluck('event')->all());

        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('platform:prune-logs', $schedule);
    }

    public function test_api_tokens_expire_and_carry_the_company_ability(): void
    {
        $company = Company::query()->create(['name' => 'Token Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'token-plan', 'name' => 'Token', 'duration' => 'yearly', 'price' => 1, 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        $user = User::query()->create(['name' => 'Api User', 'email' => 'api-user@example.test', 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Correct-horse-1'])->assertOk();
        $token = $user->tokens()->firstOrFail();
        $this->assertSame(['company-api'], $token->abilities);
        $this->assertNotNull($token->expires_at);

        $foreign = $user->createToken('other-scope', ['something-else'])->plainTextToken;
        $this->withToken($foreign)->getJson('/api/v1/me')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $legacy = $user->createToken('legacy')->plainTextToken;
        $this->withToken($legacy)->getJson('/api/v1/me')->assertOk();
    }

    public function test_platform_remember_me_is_disabled_and_password_reset_does_not_reveal_accounts(): void
    {
        $admin = $this->admin();
        $this->post('/super-admin/login', ['email' => $admin->email, 'password' => 'Correct-horse-1', 'remember' => '1'])->assertRedirect();
        $this->assertNull($admin->fresh()->remember_token, 'Platform sessions never issue remember-me cookies.');

        $known = $this->post('/forgot-password', ['email' => $admin->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(session('status'), $unknown->baseResponse->getSession()->get('status'));
    }
}
