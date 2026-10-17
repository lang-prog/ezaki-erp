<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\SubscriptionDuration;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CatalogController extends PlatformController
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Plans', [
            'plans' => Plan::query()->withCount('subscriptions')->with('durationCatalog')->orderByDesc('is_active')->orderBy('name')->get(['id', 'code', 'name', 'duration', 'duration_days', 'subscription_duration_id', 'price', 'limits', 'is_active'])
                ->map(fn (Plan $plan): array => [...$plan->only(['id', 'code', 'name', 'duration', 'duration_days', 'price', 'limits', 'is_active', 'subscriptions_count']), 'duration_info' => $plan->durationInfo()]),
            // Only active catalog entries can be chosen for a new plan; the server is the single source of the list.
            'durations' => SubscriptionDuration::query()->active()->orderBy('sort_order')->orderBy('id')->get()->map(fn (SubscriptionDuration $duration): array => [...$duration->toInfo(), 'description_ar' => $duration->description_ar, 'description_en' => $duration->description_en]),
            'coupons' => Coupon::query()->orderByDesc('is_active')->latest('id')->limit(100)->get(['id', 'code', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'max_redemptions', 'redemptions_count', 'is_active']),
        ]);
    }

    public function storePlan(Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:80', 'unique:plans,code'],
            'name' => ['required', 'string', 'max:255'],
            'subscription_duration_id' => ['nullable', 'integer'],
            // Legacy clients may still send a catalog key instead of an id.
            'duration' => ['nullable', 'string', 'max:40'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'limits' => ['nullable', 'array:users,branches,warehouses'],
            'limits.users' => ['nullable', 'integer', 'min:1'],
            'limits.branches' => ['nullable', 'integer', 'min:1'],
            'limits.warehouses' => ['nullable', 'integer', 'min:1'],
            // Feature flags are deprecated: access is controlled by limits only (see the remediation report).
            'features' => ['prohibited'],
        ]);
        $limits = array_filter($data['limits'] ?? [], fn ($value) => $value !== null && $value !== '');
        $plan = DB::transaction(function () use ($data, $limits): Plan {
            $duration = $this->selectableDuration(isset($data['subscription_duration_id']) ? (int) $data['subscription_duration_id'] : null, $data['duration'] ?? null);

            return Plan::query()->create([
                'code' => $data['code'], 'name' => $data['name'], 'price' => $data['price'], 'limits' => $limits, 'is_active' => true,
                ...$this->durationColumns($duration),
            ]);
        });
        $audit->record('platform.plan_created', $plan, null, $request->user()->id, [], $request, ['after' => ['code' => $plan->code, 'price' => $plan->price, 'duration' => $plan->duration, 'subscription_duration_id' => $plan->subscription_duration_id]]);

        return $this->done($request);
    }

    /** Renames a plan; its duration can change only while no subscription uses the plan (history must keep its meaning). */
    public function updatePlan(Plan $plan, Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'subscription_duration_id' => ['nullable', 'integer']]);
        $reason = $this->reason($request);
        DB::transaction(function () use ($plan, $data, $reason, $request, $audit): void {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            $before = ['name' => $locked->name, 'subscription_duration_id' => $locked->subscription_duration_id, 'duration' => $locked->duration];
            $attributes = ['name' => $data['name']];
            $requested = isset($data['subscription_duration_id']) ? (int) $data['subscription_duration_id'] : null;
            if ($requested !== null && $requested !== (int) $locked->subscription_duration_id) {
                if ($locked->subscriptions()->exists()) {
                    throw new HttpException(409, __('durations.plan_duration_locked'));
                }
                $attributes += $this->durationColumns($this->selectableDuration($requested, null));
            }
            $locked->forceFill($attributes)->save();
            $audit->record('platform.plan_updated', $locked, null, $request->user()->id, [], $request, ['reason' => $reason, 'before' => $before, 'after' => ['name' => $locked->name, 'subscription_duration_id' => $locked->subscription_duration_id, 'duration' => $locked->duration]]);
        });

        return $this->done($request);
    }

    private function selectableDuration(?int $id, ?string $key): SubscriptionDuration
    {
        if ($id === null && ($key === null || $key === '')) {
            throw ValidationException::withMessages(['subscription_duration_id' => __('durations.plan_duration_required')]);
        }
        $query = SubscriptionDuration::query()->lockForUpdate()->where('is_active', true);
        $duration = $id !== null ? $query->find($id) : $query->where('key', $key)->first();
        if (! $duration) {
            throw ValidationException::withMessages(['subscription_duration_id' => __('durations.plan_duration_unavailable')]);
        }

        return $duration;
    }

    /** Catalog link plus the legacy mirror columns older readers still understand. */
    private function durationColumns(SubscriptionDuration $duration): array
    {
        $days = match ($duration->unit) {
            'day' => $duration->value,
            'week' => $duration->value * 7,
            default => null,
        };

        return ['subscription_duration_id' => $duration->id, 'duration' => $duration->key, 'duration_days' => $days];
    }

    public function storeCoupon(Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:100', 'unique:coupons,code'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:'.($request->input('discount_type') === 'percent' ? '100' : '999999999')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'restrictions' => ['nullable', 'array:plan_ids,company_ids,editions'],
            'restrictions.plan_ids' => ['nullable', 'array'], 'restrictions.plan_ids.*' => ['integer', 'exists:plans,id'],
            'restrictions.company_ids' => ['nullable', 'array'], 'restrictions.company_ids.*' => ['integer', 'exists:companies,id'],
            'restrictions.editions' => ['nullable', 'array'], 'restrictions.editions.*' => ['in:saas,local'],
        ]);
        $coupon = Coupon::query()->create([...$data, 'is_active' => true]);
        $audit->record('platform.coupon_created', $coupon, null, $request->user()->id, [], $request, ['after' => ['code' => $coupon->code, 'type' => $coupon->discount_type, 'value' => $coupon->discount_value]]);

        return $this->done($request);
    }

    public function togglePlan(Plan $plan, Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $reason = $this->reason($request);
        $before = $plan->is_active;
        $plan->forceFill(['is_active' => $data['is_active']])->save();
        $audit->record('platform.plan_toggled', $plan, null, $request->user()->id, [], $request, ['reason' => $reason, 'before' => ['is_active' => $before], 'after' => ['is_active' => $plan->is_active]]);

        return $this->done($request);
    }

    public function toggleCoupon(Coupon $coupon, Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $reason = $this->reason($request);
        $before = $coupon->is_active;
        $coupon->forceFill(['is_active' => $data['is_active']])->save();
        $audit->record('platform.coupon_toggled', $coupon, null, $request->user()->id, [], $request, ['reason' => $reason, 'before' => ['is_active' => $before], 'after' => ['is_active' => $coupon->is_active]]);

        return $this->done($request);
    }
}
