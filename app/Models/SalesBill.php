<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesBill extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'warehouse_id', 'customer_id', 'created_by', 'approved_by', 'vehicle_id', 'journal_entry_id', 'reversal_journal_entry_id', 'internal_number', 'customer_bill_number', 'bill_date', 'external_vehicle_plate', 'external_driver_name', 'total_actual_weight', 'subtotal', 'transport_cost', 'loading_cost', 'extra_cost', 'discount', 'vat_rate', 'vat_amount', 'total', 'payment_method', 'due_date', 'status', 'reversed_by', 'approved_at', 'cancelled_at', 'notes'];

    protected $casts = ['bill_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'total_actual_weight' => 'decimal:3', 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'vat_amount' => 'decimal:2'];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesBillLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerSupplier::class, 'customer_id');
    }
}
