<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    protected $fillable = ['company_id', 'warehouse_id', 'product_id', 'quantity', 'inventory_value'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'inventory_value' => 'decimal:2'];
    }
}
