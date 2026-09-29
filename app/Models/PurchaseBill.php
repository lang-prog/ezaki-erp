<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseBill extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'warehouse_id', 'supplier_id', 'created_by', 'approved_by', 'vehicle_id', 'journal_entry_id', 'reversal_journal_entry_id', 'internal_number', 'supplier_bill_number', 'supplier_bill_date', 'warehouse_entry_date', 'external_vehicle_plate', 'external_driver_name', 'total_factory_weight', 'total_actual_weight', 'total_packages', 'subtotal', 'transport_cost', 'loading_cost', 'extra_cost', 'discount', 'vat_rate', 'vat_amount', 'total', 'payment_method', 'due_date', 'status', 'reversed_by', 'approved_at', 'cancelled_at', 'notes'];

    protected $casts = ['supplier_bill_date' => 'date', 'warehouse_entry_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'total_factory_weight' => 'decimal:3', 'total_actual_weight' => 'decimal:3', 'total_packages' => 'decimal:3', 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'vat_amount' => 'decimal:2'];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(CustomerSupplier::class, 'supplier_id');
    }
}
