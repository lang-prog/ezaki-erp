<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--output= : Backup directory; defaults to storage/app/backups}
        {--retention=7 : Delete backup artifacts older than this many days (0 disables deletion)}
        {--dry-run : Show the plan without writing or executing commands}
        {--include-storage : Also archive storage/app as a separate installation artifact}';

    protected $description = 'Create a checksummed MySQL dump or SQLite copy without placing secrets in arguments or logs';

    public function handle(): int
    {
        $driver = (string) config('database.default');
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            $this->error("Unsupported database driver for backup: {$driver}");

            return self::FAILURE;
        }

        $directory = $this->absoluteDirectory((string) ($this->option('output') ?: storage_path('app/backups')));
        $retention = max(0, (int) $this->option('retention'));
        $stamp = gmdate('Ymd-His');
        $base = $directory.'/ezaki-'.$stamp.'-'.$driver;
        $databasePath = $driver === 'sqlite' ? $base.'.sqlite' : $base.'.sql.gz';
        $manifestPath = $base.'.json';
        $dryRun = (bool) $this->option('dry-run');

        $this->line(($dryRun ? '[dry-run] ' : '')."driver={$driver} output={$directory}");
        if ($retention > 0) {
            $this->line(($dryRun ? '[dry-run] ' : '')."retention={$retention} days");
        }
        if ($this->runHook('BACKUP_PRE_HOOK', $dryRun) === false) {
            return self::FAILURE;
        }
        if ($dryRun) {
            return self::SUCCESS;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create backup directory.');
        }
        if ((fileperms($directory) & 0007) !== 0) {
            @chmod($directory, 0700);
        }

        if ($driver === 'sqlite') {
            $this->backupSqlite($databasePath);
        } else {
            $this->backupMysql($databasePath);
        }

        $artifacts = [$this->artifact($databasePath, $driver === 'sqlite' ? 'sqlite' : 'mysql-dump')];
        if ((bool) $this->option('include-storage')) {
            $storageArchive = $base.'-storage.tar.gz';
            $this->archiveStorage($storageArchive);
            $artifacts[] = $this->artifact($storageArchive, 'private-storage');
        }
        $manifest = [
            'format' => 'ezaki-backup-v1',
            'driver' => $driver,
            'created_at_utc' => gmdate('c'),
            'retention_days' => $retention,
            'artifacts' => $artifacts,
            'secret_policy' => 'Credentials are read from Laravel configuration and never written to this manifest.',
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
        $this->writeChecksum($manifestPath);
        $this->applyRetention($directory, $retention, $manifestPath);
        if ($this->runHook('BACKUP_POST_HOOK', false) === false) {
            return self::FAILURE;
        }
        if ($this->runHook('BACKUP_RETENTION_HOOK', false) === false) {
            return self::FAILURE;
        }
        $this->info('Backup completed: '.$this->safeName($manifestPath));

        return self::SUCCESS;
    }

    private function backupSqlite(string $destination): void
    {
        $source = (string) config('database.connections.sqlite.database');
        if ($source === ':memory:' || $source === '' || ! is_file($source)) {
            throw new RuntimeException('SQLite database file is unavailable.');
        }
        if (! copy($source, $destination)) {
            throw new RuntimeException('SQLite backup copy failed.');
        }
    }

    private function backupMysql(string $destination): void
    {
        $connection = config('database.connections.mysql', []);
        $binary = (string) (env('MYSQLDUMP_BIN') ?: 'mysqldump');
        $args = [
            $binary, '--single-transaction', '--quick', '--routines', '--triggers', '--hex-blob', '--set-gtid-purged=OFF',
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'), '--port='.(string) ($connection['port'] ?? 3306),
            '--user='.(string) ($connection['username'] ?? ''), (string) ($connection['database'] ?? ''),
        ];
        $command = implode(' ', array_map('escapeshellarg', $args)).' | gzip -c > '.escapeshellarg($destination);
        $this->runExternal($command, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], 'MySQL dump failed.');
    }

    private function archiveStorage(string $destination): void
    {
        $storage = storage_path('app');
        // Backups live below storage/app by default; exclude that directory to avoid self-inclusion.
        $command = 'tar --exclude=./backups -czf '.escapeshellarg($destination).' -C '.escapeshellarg($storage).' .';
        $this->runExternal($command, [], 'Private storage archive failed.');
    }

    private function runExternal(string $command, array $extraEnv, string $failure): void
    {
        $env = array_merge($_ENV, $_SERVER, $extraEnv);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (! is_resource($process)) {
            throw new RuntimeException($failure);
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException($failure.' See the operator-only command output/logs; no credentials were printed.');
        }
    }

    private function artifact(string $path, string $type): array
    {
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum backup artifact.');
        }
        file_put_contents($path.'.sha256', $checksum.'  '.basename($path)."\n", LOCK_EX);

        return ['type' => $type, 'file' => basename($path), 'sha256' => $checksum, 'size_bytes' => filesize($path) ?: 0];
    }

    private function writeChecksum(string $path): void
    {
        $checksum = hash_file('sha256', $path);
        if ($checksum !== false) {
            file_put_contents($path.'.sha256', $checksum.'  '.basename($path)."\n", LOCK_EX);
        }
    }

    private function applyRetention(string $directory, int $days, string $currentManifest): void
    {
        if ($days < 1) {
            return;
        }
        $cutoff = time() - ($days * 86400);
        foreach (glob($directory.'/ezaki-*') ?: [] as $file) {
            if ($file === $currentManifest || ! is_file($file) || (filemtime($file) ?: time()) >= $cutoff) {
                continue;
            }
            @unlink($file);
        }
    }

    private function runHook(string $name, bool $dryRun): bool
    {
        $hook = trim((string) env($name, ''));
        if ($hook === '') {
            return true;
        }
        $this->line(($dryRun ? '[dry-run] would run ' : 'running ').$name.' (output suppressed)');
        if ($dryRun) {
            return true;
        }
        try {
            $this->runExternal('/bin/sh -c '.escapeshellarg($hook), [], $name.' failed.');

            return true;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return false;
        }
    }

    private function absoluteDirectory(string $path): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : base_path(trim($path, '/'));
    }

    private function safeName(string $path): string
    {
        return basename($path);
    }
}
