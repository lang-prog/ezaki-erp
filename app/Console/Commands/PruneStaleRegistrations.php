<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RegistrationRequest;
use Illuminate\Console\Command;

class PruneStaleRegistrations extends Command
{
    protected $signature = 'registrations:prune-stale {--dry-run}';

    protected $description = 'Delete unverified registration requests whose verification link expired (verified/pending/approved requests are never touched)';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) config('security.registration.stale_unverified_hours', 24)));
        $query = RegistrationRequest::query()->where('status', 'unverified')->whereNull('verified_at')->where('verification_expires_at', '<', $cutoff);
        $count = (clone $query)->count();
        if (! $this->option('dry-run')) {
            $query->delete();
        }
        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."{$count} stale unverified registration request(s).");

        return self::SUCCESS;
    }
}
