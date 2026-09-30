<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oQ4v8yM8Q0p2JpY6x6lLq7GmY3Lq7u';

    public function authenticate(string $email, string $password, string $accountType, ?string $ip, ?string $userAgent): ?User
    {
        return DB::transaction(function () use ($email, $password, $accountType, $ip, $userAgent): ?User {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $passwordValid = Hash::check($password, $user?->password ?: self::DUMMY_PASSWORD_HASH);
            $locked = $user?->login_locked_until !== null && $user->login_locked_until->isFuture();
            $valid = $user !== null
                && ! $locked
                && $user->account_type === $accountType
                && $user->isActive()
                && $passwordValid;

            if (! $valid && $user && ! $locked) {
                $attempts = ((int) $user->failed_login_attempts) + 1;
                $threshold = max(1, (int) config('security.auth.lockout_attempts', 5));
                $lockedUntil = null;

                if ($attempts >= $threshold) {
                    $exponent = min($attempts - $threshold, 10);
                    $seconds = min(
                        (int) config('security.auth.lockout_max_seconds', 900),
                        (int) config('security.auth.lockout_base_seconds', 60) * (2 ** $exponent),
                    );
                    $lockedUntil = now()->addSeconds($seconds);
                }

                $user->forceFill([
                    'failed_login_attempts' => $attempts,
                    'login_locked_until' => $lockedUntil,
                ])->save();
            }

            LoginLog::query()->create([
                'company_id' => $user?->company_id,
                'user_id' => $user?->id,
                'email' => $email,
                'successful' => $valid,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

            if (! $valid) {
                return null;
            }

            $user->forceFill([
                'last_login_at' => now(),
                'login_count' => $user->login_count + 1,
                'failed_login_attempts' => 0,
                'login_locked_until' => null,
            ])->save();

            return $user;
        });
    }
}
