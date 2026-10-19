<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class LegacySqlSnapshotParser
{
    private const MAX_UNCOMPRESSED_BYTES = 268435456;

    /** @var list<string> */
    public const ALLOWED_TABLES = [
        'customer', 'import', 'categories', 'type_mm', 'stores', 'buy', 'details_buy', 'sales', 'details_sales',
        'store', 'name_treasury', 'treasury', 'name_bank', 'bank_accounts', 'bank_operations', 'all_expenses',
    ];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function parse(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The legacy source file cannot be read.');
        }
        if (! str_ends_with(strtolower($path), '.gz') && filesize($path) > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('The legacy source file is too large.');
        }

        $sql = str_ends_with(strtolower($path), '.gz')
            ? $this->readGzip($path)
            : file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('The legacy source file could not be read.');
        }
        $tables = array_fill_keys(self::ALLOWED_TABLES, []);
        $pattern = '/INSERT\\s+INTO\\s+`([^`]+)`\\s*\\((.*?)\\)\\s*VALUES\\s*(.*?);/is';

        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $table = strtolower($match[1]);
            if (! in_array($table, self::ALLOWED_TABLES, true)) {
                continue;
            }
            $columns = array_map(fn (string $column): string => trim($column, " `\t\r\n"), $this->splitFields($match[2]));
            foreach ($this->parseRows($match[3]) as $values) {
                if (count($columns) !== count($values)) {
                    continue;
                }
                $tables[$table][] = array_combine($columns, $values);
            }
        }

        return $tables;
    }

    private function readGzip(string $path): string
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The compressed legacy source file cannot be opened.');
        }
        $contents = '';
        while (! gzeof($handle)) {
            $chunk = gzread($handle, 1024 * 1024);
            if ($chunk === false) {
                gzclose($handle);
                throw new RuntimeException('The compressed legacy source file could not be read.');
            }
            $contents .= $chunk;
            if (strlen($contents) > self::MAX_UNCOMPRESSED_BYTES) {
                gzclose($handle);
                throw new RuntimeException('The decompressed legacy source file is too large.');
            }
        }
        gzclose($handle);

        return $contents;
    }

    /** @return list<string> */
    private function splitFields(string $input): array
    {
        $fields = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        $length = strlen($input);
        for ($index = 0; $index < $length; $index++) {
            $character = $input[$index];
            if ($escaped) {
                $buffer .= $character;
                $escaped = false;

                continue;
            }
            if ($quoted && $character === '\\') {
                $buffer .= $character;
                $escaped = true;

                continue;
            }
            if ($character === "'") {
                $quoted = ! $quoted;
                $buffer .= $character;

                continue;
            }
            if (! $quoted && $character === ',') {
                $fields[] = $buffer;
                $buffer = '';

                continue;
            }
            $buffer .= $character;
        }
        $fields[] = $buffer;

        return $fields;
    }

    /** @return list<list<mixed>> */
    private function parseRows(string $values): array
    {
        $rows = [];
        $length = strlen($values);
        $depth = 0;
        $quoted = false;
        $escaped = false;
        $buffer = '';
        for ($index = 0; $index < $length; $index++) {
            $character = $values[$index];
            if ($escaped) {
                $buffer .= $character;
                $escaped = false;

                continue;
            }
            if ($quoted && $character === '\\') {
                $buffer .= $character;
                $escaped = true;

                continue;
            }
            if ($character === "'") {
                $quoted = ! $quoted;
                if ($depth > 0) {
                    $buffer .= $character;
                }

                continue;
            }
            if (! $quoted && $character === '(') {
                $depth++;
                if ($depth > 1) {
                    $buffer .= $character;
                }

                continue;
            }
            if (! $quoted && $character === ')') {
                $depth--;
                if ($depth === 0) {
                    $rows[] = array_map(fn (string $value): mixed => $this->literal($value), $this->splitFields($buffer));
                    $buffer = '';
                } elseif ($depth > 0) {
                    $buffer .= $character;
                }

                continue;
            }
            if ($depth > 0) {
                $buffer .= $character;
            }
        }

        return $rows;
    }

    private function literal(string $value): mixed
    {
        $value = trim($value);
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            $value = substr($value, 1, -1);

            return strtr($value, [
                '\\0' => "\0", '\\n' => "\n", '\\r' => "\r", '\\Z' => "\x1a",
                "\\'" => "'", '\\"' => '"', '\\\\' => '\\',
            ]);
        }
        if (preg_match('/^-?\\d+$/', $value)) {
            return (int) $value;
        }
        if (preg_match('/^-?\\d+\\.\\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }
}
