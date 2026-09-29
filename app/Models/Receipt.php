<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $fillable = ['company_id', 'party_id', 'other_account_id', 'counterparty_type', 'cashbox_id', 'bank_id', 'journal_entry_id', 'created_by', 'receipt_number', 'receipt_date', 'amount', 'delivered_by', 'received_by', 'location', 'notes', 'status'];

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'amount' => 'decimal:2'];
    }
}
