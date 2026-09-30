<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticationController extends Controller
{
    public function show(string $type): Response
    {
        abort_unless(in_array($type, ['company', 'super_admin'], true), 404);

        return Inertia::render('Auth/Login', ['accountType' => $type]);
    }

    public function login(Request $request, AuthenticationService $authentication, string $type): RedirectResponse
    {
        abort_unless(in_array($type, ['company', 'super_admin'], true), 404);
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = $authentication->authenticate(
            $credentials['email'], $credentials['password'], $type,
            $request->ip(), $request->userAgent(),
        );

        if (! $user || ($type === 'company' && ! $user->company)) {
            return back()->withErrors(['email' => 'The supplied sign-in details are invalid.'])->onlyInput('email');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('session_version', (int) $user->session_version);

        return redirect()->intended($type === 'super_admin' ? route('super-admin.dashboard') : route('company.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
