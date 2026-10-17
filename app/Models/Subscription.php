<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['company_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'overrides', 'superseded_at', 'previous_subscription_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'overrides' => 'array', 'superseded_at' => 'datetime'];
    }

    /** The authoritative subscription is the newest one that has not been superseded by a plan change. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at')->orderByDesc('starts_at')->orderByDesc('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
