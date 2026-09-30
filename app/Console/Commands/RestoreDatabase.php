<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class RestoreDatabase extends Command
{
    protected $signature = 'backup:restore
        {backup : Manifest JSON path or backup basename}
        {--yes : Confirm the destructive restore without an interactive prompt}
        {--dry-run : Verify manifest/checksums and show the plan without changing data}';

    protected $description = 'Verify and restore a checksummed MySQL dump or SQLite backup';

    public function handle(): int
    {
        try {
            $manifestPath = $this->manifestPath((string) $this->argument('backup'));
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? '') !== 'ezaki-backup-v1') {
                throw new RuntimeException('Unsupported backup manifest format.');
            }
            $artifact = collect($manifest['artifacts'] ?? [])->first(fn ($item) => in_array($item['type'] ?? '', ['mysql-dump', 'sqlite'], true));
            if (! is_array($artifact)) {
                throw new RuntimeException('Manifest has no database artifact.');
            }
            $artifactPath = realpath(dirname($manifestPath).'/'.($artifact['file'] ?? ''));
            if ($artifactPath === false || ! str_starts_with($artifactPath, rtrim(realpath(dirname($manifestPath)) ?: '', DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Artifact must remain inside the manifest directory.');
            }
            $actual = hash_file('sha256', $artifactPath);
            if (! is_string($actual) || ! hash_equals((string) ($artifact['sha256'] ?? ''), $actual)) {
                throw new RuntimeException('Checksum mismatch; restore aborted.');
            }
            $driver = (string) ($manifest['driver'] ?? '');
            if (! in_array($driver, ['mysql', 'sqlite'], true) || ($driver === 'sqlite') !== (($artifact['type'] ?? '') === 'sqlite')) {
                throw new RuntimeException('Manifest driver/artifact mismatch.');
            }
            $target = $driver === 'sqlite' ? (string) config('database.connections.sqlite.database') : (string) config('database.connections.mysql.database');
            $this->line(((bool) $this->option('dry-run') ? '[dry-run] ' : '')."verified {$driver} artifact ".basename($artifactPath).' ('.number_format(filesize($artifactPath) ?: 0).' bytes)');
            if ((bool) $this->option('dry-run')) {
                return self::SUCCESS;
            }
            if (! (bool) $this->option('yes') && ! $this->confirm("This will replace the configured {$driver} database. Continue?", false)) {
                return self::INVALID;
            }
            if ($driver === 'sqlite') {
                $this->restoreSqlite($artifactPath, $target);
            } else {
                $this->restoreMysql($artifactPath);
            }
            $this->info('Restore completed. Run migrations/status and smoke tests before serving traffic.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Restore aborted: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function restoreSqlite(string $source, string $target): void
    {
        if ($target === ':memory:' || $target === '' || $source === '') {
            throw new RuntimeException('Refusing an in-memory/empty SQLite target.');
        }
        $targetDir = dirname($target);
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0700, true) && ! is_dir($targetDir)) {
            throw new RuntimeException('SQLite target directory is unavailable.');
        }
        if (is_file($target)) {
            $safety = $target.'.pre-restore-'.gmdate('Ymd-His');
            if (! copy($target, $safety)) {
                throw new RuntimeException('Could not create pre-restore SQLite safety copy.');
            }
        }
        if (! copy($source, $target)) {
            throw new RuntimeException('SQLite restore copy failed.');
        }
    }

    private function restoreMysql(string $source): void
    {
        $connection = config('database.connections.mysql', []);
        $mysql = (string) (env('MYSQL_BIN') ?: 'mysql');
        $args = [$mysql, '--host='.(string) ($connection['host'] ?? '127.0.0.1'), '--port='.(string) ($connection['port'] ?? 3306), '--user='.(string) ($connection['username'] ?? ''), (string) ($connection['database'] ?? '')];
        $mysqlCommand = implode(' ', array_map('escapeshellarg', $args));
        $input = str_ends_with($source, '.gz') ? 'gzip -dc '.escapeshellarg($source).' | ' : 'cat '.escapeshellarg($source).' | ';
        $env = array_merge($_ENV, $_SERVER, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
        $pipes = [];
        $process = proc_open($input.$mysqlCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start mysql restore command.');
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('MySQL restore failed; no credentials were printed.');
        }
    }

    private function manifestPath(string $input): string
    {
        $candidate = str_ends_with($input, '.json') ? $input : $input.'.json';
        if (! str_starts_with($candidate, '/')) {
            $candidate = base_path($candidate);
        }
        $real = realpath($candidate);
        if ($real === false || ! is_file($real)) {
            throw new RuntimeException('Backup manifest not found.');
        }

        return $real;
    }
}
