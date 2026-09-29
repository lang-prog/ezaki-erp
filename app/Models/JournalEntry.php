<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $fillable = ['company_id', 'fiscal_period_id', 'created_by', 'reversal_of_id', 'reversed_by_id', 'entry_number', 'entry_date', 'description', 'source_type', 'source_id', 'status', 'posted_at'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
