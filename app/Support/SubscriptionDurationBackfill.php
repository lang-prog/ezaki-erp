<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Seeds the system duration catalog and links existing plans to it without touching any subscription.
 * Idempotent: a plan is linked only when its legacy duration maps to exactly the same expiry rule the
 * old SubscriptionLifecycle::expiryFor applied; anything ambiguous keeps resolving through the legacy columns.
 */
final class SubscriptionDurationBackfill
{
    /** key => [name_ar, name_en, category, unit, value, sort_order] */
    public const SYSTEM = [
        'monthly' => ['شهري', 'Monthly', 'paid', 'month', 1, 10],
        'six_months' => ['ستة أشهر', 'Six months', 'paid', 'month', 6, 20],
        'yearly' => ['سنوي', 'Yearly', 'paid', 'year', 1, 30],
        'lifetime' => ['مدى الحياة', 'Lifetime', 'paid', 'lifetime', null, 40],
    ];

    /** @return array{plans_linked: int, plans_left_legacy: int} */
    public static function run(): array
    {
        $now = now();
        foreach (self::SYSTEM as $key => [$ar, $en, $category, $unit, $value, $order]) {
            DB::table('subscription_durations')->insertOrIgnore([
                'key' => $key, 'name_ar' => $ar, 'name_en' => $en, 'category' => $category, 'unit' => $unit, 'value' => $value,
                'sort_order' => $order, 'is_active' => true, 'is_system' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $ids = DB::table('subscription_durations')->pluck('id', 'key');
        $linked = 0;
        $legacy = 0;

        foreach (DB::table('plans')->whereNull('subscription_duration_id')->orderBy('id')->get(['id', 'duration', 'duration_days']) as $plan) {
            $durationId = null;
            if ($plan->duration === 'lifetime') {
                $durationId = $ids['lifetime'] ?? null;
            } elseif ($plan->duration === 'trial' && (int) $plan->duration_days > 0) {
                $days = (int) $plan->duration_days;
                $key = "trial_{$days}d";
                DB::table('subscription_durations')->insertOrIgnore([
                    'key' => $key, 'name_ar' => "تجريبي — {$days} يوم", 'name_en' => "Trial — {$days} days", 'category' => 'trial', 'unit' => 'day', 'value' => $days,
                    'sort_order' => 200 + min($days, 999), 'is_active' => true, 'is_system' => false, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $durationId = DB::table('subscription_durations')->where('key', $key)->value('id');
            } elseif (in_array($plan->duration, ['monthly', 'six_months', 'yearly'], true) && $plan->duration_days === null) {
                $durationId = $ids[$plan->duration] ?? null;
            }

            if ($durationId === null) {
                $legacy++;

                continue;
            }
            DB::table('plans')->where('id', $plan->id)->whereNull('subscription_duration_id')->update(['subscription_duration_id' => $durationId]);
            $linked++;
        }

        return ['plans_linked' => $linked, 'plans_left_legacy' => $legacy];
    }
}
