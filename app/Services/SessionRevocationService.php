<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class SessionRevocationService
{
    public function revoke(User $user): int
    {
        $user->newQuery()->whereKey($user->getKey())->increment('session_version');
        $user->refresh();

        return (int) $user->session_version;
    }
}
