<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MfaService
{
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly AuditRecorder $audit) {}

    public function isEnforced(): bool
    {
        if (config('security.platform.mfa_required')) {
            return true;
        }
        $from = config('security.platform.mfa_enforce_from');

        return $from && now()->startOfDay()->gte(Carbon::parse((string) $from)->startOfDay());
    }

    /** @return array{secret: string, uri: string} */
    public function newPendingSecret(User $user): array
    {
        $secret = Totp::generateSecret();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, (string) config('app.name', 'E-Zaki ERP'))];
    }

    /** Confirms enrolment with the pending secret and returns the one-time plaintext recovery codes. */
    public function confirmEnrollment(User $user, string $secret, string $code): ?array
    {
        if ($this->tooManyAttempts($user)) {
            return null;
        }
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            $this->failed($user, 'enrollment');

            return null;
        }
        RateLimiter::clear($this->key($user));
        $codes = $this->generateRecoveryCodes();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
            'mfa_last_step' => $step,
            'mfa_recovery_codes' => json_encode(array_map([$this, 'hashCode'], $codes), JSON_THROW_ON_ERROR),
        ])->save();
        $this->audit->record('platform.mfa_enrolled', $user, null, $user->id);

        return $codes;
    }

    public function verify(User $user, string $code, string $context = 'challenge'): bool
    {
        if (! $user->hasMfa() || $this->tooManyAttempts($user)) {
            return false;
        }
        $step = Totp::verify((string) $user->mfa_secret, $code, null, 1, $user->mfa_last_step);
        if ($step !== null) {
            $user->forceFill(['mfa_last_step' => $step])->save();
            RateLimiter::clear($this->key($user));

            return true;
        }
        if ($this->consumeRecoveryCode($user, $code)) {
            RateLimiter::clear($this->key($user));
            $this->audit->record('platform.mfa_recovery_code_used', $user, null, $user->id, [], null, ['severity' => 'warning']);

            return true;
        }
        $this->failed($user, $context);

        return false;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();
        $user->forceFill(['mfa_recovery_codes' => json_encode(array_map([$this, 'hashCode'], $codes), JSON_THROW_ON_ERROR)])->save();
        $this->audit->record('platform.mfa_recovery_codes_regenerated', $user, null, $user->id);

        return $codes;
    }

    public function disable(User $user, ?User $actor = null, ?string $reason = null): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
        $this->audit->record($actor && $actor->id !== $user->id ? 'platform.mfa_reset' : 'platform.mfa_disabled', $user, null, $actor?->id ?? $user->id, [], null, ['severity' => 'warning', 'reason' => $reason]);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedHashes($user));
    }

    public function tooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->key($user), (int) config('security.platform.mfa_attempts_per_minute', 5));
    }

    private function failed(User $user, string $context): void
    {
        RateLimiter::hit($this->key($user), 60);
        // Codes are never logged; only the fact and context of the failure.
        $this->audit->record('platform.mfa_failed', $user, null, $user->id, ['context' => $context], null, ['severity' => 'warning']);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[\s\-]/', '', $code) ?? '');
        if (strlen($normalized) < 8) {
            return false;
        }
        $hashes = $this->storedHashes($user);
        $candidate = $this->hashCode($normalized);
        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $candidate)) {
                unset($hashes[$index]);
                $user->forceFill(['mfa_recovery_codes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR)])->save();

                return true;
            }
        }

        return false;
    }

    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))->map(fn () => strtoupper(Str::random(10)))->all();
    }

    public function hashCode(string $code): string
    {
        return hash_hmac('sha256', strtoupper($code), (string) config('app.key'));
    }

    private function storedHashes(User $user): array
    {
        return $user->mfa_recovery_codes ? (array) json_decode((string) $user->mfa_recovery_codes, true) : [];
    }

    private function key(User $user): string
    {
        return 'platform-mfa:'.$user->id;
    }
}
