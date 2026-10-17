<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    // Legacy duration / duration_days stay as a compatibility mirror; the catalog link is authoritative when present.
    protected $fillable = ['code', 'name', 'duration', 'duration_days', 'subscription_duration_id', 'price', 'limits', 'features', 'is_active'];

    protected static function booted(): void
    {
        // Plans created with a plain legacy code (monthly, six_months, yearly, lifetime) join the catalog automatically.
        static::creating(function (Plan $plan): void {
            if ($plan->subscription_duration_id !== null || ! in_array($plan->duration, ['monthly', 'six_months', 'yearly', 'lifetime'], true)) {
                return;
            }
            if ($plan->duration !== 'lifetime' && $plan->duration_days !== null) {
                return;
            }
            $plan->subscription_duration_id = SubscriptionDuration::query()->where('key', $plan->duration)->value('id');
        });
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'limits' => 'array', 'features' => 'array', 'is_active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function durationCatalog(): BelongsTo
    {
        return $this->belongsTo(SubscriptionDuration::class, 'subscription_duration_id');
    }

    /** The single source of how long a subscription on this plan lasts (null when the legacy value is unrecognised). */
    public function durationDefinition(): ?SubscriptionDuration
    {
        return $this->subscription_duration_id ? $this->durationCatalog : SubscriptionDuration::fromLegacy($this);
    }

    /** @return array<string, mixed> */
    public function durationInfo(): array
    {
        $definition = $this->durationDefinition();
        if ($definition === null) {
            $legacy = (string) $this->duration;

            return ['id' => null, 'key' => $legacy, 'category' => 'paid', 'unit' => null, 'value' => null, 'name_ar' => $legacy, 'name_en' => $legacy, 'is_lifetime' => false, 'is_active' => true, 'configured' => false];
        }

        return $definition->toInfo();
    }

    /** @return array<string, mixed> Compact plan description for platform/public lists (legacy `duration` kept for old clients). */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'price' => $this->price, 'duration' => $this->duration, 'duration_info' => $this->durationInfo()];
    }
}
