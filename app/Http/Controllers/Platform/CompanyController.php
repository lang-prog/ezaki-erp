<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\Plan;
use App\Services\CompanyProvisioningService;
use App\Services\SubscriptionLifecycle;
use App\Services\SubscriptionTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends PlatformController
{
    public function index(Request $request, SubscriptionLifecycle $lifecycle): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,suspended,archived,pending']]);
        $companies = Company::query()
            ->with(['subscriptions' => fn ($query) => $query->current()->with(['plan:id,name,duration,duration_days,subscription_duration_id,price,limits', 'plan.durationCatalog'])])
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($inner) => $inner->where('name', 'like', $this->like($term))->orWhere('trade_name', 'like', $this->like($term))->orWhere('tax_number', 'like', $this->like($term))))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString()
            ->through(function (Company $company) use ($lifecycle): array {
                $subscription = $company->subscriptions->first();

                return [
                    'id' => $company->id, 'name' => $company->name, 'status' => $company->status, 'archived_at' => $company->archived_at,
                    'subscription' => $subscription ? [
                        'id' => $subscription->id, 'status' => $subscription->status, 'access_state' => $lifecycle->accessState($company, $subscription),
                        'starts_at' => $subscription->starts_at, 'ends_at' => $subscription->ends_at,
                        'plan' => ['id' => $subscription->plan?->id, 'name' => $subscription->plan?->name, 'duration' => $subscription->plan?->duration, 'duration_info' => $subscription->plan?->durationInfo(), 'price' => $subscription->plan?->price],
                        'limit_addons' => $subscription->overrides['limit_addons'] ?? ['users' => 0, 'branches' => 0, 'warehouses' => 0],
                    ] : null,
                ];
            });

        return Inertia::render('SuperAdmin/Companies', [
            'companies' => $companies,
            'filters' => $filters,
            'plans' => Plan::query()->where('is_active', true)->with('durationCatalog')->orderBy('name')->get()->map->summary()->values(),
        ]);
    }

    public function store(Request $request, CompanyProvisioningService $provisioning): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'tax_number')],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:registration_requests,email'],
            'owner_password' => ['required', 'confirmed', 'string', 'min:8'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'coupon_code' => ['nullable', 'string', 'exists:coupons,code'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['tax_number.unique' => __('platform.tax_number_taken')]);
        $plan = Plan::query()->where('is_active', true)->findOrFail($data['plan_id']);
        $coupon = isset($data['coupon_code']) ? Coupon::query()->where('code', $data['coupon_code'])->firstOrFail() : null;
        unset($data['plan_id'], $data['coupon_code']);

        $provisioning->createManually($data, $request->user(), $plan, $coupon);

        return $request->expectsJson() ? response()->json(['ok' => true], 201) : back()->with('status', 'Company and owner account created.');
    }

    public function activate(Company $company, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['changed' => $transitions->activate($company, $request->user(), $this->reason($request))]);
    }

    public function suspend(Company $company, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['changed' => $transitions->suspend($company, $request->user(), $this->reason($request))]);
    }

    public function archive(Company $company, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['changed' => $transitions->archive($company, $request->user(), $this->reason($request))]);
    }

    public function restore(Company $company, Request $request, SubscriptionTransitionService $transitions): JsonResponse|RedirectResponse
    {
        return $this->done($request, ['changed' => $transitions->restore($company, $request->user(), $this->reason($request))]);
    }
}
