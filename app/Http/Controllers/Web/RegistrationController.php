<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\RegistrationRequest as CompanyRegistrationRequest;
use App\Models\User;
use App\Notifications\RegistrationVerificationNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function show(): Response
    {
        abort_unless(PlatformSetting::boolean('self_registration_open', config('registration.open')), 404);

        return Inertia::render('Auth/Register', ['plans' => Plan::query()->where('is_active', true)->get(['id', 'name', 'duration', 'price'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PlatformSetting::boolean('self_registration_open', config('registration.open')), 404);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'coupon_code' => ['nullable', 'string', 'exists:coupons,code'],
            'terms_accepted' => ['accepted'],
        ]);

        $planId = (int) $data['plan_id'];
        $couponCode = $data['coupon_code'] ?? null;
        unset($data['coupon_code']);

        try {
            $registration = DB::transaction(function () use ($data, $planId, $couponCode): ?CompanyRegistrationRequest {
                $plan = Plan::query()->lockForUpdate()->findOrFail($planId);
                abort_unless($plan->is_active, 422, 'The selected plan is no longer active.');
                $coupon = $couponCode ? Coupon::query()->where('code', $couponCode)->lockForUpdate()->first() : null;
                abort_if($couponCode && ! $coupon, 422, 'The selected coupon is not available.');
                abort_if($coupon && ! $coupon->isAvailableFor($plan), 422, 'The selected coupon is not available.');

                if (User::query()->where('email', $data['email'])->exists()
                    || CompanyRegistrationRequest::query()->where('email', $data['email'])->exists()) {
                    return null;
                }

                return CompanyRegistrationRequest::query()->create([
                    ...$data,
                    'plan_id' => $plan->id,
                    'coupon_id' => $coupon?->id,
                    'terms_accepted' => true,
                    'status' => 'unverified',
                    'verification_expires_at' => now()->addHour(),
                ]);
            });
            if ($registration) {
                $url = URL::temporarySignedRoute('registration.verify', now()->addHour(), ['registration' => $registration->id]);
                Notification::route('mail', $registration->email)->notify(new RegistrationVerificationNotification($url));
            }
        } catch (QueryException) {
            // Duplicate emails receive the same public response and no message is sent.
        }

        return back()->with('status', 'If the request can be accepted, a verification email will be sent.');
    }

    public function verify(Request $request, CompanyRegistrationRequest $registration): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        if ($registration->status === 'unverified' && $registration->verification_expires_at?->isFuture()) {
            $registration->forceFill(['status' => 'pending', 'verified_at' => now()])->save();
        }

        return redirect()->route('home')->with('status', 'Email verification has been processed.');
    }
}
