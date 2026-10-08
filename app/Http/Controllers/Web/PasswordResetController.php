<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionRevocationService;
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
        $status = Password::sendResetLink(['email' => $data['email']]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors(['email' => 'We could not send a reset link for this email.']);
        }

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
        $status = Password::reset($data, function (User $user, string $password) use ($sessions): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            $sessions->revoke($user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This password reset link is invalid or has expired.']);
        }

        return redirect()->route('login')->with('status', 'Password reset successfully.');
    }
}
