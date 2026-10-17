<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\Plan;
use App\Models\RegistrationRequest;
use App\Services\AuditRecorder;
use App\Services\CompanyProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends PlatformController
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');
        abort_unless(in_array($status, ['pending', 'rejected', 'approved'], true), 422);

        return Inertia::render('SuperAdmin/Registrations', [
            'status' => $status,
            'registrations' => RegistrationRequest::query()->where('status', $status)->latest('id')->paginate(20)->withQueryString()
                ->through(fn (RegistrationRequest $row): array => [
                    'id' => $row->id, 'company_name' => $row->company_name, 'owner_name' => $row->owner_name, 'email' => $row->email,
                    'phone' => $row->phone, 'plan_id' => $row->plan_id, 'status' => $row->status, 'verified_at' => $row->verified_at,
                    'created_at' => $row->created_at, 'review_notes' => $row->review_notes,
                ]),
            'plans' => Plan::query()->pluck('name', 'id'),
        ]);
    }

    public function approve(RegistrationRequest $registration, Request $request, CompanyProvisioningService $provisioning): JsonResponse|RedirectResponse
    {
        $provisioning->approve($registration, $request->user(), Plan::query()->findOrFail($registration->plan_id));

        return $this->done($request);
    }

    public function reject(RegistrationRequest $registration, Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $reason = $this->reason($request);
        abort_unless($registration->status === 'pending' && $registration->verified_at, 409);
        $registration->forceFill(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $reason])->save();
        $audit->record('registration.rejected', $registration, null, $request->user()->id, [], $request, ['reason' => $reason, 'after' => ['status' => 'rejected']]);

        return $this->done($request);
    }
}
