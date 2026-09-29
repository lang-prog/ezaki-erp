<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = ['company_id', 'name', 'phone', 'license_number', 'license_type', 'license_expires_at', 'status', 'notes'];

    protected $casts = ['license_expires_at' => 'date'];
}
