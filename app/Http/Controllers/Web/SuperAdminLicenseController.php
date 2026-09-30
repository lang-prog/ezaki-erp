<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LocalLicense;
use App\Services\LocalLicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SuperAdminLicenseController extends Controller
{
    public function enable(LocalLicense $license, Request $request, LocalLicenseService $licenses): RedirectResponse
    {
        $licenses->enable($license, $request->user()->id);

        return back()->with('status', 'License enabled.');
    }

    public function disable(LocalLicense $license, Request $request, LocalLicenseService $licenses): RedirectResponse
    {
        $licenses->disable($license, $request->user()->id);

        return back()->with('status', 'License disabled.');
    }

    public function revoke(LocalLicense $license, Request $request, LocalLicenseService $licenses): RedirectResponse
    {
        $licenses->revoke($license, $request->user()->id);

        return back()->with('status', 'License revoked.');
    }

    public function transfer(LocalLicense $license, Request $request, LocalLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'string', 'max:255'],
            'customer_binding' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);
        $licenses->transfer(
            $license,
            $data['installation_id'],
            $data['customer_binding'],
            isset($data['company_id']) ? (int) $data['company_id'] : null,
            $request->user()->id,
        );

        return back()->with('status', 'License transferred.');
    }
}
