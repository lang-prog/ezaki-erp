<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['company_id', 'warehouse_id', 'product_id', 'user_id', 'movement_type', 'quantity_delta', 'source_type', 'source_id', 'notes'];

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:3'];
    }
}
