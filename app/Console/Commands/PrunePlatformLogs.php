<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrunePlatformLogs extends Command
{
    protected $signature = 'platform:prune-logs {--audit-days= : Keep audit rows newer than this many days} {--login-days= : Keep login rows newer than this many days} {--dry-run : Only report what would be deleted} {--force : Required to delete}';

    protected $description = 'Explicit, never-scheduled retention pruning for audit_logs and login_logs (dry-run supported)';

    public function handle(): int
    {
        $auditDays = max(30, (int) ($this->option('audit-days') ?: config('security.audit.retention_days', 730)));
        $loginDays = max(30, (int) ($this->option('login-days') ?: config('security.audit.login_log_retention_days', 180)));
        $targets = [
            'audit_logs' => now()->subDays($auditDays),
            'login_logs' => now()->subDays($loginDays),
        ];
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('force')) {
            $this->error('Refusing to delete without --force. Use --dry-run to preview.');

            return self::INVALID;
        }
        foreach ($targets as $table => $cutoff) {
            $query = DB::table($table)->where('created_at', '<', $cutoff);
            $count = (clone $query)->count();
            if (! $dry && $count > 0) {
                $query->delete();
            }
            $this->line(($dry ? '[dry-run] ' : '')."{$table}: {$count} row(s) older than {$cutoff->toDateString()}");
        }

        return self::SUCCESS;
    }
}
