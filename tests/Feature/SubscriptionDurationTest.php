<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionDuration;
use App\Models\User;
use App\Services\SubscriptionLifecycle;
use App\Services\SubscriptionNotice;
use App\Support\SubscriptionDurationBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SubscriptionDurationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(?string $role = 'owner'): User
    {
        $this->seq++;

        return User::query()->create(['name' => "Admin {$this->seq}", 'email' => "dur-admin-{$this->seq}@example.test", 'password' => 'Correct-horse-1', 'account_type' => 'super_admin', 'platform_role' => $role, 'status' => 'active']);
    }

    private function signIn(?string $role = 'owner'): User
    {
        $admin = $this->admin($role);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin)->withSession(['platform_stepup_at' => time()]);

        return $admin;
    }

    private function payload(array $over = []): array
    {
        return $over + ['name_ar' => 'ثلاثة أشهر', 'name_en' => 'Three months', 'category' => 'paid', 'unit' => 'month', 'value' => 3];
    }

    private function create(array $over = []): SubscriptionDuration
    {
        $id = $this->postJson('/super-admin/durations', $this->payload($over))->assertCreated()->json('id');

        return SubscriptionDuration::query()->findOrFail($id);
    }

    private function plan(SubscriptionDuration $duration, array $over = []): Plan
    {
        $this->seq++;

        return Plan::query()->create($over + ['code' => "dur-plan-{$this->seq}", 'name' => "Plan {$this->seq}", 'duration' => $duration->key, 'subscription_duration_id' => $duration->id, 'price' => 50, 'is_active' => true]);
    }

    private function subscribeCompany(Plan $plan, ?CarbonImmutable $endsAt = null): Subscription
    {
        $this->seq++;
        $company = Company::query()->create(['name' => "Dur Co {$this->seq}", 'status' => 'active']);

        return Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => $endsAt ?? '2026-12-01 00:00:00']);
    }

    private function subscriptionState(): array
    {
        return Subscription::query()->orderBy('id')->get(['id', 'plan_id', 'status', 'starts_at', 'ends_at', 'updated_at'])->map(fn ($row) => [$row->id, $row->plan_id, $row->status, (string) $row->starts_at, (string) $row->ends_at, (string) $row->updated_at])->all();
    }

    public function test_the_default_durations_are_provisioned_with_stable_keys_and_bilingual_labels(): void
    {
        $rows = SubscriptionDuration::query()->orderBy('sort_order')->get()->keyBy('key');

        $this->assertSame(['monthly', 'six_months', 'yearly', 'lifetime'], $rows->keys()->all());
        $this->assertSame(['شهري', 'Monthly', 'month', 1], [$rows['monthly']->name_ar, $rows['monthly']->name_en, $rows['monthly']->unit, $rows['monthly']->value]);
        $this->assertSame(['ستة أشهر', 'Six months', 'month', 6], [$rows['six_months']->name_ar, $rows['six_months']->name_en, $rows['six_months']->unit, $rows['six_months']->value]);
        $this->assertSame(['سنوي', 'Yearly', 'year', 1], [$rows['yearly']->name_ar, $rows['yearly']->name_en, $rows['yearly']->unit, $rows['yearly']->value]);
        $this->assertSame(['مدى الحياة', 'lifetime', null], [$rows['lifetime']->name_ar, $rows['lifetime']->unit, $rows['lifetime']->value]);
        $this->assertTrue($rows->every(fn ($row) => $row->is_system && $row->is_active && $row->category === 'paid'));
    }

    public function test_an_authorized_administrator_creates_a_three_month_and_a_test_duration(): void
    {
        $admin = $this->signIn();

        $three = $this->create();
        $test = $this->create(['name_ar' => 'فترة اختبار', 'name_en' => 'Evaluation', 'category' => 'test', 'unit' => 'day', 'value' => 14]);

        $this->assertSame(['paid_3m', 'paid', 'month', 3, false, true], [$three->key, $three->category, $three->unit, $three->value, $three->is_system, $three->is_active]);
        $this->assertSame(['test_14d', 'test', 'day', 14], [$test->key, $test->category, $test->unit, $test->value]);
        $audit = AuditLog::query()->where('event', 'platform.duration_created')->where('subject_id', (string) $three->id)->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(3, $audit->after['value']);
    }

    public function test_invalid_values_units_and_categories_are_rejected(): void
    {
        $this->signIn();
        $cases = [
            'zero' => [['value' => 0], 'value'], 'negative' => [['value' => -3], 'value'], 'fractional' => [['value' => 1.5], 'value'], 'malformed' => [['value' => 'abc'], 'value'],
            'too large' => [['value' => 121], 'value'], 'missing' => [['value' => null], 'value'], 'bad unit' => [['unit' => 'decade'], 'unit'], 'bad category' => [['category' => 'gold'], 'category'],
            'lifetime value' => [['unit' => 'lifetime', 'value' => 5], 'value'], 'trial year' => [['category' => 'trial', 'unit' => 'year', 'value' => 1], 'unit'],
            'test lifetime' => [['category' => 'test', 'unit' => 'lifetime', 'value' => null], 'unit'], 'bad key' => [['key' => 'Bad Key!'], 'key'],
        ];
        foreach ($cases as $label => [$over, $field]) {
            $this->postJson('/super-admin/durations', $this->payload($over))->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->assertSame(4, SubscriptionDuration::query()->count(), 'Nothing was created by the rejected requests.');
    }

    public function test_both_labels_are_required_and_shown_exactly_as_entered(): void
    {
        $this->signIn();
        $this->postJson('/super-admin/durations', $this->payload(['name_ar' => '']))->assertStatus(422)->assertJsonValidationErrors('name_ar');
        $this->postJson('/super-admin/durations', $this->payload(['name_en' => '']))->assertStatus(422)->assertJsonValidationErrors('name_en');

        $created = $this->create(['name_ar' => 'ربع سنوي', 'name_en' => 'Quarterly']);
        $this->get('/super-admin/durations')->assertOk()->assertInertia(fn ($page) => $page->component('SuperAdmin/Durations')
            ->where('durations', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $created->id && $row['name_ar'] === 'ربع سنوي' && $row['name_en'] === 'Quarterly')));
    }

    public function test_duplicate_intervals_need_distinct_keys_and_labels(): void
    {
        $this->signIn();
        $first = $this->create();
        $variant = $this->create(['name_ar' => 'باقة الربع', 'name_en' => 'Quarter bundle']);
        $this->assertSame('paid_3m_2', $variant->key, 'A second label for the same interval gets its own stable key.');
        $this->assertNotSame($first->key, $variant->key);

        $this->postJson('/super-admin/durations', $this->payload(['name_en' => 'three MONTHS', 'name_ar' => 'اسم آخر']))->assertStatus(422)->assertJsonValidationErrors('name_en');
        $this->postJson('/super-admin/durations', $this->payload(['name_en' => 'Unique en', 'name_ar' => 'ثلاثة أشهر']))->assertStatus(422)->assertJsonValidationErrors('name_en');
        $this->postJson('/super-admin/durations', $this->payload(['name_en' => 'Another', 'name_ar' => 'آخر', 'key' => 'paid_3m']))->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_only_roles_with_plan_management_can_change_the_catalog(): void
    {
        $this->signIn();
        $duration = $this->create();
        $before = SubscriptionDuration::query()->count();

        foreach ([null, 'support', 'auditor'] as $role) {
            $this->signIn($role);
            $this->getJson('/super-admin/durations')->assertForbidden();
            $this->postJson('/super-admin/durations', $this->payload(['name_en' => 'X', 'name_ar' => 'س']))->assertForbidden();
            $this->putJson("/super-admin/durations/{$duration->id}", ['name_ar' => 'س', 'name_en' => 'X', 'version' => 1, 'reason' => 'Not allowed'])->assertForbidden();
            $this->postJson("/super-admin/durations/{$duration->id}/deactivate", ['reason' => 'Not allowed'])->assertForbidden();
        }
        $company = Company::query()->create(['name' => 'Tenant', 'status' => 'active']);
        $tenant = User::query()->create(['name' => 'Tenant', 'email' => 'tenant-dur@example.test', 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($tenant)->postJson('/super-admin/durations', $this->payload())->assertForbidden();

        $this->assertSame($before, SubscriptionDuration::query()->count());
        $this->assertSame(1, $duration->fresh()->version);
        $this->signIn('billing');
        $this->getJson('/super-admin/durations')->assertOk();
    }

    public function test_system_and_referenced_durations_are_protected_but_labels_stay_editable(): void
    {
        $this->signIn();
        $monthly = SubscriptionDuration::query()->where('key', 'monthly')->firstOrFail();

        $this->putJson("/super-admin/durations/{$monthly->id}", ['name_ar' => 'شهري', 'name_en' => 'Monthly', 'version' => 1, 'reason' => 'Rename key', 'key' => 'one_month'])->assertStatus(422)->assertJsonValidationErrors('key');
        $this->putJson("/super-admin/durations/{$monthly->id}", ['name_ar' => 'شهري', 'name_en' => 'Monthly', 'version' => 1, 'reason' => 'Change value', 'unit' => 'month', 'value' => 2])->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->putJson("/super-admin/durations/{$monthly->id}", ['name_ar' => 'شهر واحد', 'name_en' => 'One month', 'version' => 1, 'reason' => 'Better label', 'sort_order' => 5, 'description_en' => 'Billed monthly'])->assertOk();
        $this->assertSame(['شهر واحد', 'One month', 'month', 1, 2], [$monthly->fresh()->name_ar, $monthly->fresh()->name_en, $monthly->fresh()->unit, $monthly->fresh()->value, $monthly->fresh()->version]);

        $custom = $this->create();
        $this->putJson("/super-admin/durations/{$custom->id}", ['name_ar' => $custom->name_ar, 'name_en' => $custom->name_en, 'version' => 1, 'reason' => 'Fix value', 'unit' => 'month', 'value' => 4])->assertOk();
        $this->assertSame(4, $custom->fresh()->value, 'An unreferenced custom duration may still change its interval.');
        $this->plan($custom->fresh());
        $this->putJson("/super-admin/durations/{$custom->id}", ['name_ar' => $custom->name_ar, 'name_en' => $custom->name_en, 'version' => 2, 'reason' => 'Too late', 'unit' => 'month', 'value' => 5])->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->assertSame(4, $custom->fresh()->value);
    }

    public function test_updates_need_step_up_a_reason_and_a_current_version_and_are_audited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $duration = $this->create();
        $body = ['name_ar' => 'ربع', 'name_en' => 'Quarter', 'version' => 1, 'reason' => 'Clearer label'];

        $this->putJson("/super-admin/durations/{$duration->id}", $body)->assertStatus(423)->assertJsonPath('code', 'step_up_required');
        $this->withSession(['platform_stepup_at' => time()]);
        $this->putJson("/super-admin/durations/{$duration->id}", ['reason' => ''] + $body)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson("/super-admin/durations/{$duration->id}", $body)->assertOk();
        $this->putJson("/super-admin/durations/{$duration->id}", $body)->assertStatus(409);

        $audit = AuditLog::query()->where('event', 'platform.duration_updated')->firstOrFail();
        $this->assertSame([$admin->id, 'Clearer label', 'Three months', 'Quarter'], [$audit->user_id, $audit->reason, $audit->before['name_en'], $audit->after['name_en']]);
    }

    public function test_protected_fields_cannot_be_mass_assigned(): void
    {
        $this->signIn();
        $created = $this->create(['is_system' => true, 'is_active' => false, 'version' => 99, 'id' => 500]);

        $this->assertFalse($created->is_system);
        $this->assertTrue($created->is_active);
        $this->assertSame(1, $created->version);
        $this->assertNotSame(500, $created->id);
    }

    public function test_deactivating_a_duration_used_by_active_plans_is_explicit_and_never_breaks_existing_records(): void
    {
        $this->signIn();
        $duration = $this->create();
        $plan = $this->plan($duration);
        $subscription = $this->subscribeCompany($plan);
        $state = $this->subscriptionState();

        $this->postJson("/super-admin/durations/{$duration->id}/deactivate", ['reason' => 'Retire it'])->assertStatus(409)
            ->assertJsonPath('code', 'duration_in_use')->assertJsonPath('affected_plans.0.name', $plan->name);
        $this->assertTrue($duration->fresh()->is_active);

        $this->postJson("/super-admin/durations/{$duration->id}/deactivate", ['reason' => 'Retire it', 'confirm_affected' => true])->assertOk();
        $this->assertFalse($duration->fresh()->is_active);
        $this->assertSame($state, $this->subscriptionState());
        $fresh = Plan::query()->findOrFail($plan->id);
        $this->assertSame($duration->id, $fresh->subscription_duration_id);
        $this->assertSame('2026-12-01', app(SubscriptionLifecycle::class)->expiryFor($fresh, CarbonImmutable::parse('2026-09-01'))->toDateString(), 'An archived duration still defines how its existing plan computes expiry.');
        $this->assertTrue($fresh->durationInfo()['configured']);

        $this->postJson('/super-admin/plans', ['code' => 'new-on-inactive', 'name' => 'New', 'subscription_duration_id' => $duration->id, 'price' => 10])->assertStatus(422)->assertJsonValidationErrors('subscription_duration_id');
        $this->postJson("/super-admin/durations/{$duration->id}/activate", ['reason' => 'Back in use'])->assertOk();
        $this->postJson('/super-admin/plans', ['code' => 'new-on-active', 'name' => 'New', 'subscription_duration_id' => $duration->id, 'price' => 10])->assertOk();
        $this->assertNotNull($subscription->fresh());
    }

    public function test_an_unused_duration_deactivates_without_confirmation_and_is_audited(): void
    {
        $admin = $this->signIn();
        $duration = $this->create();

        $this->postJson("/super-admin/durations/{$duration->id}/deactivate", ['reason' => 'No longer sold'])->assertOk();

        $audit = AuditLog::query()->where('event', 'platform.duration_deactivated')->firstOrFail();
        $this->assertSame([$admin->id, 'No longer sold', true, false], [$audit->user_id, $audit->reason, $audit->before['is_active'], $audit->after['is_active']]);
    }

    public function test_plan_creation_validates_the_catalog_link_and_mirrors_legacy_columns(): void
    {
        $this->signIn();
        $weeks = $this->create(['name_ar' => 'أسبوعان', 'name_en' => 'Two weeks', 'category' => 'test', 'unit' => 'week', 'value' => 2]);

        $this->postJson('/super-admin/plans', ['code' => 'no-duration', 'name' => 'X', 'price' => 1])->assertStatus(422)->assertJsonValidationErrors('subscription_duration_id');
        $this->postJson('/super-admin/plans', ['code' => 'ghost', 'name' => 'X', 'price' => 1, 'subscription_duration_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('subscription_duration_id');
        $this->postJson('/super-admin/plans', ['code' => 'week-plan', 'name' => 'Weeks', 'price' => 1, 'subscription_duration_id' => (string) $weeks->id])->assertOk();
        $this->postJson('/super-admin/plans', ['code' => 'legacy-key', 'name' => 'Legacy', 'price' => 1, 'duration' => 'yearly'])->assertOk();

        $plan = Plan::query()->where('code', 'week-plan')->firstOrFail();
        $this->assertSame([$weeks->id, 'test_2w', 14], [$plan->subscription_duration_id, $plan->duration, $plan->duration_days]);
        $this->assertSame('yearly', Plan::query()->where('code', 'legacy-key')->firstOrFail()->durationCatalog->key);
    }

    public function test_a_plan_duration_can_change_only_while_it_has_no_subscriptions(): void
    {
        $this->signIn();
        $three = $this->create();
        $monthly = SubscriptionDuration::query()->where('key', 'monthly')->firstOrFail();
        $free = $this->plan($monthly);
        $used = $this->plan($monthly);
        $this->subscribeCompany($used);
        $state = $this->subscriptionState();

        $this->putJson("/super-admin/plans/{$free->id}", ['name' => 'Renamed', 'subscription_duration_id' => $three->id, 'reason' => 'Rework'])->assertOk();
        $this->assertSame([$three->id, 'paid_3m', 'Renamed'], [$free->fresh()->subscription_duration_id, $free->fresh()->duration, $free->fresh()->name]);

        $this->putJson("/super-admin/plans/{$used->id}", ['name' => 'Renamed', 'subscription_duration_id' => $three->id, 'reason' => 'Rework'])->assertStatus(409);
        $this->putJson("/super-admin/plans/{$used->id}", ['name' => 'Renamed only', 'reason' => 'Rename'])->assertOk();
        $this->assertSame($monthly->id, $used->fresh()->subscription_duration_id);
        $this->assertSame($state, $this->subscriptionState());
    }

    public function test_legacy_plans_map_to_the_catalog_without_touching_subscriptions(): void
    {
        $now = now();
        $legacy = ['monthly' => ['monthly', null], 'six' => ['six_months', null], 'year' => ['yearly', null], 'life' => ['lifetime', null], 'trial10' => ['trial', 10], 'odd' => ['monthly', 30], 'unknown' => ['weekly', null]];
        $ids = [];
        foreach ($legacy as $code => [$duration, $days]) {
            $ids[$code] = DB::table('plans')->insertGetId(['code' => "legacy-{$code}", 'name' => $code, 'duration' => $duration, 'duration_days' => $days, 'price' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $subscription = $this->subscribeCompany(Plan::query()->findOrFail($ids['monthly']), CarbonImmutable::parse('2026-10-20 08:30:00'));
        $before = $this->subscriptionState();

        $result = SubscriptionDurationBackfill::run();

        $key = fn (string $code): ?string => DB::table('plans')->join('subscription_durations', 'subscription_durations.id', '=', 'plans.subscription_duration_id')->where('plans.id', $ids[$code])->value('key');
        $this->assertSame(['monthly', 'six_months', 'yearly', 'lifetime', 'trial_10d', null, null], array_map($key, array_keys($legacy)));
        $this->assertSame(['plans_linked' => 5, 'plans_left_legacy' => 2], $result);
        $this->assertSame($before, $this->subscriptionState(), 'Backfill never touches a subscription date.');
        $this->assertSame(['plans_linked' => 0, 'plans_left_legacy' => 2], SubscriptionDurationBackfill::run(), 'Backfill is idempotent.');
        $trial = SubscriptionDuration::query()->where('key', 'trial_10d')->firstOrFail();
        $this->assertSame(['trial', 'day', 10, false], [$trial->category, $trial->unit, $trial->value, $trial->is_system]);
        $this->assertNotNull($subscription->fresh());
    }

    public function test_unlinked_legacy_plans_keep_their_original_expiry_rules(): void
    {
        $lifecycle = app(SubscriptionLifecycle::class);
        $start = CarbonImmutable::parse('2026-01-31 10:00:00');
        $legacy = [['monthly', null, '2026-02-28'], ['six_months', null, '2026-07-31'], ['yearly', null, '2027-01-31'], ['trial', 10, '2026-02-10'], ['monthly', 30, '2026-03-02'], ['weekly', null, null], ['lifetime', null, null]];

        foreach ($legacy as [$duration, $days, $expected]) {
            $plan = new Plan(['duration' => $duration, 'duration_days' => $days]);
            $this->assertSame($expected, $lifecycle->expiryFor($plan, $start)?->toDateString(), "{$duration}/{$days}");
        }
        $this->assertTrue($lifecycle->isLifetime(new Plan(['duration' => 'lifetime'])));
    }

    public function test_catalog_durations_compute_calendar_exact_expiry_dates(): void
    {
        $lifecycle = app(SubscriptionLifecycle::class);
        $durationFor = fn (string $unit, ?int $value, string $category = 'paid') => (new SubscriptionDuration)->forceFill(['category' => $category, 'unit' => $unit, 'value' => $value]);
        $cases = [
            ['month', 1, '2026-01-31 10:00:00', '2026-02-28 10:00:00'], ['month', 1, '2028-01-31 10:00:00', '2028-02-29 10:00:00'],
            ['month', 3, '2026-11-30 10:00:00', '2027-02-28 10:00:00'], ['month', 6, '2026-08-31 10:00:00', '2027-02-28 10:00:00'],
            ['year', 1, '2028-02-29 10:00:00', '2029-02-28 10:00:00'], ['day', 10, '2026-10-17 23:59:59', '2026-10-27 23:59:59'], ['week', 2, '2026-10-17 00:00:00', '2026-10-31 00:00:00'],
        ];
        foreach ($cases as [$unit, $value, $start, $expected]) {
            $this->assertSame($expected, $durationFor($unit, $value)->addTo(CarbonImmutable::parse($start, 'UTC'))->toDateTimeString(), "{$value} {$unit} from {$start}");
        }
        $this->assertNull($durationFor('lifetime', null)->addTo(CarbonImmutable::parse('2026-01-01')));
        $span = $durationFor('week', 2)->addTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'))->getTimestamp() - CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC')->getTimestamp();
        $this->assertSame(14 * 86400, $span, 'Weeks are exact multiples of 24 hours, independent of any DST change.');
    }

    public function test_notice_wording_follows_the_duration_category(): void
    {
        $this->signIn();
        $notices = app(SubscriptionNotice::class);
        $now = CarbonImmutable::parse('2026-10-17 10:00:00', 'UTC');
        $wording = [];
        foreach ([['trial', 'month', 1, 'trial.ends'], ['test', 'day', 14, 'test.ends'], ['paid', 'month', 3, 'paid.ends']] as [$category, $unit, $value, $expectedKey]) {
            $duration = $this->create(['name_ar' => "اسم {$category}", 'name_en' => "Name {$category}", 'category' => $category, 'unit' => $unit, 'value' => $value]);
            $subscription = $this->subscribeCompany($this->plan($duration), $now->addDays(5));
            $notice = $notices->for($subscription->company, $subscription->fresh('plan'), null, $now);
            $wording[$category] = [$notice['category'], $notice['key'], $notice['params']['days']];
            $this->assertSame($expectedKey, $notice['key']);
        }
        $this->assertSame(['trial' => ['trial', 'trial.ends', 5], 'test' => ['test', 'test.ends', 5], 'paid' => ['paid', 'paid.ends', 5]], $wording);

        $lifetime = $this->subscribeCompany($this->plan(SubscriptionDuration::query()->where('key', 'lifetime')->firstOrFail()), null);
        $notice = $notices->for($lifetime->company, $lifetime->fresh('plan'), null, $now);
        $this->assertSame(['lifetime', false, null], [$notice['category'], $notice['show'], $notice['key']]);
    }

    public function test_renewal_and_new_companies_use_the_catalog_duration(): void
    {
        $this->signIn();
        $three = $this->create();
        $plan = $this->plan($three, ['price' => 100]);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00:00', 'UTC'));

        $this->postJson('/super-admin/companies', ['name' => 'Quarter Co', 'owner_name' => 'Owner', 'owner_email' => 'quarter@example.test', 'owner_password' => 'Correct-horse-1', 'owner_password_confirmation' => 'Correct-horse-1', 'plan_id' => $plan->id])->assertCreated();
        $company = Company::query()->where('name', 'Quarter Co')->firstOrFail();
        $subscription = $company->subscriptions()->firstOrFail();
        $this->assertSame('2027-01-17', $subscription->ends_at->toDateString());

        $this->postJson("/super-admin/subscriptions/{$subscription->id}/payments", ['amount' => 100, 'status' => 'paid', 'idempotency_key' => 'quarter-renewal-1', 'reason' => 'Renewal'])->assertOk();
        $this->assertSame('2027-04-17', $subscription->fresh()->ends_at->toDateString(), 'Renewal extends from the current end by the plan duration.');
    }

    public function test_editing_or_creating_durations_never_moves_subscription_dates_or_accounting_data(): void
    {
        $this->signIn();
        $duration = $this->create();
        $this->subscribeCompany($this->plan($duration));
        $this->subscribeCompany($this->plan(SubscriptionDuration::query()->where('key', 'yearly')->firstOrFail()), CarbonImmutable::parse('2027-05-05 05:05:05'));
        $state = $this->subscriptionState();
        $journals = JournalEntry::query()->count();
        $payments = DB::table('manual_payments')->count();

        $this->putJson("/super-admin/durations/{$duration->id}", ['name_ar' => 'اسم جديد', 'name_en' => 'New label', 'version' => 1, 'reason' => 'Relabel', 'sort_order' => 1])->assertOk();
        $this->create(['name_ar' => 'جديد', 'name_en' => 'Brand new', 'category' => 'trial', 'unit' => 'day', 'value' => 7]);
        $this->postJson("/super-admin/durations/{$duration->id}/deactivate", ['reason' => 'Archive', 'confirm_affected' => true])->assertOk();

        $this->assertSame($state, $this->subscriptionState());
        $this->assertSame([$journals, $payments], [JournalEntry::query()->count(), DB::table('manual_payments')->count()]);
    }

    public function test_old_api_consumers_keep_the_legacy_field_and_gain_an_additive_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00:00', 'UTC'));
        $monthly = SubscriptionDuration::query()->where('key', 'monthly')->firstOrFail();
        $subscription = $this->subscribeCompany($this->plan($monthly), CarbonImmutable::parse('2026-11-17 10:00:00'));
        $this->seq++;
        $owner = User::query()->create(['name' => 'Owner', 'email' => "dur-owner-{$this->seq}@example.test", 'password' => 'Correct-horse-1', 'company_id' => $subscription->company_id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $subscription->company_id);
        Permission::findOrCreate('subscriptions.view', 'web');
        $owner->assignRole(Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $subscription->company_id]));
        $registrar->setPermissionsTeamId(null);

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('plan.duration', 'monthly')
            ->assertJsonPath('plan.duration_info.key', 'monthly')->assertJsonPath('plan.duration_info.unit', 'month')->assertJsonPath('plan.duration_info.name_ar', 'شهري')
            ->assertJsonMissingPath('plan.duration_info.description_en');
    }

    public function test_the_plans_page_serves_active_durations_from_the_server_only(): void
    {
        $this->signIn();
        $custom = $this->create();
        $inactive = $this->create(['name_ar' => 'قديم', 'name_en' => 'Old one', 'category' => 'trial', 'unit' => 'day', 'value' => 9]);
        $this->postJson("/super-admin/durations/{$inactive->id}/deactivate", ['reason' => 'Retired'])->assertOk();

        $this->get('/super-admin/plans')->assertOk()->assertInertia(fn ($page) => $page->component('SuperAdmin/Plans')
            ->where('durations', fn ($rows) => collect($rows)->pluck('key')->contains($custom->key) && ! collect($rows)->pluck('key')->contains($inactive->key)));
    }
}
