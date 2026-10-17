<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionExpiryWarning;
use App\Services\SubscriptionLifecycle;
use App\Services\SubscriptionNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SubscriptionNoticeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-17 10:00:00', 'UTC');
    }

    /** @return array{0: Company, 1: Subscription} */
    private function subscribe(string $duration, ?CarbonImmutable $endsAt, array $company = [], array $overrides = [], string $status = 'active', ?int $durationDays = null): array
    {
        $this->seq++;
        $company = Company::query()->create($company + ['name' => "Notice Co {$this->seq}", 'status' => 'active']);
        $plan = Plan::query()->create(['code' => "notice-{$this->seq}", 'name' => "Plan {$this->seq}", 'duration' => $duration, 'duration_days' => $durationDays ?? ($duration === 'trial' ? 10 : null), 'price' => 10, 'limits' => [], 'is_active' => true]);
        $subscription = Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => $status, 'starts_at' => $this->now()->subDays(30), 'ends_at' => $endsAt, 'overrides' => $overrides ?: null]);

        return [$company, $subscription->load('plan')];
    }

    private function notice(Company $company, Subscription $subscription, ?CarbonImmutable $now = null): array
    {
        return app(SubscriptionNotice::class)->for($company, $subscription->fresh('plan'), null, $now ?? $this->now());
    }

    private function companyOwner(Company $company): User
    {
        $this->seq++;
        $user = User::query()->create(['name' => "Owner {$this->seq}", 'email' => "owner-notice-{$this->seq}@example.test", 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        Permission::findOrCreate('subscriptions.view', 'web');
        $user->assignRole(Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]));
        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    public function test_a_ten_day_trial_reports_ten_days_immediately_after_activation(): void
    {
        $this->seq++;
        $plan = Plan::query()->create(['code' => 'trial-10', 'name' => 'Trial', 'duration' => 'trial', 'duration_days' => 10, 'price' => 0, 'is_active' => true]);
        $ends = app(SubscriptionLifecycle::class)->expiryFor($plan, $this->now());
        [$company, $subscription] = $this->subscribe('trial', $ends);

        $notice = $this->notice($company, $subscription);

        $this->assertSame('trial', $notice['category']);
        $this->assertSame('trial.ends', $notice['key']);
        $this->assertSame(['days' => 10], $notice['params']);
        $this->assertSame(10, $notice['days_remaining']);
        $this->assertTrue($notice['show']);
        $this->assertSame('normal', $notice['access'], 'Ten days left is informational, not a warning.');
        $this->assertSame('2026-10-27', $notice['ends_on']);
        $this->assertSame('Africa/Cairo', $notice['timezone']);
    }

    public function test_trials_report_their_real_remaining_days_not_a_fixed_threshold(): void
    {
        foreach ([7, 3, 1] as $days) {
            [$company, $subscription] = $this->subscribe('trial', $this->now()->addDays($days));
            $notice = $this->notice($company, $subscription);

            $this->assertSame('trial.ends', $notice['key']);
            $this->assertSame(['days' => $days], $notice['params'], "{$days} days remaining");
            $this->assertSame($days, $notice['days_remaining']);
            $this->assertSame('warning', $notice['access']);
        }
    }

    public function test_less_than_a_day_never_reports_zero_days(): void
    {
        [$company, $subscription] = $this->subscribe('trial', $this->now()->addSeconds(86399));
        $notice = $this->notice($company, $subscription);
        $this->assertSame('trial.today', $notice['key']);
        $this->assertSame([], $notice['params']);
        $this->assertSame(0, $notice['days_remaining']);

        [$company, $subscription] = $this->subscribe('trial', $this->now()->addSeconds(86400));
        $this->assertSame(['days' => 1], $this->notice($company, $subscription)['params'], 'Exactly 24 hours is one day.');
    }

    public function test_test_and_paid_periods_use_their_own_wording_keys(): void
    {
        [$company, $subscription] = $this->subscribe('monthly', $this->now()->addDays(5), overrides: ['period_type' => 'test']);
        $this->assertSame('test', $this->notice($company, $subscription)['category']);
        $this->assertSame('test.ends', $this->notice($company, $subscription)['key']);

        [$company, $subscription] = $this->subscribe('monthly', $this->now()->addDays(5));
        $this->assertSame('paid', $this->notice($company, $subscription)['category']);
        $this->assertSame('paid.ends', $this->notice($company, $subscription)['key']);
    }

    public function test_paid_subscriptions_are_announced_only_inside_the_configured_window(): void
    {
        config(['subscription.notice_days' => 7]);
        [$company, $subscription] = $this->subscribe('yearly', $this->now()->addDays(20));
        $far = $this->notice($company, $subscription);
        $this->assertFalse($far['show']);
        $this->assertSame('normal', $far['access']);

        [$company, $subscription] = $this->subscribe('yearly', $this->now()->addDays(8));
        $this->assertFalse($this->notice($company, $subscription)['show']);
        [$company, $subscription] = $this->subscribe('yearly', $this->now()->addDays(7));
        $edge = $this->notice($company, $subscription);
        $this->assertTrue($edge['show']);
        $this->assertSame(['days' => 7], $edge['params']);

        config(['subscription.notice_days' => 14]);
        [$company, $subscription] = $this->subscribe('yearly', $this->now()->addDays(12));
        $this->assertTrue($this->notice($company, $subscription)['show'], 'The window comes from configuration, not a constant.');
    }

    public function test_grace_and_read_only_states_describe_the_actual_state(): void
    {
        [$company, $subscription] = $this->subscribe('monthly', $this->now()->subDays(2));
        $grace = $this->notice($company, $subscription);
        $this->assertSame('grace', $grace['state']);
        $this->assertSame('paid.grace', $grace['key']);
        $this->assertSame(['days' => 2], $grace['params'], 'The grace phase ends on day 4 after expiry, so two days remain.');
        $this->assertSame('warning', $grace['access']);

        [$company, $subscription] = $this->subscribe('trial', $this->now()->subDays(2));
        $this->assertSame('trial.grace', $this->notice($company, $subscription)['key']);

        [$company, $subscription] = $this->subscribe('monthly', $this->now()->subDays(10));
        $readOnly = $this->notice($company, $subscription);
        $this->assertSame('read_only', $readOnly['state']);
        $this->assertSame('paid.read_only', $readOnly['key']);
        $this->assertSame('read_only', $readOnly['access']);
        $this->assertSame([], $readOnly['params']);
    }

    public function test_expired_subscriptions_say_expired_and_never_ends_in(): void
    {
        [$company, $subscription] = $this->subscribe('monthly', $this->now()->subDays(40));
        $expired = $this->notice($company, $subscription);
        $this->assertSame('suspended', $expired['state']);
        $this->assertSame('paid.expired', $expired['key']);
        $this->assertSame(['days' => 40], $expired['params']);
        $this->assertSame('blocked', $expired['access']);

        [$company, $subscription] = $this->subscribe('trial', $this->now()->subMonths(8));
        $archived = $this->notice($company, $subscription);
        $this->assertSame('archived', $archived['state']);
        $this->assertSame('trial.expired', $archived['key']);
        $this->assertStringNotContainsString('ends', $archived['key']);
    }

    public function test_administrative_suspension_is_not_described_as_expiry(): void
    {
        [$company, $subscription] = $this->subscribe('yearly', $this->now()->addDays(200), ['status' => 'suspended']);
        $notice = $this->notice($company, $subscription);
        $this->assertSame('suspended', $notice['state']);
        $this->assertSame('suspended_admin', $notice['key']);
        $this->assertSame('blocked', $notice['access']);

        [$company, $subscription] = $this->subscribe('yearly', $this->now()->addDays(200), ['status' => 'archived']);
        $this->assertSame('archived_admin', $this->notice($company, $subscription)['key']);
    }

    public function test_lifetime_subscriptions_never_show_an_expiry_warning(): void
    {
        [$company, $subscription] = $this->subscribe('lifetime', null);
        $notice = $this->notice($company, $subscription);

        $this->assertSame('lifetime', $notice['category']);
        $this->assertFalse($notice['show']);
        $this->assertNull($notice['key']);
        $this->assertNull($notice['days_remaining']);
        $this->assertSame('normal', $notice['access']);
    }

    public function test_calendar_days_follow_the_company_timezone(): void
    {
        $now = CarbonImmutable::parse('2026-10-17 23:00:00', 'UTC');
        $ends = CarbonImmutable::parse('2026-10-19 00:00:00', 'UTC');

        [$company, $subscription] = $this->subscribe('trial', $ends, ['settings' => ['timezone' => 'UTC']]);
        $utc = $this->notice($company, $subscription, $now);
        [$company, $subscription] = $this->subscribe('trial', $ends, ['settings' => ['timezone' => 'Pacific/Pago_Pago']]);
        $samoa = $this->notice($company, $subscription, $now);
        [$company, $subscription] = $this->subscribe('trial', $ends, ['settings' => ['timezone' => 'Not/AZone']]);
        $invalid = $this->notice($company, $subscription, $now);

        $this->assertSame(2, $utc['days_remaining']);
        $this->assertSame('2026-10-19', $utc['ends_on']);
        $this->assertSame(1, $samoa['days_remaining']);
        $this->assertSame('2026-10-18', $samoa['ends_on']);
        $this->assertSame('Pacific/Pago_Pago', $samoa['timezone']);
        $this->assertSame('Africa/Cairo', $invalid['timezone'], 'An unknown timezone falls back to the configured default.');
    }

    public function test_midnight_boundaries_do_not_produce_off_by_one_results(): void
    {
        // Asia/Riyadh is a fixed UTC+3 (no DST, independent of the host tzdata), so 21:00 UTC is local midnight.
        $localMidnight = CarbonImmutable::parse('2026-10-17 21:00:00', 'UTC');
        foreach ([$localMidnight->subSecond(), $localMidnight, $localMidnight->addSecond()] as $created) {
            [$company, $subscription] = $this->subscribe('trial', $created->addDays(10), ['settings' => ['timezone' => 'Asia/Riyadh']]);
            $this->assertSame(10, $this->notice($company, $subscription, $created)['days_remaining'], 'A ten-day period created at '.$created->toTimeString().' shows ten days.');
        }

        [$company, $subscription] = $this->subscribe('trial', $localMidnight->addDays(3), ['settings' => ['timezone' => 'Asia/Riyadh']]);
        $this->assertSame(3, $this->notice($company, $subscription, $localMidnight)['days_remaining']);
        $this->assertSame(4, $this->notice($company, $subscription, $localMidnight->subSecond())['days_remaining'], 'The previous calendar day counts one more date.');
    }

    public function test_daylight_saving_changes_do_not_shift_the_day_count(): void
    {
        [$company, $subscription] = $this->subscribe('trial', CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC'), ['settings' => ['timezone' => 'America/New_York']]);

        $notice = $this->notice($company, $subscription, CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));

        $this->assertSame(4, $notice['days_remaining'], 'Clocks go back on 2026-11-01; calendar dates still differ by four.');
        $this->assertSame('2026-11-03', $notice['ends_on']);
    }

    public function test_missing_or_malformed_dates_use_a_safe_fallback(): void
    {
        [$company, $missing] = $this->subscribe('monthly', null);
        $notice = $this->notice($company, $missing);
        $this->assertFalse($notice['show']);
        $this->assertNull($notice['days_remaining']);

        [$company, $broken] = $this->subscribe('monthly', $this->now()->addDays(3));
        DB::table('subscriptions')->where('id', $broken->id)->update(['ends_at' => 'not-a-date']);
        $notice = app(SubscriptionNotice::class)->for($company, Subscription::query()->with('plan')->findOrFail($broken->id), null, $this->now());
        $this->assertSame('unavailable', $notice['key']);
        $this->assertSame([], $notice['params']);

        $owner = $this->companyOwner($company);
        $this->actingAs($owner)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('notice.key', 'unavailable')->assertJsonPath('status', 'suspended');
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_a_company_never_receives_another_companys_notice(): void
    {
        [$companyA] = $this->subscribe('trial', $this->now()->addDays(3));
        [, $subscriptionB] = $this->subscribe('trial', $this->now()->addDays(9));

        $notice = app(SubscriptionNotice::class)->for($companyA, $subscriptionB, null, $this->now());
        $this->assertFalse($notice['show']);
        $this->assertSame('none', $notice['state']);
        $this->assertNull($notice['ends_at']);
        $this->assertNull($notice['category']);
    }

    public function test_the_api_and_the_shared_page_notice_match_the_access_middleware(): void
    {
        $this->travelTo($this->now());
        $cases = [
            'active' => $this->now()->addDays(3),
            'grace' => $this->now()->subDays(2),
            'read_only' => $this->now()->subDays(10),
            'suspended' => $this->now()->subDays(40),
        ];
        foreach ($cases as $expected => $ends) {
            [$company] = $this->subscribe('trial', $ends);
            $owner = $this->companyOwner($company);
            $this->app['auth']->forgetGuards();

            $response = $this->actingAs($owner)->getJson('/api/v1/subscription')->assertOk();
            $this->assertSame($expected, $response->json('status'));
            $this->assertSame($expected, $response->json('notice.state'), 'The notice must describe the state the middleware enforces.');
            $this->assertSame($expected === 'active' ? 'warning' : ($expected === 'suspended' ? 'blocked' : ($expected === 'grace' ? 'warning' : 'read_only')), $response->json('notice.access'));

            $this->get('/subscription')->assertOk()->assertInertia(fn ($page) => $page
                ->component('Company/Subscription')
                ->where('subscriptionStatus', $expected)
                ->where('subscriptionNotice.state', $expected)
                ->missing('subscriptionWarning'));
        }
    }

    public function test_the_notice_never_leaks_across_tenants_over_http(): void
    {
        $this->travelTo($this->now());
        [$companyA] = $this->subscribe('trial', $this->now()->addDays(3));
        [$companyB] = $this->subscribe('trial', $this->now()->addDays(9));
        $ownerA = $this->companyOwner($companyA);
        $this->companyOwner($companyB);

        $this->actingAs($ownerA)->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('notice.params.days', 3)->assertJsonPath('notice.ends_on', '2026-10-20');
    }

    public function test_the_expiry_email_threshold_is_separate_from_the_banner_threshold(): void
    {
        Notification::fake();
        $this->travelTo($this->now());
        config(['subscription.notice_days' => 7]);
        [$longTrial] = $this->subscribe('trial', $this->now()->addDays(10));
        [$shortTrial] = $this->subscribe('trial', $this->now()->addDays(5));
        [$longPaid] = $this->subscribe('monthly', $this->now()->addDays(20));
        [$lifetime] = $this->subscribe('lifetime', null);
        $owners = [];
        foreach ([$longTrial, $shortTrial, $longPaid, $lifetime] as $company) {
            $owners[$company->id] = $this->companyOwner($company);
        }

        $this->artisan('subscriptions:send-expiry-warnings')->assertSuccessful();

        Notification::assertSentTo($owners[$shortTrial->id], SubscriptionExpiryWarning::class);
        Notification::assertNotSentTo($owners[$longTrial->id], SubscriptionExpiryWarning::class);
        Notification::assertNotSentTo($owners[$longPaid->id], SubscriptionExpiryWarning::class);
        Notification::assertNotSentTo($owners[$lifetime->id], SubscriptionExpiryWarning::class);
        $this->assertTrue($this->notice($longTrial, Subscription::query()->where('company_id', $longTrial->id)->firstOrFail())['show'], 'The banner still announces a ten-day trial that is too far for an email.');
    }

    public function test_expiry_emails_state_the_real_remaining_time_in_both_languages(): void
    {
        $this->travelTo($this->now());
        [$company, $subscription] = $this->subscribe('trial', $this->now()->addDays(3));
        $owner = $this->companyOwner($company);

        app()->setLocale('en');
        $english = (new SubscriptionExpiryWarning($subscription))->toMail($owner);
        $this->assertStringContainsString('trial period ends in 3 days', implode(' ', $english->introLines));
        $this->assertStringContainsString('2026-10-20', implode(' ', $english->introLines));

        app()->setLocale('ar');
        $arabic = (new SubscriptionExpiryWarning($subscription))->toMail($owner);
        $this->assertStringContainsString('الفترة التجريبية', implode(' ', $arabic->introLines));
        $this->assertStringContainsString('خلال 3 أيام', implode(' ', $arabic->introLines));
        $this->assertStringNotContainsString('seven', strtolower(implode(' ', $english->introLines)));

        [$paidCompany, $paid] = $this->subscribe('monthly', $this->now()->addDays(1)->addHour());
        app()->setLocale('en');
        $this->assertStringContainsString('subscription ends in 1 day', implode(' ', (new SubscriptionExpiryWarning($paid))->toMail($owner)->introLines));
        app()->setLocale('ar');
        $this->assertStringContainsString('خلال يوم واحد', implode(' ', (new SubscriptionExpiryWarning($paid))->toMail($owner)->introLines));
        $this->assertStringContainsString('خلال يومين', implode(' ', (new SubscriptionExpiryWarning($this->subscribe('monthly', $this->now()->addDays(2)->addHour())[1]))->toMail($owner)->introLines));
        $this->assertStringContainsString('خلال 11 يومًا', trans_choice('subscription.mail.ends.paid', 1, ['days' => trans_choice('subscription.days', 11), 'date' => 'x']) ?: '');
    }

    public function test_no_user_facing_text_hard_codes_a_seven_day_subscription_message(): void
    {
        $pattern = '/(?:seven|سبعة|\b7)\s*(?:-\s*)?(?:days|day|أيام)|within\s+(?:seven|7)|expires within/iu';
        $allowed = ['resources/js/i18n-platform.js' => 'Platform dashboards use a documented 7-day filter, not a company notice.'];
        $roots = ['app/Notifications', 'lang', 'resources/js', 'resources/views'];
        $violations = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|jsx?|blade\.php)$/', $file->getFilename())) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (isset($allowed[$relative]) || str_contains($relative, 'Pages/SuperAdmin/') || str_contains($relative, 'Platform/')) {
                    continue;
                }
                if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $match)) {
                    $violations[] = "{$relative}: {$match[0]}";
                }
            }
        }

        $this->assertSame([], $violations, 'A fixed seven-day message must not return outside the documented platform filters.');
    }
}
