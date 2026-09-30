<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CompanyProfileController extends Controller
{
    public function updatePassword(Request $request, SessionRevocationService $sessions): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ]);
        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->user()->tokens()->delete();
        $sessions->revoke($request->user());

        return back()->with('status', 'Password updated.');
    }
}
