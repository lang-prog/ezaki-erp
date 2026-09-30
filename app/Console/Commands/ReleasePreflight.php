<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReleasePreflight extends Command
{
    protected $signature = 'release:preflight {--production : Apply production-safe gates even when APP_ENV is not production} {--json : Emit machine-readable JSON}';

    protected $description = 'Check production configuration, writable paths, database reachability and pending migrations';

    public function handle(): int
    {
        $production = (bool) $this->option('production') || (string) config('app.env') === 'production';
        $checks = [];
        $check = function (string $name, bool $ok, string $detail, ?bool $blocking = null) use (&$checks, $production): void {
            $checks[] = compact('name', 'ok', 'detail') + ['blocking' => $blocking ?? $production];
        };
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $key = (string) config('app.key');
        $url = (string) config('app.url');
        $check('app_env', ! $production || $env === 'production', "APP_ENV={$env}");
        $check('app_debug', ! $production || ! $debug, $debug ? 'APP_DEBUG=true' : 'APP_DEBUG=false');
        $check('app_key', $key !== '' && ! in_array($key, ['base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'SomeRandomString'], true), $key === '' ? 'APP_KEY is empty' : 'APP_KEY is configured');
        $check('https_url', ! $production || str_starts_with(strtolower($url), 'https://'), $url === '' ? 'APP_URL is empty' : "APP_URL={$url}");
        $check('session_secure_cookie', ! $production || (bool) config('session.secure'), config('session.secure') ? 'enabled' : 'disabled');
        $session = (string) config('session.driver');
        $cache = (string) config('cache.default');
        $queue = (string) config('queue.default');
        $mail = (string) config('mail.default');
        $db = (string) config('database.default');
        $check('session_driver', ! $production || ! in_array($session, ['array', 'cookie'], true), "SESSION_DRIVER={$session}");
        $check('cache_store', ! $production || $cache !== 'array', "CACHE_STORE={$cache}");
        $check('queue_connection', ! $production || $queue !== 'sync', "QUEUE_CONNECTION={$queue}");
        $check('mail_transport', ! $production || ! in_array($mail, ['log', 'array'], true), "MAIL_MAILER={$mail}");
        $check('database_driver', ! $production || $db === 'mysql', "DB_CONNECTION={$db}");
        $check('storage_writable', is_writable(storage_path()), storage_path());
        $check('bootstrap_cache_writable', is_writable(base_path('bootstrap/cache')), base_path('bootstrap/cache'));

        try {
            DB::connection()->getPdo();
            $check('database_reachable', true, 'connection established');
            $migrationTable = (string) config('database.migrations.table', 'migrations');
            $ran = DB::table($migrationTable)->pluck('migration')->all();
            $files = [];
            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                $files[] = pathinfo($file, PATHINFO_FILENAME);
            }
            $pending = array_values(array_diff($files, $ran));
            $check('migrations', $pending === [], $pending === [] ? 'no pending migrations' : 'pending: '.implode(', ', array_slice($pending, 0, 10)));
        } catch (Throwable $e) {
            $check('database_reachable', false, 'connection failed (credentials/details intentionally omitted)');
            $check('migrations', false, 'not checked because database is unavailable');
        }

        $blockingFailures = array_values(array_filter($checks, static fn (array $item): bool => $item['blocking'] && ! $item['ok']));
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['ok' => $blockingFailures === [], 'production_gate' => $production, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Release preflight '.($production ? '(production gate)' : '(advisory)').': '.($blockingFailures === [] ? 'PASS' : 'FAIL'));
            foreach ($checks as $item) {
                $this->line(sprintf('%s %s — %s', $item['ok'] ? '[OK]' : '[FAIL]', $item['name'], $item['detail']));
            }
        }

        return $blockingFailures === [] ? self::SUCCESS : self::FAILURE;
    }
}
