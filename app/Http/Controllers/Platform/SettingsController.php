<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\PlatformSetting;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends PlatformController
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Settings', [
            'registrationOpen' => PlatformSetting::boolean('self_registration_open', config('registration.open')),
            'security' => [
                'mfa_required' => (bool) config('security.platform.mfa_required'), 'mfa_enforce_from' => config('security.platform.mfa_enforce_from'),
                'step_up_minutes' => (int) config('security.platform.step_up_minutes'), 'audit_retention_days' => (int) config('security.audit.retention_days'),
                'login_log_retention_days' => (int) config('security.audit.login_log_retention_days'),
            ],
        ]);
    }

    public function updateRegistration(Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $reason = $request->input('reason');
        $before = PlatformSetting::boolean('self_registration_open', config('registration.open'));
        PlatformSetting::query()->updateOrCreate(['key' => 'self_registration_open'], ['value' => ['enabled' => (bool) $data['enabled']]]);
        $audit->record('platform.registration_setting_changed', null, null, $request->user()->id, [], $request, [
            'reason' => $reason, 'before' => ['enabled' => $before], 'after' => ['enabled' => (bool) $data['enabled']],
        ]);

        return $this->done($request);
    }
}
