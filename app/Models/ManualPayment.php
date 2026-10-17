<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualPayment extends Model
{
    protected $fillable = [
        'subscription_id', 'recorded_by', 'amount', 'currency', 'status', 'reference', 'notes', 'paid_at',
        'idempotency_key', 'reason', 'previous_ends_at', 'new_ends_at', 'amount_due', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'amount_due' => 'decimal:2', 'paid_at' => 'datetime',
            'previous_ends_at' => 'datetime', 'new_ends_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
