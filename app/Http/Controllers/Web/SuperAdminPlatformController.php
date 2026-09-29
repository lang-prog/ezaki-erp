<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\RegistrationRequest;
use App\Models\Subscription;
use App\Services\AuditRecorder;
use App\Services\CompanyProvisioningService;
use App\Services\SubscriptionLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminPlatformController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Dashboard', [
            'registrationOpen' => PlatformSetting::boolean('self_registration_open', config('registration.open')),
            'registrations' => RegistrationRequest::query()->whereIn('status', ['pending', 'rejected'])->latest()->limit(50)->get(),
            'plans' => Plan::query()->latest()->get(),
            'subscriptions' => Subscription::query()->with(['company:id,name', 'plan:id,name,duration'])->latest()->limit(50)->get(),
            'companies' => Company::query()->with(['subscriptions' => fn ($query) => $query->with('plan')->latest('starts_at')])->latest()->paginate(25)->through(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status,
                'archived_at' => $company->archived_at,
                'subscription' => $company->subscriptions->first(),
            ]),
        ]);
    }

    public function updateRegistration(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        PlatformSetting::query()->updateOrCreate(['key' => 'self_registration_open'], ['value' => ['enabled' => $data['enabled']]]);
        $audit->record('platform.registration_setting_changed', null, null, $request->user()->id, ['enabled' => $data['enabled']], $request);

        return back();
    }

    public function storePlan(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:80', 'unique:plans,code'],
            'name' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'in:monthly,six_months,yearly,trial,lifetime'],
            'duration_days' => ['required_if:duration,trial', 'nullable', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'limits' => ['nullable', 'array'],
            'limits.users' => ['nullable', 'integer', 'min:1'],
            'limits.branches' => ['nullable', 'integer', 'min:1'],
            'limits.warehouses' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
        ]);
        abort_if($data['duration'] === 'lifetime' && ! $request->user()->isSuperAdmin(), 403);
        $plan = Plan::query()->create([...$data, 'is_active' => true]);
        $audit->record('platform.plan_created', $plan, null, $request->user()->id, [], $request);

        return back();
    }

    public function storeCoupon(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:100', 'unique:coupons,code'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:'.($request->input('discount_type') === 'percent' ? '100' : '999999999')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'restrictions' => ['nullable', 'array'],
        ]);
        $coupon = Coupon::query()->create([...$data, 'is_active' => true]);
        $audit->record('platform.coupon_created', $coupon, null, $request->user()->id, [], $request);

        return back();
    }

    public function approveRegistration(RegistrationRequest $registration, Request $request, CompanyProvisioningService $provisioning): RedirectResponse
    {
        $provisioning->approve($registration, $request->user(), Plan::query()->findOrFail($registration->plan_id));

        return back();
    }

    public function rejectRegistration(RegistrationRequest $registration, Request $request, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($registration->status === 'pending' && $registration->verified_at, 409);
        $registration->forceFill(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
        $audit->record('registration.rejected', $registration, null, $request->user()->id, [], $request);

        return back();
    }

    public function storeCompany(Request $request, CompanyProvisioningService $provisioning): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
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
        ]);
        $plan = Plan::query()->where('is_active', true)->findOrFail($data['plan_id']);
        $coupon = isset($data['coupon_code']) ? Coupon::query()->where('code', $data['coupon_code'])->firstOrFail() : null;
        unset($data['plan_id'], $data['coupon_code']);

        $provisioning->createManually($data, $request->user(), $plan, $coupon);

        return back()->with('status', 'Company and owner account created.');
    }

    public function activateCompany(Company $company, Request $request, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($company, $request, $audit): void {
            $company->forceFill(['status' => 'active', 'archived_at' => null])->save();
            $subscription = $company->subscriptions()->latest('starts_at')->first();
            if ($subscription && $subscription->status === 'suspended') {
                $subscription->forceFill(['status' => 'active'])->save();
            }
            $audit->record('company.activated', $company, null, $request->user()->id, [], $request);
        });

        return back();
    }

    public function suspendCompany(Company $company, Request $request, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($company, $request, $audit): void {
            $company->forceFill(['status' => 'suspended'])->save();
            $company->subscriptions()->latest('starts_at')->first()?->forceFill(['status' => 'suspended'])->save();
            $audit->record('company.suspended', $company, null, $request->user()->id, [], $request);
        });

        return back();
    }

    public function archiveCompany(Company $company, Request $request, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($company, $request, $audit): void {
            $company->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
            $company->subscriptions()->latest('starts_at')->first()?->forceFill(['status' => 'archived'])->save();
            $audit->record('company.archived', $company, null, $request->user()->id, ['archived_at' => now()->toIso8601String()], $request);
        });

        return back();
    }

    public function restoreCompany(Company $company, Request $request, AuditRecorder $audit, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        DB::transaction(function () use ($company, $request, $audit, $lifecycle): void {
            $company->forceFill(['status' => 'active', 'archived_at' => null])->save();
            $subscription = $company->subscriptions()->latest('starts_at')->first();
            if ($subscription) {
                $subscription->forceFill(['status' => 'active'])->save();
                if ($lifecycle->status($subscription->fresh()) === 'archived') {
                    $subscription->forceFill(['status' => 'suspended'])->save();
                }
            }
            $audit->record('company.restored', $company, null, $request->user()->id, [], $request);
        });

        return back();
    }

    public function changeCompanyPlan(Company $company, Request $request, SubscriptionLifecycle $lifecycle, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id']]);
        $plan = Plan::query()->where('is_active', true)->findOrFail($data['plan_id']);
        DB::transaction(function () use ($company, $plan, $request, $lifecycle, $audit): void {
            $subscription = $company->subscriptions()->latest('starts_at')->first();
            $startsAt = now();
            $attributes = [
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $lifecycle->expiryFor($plan, $startsAt->toImmutable()),
            ];
            if ($subscription) {
                $subscription->forceFill($attributes)->save();
            } else {
                $subscription = $company->subscriptions()->create($attributes);
            }
            $company->forceFill(['status' => 'active', 'archived_at' => null])->save();
            $audit->record('company.plan_changed', $subscription, null, $request->user()->id, ['plan_id' => $plan->id], $request);
        });

        return back();
    }

    public function renewCompany(Company $company, Request $request, PaymentGatewayInterface $gateway, SubscriptionLifecycle $lifecycle, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($company, $request, $gateway, $lifecycle, $audit, $data): void {
            $subscription = $company->subscriptions()->with('plan')->latest('starts_at')->lockForUpdate()->firstOrFail();
            $startsAt = $subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now();
            $payment = $gateway->record($subscription, (float) $data['amount'], 'paid', $request->user(), $data['reference'] ?? null, 'Manual subscription renewal');
            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $lifecycle->expiryFor($subscription->plan, $startsAt->toImmutable()),
            ])->save();
            $company->forceFill(['status' => 'active', 'archived_at' => null])->save();
            $audit->record('company.renewed', $payment, null, $request->user()->id, ['subscription_id' => $subscription->id, 'amount' => $payment->amount], $request);
        });

        return back();
    }

    public function updateCompanyLimitAddons(Company $company, Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'users' => ['required', 'integer', 'min:0', 'max:100000'],
            'branches' => ['required', 'integer', 'min:0', 'max:100000'],
            'warehouses' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $subscription = $company->subscriptions()->latest('starts_at')->firstOrFail();
        $overrides = $subscription->overrides ?? [];
        $overrides['limit_addons'] = $data;
        $subscription->forceFill(['overrides' => $overrides])->save();
        $audit->record('company.limit_addons_changed', $subscription, null, $request->user()->id, ['limit_addons' => $data], $request);

        return back();
    }

    public function recordPayment(Subscription $subscription, Request $request, PaymentGatewayInterface $gateway, AuditRecorder $audit, SubscriptionLifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:pending,paid,failed,cancelled,refunded,partially_refunded'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $payment = $gateway->record($subscription, (float) $data['amount'], $data['status'], $request->user(), $data['reference'] ?? null, $data['notes'] ?? null);
        if ($payment->status === 'paid') {
            $startsAt = now();
            $subscription->forceFill([
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $lifecycle->expiryFor($subscription->plan, $startsAt->toImmutable()),
            ])->save();
        }
        $audit->record('subscription.manual_payment_recorded', $payment, $subscription->company_id, $request->user()->id, ['status' => $payment->status, 'amount' => $payment->amount], $request);

        return back();
    }
}
