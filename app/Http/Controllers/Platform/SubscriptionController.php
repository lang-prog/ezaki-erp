<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Company;
use App\Models\ManualPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlatformMetricsService;
use App\Services\SubscriptionLifecycle;
use App\Services\SubscriptionTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends PlatformController
{
    public function index(Request $request, SubscriptionLifecycle $lifecycle, PlatformMetricsService $metrics): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'in:all,expiring,lapsed,suspended']]);
        $state = $filters['state'] ?? 'all';
        $query = match ($state) {
            'expiring' => $metrics->expiringSoon(),
            'lapsed' => $metrics->lapsed(),
            'suspended' => $metrics->currentSubscriptions()->where('status', 'suspended'),
            default => $metrics->currentSubscriptions(),
        };
        $subscriptions = $query->with(['company:id,name,status', 'plan:id,name,duration,duration_days,subscription_duration_id,price', 'plan.durationCatalog'])
            ->when($filters['q'] ?? null, fn ($builder, $term) => $builder->whereHas('company', fn ($company) => $company->where('name', 'like', $this->like($term))))
            ->latest('id')->paginate(20)->withQueryString()
            ->through(fn (Subscription $subscription): array => [
                'id' => $subscription->id, 'company_id' => $subscription->company_id, 'company' => $subscription->company?->name,
                'plan' => $subscription->plan?->name, 'price' => $subscription->plan?->price, 'status' => $subscription->status,
                'access_state' => $subscription->company ? $lifecycle->accessState($subscription->company, $subscription) : $subscription->status,
                'starts_at' => $subscription->starts_at, 'ends_at' => $subscription->ends_at,
            ]);

        return Inertia::render('SuperAdmin/Subscriptions', [
            'subscriptions' => $subscriptions,
            'filters' => ['q' => $filters['q'] ?? '', 'state' => $state],
            'payments' => ManualPayment::query()->with(['subscription.company:id,name', 'subscription.plan:id,name'])->latest('id')->limit(25)->get()
                ->map(fn (ManualPayment $payment): array => [
                    'id' => $payment->id, 'company' => $payment->subscription?->company?->name, 'plan' => $payment->subscription?->plan?->name,
                    'amount' => $payment->amount, 'status' => $payment->status, 'reference' => $payment->reference, 'paid_at' => $payment->paid_at,
                    'created_at' => $payment->created_at, 'new_ends_at' => $payment->new_ends_at,
                ]),
            'plans' => Plan::query()->where('is_active', true)->with('durationCatalog')->orderBy('name')->get()->map->summary()->values(),
        ]);
    }

    /** Read-only impact preview shown before the confirmation dialog; nothing is written. */
    public function preview(Request $request, SubscriptionTransitionService $transitions, SubscriptionLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'], 'action' => ['required', 'in:plan,payment,limits'],
            'plan_id' => ['nullable', 'integer', 'required_if:action,plan', 'exists:plans,id'], 'users' => ['nullable', 'integer', 'min:0'],
            'branches' => ['nullable', 'integer', 'min:0'], 'warehouses' => ['nullable', 'integer', 'min:0'],
        ]);
        $company = Company::query()->findOrFail($data['company_id']);
        $current = $lifecycle->currentFor($company);
        abort_unless($current, 409, 'The company has no subscription.');
        $addons = $current->overrides['limit_addons'] ?? [];

        if ($data['action'] === 'payment') {
            $extension = $transitions->previewExtension($current);

            return response()->json(['action' => 'payment', 'company_status' => $company->status, 'subscription_status' => $current->status, 'will_reactivate' => false] + $extension);
        }
        if ($data['action'] === 'plan') {
            $plan = Plan::query()->findOrFail($data['plan_id'] ?? 0);

            return response()->json([
                'action' => 'plan', 'same_plan' => (int) $plan->id === (int) $current->plan_id, 'inactive' => ! $plan->is_active,
                'from' => $current->plan?->name, 'to' => $plan->name, 'ends_at' => $lifecycle->isLifetime($plan) ? null : $current->ends_at,
                'usage' => $transitions->usage($company, $plan, $addons), 'over_limit' => array_keys($transitions->overLimit($company, $plan, $addons)),
            ]);
        }
        $new = ['users' => (int) ($data['users'] ?? 0), 'branches' => (int) ($data['branches'] ?? 0), 'warehouses' => (int) ($data['warehouses'] ?? 0)];

        return response()->json(['action' => 'limits', 'usage' => $transitions->usage($company, $current->plan, $new), 'over_limit' => array_keys($transitions->overLimit($company, $current->plan, $new)), 'before' => $addons]);
    }

    public function changePlan(Company $company, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id']]);
        $subscription = $transitions->changePlan($company, Plan::query()->findOrFail($data['plan_id']), $request->user(), $this->reason($request));

        return $this->done($request, ['subscription_id' => $subscription->id]);
    }

    public function renew(Company $company, Request $request, SubscriptionTransitionService $transitions, SubscriptionLifecycle $lifecycle): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'], 'reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);
        $current = $lifecycle->currentFor($company);
        abort_unless($current, 409, 'The company has no subscription.');
        $result = $transitions->recordPayment($current, $data + ['status' => 'paid', 'reason' => $this->reason($request)], $request->user());

        return $this->done($request, ['replayed' => $result['replayed']]);
    }

    public function recordPayment(Subscription $subscription, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'status' => ['required', 'in:'.implode(',', SubscriptionTransitionService::PAYMENT_STATUSES)],
            'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);
        $result = $transitions->recordPayment($subscription, $data + ['reason' => $this->reason($request)], $request->user());

        return $this->done($request, ['replayed' => $result['replayed']]);
    }

    public function resolvePayment(ManualPayment $payment, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:paid,failed']]);
        $transitions->resolvePayment($payment, $data['status'], $request->user(), $this->reason($request));

        return $this->done($request);
    }

    public function updateAddons(Company $company, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'users' => ['required', 'integer', 'min:0', 'max:100000'], 'branches' => ['required', 'integer', 'min:0', 'max:100000'],
            'warehouses' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $transitions->updateAddons($company, $data, $request->user(), $this->reason($request));

        return $this->done($request);
    }
}
