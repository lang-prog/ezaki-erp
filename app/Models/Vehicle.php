<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'plate', 'type', 'make_model', 'year', 'ownership', 'capacity', 'fuel_type', 'status', 'license_expires_at', 'insurance_expires_at', 'notes'];

    protected $casts = ['license_expires_at' => 'date', 'insurance_expires_at' => 'date', 'capacity' => 'decimal:3'];

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
