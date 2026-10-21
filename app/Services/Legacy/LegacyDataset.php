<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Models\DataMigrationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** In-memory, indexed view of the staged legacy rows used by the analysis services. */
final class LegacyDataset
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    private array $indexes = [];

    private function __construct(public readonly DataMigrationRun $run) {}

    /** @param list<string>|null $tables */
    public static function load(DataMigrationRun $run, ?array $tables = null): self
    {
        $self = new self($run);
        foreach ($tables ?? LegacySchema::staged() as $table) {
            $rows = [];
            foreach (DB::table('data_migration_source_rows')->where('run_id', $run->id)->where('source_table', $table)->orderBy('row_number')->select('payload')->cursor() as $r) {
                $rows[] = json_decode($r->payload, true, 512, JSON_THROW_ON_ERROR);
            }
            $self->tables[$table] = $rows;
        }

        return $self;
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function where(string $table, string $column, mixed $value): array
    {
        $this->indexes[$table][$column] ??= $this->build($table, $column);

        return $this->indexes[$table][$column][(string) $value] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function one(string $table, string $column, mixed $value): ?array
    {
        return $this->where($table, $column, $value)[0] ?? null;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function build(string $table, string $column): array
    {
        $index = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            $index[(string) ($row[$column] ?? '')][] = $row;
        }

        return $index;
    }

    public static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function key(mixed $value): string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' || $s === '0' ? '0' : $s;
    }

    /** Returns null for NULL, zero dates and implausible years so callers must route the row to review. */
    public static function date(mixed $value): ?CarbonImmutable
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '' || str_starts_with($s, '0000') || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return null;
        }
        if ((int) substr($s, 0, 4) < (int) config('migration.valid_from_year', 2015)) {
            return null;
        }
        try {
            return CarbonImmutable::parse(substr($s, 0, 10))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
