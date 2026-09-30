<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionVersion;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\LocalLicense;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CouponRedemptionService;
use App\Services\LocalLicenseService;
use App\Services\SessionRevocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SecurityPlatformHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_uses_generic_errors_and_locks_the_account_after_repeated_failures(): void
    {
        config([
            'security.auth.login_ip_rate_limit' => 100,
            'security.auth.login_account_rate_limit' => 100,
            'security.auth.lockout_attempts' => 2,
            'security.auth.lockout_base_seconds' => 60,
        ]);
        RateLimiter::clear('login-ip:127.0.0.1');
        $company = Company::query()->create(['name' => 'Secure Co', 'status' => 'active']);
        $user = User::query()->create([
            'name' => 'Secure Owner',
            'email' => 'secure-owner@example.test',
            'password' => 'correct-password',
            'company_id' => $company->id,
            'account_type' => 'company',
            'status' => 'active',
        ]);

        $first = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $second = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $unknown = $this->postJson('/api/v1/login', ['email' => 'unknown@example.test', 'password' => 'wrong-password']);

        $first->assertUnauthorized()->assertExactJson(['message' => 'The supplied sign-in details are invalid.']);
        $second->assertUnauthorized()->assertExactJson(['message' => 'The supplied sign-in details are invalid.']);
        $unknown->assertUnauthorized()->assertExactJson(['message' => 'The supplied sign-in details are invalid.']);
        $this->assertSame(2, $user->fresh()->failed_login_attempts);
        $this->assertTrue($user->fresh()->login_locked_until->isFuture());
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-password'])->assertUnauthorized();
    }

    public function test_api_login_named_limiter_returns_429(): void
    {
        config([
            'security.auth.login_ip_rate_limit' => 2,
            'security.auth.login_account_rate_limit' => 2,
        ]);
        $email = 'limited-'.str()->random(12).'@example.test';

        $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'wrong-password'])->assertUnauthorized();
        $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'wrong-password'])->assertUnauthorized();
        $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'wrong-password'])->assertTooManyRequests();
    }

    public function test_revoking_sessions_invalidates_an_older_browser_session_version(): void
    {
        $company = Company::query()->create(['name' => 'Session Co', 'status' => 'active']);
        $user = User::query()->create([
            'name' => 'Session Owner',
            'email' => 'session-owner@example.test',
            'password' => 'correct-password',
            'company_id' => $company->id,
            'account_type' => 'company',
            'status' => 'active',
        ]);
        Auth::login($user);
        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('session_version', 0);
        $request->setUserResolver(fn () => $user->fresh());
        app(SessionRevocationService::class)->revoke($user);

        $response = app(EnsureSessionVersion::class)->handle($request, fn () => response('ok'));

        $this->assertTrue($response->isRedirect(route('login')));
        $this->assertFalse(Auth::check());
    }

    public function test_coupon_redemption_is_transactional_idempotent_and_honours_the_global_limit(): void
    {
        $plan = Plan::query()->create([
            'code' => 'coupon-plan', 'name' => 'Coupon Plan', 'duration' => 'monthly',
            'price' => 100, 'is_active' => true,
        ]);
        $company = Company::query()->create(['name' => 'Coupon Co', 'status' => 'active']);
        $subscription = Subscription::query()->create([
            'company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);
        $coupon = Coupon::query()->create([
            'code' => 'ONEUSE', 'discount_type' => 'percent', 'discount_value' => 25,
            'max_redemptions' => 1, 'redemptions_count' => 0, 'is_active' => true,
        ]);
        $service = app(CouponRedemptionService::class);

        $first = \DB::transaction(fn () => $service->redeem($coupon, $plan, $company, $subscription));
        $second = \DB::transaction(fn () => $service->redeem($coupon, $plan, $company, $subscription));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $coupon->fresh()->redemptions_count);
        $this->assertDatabaseCount('coupon_redemptions', 1);
        $this->assertSame('25.00', $first->discount_amount);
        $this->assertSame('75.00', $first->payable_amount);

        $otherCompany = Company::query()->create(['name' => 'Other Coupon Co', 'status' => 'active']);
        $otherSubscription = Subscription::query()->create([
            'company_id' => $otherCompany->id, 'plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);

        try {
            \DB::transaction(fn () => $service->redeem($coupon, $plan, $otherCompany, $otherSubscription));
            $this->fail('The coupon must not exceed its global redemption limit.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_local_license_lifecycle_is_bound_and_audited(): void
    {
        $company = Company::query()->create(['name' => 'Licensed Co', 'status' => 'active']);
        $license = LocalLicense::query()->create([
            'key_hash' => hash('sha256', 'license-key'),
            'installation_id' => 'install-one',
            'customer_binding' => 'customer-one',
            'company_id' => $company->id,
            'status' => 'enabled',
            'activated_at' => now(),
            'enabled_at' => now(),
            'signed_payload' => ['company_id' => $company->id, 'customer_binding' => 'customer-one'],
        ]);
        $service = app(LocalLicenseService::class);

        $service->disable($license);
        $this->assertSame('disabled', $license->fresh()->status);
        $service->enable($license);
        $transferred = $service->transfer($license, 'install-two', 'customer-two', $company->id);
        $this->assertSame('install-two', $transferred->installation_id);
        $this->assertSame('customer-two', $transferred->customer_binding);
        $this->assertNotNull($transferred->transfer_reference);
        $service->revoke($license);
        $this->assertSame('revoked', $license->fresh()->status);
        $this->assertDatabaseCount('local_license_audits', 4);

        try {
            $service->enable($license);
            $this->fail('A revoked license must not be enabled again.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_expired_company_archiving_command_is_idempotent(): void
    {
        $company = Company::query()->create(['name' => 'Expired Co', 'status' => 'active']);
        $plan = Plan::query()->create([
            'code' => 'expired-plan', 'name' => 'Expired Plan', 'duration' => 'monthly',
            'price' => 10, 'is_active' => true,
        ]);
        $subscription = Subscription::query()->create([
            'company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now()->subYear(), 'ends_at' => now()->subMonths(7),
        ]);

        $this->artisan('companies:archive-expired')->assertSuccessful();
        $this->artisan('companies:archive-expired')->assertSuccessful();

        $this->assertSame('archived', $company->fresh()->status);
        $this->assertNotNull($company->fresh()->archived_at);
        $this->assertSame('archived', $subscription->fresh()->status);
        $this->assertSame(1, \DB::table('audit_logs')->where('event', 'company.auto_archived_expired')->where('company_id', $company->id)->count());
    }
}
