<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    public function authenticate(string $email, string $password, string $accountType, ?string $ip, ?string $userAgent): ?User
    {
        $user = User::query()->where('email', $email)->first();
        $valid = $user && $user->account_type === $accountType && $user->isActive() && Hash::check($password, $user->password);

        LoginLog::query()->create([
            'company_id' => $user?->company_id,
            'user_id' => $user?->id,
            'email' => $email,
            'successful' => $valid,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);

        if (! $valid) {
            if ($user) {
                $user->increment('failed_login_attempts');
            }

            return null;
        }

        $user->forceFill([
            'last_login_at' => now(),
            'login_count' => $user->login_count + 1,
            'failed_login_attempts' => 0,
        ])->save();

        return $user;
    }
}
