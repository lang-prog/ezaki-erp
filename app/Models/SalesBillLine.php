<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesBillLine extends Model
{
    protected $fillable = ['sales_bill_id', 'company_id', 'product_id', 'actual_weight', 'packages', 'unit_price', 'line_total', 'notes'];

    protected $casts = ['actual_weight' => 'decimal:3', 'packages' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
