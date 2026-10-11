<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentVoucher extends Model
{
    protected $fillable = ['company_id', 'party_id', 'other_account_id', 'counterparty_type', 'cashbox_id', 'bank_id', 'journal_entry_id', 'created_by', 'voucher_number', 'voucher_date', 'amount', 'paid_by', 'received_by', 'location', 'notes', 'status'];

    protected function casts(): array
    {
        return ['voucher_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CustomerSupplier::class, 'party_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
