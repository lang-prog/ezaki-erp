<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRecord extends Model
{
    protected $fillable = ['company_id', 'vehicle_id', 'maintenance_type', 'issue', 'parts', 'labor_cost', 'parts_cost', 'vendor', 'cost', 'starts_on', 'ends_on', 'next_due_on', 'vehicle_status', 'status', 'journal_entry_id'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'next_due_on' => 'date', 'cost' => 'decimal:2'];
}
