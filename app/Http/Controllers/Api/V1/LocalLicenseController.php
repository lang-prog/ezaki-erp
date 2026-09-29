<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LocalLicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalLicenseController extends Controller
{
    public function activate(Request $request, LocalLicenseService $licenses): JsonResponse
    {
        abort_unless(config('licensing.edition') === 'local', 404);
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:4096'],
            'installation_id' => ['required', 'string', 'max:255'],
            'customer_binding' => ['required', 'string', 'max:255'],
        ]);
        $license = $licenses->activate($data['license_key'], $data['installation_id'], $data['customer_binding']);

        return response()->json(['activated' => true, 'activated_at' => $license->activated_at]);
    }
}
