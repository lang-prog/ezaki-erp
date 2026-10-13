<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    protected $fillable = ['transfer_id', 'company_id', 'product_id', 'quantity', 'transferred_value', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'transferred_value' => 'decimal:2', 'unit_cost' => 'decimal:6'];
    }
}
