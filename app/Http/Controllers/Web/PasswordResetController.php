<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\SessionRevocationService;
use App\Support\PlatformPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => $data['email']]);

        // Identical response for known, unknown and throttled addresses so accounts cannot be enumerated.
        return back()->with('status', 'Password reset link sent.');
    }

    public function form(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request, SessionRevocationService $sessions): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ]);
        $broker = Password::broker();
        $candidate = $broker->getUser(['email' => $data['email']]);
        // The stronger platform policy is revealed only to someone holding a valid token for that account.
        if ($candidate && $candidate->account_type === 'super_admin' && $broker->tokenExists($candidate, $data['token']) && ! PlatformPassword::passes($data['password'])) {
            return back()->withErrors(['password' => __('platform.password_policy')]);
        }
        $status = Password::reset($data, function (User $user, string $password) use ($sessions): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            $sessions->revoke($user);
            if ($user->account_type === 'super_admin') {
                app(AuditRecorder::class)->record('platform.password_reset', $user, null, $user->id, [], null, ['severity' => 'warning']);
            }
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This password reset link is invalid or has expired.']);
        }

        return redirect()->route('login')->with('status', 'Password reset successfully.');
    }
}
