<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\LocalLicense;
use App\Services\AuditRecorder;
use App\Services\LocalLicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LicenseController extends PlatformController
{
    public function index(): Response
    {
        $licenses = LocalLicense::query()->with(['company:id,name', 'audits' => fn ($query) => $query->latest('id')->limit(5)])->latest('id')->paginate(20)
            ->through(fn (LocalLicense $license): array => [
                'id' => $license->id, 'company' => $license->company?->name, 'status' => $license->status,
                'installation_id' => $license->installation_id, 'customer_binding' => $license->customer_binding,
                'activated_at' => $license->activated_at, 'last_verified_at' => $license->last_verified_at,
                'expires_at' => $license->signed_payload['expires_at'] ?? null,
                'history' => $license->audits->map(fn ($audit): array => ['action' => $audit->action, 'at' => $audit->created_at])->all(),
            ]);

        return Inertia::render('SuperAdmin/Licenses', ['licenses' => $licenses]);
    }

    public function enable(LocalLicense $license, Request $request, LocalLicenseService $licenses, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        return $this->change($license, $request, $audit, 'enabled', fn () => $licenses->enable($license, $request->user()->id));
    }

    public function disable(LocalLicense $license, Request $request, LocalLicenseService $licenses, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        return $this->change($license, $request, $audit, 'disabled', fn () => $licenses->disable($license, $request->user()->id));
    }

    public function revoke(LocalLicense $license, Request $request, LocalLicenseService $licenses, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        return $this->change($license, $request, $audit, 'revoked', fn () => $licenses->revoke($license, $request->user()->id));
    }

    public function transfer(LocalLicense $license, Request $request, LocalLicenseService $licenses, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'string', 'max:255'],
            'customer_binding' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'license_key' => ['required', 'string', 'max:4096'],
        ]);
        $reason = $this->reason($request);
        $before = ['installation_id' => $license->installation_id, 'company_id' => $license->company_id, 'status' => $license->status];
        $updated = $licenses->transfer($license, $data['installation_id'], $data['customer_binding'], isset($data['company_id']) ? (int) $data['company_id'] : null, $data['license_key'], $request->user()->id);
        $audit->record('platform.license_transferred', $updated, $updated->company_id, $request->user()->id, [], $request, [
            'reason' => $reason, 'severity' => 'warning', 'before' => $before,
            'after' => ['installation_id' => $updated->installation_id, 'company_id' => $updated->company_id, 'status' => $updated->status],
        ]);

        return $this->done($request);
    }

    private function change(LocalLicense $license, Request $request, AuditRecorder $audit, string $to, callable $apply): JsonResponse|RedirectResponse
    {
        $reason = $this->reason($request);
        $before = $license->status;
        $apply();
        $audit->record('platform.license_'.$to, $license, $license->company_id, $request->user()->id, [], $request, [
            'reason' => $reason, 'severity' => $to === 'revoked' ? 'warning' : 'info', 'before' => ['status' => $before], 'after' => ['status' => $to],
        ]);

        return $this->done($request);
    }
}
