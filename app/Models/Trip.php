<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trip extends Model
{
    protected $fillable = ['company_id', 'vehicle_id', 'driver_id', 'branch_id', 'trip_type', 'origin', 'destination', 'trip_at', 'bill_type', 'bill_id', 'distance', 'odometer_in', 'odometer_out', 'fuel_cost', 'road_fees', 'loading_fees', 'revenue', 'status', 'notes'];

    protected $casts = ['trip_at' => 'datetime', 'distance' => 'decimal:3', 'fuel_cost' => 'decimal:2', 'road_fees' => 'decimal:2', 'loading_fees' => 'decimal:2', 'revenue' => 'decimal:2'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
