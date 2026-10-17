<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionDuration;
use App\Services\SubscriptionDurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DurationController extends PlatformController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(SubscriptionDuration::CATEGORIES)],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $durations = SubscriptionDuration::query()->withCount(['plans', 'plans as active_plans_count' => fn ($query) => $query->where('is_active', true)])
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($inner) => $inner->where('name_ar', 'like', $this->like($term))->orWhere('name_en', 'like', $this->like($term))->orWhere('key', 'like', $this->like($term))))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')->orderBy('sort_order')->orderBy('id')->get();
        $subscriptions = Subscription::query()->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->whereNotNull('plans.subscription_duration_id')
            ->groupBy('plans.subscription_duration_id')->selectRaw('plans.subscription_duration_id as duration_id, count(*) as total')->pluck('total', 'duration_id');
        $activePlans = Plan::query()->where('is_active', true)->whereNotNull('subscription_duration_id')->orderBy('name')->get(['id', 'name', 'subscription_duration_id'])->groupBy('subscription_duration_id');

        return Inertia::render('SuperAdmin/Durations', [
            'durations' => $durations->map(fn (SubscriptionDuration $duration): array => [
                ...$duration->only(['id', 'key', 'name_ar', 'name_en', 'description_ar', 'description_en', 'category', 'unit', 'value', 'sort_order', 'is_active', 'is_system', 'version']),
                'plans_count' => $duration->plans_count, 'active_plans_count' => $duration->active_plans_count, 'subscriptions_count' => (int) ($subscriptions[$duration->id] ?? 0),
                'active_plans' => ($activePlans[$duration->id] ?? collect())->take(10)->map(fn (Plan $plan): array => ['id' => $plan->id, 'name' => $plan->name])->values(),
                'immutable' => $duration->is_system ? 'system' : ($duration->plans_count > 0 ? 'referenced' : null),
            ])->values(),
            'filters' => ['q' => $filters['q'] ?? '', 'category' => $filters['category'] ?? '', 'status' => $filters['status'] ?? ''],
            'meta' => ['categories' => SubscriptionDuration::CATEGORIES, 'units' => SubscriptionDuration::UNITS, 'max_value' => SubscriptionDuration::MAX_VALUE, 'non_paid_units' => SubscriptionDuration::NON_PAID_UNITS],
        ]);
    }

    public function store(Request $request, SubscriptionDurationService $durations): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'],
            'description_ar' => ['nullable', 'string', 'max:500'], 'description_en' => ['nullable', 'string', 'max:500'],
            'category' => ['required', Rule::in(SubscriptionDuration::CATEGORIES)], 'unit' => ['required', Rule::in(SubscriptionDuration::UNITS)],
            'value' => ['nullable', 'integer'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'key' => ['nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
        ], ['key.regex' => __('durations.key_invalid')]);
        $data['sort_order'] = $data['sort_order'] ?? 100;
        $duration = $durations->create($data, $request->user(), $request);

        return $request->expectsJson() ? response()->json(['ok' => true, 'id' => $duration->id], 201) : back();
    }

    public function update(SubscriptionDuration $duration, Request $request, SubscriptionDurationService $durations): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'],
            'description_ar' => ['nullable', 'string', 'max:500'], 'description_en' => ['nullable', 'string', 'max:500'],
            'category' => ['sometimes', Rule::in(SubscriptionDuration::CATEGORIES)], 'unit' => ['sometimes', Rule::in(SubscriptionDuration::UNITS)],
            'value' => ['sometimes', 'nullable', 'integer'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'key' => ['sometimes', 'string', 'max:40'],
        ]);
        $durations->update($duration, $data, $request->user(), $this->reason($request), $request);

        return $this->done($request);
    }

    public function activate(SubscriptionDuration $duration, Request $request, SubscriptionDurationService $durations): JsonResponse|RedirectResponse
    {
        $durations->setActive($duration, true, true, $request->user(), $this->reason($request), $request);

        return $this->done($request);
    }

    public function deactivate(SubscriptionDuration $duration, Request $request, SubscriptionDurationService $durations): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['confirm_affected' => ['sometimes', 'boolean']]);
        $durations->setActive($duration, false, (bool) ($data['confirm_affected'] ?? false), $request->user(), $this->reason($request), $request);

        return $this->done($request);
    }
}
