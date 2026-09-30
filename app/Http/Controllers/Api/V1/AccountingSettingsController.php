<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanyAccountingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AccountingSettingsController extends Controller
{
    public function show(Request $request, CompanyAccountingPolicyService $policies): JsonResponse
    {
        abort_unless($request->user()->isCompanyOwner(), 403);

        return response()->json(['data' => $policies->get($this->company($request))]);
    }

    public function update(Request $request, CompanyAccountingPolicyService $policies): JsonResponse
    {
        $data = $request->validate([
            'purchase_inventory_basis' => ['required', Rule::in([
                CompanyAccountingPolicyService::FACTORY_WEIGHT,
                CompanyAccountingPolicyService::ACTUAL_WEIGHT,
            ])],
            'vat_enabled' => ['required', 'boolean'],
            'vat_rate' => ['required_if:vat_enabled,true', 'nullable', 'numeric', 'gte:0', 'lte:100'],
        ]);

        return response()->json(['data' => $policies->update($this->company($request), $request->user(), $data)]);
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }
}
