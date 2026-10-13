<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = ['company_id', 'from_warehouse_id', 'to_warehouse_id', 'created_by', 'status', 'transfer_number', 'request_hash', 'reversal_of_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'notes', 'transferred_at'];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }
}
