<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMigrationRun extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PARSING = 'parsing';

    public const STATUS_PARSED = 'parsed';

    public const STATUS_REVIEW = 'review';

    public const STATUS_MASTERS_PREFLIGHT = 'masters_preflight';

    public const STATUS_MASTERS_READY = 'masters_ready';

    public const STATUS_MASTERS_CREATED = 'masters_created';

    public const STATUS_OPENINGS_PREFLIGHT = 'openings_preflight';

    public const STATUS_OPENINGS_READY = 'openings_ready';

    public const STATUS_OPENINGS_POSTED = 'openings_posted';

    public const STATUS_PARTY_OPENINGS_PREFLIGHT = 'party_openings_preflight';

    public const STATUS_PARTY_OPENINGS_READY = 'party_openings_ready';

    public const STATUS_PARTY_OPENINGS_POSTED = 'party_openings_posted';

    public const STATUS_PHASE1_COMPLETED = 'phase1_completed';

    public const STATUS_DOCUMENTS_READY = 'documents_ready';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_REVIEW = 'completed_with_review';

    public const STATUS_FAILED_RECOVERABLE = 'failed_recoverable';

    public const STATUS_FAILED_TERMINAL = 'failed_terminal';

    public const STATUS_SIGNED_OFF = 'signed_off';

    public const STATUS_ARCHIVED = 'archived';

    /** Statuses in which mappings may still be corrected by a human. */
    public const MAPPING_EDITABLE = ['review', 'masters_ready', 'masters_created', 'openings_ready', 'openings_posted', 'party_openings_preflight', 'party_openings_ready', 'documents_ready', 'failed_recoverable'];

    /** Statuses that carry irreversible financial effects. */
    public const FINANCIAL = ['openings_posted', 'party_openings_posted', 'phase1_completed', 'documents_ready', 'executing', 'completed', 'completed_with_review', 'failed_recoverable', 'signed_off', 'archived'];

    protected $fillable = [
        'company_id', 'created_by', 'source_system', 'source_name', 'source_disk', 'source_path', 'source_hash', 'source_bytes',
        'source_retention_until', 'source_removed_at', 'documents_from', 'documents_to', 'opening_balance_as_of', 'mode', 'status',
        'options', 'summary', 'integrity', 'preflight', 'policy', 'error_message', 'started_at', 'completed_at',
        'gap_acknowledged_at', 'gap_acknowledged_reason', 'signed_off_by', 'signed_off_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'documents_from' => 'date',
            'documents_to' => 'date',
            'opening_balance_as_of' => 'date',
            'source_retention_until' => 'date',
            'options' => 'array',
            'summary' => 'array',
            'integrity' => 'array',
            'preflight' => 'array',
            'policy' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'source_removed_at' => 'datetime',
            'gap_acknowledged_at' => 'datetime',
            'signed_off_at' => 'datetime',
            'archived_at' => 'datetime',
            'lock_expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(DataMigrationMapping::class, 'run_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(DataMigrationRecord::class, 'run_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DataMigrationEvent::class, 'run_id');
    }

    public function hasFinancialEffects(): bool
    {
        return in_array($this->status, self::FINANCIAL, true);
    }

    public function policy(string $key, mixed $default = null): mixed
    {
        return data_get($this->policy ?? [], $key, $default);
    }
}
