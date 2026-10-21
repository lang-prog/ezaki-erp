<?php

declare(strict_types=1);

namespace App\Services\Legacy;

/** Collected facts about one pass over a dump; error details never contain row values. */
final class SourceIntegrityReport
{
    public int $compressedBytes = 0;

    public bool $compressed = false;

    public int $uncompressedBytes = 0;

    public int $statements = 0;

    public int $converted = 0;

    /** @var array<string, TableStats> */
    public array $tables = [];

    /** @var array<string, int> */
    public array $otherStatements = [];

    /** @var list<array{table: string, line: int, offset: int, message: string}> */
    public array $errors = [];

    public int $errorCount = 0;

    /** @var list<string> */
    public array $hints = [];

    /** @var array<string, true> */
    public array $charsets = [];

    private const ERROR_CAP = 200;

    public function table(string $name): TableStats
    {
        return $this->tables[$name] ??= new TableStats;
    }

    public function error(string $table, int $line, int $offset, string $message): void
    {
        $this->errorCount++;
        if (count($this->errors) < self::ERROR_CAP) {
            $this->errors[] = ['table' => $table, 'line' => $line, 'offset' => $offset, 'message' => $message];
        }
    }

    public function otherStatement(string $keyword): void
    {
        $this->otherStatements[$keyword] = ($this->otherStatements[$keyword] ?? 0) + 1;
    }

    public function hint(string $comment): void
    {
        if (count($this->hints) < 20) {
            $this->hints[] = mb_substr(trim($comment), 0, 160);
        }
    }

    public function charset(string $name): void
    {
        $this->charsets[$name] = true;
    }

    public function declaredCharset(): ?string
    {
        $names = array_keys($this->charsets);

        return $names === [] ? null : end($names);
    }

    public function finish(int $bytes): void
    {
        $this->uncompressedBytes = $bytes;
    }

    public function hasContent(): bool
    {
        return $this->tables !== [] || isset($this->otherStatements['CREATE']);
    }

    /** @param list<string> $material */
    public function isMaterialClean(array $material): bool
    {
        foreach ($this->errors as $e) {
            if ($e['table'] === '*' || in_array($e['table'], $material, true)) {
                return false;
            }
        }
        foreach ($material as $t) {
            if (($this->tables[$t]->rejected ?? 0) > 0) {
                return false;
            }
        }

        return $this->hasContent();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tables = [];
        foreach ($this->tables as $name => $s) {
            $tables[$name] = ['statements' => $s->statements, 'rows' => $s->rows, 'rejected' => $s->rejected, 'columns' => count($s->columns)];
        }
        ksort($tables);

        return [
            'compressed' => $this->compressed,
            'compressed_bytes' => $this->compressedBytes,
            'uncompressed_bytes' => $this->uncompressedBytes,
            'statements' => $this->statements,
            'tables' => $tables,
            'other_statements' => $this->otherStatements,
            'charsets' => array_keys($this->charsets),
            'converted_values' => $this->converted,
            'error_count' => $this->errorCount,
            'errors' => $this->errors,
            'hints' => $this->hints,
        ];
    }
}
