<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationMapping extends Model
{
    protected $fillable = [
        'run_id', 'entity_type', 'source_table', 'source_key', 'target_type', 'target_id', 'decision', 'confidence', 'payload',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'decimal:4', 'payload' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataMigrationRun::class, 'run_id');
    }
}
