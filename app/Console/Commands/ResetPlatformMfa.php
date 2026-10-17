<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MfaService;
use Illuminate\Console\Command;

class ResetPlatformMfa extends Command
{
    protected $signature = 'platform:mfa-reset {email : Platform administrator email} {--yes : Skip the confirmation prompt}';

    protected $description = 'Emergency recovery: remove MFA enrolment for a platform administrator (audited). Requires server access.';

    public function handle(MfaService $mfa): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->where('account_type', 'super_admin')->first();
        if (! $user) {
            $this->error('No platform administrator found for that email.');

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! $this->confirm("Remove MFA for {$user->email}? They must enrol again.", false)) {
            return self::INVALID;
        }
        $mfa->disable($user, null, 'CLI emergency reset');
        $user->forceFill(['session_version' => $user->session_version + 1])->save();
        $this->info('MFA removed and existing sessions revoked.');

        return self::SUCCESS;
    }
}
