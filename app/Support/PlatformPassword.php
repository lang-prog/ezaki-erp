<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Platform administrators need at least 12 characters with mixed case and digits (matches app:create-super-admin). */
final class PlatformPassword
{
    public static function rule(): Password
    {
        return Password::min((int) config('security.platform.min_password_length', 12))->mixedCase()->numbers();
    }

    public static function passes(string $password): bool
    {
        return ! Validator::make(['password' => $password], ['password' => ['required', 'string', self::rule()]])->fails();
    }
}
