<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\ManualPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only writer of subscription/company lifecycle state. Policy (documented in the remediation report):
 * - the current subscription is the newest non-superseded row; plan changes create a new row and keep the old one;
 * - only fully-paid manual payments extend access (partial payments are rejected, overpayments are recorded as paid);
 * - refunds are handled outside the system, so refunded statuses are not accepted;
 * - a payment or plan change never reactivates a suspended/archived company; that needs an explicit transition.
 */
class SubscriptionTransitionService
{
    public const PAYMENT_STATUSES = ['paid', 'pending', 'failed'];

    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly CompanyLimitService $limits,
        private readonly AuditRecorder $audit,
    ) {}

    public function suspend(Company $company, ?User $actor, ?string $reason): bool
    {
        return $this->transition($company, $actor, $reason, 'suspended', function (Company $company, ?Subscription $subscription): ?array {
            if ($company->status === 'archived') {
                throw new HttpException(409, 'An archived company must be restored before it can be suspended.');
            }
            if ($company->status === 'suspended' && (! $subscription || $subscription->status === 'suspended')) {
                return null;
            }
            $company->forceFill(['status' => 'suspended'])->save();
            $subscription?->forceFill(['status' => 'suspended'])->save();

            return ['event' => 'company.suspended', 'severity' => 'warning'];
        });
    }

    public function activate(Company $company, ?User $actor, ?string $reason): bool
    {
        return $this->transition($company, $actor, $reason, 'activated', function (Company $company, ?Subscription $subscription): ?array {
            if ($company->status === 'archived') {
                throw new HttpException(409, 'An archived company must be restored, not activated.');
            }
            if ($company->status === 'active' && (! $subscription || $subscription->status !== 'suspended')) {
                return null;
            }
            $company->forceFill(['status' => 'active', 'archived_at' => null])->save();
            if ($subscription && $subscription->status === 'suspended') {
                $subscription->forceFill(['status' => 'active'])->save();
            }

            return ['event' => 'company.activated'];
        });
    }

    public function archive(Company $company, ?User $actor, ?string $reason, string $event = 'company.archived'): bool
    {
        return $this->transition($company, $actor, $reason, 'archived', function (Company $company, ?Subscription $subscription) use ($event): ?array {
            if ($company->status === 'archived' && (! $subscription || $subscription->status === 'archived')) {
                return null;
            }
            $company->forceFill(['status' => 'archived', 'archived_at' => $company->archived_at ?? now()])->save();
            $subscription?->forceFill(['status' => 'archived'])->save();

            return ['event' => $event, 'severity' => 'warning'];
        });
    }

    public function restore(Company $company, ?User $actor, ?string $reason): bool
    {
        return $this->transition($company, $actor, $reason, 'restored', function (Company $company, ?Subscription $subscription): ?array {
            if ($company->status !== 'archived') {
                return null;
            }
            $company->forceFill(['status' => 'active', 'archived_at' => null])->save();
            if ($subscription) {
                $subscription->forceFill(['status' => 'active'])->save();
                // Expired beyond the archive window: the company can sign in only to renew.
                if ($this->lifecycle->status($subscription->fresh()) === 'archived') {
                    $subscription->forceFill(['status' => 'suspended'])->save();
                }
            }

            return ['event' => 'company.restored'];
        });
    }

    /** Plan change: a new subscription row preserves the previous one; the paid-through date is kept (no proration). */
    public function changePlan(Company $company, Plan $plan, User $actor, string $reason): Subscription
    {
        return DB::transaction(function () use ($company, $plan, $actor, $reason): Subscription {
            [$company, $current] = $this->lock($company);
            if (! $current) {
                throw new HttpException(409, 'The company has no subscription to change.');
            }
            if ($current->status === 'archived' || $company->status === 'archived') {
                throw new HttpException(409, 'Restore the company before changing its plan.');
            }
            if (! $plan->is_active) {
                throw ValidationException::withMessages(['plan_id' => 'The selected plan is not active.']);
            }
            if ((int) $current->plan_id === (int) $plan->id) {
                throw ValidationException::withMessages(['plan_id' => 'The company is already on this plan.']);
            }
            $over = $this->overLimit($company, $plan, $current->overrides['limit_addons'] ?? []);
            if ($over !== []) {
                throw ValidationException::withMessages(['plan_id' => 'Current usage exceeds the target plan limits: '.collect($over)->map(fn ($row, $key) => "{$key} ({$row['usage']} > {$row['limit']})")->implode(', ').'.']);
            }

            $now = CarbonImmutable::now();
            $endsAt = $plan->duration === 'lifetime'
                ? null
                : ($current->ends_at ?? $this->lifecycle->expiryFor($plan, $now));
            $before = $this->snapshot($current);
            $new = Subscription::query()->create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'status' => $current->status,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'overrides' => array_filter(['limit_addons' => $current->overrides['limit_addons'] ?? null]),
                'previous_subscription_id' => $current->id,
            ]);
            $current->forceFill(['superseded_at' => $now])->save();
            $this->event($company, $new, 'plan_changed', $before, $actor, $reason, ['to_plan_id' => $plan->id, 'to_status' => $new->status, 'to_starts_at' => $new->starts_at, 'to_ends_at' => $new->ends_at]);
            $this->audit->record('company.plan_changed', $new, $company->id, $actor->id, ['from_subscription_id' => $current->id], null, [
                'reason' => $reason, 'before' => $before, 'after' => $this->snapshot($new),
            ]);

            return $new;
        });
    }

    /**
     * @return array{payment: ManualPayment, replayed: bool}
     */
    public function recordPayment(Subscription $subscription, array $data, User $actor): array
    {
        $key = (string) $data['idempotency_key'];

        return DB::transaction(function () use ($subscription, $data, $actor, $key): array {
            if ($existing = ManualPayment::query()->where('idempotency_key', $key)->first()) {
                return ['payment' => $existing, 'replayed' => true];
            }
            $company = Company::query()->lockForUpdate()->findOrFail($subscription->company_id);
            $current = $company->subscriptions()->current()->lockForUpdate()->first();
            if (! $current || (int) $current->id !== (int) $subscription->id) {
                throw new HttpException(409, 'Payments can only be recorded against the current subscription.');
            }
            if ($current->status === 'archived' || $company->status === 'archived') {
                throw new HttpException(409, 'Restore the company before recording a payment.');
            }
            $current->load('plan');
            $status = (string) $data['status'];
            $amount = round((float) $data['amount'], 2);
            $due = round((float) $current->plan->price, 2);
            if ($status === 'paid') {
                $this->assertPayable($amount, $due);
            }

            $payment = ManualPayment::query()->create([
                'subscription_id' => $current->id,
                'recorded_by' => $actor->id,
                'amount' => $amount,
                'currency' => 'EGP',
                'status' => $status,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'reason' => $data['reason'] ?? null,
                'idempotency_key' => $key,
                'amount_due' => $due,
            ]);
            if ($status === 'paid') {
                $this->extend($company, $current, $payment, $actor, (string) ($data['reason'] ?? ''));
            } else {
                $this->audit->record('subscription.manual_payment_recorded', $payment, $company->id, $actor->id, ['status' => $status, 'amount' => $amount], null, ['reason' => $data['reason'] ?? null]);
            }

            return ['payment' => $payment->fresh(), 'replayed' => false];
        });
    }

    public function resolvePayment(ManualPayment $payment, string $status, User $actor, string $reason): ManualPayment
    {
        abort_unless(in_array($status, ['paid', 'failed'], true), 422);

        return DB::transaction(function () use ($payment, $status, $actor, $reason): ManualPayment {
            $payment = ManualPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === $status) {
                return $payment;
            }
            if ($payment->status !== 'pending') {
                throw new HttpException(409, 'Only pending payments can be resolved.');
            }
            $subscription = Subscription::query()->with('plan')->findOrFail($payment->subscription_id);
            $company = Company::query()->lockForUpdate()->findOrFail($subscription->company_id);
            $current = $company->subscriptions()->current()->lockForUpdate()->first();
            if ($status === 'paid') {
                if (! $current || (int) $current->id !== (int) $subscription->id) {
                    throw new HttpException(409, 'Payments can only settle the current subscription.');
                }
                $this->assertPayable((float) $payment->amount, round((float) $subscription->plan->price, 2));
            }
            $payment->forceFill(['status' => $status, 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            if ($status === 'paid') {
                $this->extend($company, $current->load('plan'), $payment, $actor, $reason);
            } else {
                $this->audit->record('subscription.payment_failed', $payment, $company->id, $actor->id, [], null, ['reason' => $reason]);
            }

            return $payment->fresh();
        });
    }

    public function updateAddons(Company $company, array $addons, User $actor, string $reason): Subscription
    {
        return DB::transaction(function () use ($company, $addons, $actor, $reason): Subscription {
            [$company, $current] = $this->lock($company);
            if (! $current) {
                throw new HttpException(409, 'The company has no subscription.');
            }
            $current->load('plan');
            $over = $this->overLimit($company, $current->plan, $addons);
            if ($over !== []) {
                throw ValidationException::withMessages(['limits' => 'Current usage exceeds the resulting limits: '.collect($over)->keys()->implode(', ').'.']);
            }
            $before = $current->overrides['limit_addons'] ?? [];
            $overrides = $current->overrides ?? [];
            $overrides['limit_addons'] = $addons;
            $current->forceFill(['overrides' => $overrides])->save();
            $this->event($company, $current, 'limits_changed', $this->snapshot($current), $actor, $reason, []);
            $this->audit->record('company.limit_addons_changed', $current, $company->id, $actor->id, [], null, ['reason' => $reason, 'before' => $before, 'after' => $addons]);

            return $current;
        });
    }

    /** @return array{users: array, branches: array, warehouses: array} */
    public function usage(Company $company, Plan $plan, array $addons): array
    {
        $rows = [];
        foreach (['users', 'branches', 'warehouses'] as $resource) {
            $rows[$resource] = [
                'usage' => $this->limits->currentCount($company, $resource),
                'limit' => $this->limits->limitFor($plan->limits, $addons, $resource),
            ];
        }

        return $rows;
    }

    public function overLimit(Company $company, Plan $plan, array $addons): array
    {
        return array_filter($this->usage($company, $plan, $addons), fn (array $row): bool => $row['limit'] !== null && $row['usage'] > $row['limit']);
    }

    /** Dates a paid payment would produce, for the confirmation preview. */
    public function previewExtension(Subscription $subscription): array
    {
        $now = CarbonImmutable::now();
        $base = $subscription->ends_at && $subscription->ends_at->isFuture() ? $subscription->ends_at->toImmutable() : $now;

        return ['from' => $subscription->ends_at, 'to' => $this->lifecycle->expiryFor($subscription->plan, $base), 'due' => round((float) $subscription->plan->price, 2)];
    }

    private function assertPayable(float $amount, float $due): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'A paid amount must be greater than zero.']);
        }
        if ($amount + 0.0001 < $due) {
            throw ValidationException::withMessages(['amount' => 'Partial payments are not supported; the amount must cover the plan price.']);
        }
    }

    private function extend(Company $company, Subscription $current, ManualPayment $payment, User $actor, string $reason): void
    {
        $before = $this->snapshot($current);
        $now = CarbonImmutable::now();
        $future = $current->ends_at && $current->ends_at->isFuture();
        $base = $future ? $current->ends_at->toImmutable() : $now;
        $newEnds = $this->lifecycle->expiryFor($current->plan, $base);
        $attributes = ['ends_at' => $newEnds];
        if (! $future && $current->plan->duration !== 'lifetime') {
            $attributes['starts_at'] = $now;
        }
        $current->forceFill($attributes)->save();
        $payment->forceFill(['paid_at' => now(), 'previous_ends_at' => $before['ends_at'], 'new_ends_at' => $newEnds])->save();
        $this->event($company, $current, 'payment_paid', $before, $actor, $reason, ['to_status' => $current->status, 'to_starts_at' => $current->starts_at, 'to_ends_at' => $newEnds, 'payment_id' => $payment->id]);
        $this->audit->record('subscription.manual_payment_recorded', $payment, $company->id, $actor->id, ['status' => 'paid', 'amount' => (float) $payment->amount], null, [
            'reason' => $reason, 'before' => $before, 'after' => $this->snapshot($current->fresh()),
        ]);
    }

    private function transition(Company $company, ?User $actor, ?string $reason, string $type, callable $apply): bool
    {
        return DB::transaction(function () use ($company, $actor, $reason, $type, $apply): bool {
            [$company, $subscription] = $this->lock($company);
            $beforeCompany = ['status' => $company->status];
            $before = $subscription ? $this->snapshot($subscription) : null;
            $result = $apply($company, $subscription);
            if ($result === null) {
                return false;
            }
            $this->event($company, $subscription, $type, $before ?? [], $actor, $reason, ['to_status' => $subscription?->status]);
            $this->audit->record($result['event'], $company, $company->id, $actor?->id, [], null, [
                'reason' => $reason, 'severity' => $result['severity'] ?? 'info',
                'before' => $beforeCompany + ['subscription_status' => $before['status'] ?? null],
                'after' => ['status' => $company->status, 'subscription_status' => $subscription?->status],
            ]);

            return true;
        });
    }

    /** @return array{0: Company, 1: ?Subscription} */
    private function lock(Company $company): array
    {
        $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
        $subscription = $locked->subscriptions()->current()->lockForUpdate()->first();

        return [$locked, $subscription];
    }

    private function snapshot(Subscription $subscription): array
    {
        return [
            'subscription_id' => $subscription->id, 'plan_id' => $subscription->plan_id, 'status' => $subscription->status,
            'starts_at' => $subscription->starts_at?->toIso8601String(), 'ends_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }

    private function event(Company $company, ?Subscription $subscription, string $type, array $before, ?User $actor, ?string $reason, array $after): void
    {
        SubscriptionEvent::query()->create([
            'company_id' => $company->id,
            'subscription_id' => $subscription?->id,
            'type' => $type,
            'from_plan_id' => $before['plan_id'] ?? null,
            'to_plan_id' => $after['to_plan_id'] ?? ($subscription?->plan_id),
            'from_status' => $before['status'] ?? null,
            'to_status' => $after['to_status'] ?? null,
            'from_starts_at' => $before['starts_at'] ?? null,
            'from_ends_at' => $before['ends_at'] ?? null,
            'to_starts_at' => $after['to_starts_at'] ?? null,
            'to_ends_at' => $after['to_ends_at'] ?? null,
            'payment_id' => $after['payment_id'] ?? null,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'request_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
        ]);
    }
}
