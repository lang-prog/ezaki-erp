<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionDuration;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only writer of the duration catalog. Rows are never deleted. Once a plan references a duration (or it is a
 * system duration) its category/unit/value are frozen, so no edit can change how an existing plan or subscription is read.
 */
class SubscriptionDurationService
{
    private const UNIT_LETTER = ['day' => 'd', 'week' => 'w', 'month' => 'm', 'year' => 'y'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?Request $request = null): SubscriptionDuration
    {
        return DB::transaction(function () use ($data, $actor, $request): SubscriptionDuration {
            $value = ($data['value'] ?? null) === null ? null : (int) $data['value'];
            $this->assertInterval($data['category'], $data['unit'], $value);
            $this->assertNamesFree($data['name_ar'], $data['name_en']);
            $key = $this->keyFor($data['key'] ?? null, $data['category'], $data['unit'], $value);

            $duration = new SubscriptionDuration;
            $duration->fill(array_intersect_key($data, array_flip($duration->getFillable())));
            $duration->forceFill(['key' => $key, 'category' => $data['category'], 'unit' => $data['unit'], 'value' => $value, 'is_active' => true, 'is_system' => false, 'version' => 1])->save();
            $this->audit->record('platform.duration_created', $duration, null, $actor->id, [], $request, ['after' => $this->snapshot($duration)]);

            return $duration;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SubscriptionDuration $duration, array $data, User $actor, string $reason, ?Request $request = null): SubscriptionDuration
    {
        return DB::transaction(function () use ($duration, $data, $actor, $reason, $request): SubscriptionDuration {
            $locked = SubscriptionDuration::query()->lockForUpdate()->findOrFail($duration->id);
            if ((int) ($data['version'] ?? 0) !== (int) $locked->version) {
                throw new HttpException(409, __('durations.version_conflict'));
            }
            if (isset($data['key']) && $data['key'] !== $locked->key) {
                throw ValidationException::withMessages(['key' => __('durations.key_immutable')]);
            }
            $category = $data['category'] ?? $locked->category;
            $unit = $data['unit'] ?? $locked->unit;
            $value = array_key_exists('value', $data) ? ($data['value'] === null ? null : (int) $data['value']) : ($unit === 'lifetime' ? null : $locked->value);
            if ($category !== $locked->category || $unit !== $locked->unit || $value !== $locked->value) {
                if ($locked->is_system) {
                    throw ValidationException::withMessages(['unit' => __('durations.immutable_system')]);
                }
                if ($locked->plans()->exists()) {
                    throw ValidationException::withMessages(['unit' => __('durations.immutable_referenced')]);
                }
                $this->assertInterval($category, $unit, $value);
            }
            $before = $this->snapshot($locked);
            $names = [$data['name_ar'] ?? $locked->name_ar, $data['name_en'] ?? $locked->name_en];
            if ($locked->is_active) {
                $this->assertNamesFree($names[0], $names[1], $locked->id);
            }
            $locked->fill(array_intersect_key($data, array_flip($locked->getFillable())));
            $locked->forceFill(['category' => $category, 'unit' => $unit, 'value' => $value, 'version' => $locked->version + 1])->save();
            $this->audit->record('platform.duration_updated', $locked, null, $actor->id, [], $request, ['reason' => $reason, 'before' => $before, 'after' => $this->snapshot($locked)]);

            return $locked;
        });
    }

    public function setActive(SubscriptionDuration $duration, bool $active, bool $confirmAffected, User $actor, string $reason, ?Request $request = null): SubscriptionDuration
    {
        return DB::transaction(function () use ($duration, $active, $confirmAffected, $actor, $reason, $request): SubscriptionDuration {
            $locked = SubscriptionDuration::query()->lockForUpdate()->findOrFail($duration->id);
            if ($locked->is_active === $active) {
                return $locked;
            }
            if (! $active) {
                $affected = $locked->plans()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
                if ($affected->isNotEmpty() && ! $confirmAffected) {
                    throw new HttpResponseException(response()->json([
                        'message' => __('durations.in_use'), 'code' => 'duration_in_use',
                        'affected_plans' => $affected->map(fn (Plan $plan): array => ['id' => $plan->id, 'name' => $plan->name])->all(),
                    ], 409));
                }
            } else {
                $this->assertNamesFree($locked->name_ar, $locked->name_en, $locked->id);
            }
            $before = $this->snapshot($locked);
            $locked->forceFill(['is_active' => $active, 'version' => $locked->version + 1])->save();
            $this->audit->record($active ? 'platform.duration_activated' : 'platform.duration_deactivated', $locked, null, $actor->id, [], $request, [
                'reason' => $reason, 'severity' => $active ? 'info' : 'warning', 'before' => $before, 'after' => $this->snapshot($locked),
            ]);

            return $locked;
        });
    }

    /** @return array{plans: int, active_plans: int, subscriptions: int} */
    public function usage(SubscriptionDuration $duration): array
    {
        $plans = $duration->plans();

        return [
            'plans' => (clone $plans)->count(),
            'active_plans' => (clone $plans)->where('is_active', true)->count(),
            'subscriptions' => Subscription::query()->whereIn('plan_id', (clone $plans)->select('id'))->count(),
        ];
    }

    public function assertInterval(string $category, string $unit, ?int $value): void
    {
        if ($unit === 'lifetime') {
            if ($value !== null) {
                throw ValidationException::withMessages(['value' => __('durations.value_forbidden')]);
            }
            if ($category !== 'paid') {
                throw ValidationException::withMessages(['unit' => __('durations.category_unit')]);
            }

            return;
        }
        if ($category !== 'paid' && ! in_array($unit, SubscriptionDuration::NON_PAID_UNITS, true)) {
            throw ValidationException::withMessages(['unit' => __('durations.category_unit')]);
        }
        $max = SubscriptionDuration::MAX_VALUE[$unit] ?? 0;
        if ($value === null || $value < 1 || $value > $max) {
            throw ValidationException::withMessages(['value' => __('durations.value_invalid', ['max' => $max])]);
        }
    }

    /** Two active durations may share an interval (different commercial labels) but never a label. */
    private function assertNamesFree(string $nameAr, string $nameEn, ?int $ignoreId = null): void
    {
        $taken = SubscriptionDuration::query()->active()->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where(fn ($query) => $query->whereRaw('lower(name_en) = ?', [mb_strtolower($nameEn)])->orWhere('name_ar', $nameAr))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name_en' => __('durations.name_taken')]);
        }
    }

    private function keyFor(?string $requested, string $category, string $unit, ?int $value): string
    {
        if ($requested !== null && $requested !== '') {
            if (SubscriptionDuration::query()->where('key', $requested)->exists()) {
                throw ValidationException::withMessages(['key' => __('durations.key_taken')]);
            }

            return $requested;
        }
        $base = $unit === 'lifetime' ? "{$category}_lifetime" : "{$category}_{$value}".self::UNIT_LETTER[$unit];
        $key = $base;
        for ($suffix = 2; SubscriptionDuration::query()->where('key', $key)->exists(); $suffix++) {
            $key = "{$base}_{$suffix}";
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function snapshot(SubscriptionDuration $duration): array
    {
        return $duration->only(['key', 'name_ar', 'name_en', 'description_ar', 'description_en', 'category', 'unit', 'value', 'sort_order', 'is_active', 'is_system', 'version']);
    }
}
