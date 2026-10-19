<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationRecord extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_CREATED = 'created';

    public const STATUS_EXCLUDED = 'excluded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'run_id', 'entity_type', 'source_table', 'source_id', 'source_date', 'source_number', 'source_payload',
        'normalized_payload', 'status', 'target_type', 'target_id', 'fingerprint', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'source_payload' => 'array',
            'normalized_payload' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataMigrationRun::class, 'run_id');
    }
}
