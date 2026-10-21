<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['run_id', 'action', 'from_state', 'to_state', 'actor_id', 'reason', 'counts', 'error'];

    protected function casts(): array
    {
        return ['counts' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataMigrationRun::class, 'run_id');
    }
}
