<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEvent extends Model
{
    protected $fillable = [
        'company_id', 'subscription_id', 'type', 'from_plan_id', 'to_plan_id', 'from_status', 'to_status',
        'from_starts_at', 'from_ends_at', 'to_starts_at', 'to_ends_at', 'payment_id', 'actor_id', 'reason', 'request_id',
    ];

    protected function casts(): array
    {
        return ['from_starts_at' => 'datetime', 'from_ends_at' => 'datetime', 'to_starts_at' => 'datetime', 'to_ends_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
