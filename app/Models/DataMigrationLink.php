<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataMigrationLink extends Model
{
    protected $fillable = ['company_id', 'run_id', 'source_system', 'source_table', 'source_id', 'document_type', 'target_type', 'target_id', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
