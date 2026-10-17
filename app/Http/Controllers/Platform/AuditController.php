<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends PlatformController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:100'], 'company_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:100'], 'severity' => ['nullable', 'in:info,warning,critical'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $logs = AuditLog::query()->with(['user:id,name,account_type', 'company:id,name'])
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', 'like', $this->like($v)))
            ->when($filters['company_id'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['subject'] ?? null, fn ($q, $v) => $q->where('subject_type', 'like', $this->like($v)))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')->paginate(30)->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id, 'event' => $log->event, 'severity' => $log->severity, 'created_at' => $log->created_at,
                'actor' => $log->user?->name, 'actor_type' => $log->actor_type, 'company' => $log->company?->name, 'company_id' => $log->company_id,
                'subject' => $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : null,
                'reason' => $log->reason, 'before' => $log->before, 'after' => $log->after, 'request_id' => $log->request_id, 'ip_address' => $log->ip_address,
            ]);

        return Inertia::render('SuperAdmin/Audit', ['logs' => $logs, 'filters' => $filters]);
    }
}
