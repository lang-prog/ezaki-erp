<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Models\RegistrationRequest;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CouponRedemptionService
{
    public function redeem(
        Coupon|int $coupon,
        Plan $plan,
        Company $company,
        Subscription $subscription,
        ?User $actor = null,
        ?RegistrationRequest $registration = null,
    ): CouponRedemption {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Coupon redemption must run inside a transaction.');
        }

        $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon instanceof Coupon ? $coupon->id : $coupon);
        $existing = CouponRedemption::query()
            ->where('coupon_id', $locked->id)
            ->where('company_id', $company->id)
            ->where('subscription_id', $subscription->id)
            ->first();
        if ($existing) {
            return $existing;
        }
        abort_unless($locked->isAvailableForCompany($plan, $company), 409, 'The selected coupon is no longer available for this company.');

        $base = (float) $plan->price;
        $discount = $locked->discount_type === 'percent'
            ? $base * min(100, (float) $locked->discount_value) / 100
            : min($base, (float) $locked->discount_value);
        $redemption = CouponRedemption::query()->create([
            'coupon_id' => $locked->id,
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'registration_id' => $registration?->id,
            'redeemed_by' => $actor?->id,
            'base_amount' => $base,
            'discount_amount' => round($discount, 2),
            'payable_amount' => round($base - $discount, 2),
        ]);
        $locked->increment('redemptions_count');

        return $redemption;
    }
}
