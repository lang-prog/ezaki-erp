<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMigrationRun extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_REVIEW = 'review';

    public const STATUS_READY = 'ready';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'created_by', 'source_system', 'source_name', 'source_disk', 'source_path', 'source_hash',
        'documents_from', 'documents_to', 'opening_balance_as_of', 'mode', 'status', 'options', 'summary',
        'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'documents_from' => 'date',
            'documents_to' => 'date',
            'opening_balance_as_of' => 'date',
            'options' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
}
