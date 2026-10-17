<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\SubscriptionRequest;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequestController extends PlatformController
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'open');
        abort_unless(in_array($status, ['open', 'handled'], true), 422);

        return Inertia::render('SuperAdmin/Requests', [
            'status' => $status,
            'requests' => SubscriptionRequest::query()->with(['company:id,name', 'user:id,name,email'])->where('status', $status)->latest('id')->paginate(20)->withQueryString()
                ->through(fn (SubscriptionRequest $row): array => [
                    'id' => $row->id, 'type' => $row->type, 'message' => $row->message, 'status' => $row->status, 'created_at' => $row->created_at,
                    'handled_at' => $row->handled_at, 'company' => $row->company?->name, 'user' => $row->user?->name, 'email' => $row->user?->email,
                ]),
        ]);
    }

    public function handle(SubscriptionRequest $subscriptionRequest, Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        $reason = $this->reason($request);
        if ($subscriptionRequest->status === 'open') {
            $subscriptionRequest->forceFill(['status' => 'handled', 'handled_by' => $request->user()->id, 'handled_at' => now()])->save();
            $audit->record('subscription.request_handled', $subscriptionRequest, $subscriptionRequest->company_id, $request->user()->id, [], $request, ['reason' => $reason]);
        }

        return $this->done($request);
    }
}
