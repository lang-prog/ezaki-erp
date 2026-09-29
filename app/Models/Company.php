<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name', 'trade_name', 'tax_number', 'phone', 'country', 'city', 'address', 'notes',
        'status', 'edition', 'settings', 'approved_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array', 'approved_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
