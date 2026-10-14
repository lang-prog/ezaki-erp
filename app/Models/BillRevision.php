<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillRevision extends Model
{
    protected $fillable = [
        'company_id',
        'document_type',
        'document_id',
        'user_id',
        'reason',
        'before_data',
        'after_data',
        'revision_chain_id',
        'original_journal_entry_id',
        'reversal_journal_entry_id',
        'replacement_journal_entry_id',
        'original_amount',
        'revised_amount',
        'delta_amount',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
        'original_amount' => 'decimal:2',
        'revised_amount' => 'decimal:2',
        'delta_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function originalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'original_journal_entry_id');
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function replacementJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'replacement_journal_entry_id');
    }
}
