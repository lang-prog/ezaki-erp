<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oQ4v8yM8Q0p2JpY6x6lLq7GmY3Lq7u';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function authenticate(string $email, string $password, string $accountType, ?string $ip, ?string $userAgent): ?User
    {
        return DB::transaction(function () use ($email, $password, $accountType, $ip, $userAgent): ?User {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $passwordValid = Hash::check($password, $user?->password ?: self::DUMMY_PASSWORD_HASH);
            $sourceKey = 'auth-lock:'.hash('sha256', strtolower($email).'|'.$ip);
            $source = Cache::get($sourceKey, ['fails' => 0, 'until' => null]);
            $locked = ($source['until'] !== null && $source['until'] > time())
                || ($user?->login_locked_until !== null && $user->login_locked_until->isFuture());
            $valid = $user !== null
                && ! $locked
                && $user->account_type === $accountType
                && $user->isActive()
                && $passwordValid;

            if (! $valid && ! $locked) {
                $source['fails']++;
                $threshold = max(1, (int) config('security.auth.lockout_attempts', 5));
                if ($source['fails'] >= $threshold) {
                    $exponent = min($source['fails'] - $threshold, 10);
                    $seconds = min((int) config('security.auth.lockout_max_seconds', 900), (int) config('security.auth.lockout_base_seconds', 60) * (2 ** $exponent));
                    $source['until'] = time() + $seconds;
                    if ($user?->account_type === 'super_admin') {
                        $this->audit->record('platform.login_locked', $user, null, $user->id, ['seconds' => $seconds], null, ['severity' => 'warning']);
                    }
                }
                Cache::put($sourceKey, $source, now()->addSeconds((int) config('security.auth.lockout_max_seconds', 900) * 2));

                if ($user) {
                    // The account-wide lock only trips on a much larger (distributed) attack, so one source cannot lock out the owner.
                    $attempts = ((int) $user->failed_login_attempts) + 1;
                    $update = ['failed_login_attempts' => $attempts];
                    if ($attempts >= max($threshold, (int) config('security.auth.lockout_global_attempts', 30))) {
                        $update['login_locked_until'] = now()->addSeconds((int) config('security.auth.lockout_max_seconds', 900));
                    }
                    $user->forceFill($update)->save();
                }
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

            Cache::forget($sourceKey);
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
