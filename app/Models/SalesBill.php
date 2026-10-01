<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesBill extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'warehouse_id', 'customer_id', 'created_by', 'approved_by', 'vehicle_id', 'trip_driver_id', 'trip_origin', 'trip_destination', 'trip_distance', 'trip_odometer_in', 'trip_odometer_out', 'trip_fuel_cost', 'trip_road_fees', 'trip_loading_fees', 'journal_entry_id', 'reversal_journal_entry_id', 'internal_number', 'customer_bill_number', 'bill_date', 'external_vehicle_plate', 'external_driver_name', 'total_actual_weight', 'subtotal', 'transport_cost', 'loading_cost', 'extra_cost', 'discount', 'vat_rate', 'vat_amount', 'total', 'payment_method', 'cashbox_id', 'bank_id', 'paid_amount', 'due_date', 'status', 'reversed_by', 'approved_at', 'cancelled_at', 'notes'];

    protected $casts = ['bill_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'trip_distance' => 'decimal:3', 'trip_odometer_in' => 'decimal:3', 'trip_odometer_out' => 'decimal:3', 'trip_fuel_cost' => 'decimal:2', 'trip_road_fees' => 'decimal:2', 'trip_loading_fees' => 'decimal:2', 'total_actual_weight' => 'decimal:3', 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'vat_amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesBillLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerSupplier::class, 'customer_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BillRevision::class, 'document_id')->where('document_type', 'sales');
    }
}
