<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only from application code; retention pruning uses the query builder explicitly (platform:prune-logs). */
class AuditLog extends Model
{
    protected $fillable = ['company_id', 'user_id', 'event', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'actor_type', 'severity', 'reason', 'before', 'after', 'request_id', 'user_agent'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit records are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit records are append-only.'));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Audit records are append-only.');
            }

            public function delete()
            {
                throw new LogicException('Audit records are append-only.');
            }

            public function forceDelete()
            {
                throw new LogicException('Audit records are append-only.');
            }

            public function upsert(array $values, $uniqueBy, $update = null)
            {
                throw new LogicException('Audit records are append-only.');
            }
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
