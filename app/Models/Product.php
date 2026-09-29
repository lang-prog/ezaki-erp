<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['company_id', 'product_type_id', 'diameter_id', 'sku', 'name', 'unit', 'minimum_stock', 'status', 'archived_at'];

    protected function casts(): array
    {
        return ['minimum_stock' => 'decimal:3', 'archived_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function diameter(): BelongsTo
    {
        return $this->belongsTo(Diameter::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
