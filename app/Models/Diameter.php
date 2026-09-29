<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diameter extends Model
{
    protected $fillable = ['company_id', 'millimeters'];

    protected function casts(): array
    {
        return ['millimeters' => 'decimal:2'];
    }
}
