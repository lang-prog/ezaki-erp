<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Models\DataMigrationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class SourceImporter
{
    private const BATCH = 400;

    /** @return array<string, mixed> integrity summary (also persisted on the run) */
    public function import(DataMigrationRun $run): array
    {
        $path = Storage::disk($run->source_disk)->path($run->source_path);
        if (hash_file('sha256', $path) !== $run->source_hash) {
            throw new SourceIntegrityException('The stored source no longer matches its recorded SHA-256 hash.', ['hash_mismatch' => true]);
        }
        $reader = new SqlDumpReader((int) config('migration.max_uncompressed_bytes'), (int) config('migration.max_statement_bytes'));
        $buffer = [];
        $staged = [];
        $flush = function () use (&$buffer): void {
            if ($buffer !== []) {
                DB::table('data_migration_source_rows')->insert($buffer);
                $buffer = [];
            }
        };

        $report = null;
        DB::transaction(function () use ($run, $path, $reader, &$buffer, &$staged, $flush, &$report): void {
            DB::table('data_migration_source_rows')->where('run_id', $run->id)->delete();
            $report = $reader->read($path, LegacySchema::staged(), function (string $table, array $row, int $n) use (&$buffer, &$staged, $run, $flush): void {
                $first = array_key_first($row);
                $buffer[] = [
                    'run_id' => $run->id, 'source_table' => $table, 'row_number' => $n,
                    'source_key' => $first === null || $row[$first] === null ? null : mb_substr((string) $row[$first], 0, 100),
                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ];
                $staged[$table] = ($staged[$table] ?? 0) + 1;
                if (count($buffer) >= self::BATCH) {
                    $flush();
                }
            });
            $flush();
        });

        $integrity = $report->toArray();
        $integrity['staged_rows'] = $staged;
        $integrity['schema_issues'] = $this->schemaIssues($report);
        $integrity['duplicate_keys'] = $this->duplicateKeys($run);
        $integrity['row_reconciliation'] = $this->reconcile($report, $staged);
        $material = LegacySchema::staged();
        $clean = $report->isMaterialClean($material) && $integrity['schema_issues'] === [] && $integrity['row_reconciliation'] === [];
        $integrity['material_clean'] = $clean;
        if (! $clean) {
            DB::table('data_migration_source_rows')->where('run_id', $run->id)->delete();
            throw new SourceIntegrityException('The source dump could not be read without losing data; nothing was staged.', $integrity);
        }

        return $integrity;
    }

    /** @return list<string> */
    private function schemaIssues(SourceIntegrityReport $report): array
    {
        $issues = [];
        foreach (LegacySchema::REQUIRED as $table => $columns) {
            $stats = $report->tables[$table] ?? null;
            if ($stats === null) {
                $issues[] = "missing_table:{$table}";

                continue;
            }
            foreach (array_diff($columns, $stats->columns) as $col) {
                $issues[] = "missing_column:{$table}.{$col}";
            }
        }

        return $issues;
    }

    /** @param array<string, int> $staged @return list<string> */
    private function reconcile(SourceIntegrityReport $report, array $staged): array
    {
        $issues = [];
        foreach (LegacySchema::staged() as $table) {
            $parsed = $report->tables[$table]->rows ?? 0;
            if (($staged[$table] ?? 0) !== $parsed) {
                $issues[] = "staged_mismatch:{$table}:{$parsed}:".($staged[$table] ?? 0);
            }
        }

        return $issues;
    }

    /** @return array<string, int> */
    private function duplicateKeys(DataMigrationRun $run): array
    {
        $rows = DB::table('data_migration_source_rows')
            ->where('run_id', $run->id)->whereNotNull('source_key')
            ->groupBy('source_table', 'source_key')->havingRaw('count(*) > 1')
            ->selectRaw('source_table, count(*) as duplicated')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->source_table] = ($out[$r->source_table] ?? 0) + 1;
        }

        return $out;
    }
}
