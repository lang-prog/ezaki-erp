<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class CompanyProfileController extends Controller
{
    public function companySettings(Request $request): Response
    {
        abort_unless($request->user()->isCompanyOwner(), 403);
        $company = $request->attributes->get('company');

        return Inertia::render('Company/CompanySettings', ['company' => $company->only(['id', 'name', 'trade_name', 'tax_number', 'phone', 'country', 'city', 'address', 'notes'])]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isCompanyOwner(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $request->attributes->get('company')->update($data);

        return back()->with('status', 'Company details updated.');
    }

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
