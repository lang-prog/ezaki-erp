<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\AuditRecorder;
use App\Services\MfaService;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MfaController extends PlatformController
{
    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasMfa() || $request->session()->has('platform_mfa_at')) {
            return redirect()->route('super-admin.dashboard');
        }

        return Inertia::render('SuperAdmin/MfaChallenge');
    }

    public function verify(Request $request, MfaService $mfa): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = $request->user();
        if (! $mfa->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => $mfa->tooManyAttempts($user) ? 'Too many attempts. Try again in a minute.' : 'The verification code is invalid.']);
        }
        $request->session()->regenerate();
        $request->session()->put(['platform_mfa_at' => time(), 'platform_stepup_at' => time()]);

        return redirect()->intended(route('super-admin.dashboard'));
    }

    public function security(Request $request, MfaService $mfa): Response
    {
        $user = $request->user();
        $pending = null;
        if (! $user->hasMfa()) {
            if (! $request->session()->has('platform_mfa_pending')) {
                $request->session()->put('platform_mfa_pending', $mfa->newPendingSecret($user)['secret']);
            }
            $secret = (string) $request->session()->get('platform_mfa_pending');
            $pending = ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, (string) config('app.name', 'E-Zaki ERP'))];
        }

        return Inertia::render('SuperAdmin/Security', [
            'mfa' => ['enabled' => $user->hasMfa(), 'enforced' => $mfa->isEnforced(), 'enforce_from' => config('security.platform.mfa_enforce_from'), 'remaining_codes' => $mfa->remainingRecoveryCodes($user)],
            'pending' => $pending,
            'recoveryCodes' => $request->session()->pull('platform_mfa_codes'),
        ]);
    }

    public function confirm(Request $request, MfaService $mfa): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $secret = (string) $request->session()->get('platform_mfa_pending', '');
        $codes = $secret === '' ? null : $mfa->confirmEnrollment($request->user(), $secret, $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid.']);
        }
        $request->session()->forget('platform_mfa_pending');
        $request->session()->put(['platform_mfa_at' => time(), 'platform_stepup_at' => time(), 'platform_mfa_codes' => $codes]);

        return redirect()->route('super-admin.security');
    }

    public function regenerate(Request $request, MfaService $mfa): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->hasMfa(), 409);
        $request->session()->put('platform_mfa_codes', $mfa->regenerateRecoveryCodes($request->user()));

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('super-admin.security');
    }

    public function disable(Request $request, MfaService $mfa): RedirectResponse|JsonResponse
    {
        abort_if($mfa->isEnforced(), 409, 'MFA is mandatory and cannot be disabled.');
        $reason = $this->reason($request);
        $mfa->disable($request->user(), null, $reason);
        $request->session()->forget('platform_mfa_at');

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('super-admin.security');
    }

    public function stepUp(Request $request, MfaService $mfa, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:32']]);
        $user = $request->user();
        $key = 'platform-stepup:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many attempts. Try again in a minute.']);
        }
        $passwordOk = Hash::check($data['password'], $user->password);
        $codeOk = ! $user->hasMfa() || ($passwordOk && $mfa->verify($user, (string) ($data['code'] ?? ''), 'step_up'));
        if (! $passwordOk || ! $codeOk) {
            RateLimiter::hit($key, 60);
            $audit->record('platform.step_up_failed', $user, null, $user->id, [], $request, ['severity' => 'warning']);
            throw ValidationException::withMessages([$passwordOk ? 'code' : 'password' => 'The confirmation details are invalid.']);
        }
        RateLimiter::clear($key);
        $request->session()->put('platform_stepup_at', time());
        $audit->record('platform.step_up', $user, null, $user->id, [], $request);

        return $this->done($request);
    }
}
