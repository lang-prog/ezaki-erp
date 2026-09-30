<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBillLine extends Model
{
    protected $fillable = ['purchase_bill_id', 'company_id', 'product_id', 'factory_weight', 'actual_weight', 'packages', 'unit_price', 'line_total', 'inventory_value', 'notes'];

    protected $casts = ['factory_weight' => 'decimal:3', 'actual_weight' => 'decimal:3', 'packages' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'inventory_value' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
