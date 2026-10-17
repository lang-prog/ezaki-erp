<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalog entry: period category (trial/test/paid) + interval (unit/value). Plans reference one; subscriptions
 * keep their own dates, so editing a label can never move a date. Protected fields are set only by the service.
 */
class SubscriptionDuration extends Model
{
    public const CATEGORIES = ['trial', 'test', 'paid'];

    public const UNITS = ['day', 'week', 'month', 'year', 'lifetime'];

    /** Largest accepted value per unit; trial/test periods may not use year or lifetime. */
    public const MAX_VALUE = ['day' => 3650, 'week' => 520, 'month' => 120, 'year' => 50];

    public const NON_PAID_UNITS = ['day', 'week', 'month'];

    protected $fillable = ['name_ar', 'name_en', 'description_ar', 'description_en', 'sort_order'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'sort_order' => 'integer', 'version' => 'integer', 'is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'subscription_duration_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isLifetime(): bool
    {
        return $this->unit === 'lifetime';
    }

    /**
     * End of a period that starts at $start. Arithmetic is on the UTC instant (the application timezone): months and
     * years never overflow (31 Jan + 1 month = 28/29 Feb), days and weeks are exact multiples of 24 hours, so DST and
     * the viewer's timezone cannot shift a stored end date. Null means no expiry.
     */
    public function addTo(CarbonImmutable $start): ?CarbonImmutable
    {
        $value = (int) $this->value;

        return match ($this->unit) {
            'day' => $start->addDays($value),
            'week' => $start->addDays(7 * $value),
            'month' => $start->addMonthsNoOverflow($value),
            'year' => $start->addYearsNoOverflow($value),
            default => null,
        };
    }

    /** In-memory definition equivalent to what SubscriptionLifecycle::expiryFor applied to a plan without a catalog link. */
    public static function fromLegacy(Plan $plan): ?self
    {
        $make = fn (string $key, string $category, string $unit, ?int $value, string $ar, string $en): self => (new self)->forceFill([
            'key' => $key, 'category' => $category, 'unit' => $unit, 'value' => $value, 'name_ar' => $ar, 'name_en' => $en, 'is_active' => true, 'is_system' => false,
        ]);
        $legacy = (string) $plan->duration;
        if ($legacy === 'lifetime') {
            return $make('lifetime', 'paid', 'lifetime', null, 'مدى الحياة', 'Lifetime');
        }
        if ($plan->duration_days) {
            $days = (int) $plan->duration_days;

            return $make($legacy.'_'.$days.'d', $legacy === 'trial' ? 'trial' : 'paid', 'day', $days, "{$days} يوم", "{$days} days");
        }

        return match ($legacy) {
            'monthly' => $make('monthly', 'paid', 'month', 1, 'شهري', 'Monthly'),
            'six_months' => $make('six_months', 'paid', 'month', 6, 'ستة أشهر', 'Six months'),
            'yearly' => $make('yearly', 'paid', 'year', 1, 'سنوي', 'Yearly'),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function toInfo(): array
    {
        return [
            'id' => $this->exists ? $this->id : null, 'key' => $this->key, 'category' => $this->category, 'unit' => $this->unit, 'value' => $this->value,
            'name_ar' => $this->name_ar, 'name_en' => $this->name_en, 'is_lifetime' => $this->isLifetime(), 'is_active' => (bool) $this->is_active, 'configured' => true,
        ];
    }
}
