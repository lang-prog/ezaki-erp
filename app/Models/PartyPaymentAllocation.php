<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PartyPaymentAllocation extends Model
{
    protected $fillable = [
        'company_id', 'party_id', 'bill_type', 'bill_id', 'payment_type', 'payment_id', 'amount',
        'status', 'idempotency_key', 'created_by', 'reversal_of_id', 'reversed_by', 'reversed_at',
        'reversal_reason', 'reversal_journal_entry_id', 'allocated_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CustomerSupplier::class, 'party_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
