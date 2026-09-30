<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'discount_type', 'discount_value', 'starts_at', 'ends_at',
        'max_redemptions', 'redemptions_count', 'restrictions', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'restrictions' => 'array', 'is_active' => 'boolean',
        ];
    }

    public function isAvailableFor(?Plan $plan): bool
    {
        $allowedPlans = $this->restrictions['plan_ids'] ?? [];

        return $this->is_active
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture())
            && (! $this->max_redemptions || $this->redemptions_count < $this->max_redemptions)
            && (! $allowedPlans || ($plan && in_array($plan->id, array_map('intval', $allowedPlans), true)));
    }

    public function isAvailableForCompany(?Plan $plan, ?Company $company): bool
    {
        $allowedCompanies = $this->restrictions['company_ids'] ?? [];
        $allowedEditions = $this->restrictions['editions'] ?? [];

        return $this->isAvailableFor($plan)
            && (! $allowedCompanies || ($company && in_array($company->id, array_map('intval', $allowedCompanies), true)))
            && (! $allowedEditions || ($company && in_array($company->edition, $allowedEditions, true)));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
