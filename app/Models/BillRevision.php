<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillRevision extends Model
{
    protected $fillable = ['company_id', 'document_type', 'document_id', 'user_id', 'reason', 'before_data', 'after_data'];

    protected $casts = ['before_data' => 'array', 'after_data' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
