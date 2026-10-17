<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MfaService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PlanChangeFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(?string $role = 'owner'): User
    {
        $this->seq++;

        return User::query()->create([
            'name' => "Admin {$this->seq}", 'email' => "plan-admin-{$this->seq}@example.test", 'password' => 'Correct-horse-1',
            'account_type' => 'super_admin', 'platform_role' => $role, 'status' => 'active',
        ]);
    }

    /** @return array{0: Company, 1: Plan, 2: Subscription} */
    private function lapsedCompany(string $companyStatus = 'active', string $subscriptionStatus = 'active'): array
    {
        $this->seq++;
        $company = Company::query()->create(['name' => "Lapsed {$this->seq}", 'status' => $companyStatus]);
        $plan = Plan::query()->create(['code' => "current-{$this->seq}", 'name' => "Current {$this->seq}", 'duration' => 'monthly', 'price' => 10, 'is_active' => true]);
        $subscription = Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => $subscriptionStatus, 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDays(10)]);

        return [$company, $plan, $subscription];
    }

    private function target(array $attributes = []): Plan
    {
        $this->seq++;

        return Plan::query()->create($attributes + ['code' => "target-{$this->seq}", 'name' => "Target {$this->seq}", 'duration' => 'yearly', 'price' => 99, 'is_active' => true]);
    }

    private function snapshot(): array
    {
        return [
            'subscriptions' => Subscription::query()->count(),
            'events' => DB::table('subscription_events')->count(),
            'payments' => DB::table('manual_payments')->count(),
            'audits' => AuditLog::query()->count(),
            'statuses' => Subscription::query()->orderBy('id')->pluck('status', 'id')->all(),
            'ends' => Subscription::query()->orderBy('id')->pluck('ends_at', 'id')->map(fn ($value) => (string) $value)->all(),
        ];
    }

    private function stepUp(): array
    {
        return ['platform_stepup_at' => time()];
    }

    public function test_selecting_a_plan_only_previews_and_never_writes(): void
    {
        [$company] = $this->lapsedCompany();
        $target = $this->target();
        $inactive = $this->target(['is_active' => false]);
        $this->actingAs($this->admin());
        $before = $this->snapshot();

        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => $company->id, 'action' => 'plan', 'plan_id' => $target->id]))
            ->assertOk()
            ->assertJsonPath('action', 'plan')
            ->assertJsonPath('same_plan', false)
            ->assertJsonPath('to', $target->name)
            ->assertJsonStructure(['from', 'to', 'ends_at', 'usage' => ['users' => ['usage', 'limit'], 'branches', 'warehouses'], 'over_limit']);
        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => $company->id, 'action' => 'plan', 'plan_id' => $inactive->id]))
            ->assertOk()->assertJsonPath('inactive', true);

        $this->assertSame($before, $this->snapshot(), 'Previewing a plan must not touch subscriptions, events, payments or audit rows.');
    }

    public function test_preview_validates_input_and_reports_missing_subscriptions(): void
    {
        [$company] = $this->lapsedCompany();
        $bare = Company::query()->create(['name' => 'No Subscription', 'status' => 'active']);
        $this->actingAs($this->admin());

        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => $company->id, 'action' => 'plan']))->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => $company->id, 'action' => 'plan', 'plan_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => 999999, 'action' => 'plan', 'plan_id' => $this->target()->id]))->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => $bare->id, 'action' => 'plan', 'plan_id' => $this->target()->id]))
            ->assertStatus(409)->assertJsonPath('message', 'The company has no subscription.');
    }

    public function test_preview_and_change_are_limited_to_platform_staff_with_the_right_permission(): void
    {
        [$company] = $this->lapsedCompany();
        $target = $this->target();
        $query = '?'.http_build_query(['company_id' => $company->id, 'action' => 'plan', 'plan_id' => $target->id]);
        $tenantUser = User::query()->create(['name' => 'Tenant', 'email' => 'tenant-plan@example.test', 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        $before = $this->snapshot();

        $this->getJson('/super-admin/subscriptions/preview'.$query)->assertUnauthorized();
        $this->actingAs($tenantUser)->getJson('/super-admin/subscriptions/preview'.$query)->assertForbidden();
        $this->withSession($this->stepUp())->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $target->id, 'reason' => 'Tenant attempt'])->assertForbidden();
        $this->actingAs($this->admin(null))->getJson('/super-admin/subscriptions/preview'.$query)->assertForbidden();
        $this->actingAs($this->admin('support'))->getJson('/super-admin/subscriptions/preview'.$query)->assertOk();
        $this->withSession($this->stepUp())->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $target->id, 'reason' => 'Support attempt'])->assertForbidden();
        $this->actingAs($this->admin('auditor'))->withSession($this->stepUp())->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $target->id, 'reason' => 'Auditor attempt'])->assertForbidden();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_confirmed_change_on_a_lapsed_company_keeps_history_dates_and_audit_context(): void
    {
        [$company, $old, $subscription] = $this->lapsedCompany();
        $target = $this->target();
        $admin = $this->admin('billing');

        $this->actingAs($admin)->withSession($this->stepUp())
            ->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => (string) $target->id, 'reason' => 'Customer upgrade'])->assertOk()->assertJsonPath('ok', true);

        $current = $company->subscriptions()->current()->firstOrFail();
        $this->assertSame($target->id, $current->plan_id);
        $this->assertSame($subscription->id, $current->previous_subscription_id);
        $this->assertSame($old->id, $subscription->fresh()->plan_id, 'The historical row keeps its original plan.');
        $this->assertNotNull($subscription->fresh()->superseded_at);
        $this->assertSame($subscription->ends_at->timestamp, $current->ends_at->timestamp, 'The paid-through date is preserved.');
        $this->assertSame('active', $current->status);
        $this->assertSame(2, Subscription::query()->where('company_id', $company->id)->count());
        $this->assertDatabaseHas('subscription_events', ['company_id' => $company->id, 'type' => 'plan_changed', 'from_plan_id' => $old->id, 'to_plan_id' => $target->id, 'actor_id' => $admin->id, 'reason' => 'Customer upgrade']);

        $audit = AuditLog::query()->where('event', 'company.plan_changed')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('Customer upgrade', $audit->reason);
        $this->assertSame($old->id, $audit->before['plan_id']);
        $this->assertSame($target->id, $audit->after['plan_id']);
    }

    public function test_the_mutation_requires_step_up_and_the_second_factor_when_enrolled(): void
    {
        [$company, , $subscription] = $this->lapsedCompany();
        $target = $this->target();
        $admin = $this->admin();
        $before = $this->snapshot();
        $payload = ['plan_id' => $target->id, 'reason' => 'Customer upgrade'];

        $this->actingAs($admin)->postJson("/super-admin/companies/{$company->id}/plan", $payload)->assertStatus(423)->assertJsonPath('code', 'step_up_required');
        $this->assertSame($before, $this->snapshot());

        $mfa = app(MfaService::class);
        $pending = $mfa->newPendingSecret($admin);
        $mfa->confirmEnrollment($admin, $pending['secret'], Totp::code($pending['secret'], intdiv(time(), 30)));
        $this->app['auth']->forgetGuards();
        $before = $this->snapshot();
        $this->actingAs($admin->fresh())->withSession($this->stepUp())->postJson("/super-admin/companies/{$company->id}/plan", $payload)->assertStatus(423)->assertJsonPath('code', 'mfa_required');
        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => $company->id, 'action' => 'plan', 'plan_id' => $target->id]))->assertStatus(423);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($subscription->id, $company->subscriptions()->current()->firstOrFail()->id);
    }

    public function test_status_rules_for_suspended_and_archived_companies(): void
    {
        $target = $this->target();
        $this->actingAs($this->admin())->withSession($this->stepUp());

        [$suspended, , $suspendedSubscription] = $this->lapsedCompany('suspended', 'suspended');
        $this->postJson("/super-admin/companies/{$suspended->id}/plan", ['plan_id' => $target->id, 'reason' => 'While suspended'])->assertOk();
        $this->assertSame('suspended', $suspended->fresh()->status, 'A plan change never reactivates a suspended company.');
        $this->assertSame('suspended', $suspended->subscriptions()->current()->firstOrFail()->status);
        $this->assertNotNull($suspendedSubscription->fresh()->superseded_at);

        [$archived] = $this->lapsedCompany('archived', 'archived');
        $before = $this->snapshot();
        $this->postJson("/super-admin/companies/{$archived->id}/plan", ['plan_id' => $target->id, 'reason' => 'While archived'])->assertStatus(409);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_downgrade_below_current_usage_is_rejected_with_a_clear_message(): void
    {
        [$company] = $this->lapsedCompany();
        foreach ([1, 2, 3] as $index) {
            User::query()->create(['name' => "Staff {$index}", 'email' => "plan-staff-{$index}-{$company->id}@example.test", 'password' => 'Correct-horse-1', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active']);
        }
        $tight = $this->target(['limits' => ['users' => 1], 'price' => 1]);
        $this->actingAs($this->admin())->withSession($this->stepUp());
        $before = $this->snapshot();

        $this->getJson('/super-admin/subscriptions/preview?'.http_build_query(['company_id' => $company->id, 'action' => 'plan', 'plan_id' => $tight->id]))
            ->assertOk()->assertJsonPath('over_limit.0', 'users')->assertJsonPath('usage.users.limit', 1);
        $this->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $tight->id, 'reason' => 'Downgrade'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_id')
            ->assertJsonPath('errors.plan_id.0', fn (string $message): bool => str_contains($message, 'users'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_repeating_a_confirmed_change_cannot_create_a_duplicate_history_row(): void
    {
        [$company] = $this->lapsedCompany();
        $target = $this->target();
        $this->actingAs($this->admin())->withSession($this->stepUp());
        $payload = ['plan_id' => $target->id, 'reason' => 'Customer upgrade'];

        $this->postJson("/super-admin/companies/{$company->id}/plan", $payload)->assertOk();
        $after = $this->snapshot();
        $this->postJson("/super-admin/companies/{$company->id}/plan", $payload)->assertStatus(422)->assertJsonValidationErrors('plan_id');

        $this->assertSame($after, $this->snapshot(), 'A repeated request changes nothing.');
        $this->assertSame(2, Subscription::query()->where('company_id', $company->id)->count());
        $this->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $target->id])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_changing_one_company_never_touches_another_tenant(): void
    {
        [$company] = $this->lapsedCompany();
        [$other, , $otherSubscription] = $this->lapsedCompany();
        $target = $this->target();
        $this->actingAs($this->admin())->withSession($this->stepUp());

        $this->postJson("/super-admin/companies/{$company->id}/plan", ['plan_id' => $target->id, 'reason' => 'Isolated change'])->assertOk();

        $this->assertSame(1, Subscription::query()->where('company_id', $other->id)->count());
        $this->assertNull($otherSubscription->fresh()->superseded_at);
        $this->assertNotSame($target->id, $otherSubscription->fresh()->plan_id);
        $this->assertSame(0, AuditLog::query()->where('company_id', $other->id)->count());
    }
}
