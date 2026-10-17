<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionRequest;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Company-side contact/renewal requests: stored, audited and surfaced to platform staff (no silent success). */
class CompanySubscriptionRequestController extends Controller
{
    public function store(Request $request, AuditRecorder $audit, string $type): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($type, ['contact', 'renew'], true), 404);
        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);
        $company = $request->attributes->get('company');

        [$record, $created] = DB::transaction(function () use ($company, $request, $type, $data): array {
            $open = SubscriptionRequest::query()->where('company_id', $company->id)->where('type', $type)->where('status', 'open')->lockForUpdate()->first();
            if ($open) {
                return [$open, false];
            }

            return [SubscriptionRequest::query()->create(['company_id' => $company->id, 'user_id' => $request->user()->id, 'type' => $type, 'message' => $data['message'] ?? null]), true];
        });
        if ($created) {
            $audit->record('subscription.request_created', $record, $company->id, $request->user()->id, ['type' => $type], $request);
        }
        $message = __($created ? 'platform.request_sent' : 'platform.request_exists');

        return $request->expectsJson()
            ? response()->json(['status' => $type === 'renew' ? 'renewal_requested' : 'contact_requested', 'created' => $created, 'message' => $message], $created ? 201 : 200)
            : back()->with('status', $message);
    }
}
