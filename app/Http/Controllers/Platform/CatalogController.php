<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Coupon;
use App\Models\Plan;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends PlatformController
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Plans', [
            'plans' => Plan::query()->withCount('subscriptions')->orderByDesc('is_active')->orderBy('name')->get(['id', 'code', 'name', 'duration', 'duration_days', 'price', 'limits', 'is_active']),
            'coupons' => Coupon::query()->orderByDesc('is_active')->latest('id')->limit(100)->get(['id', 'code', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'max_redemptions', 'redemptions_count', 'is_active']),
        ]);
    }

    public function storePlan(Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:80', 'unique:plans,code'],
            'name' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'in:monthly,six_months,yearly,trial,lifetime'],
            'duration_days' => ['required_if:duration,trial', 'nullable', 'integer', 'min:1', 'max:3650'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'limits' => ['nullable', 'array:users,branches,warehouses'],
            'limits.users' => ['nullable', 'integer', 'min:1'],
            'limits.branches' => ['nullable', 'integer', 'min:1'],
            'limits.warehouses' => ['nullable', 'integer', 'min:1'],
            // Feature flags are deprecated: access is controlled by limits only (see the remediation report).
            'features' => ['prohibited'],
        ]);
        $data['limits'] = array_filter($data['limits'] ?? [], fn ($value) => $value !== null && $value !== '');
        $plan = Plan::query()->create([...$data, 'is_active' => true]);
        $audit->record('platform.plan_created', $plan, null, $request->user()->id, [], $request, ['after' => ['code' => $plan->code, 'price' => $plan->price, 'duration' => $plan->duration]]);

        return $this->done($request);
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
