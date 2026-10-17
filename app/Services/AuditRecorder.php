<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditRecorder
{
    private const SENSITIVE = '/password|passwd|secret|token|signature|private|recovery|otp|mfa_code|license_key|key_hash|authorization/i';

    /**
     * @param  array{reason?: ?string, before?: ?array, after?: ?array, severity?: string}  $context
     */
    public function record(string $event, ?Model $subject, ?int $companyId, ?int $userId, array $metadata = [], ?Request $request = null, array $context = []): AuditLog
    {
        $request ??= app()->bound('request') ? request() : null;
        $actor = $userId ? User::query()->whereKey($userId)->value('account_type') : null;

        return AuditLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'metadata' => $this->redact($metadata),
            'ip_address' => $request?->ip(),
            'actor_type' => $actor ?? ($userId === null ? 'system' : null),
            'severity' => $context['severity'] ?? 'info',
            'reason' => isset($context['reason']) ? Str::limit((string) $context['reason'], 1000, '') : null,
            'before' => isset($context['before']) ? $this->redact($context['before']) : null,
            'after' => isset($context['after']) ? $this->redact($context['after']) : null,
            'request_id' => $request?->attributes->get('request_id'),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    public function redact(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
