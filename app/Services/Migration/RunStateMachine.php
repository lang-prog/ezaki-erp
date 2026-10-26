<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\DataMigrationEvent;
use App\Models\DataMigrationLink;
use App\Models\DataMigrationRun;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persisted state machine with run-level locking; every change is stored as an event and audited. */
final class RunStateMachine
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'uploaded' => ['parsing'],
        'parsing' => ['parsed', 'failed_terminal', 'failed_recoverable'],
        'parsed' => ['review', 'parsing', 'failed_recoverable'],
        'review' => ['masters_preflight', 'party_openings_preflight', 'parsing'],
        'masters_preflight' => ['masters_ready', 'review', 'parsing'],
        'masters_ready' => ['masters_created', 'review', 'parsing'],
        'masters_created' => ['openings_preflight', 'parsing'],
        'openings_preflight' => ['openings_ready', 'masters_created', 'parsing'],
        'openings_ready' => ['openings_posted', 'masters_created', 'parsing'],
        'openings_posted' => ['documents_ready', 'masters_created'],
        'party_openings_preflight' => ['party_openings_ready', 'review'],
        'party_openings_ready' => ['party_openings_ready', 'review', 'party_openings_posted'],
        'party_openings_posted' => ['phase1_completed', 'review'],
        'phase1_completed' => ['signed_off', 'review'],
        'documents_ready' => ['executing', 'documents_ready'],
        'executing' => ['completed', 'completed_with_review', 'failed_recoverable', 'executing'],
        'failed_recoverable' => ['executing', 'documents_ready', 'parsing', 'review'],
        'completed_with_review' => ['executing', 'documents_ready', 'completed'],
        'completed' => ['signed_off', 'documents_ready'],
        'signed_off' => ['archived'],
        'failed_terminal' => ['archived'],
        'archived' => [],
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /** @param array<string, mixed> $counts */
    public function transition(DataMigrationRun $run, string $to, string $action, ?User $actor = null, ?string $reason = null, array $counts = [], ?string $error = null): DataMigrationRun
    {
        $from = $run->status;
        if (! $this->can($from, $to)) {
            throw self::refuse('illegal_transition', "Cannot move a migration from {$from} to {$to}.", ['from' => $from, 'to' => $to]);
        }
        $run->forceFill(['status' => $to, 'state_version' => (int) $run->state_version + 1, 'error_message' => $error !== null ? mb_substr($error, 0, 2000) : null])->save();
        $this->record($run, $action, $from, $to, $actor, $reason, $counts, $error);

        return $run;
    }

    /** @param array<string, mixed> $counts */
    public function record(DataMigrationRun $run, string $action, ?string $from, ?string $to, ?User $actor, ?string $reason, array $counts = [], ?string $error = null): void
    {
        DataMigrationEvent::query()->create(['run_id' => $run->id, 'action' => $action, 'from_state' => $from, 'to_state' => $to, 'actor_id' => $actor?->id, 'reason' => $reason, 'counts' => $counts ?: null, 'error' => $error !== null ? mb_substr($error, 0, 2000) : null]);
        $this->audit->record('platform.data_migration.'.$action, $run, (int) $run->company_id, $actor?->id, ['from' => $from, 'to' => $to, 'counts' => $counts, 'error' => $error !== null ? mb_substr($error, 0, 300) : null], null, [
            'severity' => $error !== null ? 'critical' : 'warning', 'reason' => $reason ?? "Data migration {$action}",
        ]);
    }

    /**
     * Runs $work while holding the run lock. The persisted state is re-read after the lock is taken and checked
     * against $allowedFrom, so duplicate or concurrent requests are refused instead of repeating effects.
     *
     * @param  list<string>  $allowedFrom
     * @param  callable(DataMigrationRun): mixed  $work
     */
    public function locked(int $runId, string $action, array $allowedFrom, callable $work): mixed
    {
        $token = Str::random(40);
        $run = DB::transaction(function () use ($runId, $action, $allowedFrom, $token): DataMigrationRun {
            $run = DataMigrationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if ($run->lock_token !== null && $run->lock_expires_at?->isFuture()) {
                throw self::refuse('migration_busy', "Another {$run->lock_action} action is still running for this migration.", ['lock_action' => $run->lock_action]);
            }
            if (! in_array($run->status, $allowedFrom, true)) {
                throw self::refuse('illegal_state', "The action {$action} is not allowed while the migration is {$run->status}.", ['status' => $run->status, 'allowed' => $allowedFrom]);
            }
            $run->forceFill(['lock_token' => $token, 'lock_action' => $action, 'lock_expires_at' => now()->addSeconds((int) config('migration.lock_seconds', 900))])->save();

            return $run;
        });
        try {
            return $work($run->fresh());
        } finally {
            DataMigrationRun::query()->whereKey($runId)->where('lock_token', $token)->update(['lock_token' => null, 'lock_action' => null, 'lock_expires_at' => null]);
        }
    }

    public function hasFinancialEffects(DataMigrationRun $run): bool
    {
        return DataMigrationLink::query()->where('run_id', $run->id)->exists();
    }

    /** @param array<string, mixed> $extra */
    public static function refuse(string $code, string $message, array $extra = [], int $status = 409): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code] + $extra, $status));
    }
}
