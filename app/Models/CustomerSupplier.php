<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSupplier extends Model
{
    protected $fillable = ['company_id', 'account_id', 'parent_account_id', 'name', 'email', 'phone', 'tax_number', 'address', 'is_customer', 'is_supplier', 'opening_balance', 'credit_limit', 'status'];

    protected function casts(): array
    {
        return ['is_customer' => 'boolean', 'is_supplier' => 'boolean', 'opening_balance' => 'decimal:2', 'credit_limit' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
