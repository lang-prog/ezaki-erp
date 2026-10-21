<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use RuntimeException;

/**
 * Streaming, data-only reader for mysqldump/phpMyAdmin dumps. It never executes SQL: statements are
 * tokenised, INSERT tuples are parsed into PHP values and everything else is only counted.
 * Any unparseable INSERT row is recorded as rejected so callers can fail the whole analysis.
 */
final class SqlDumpReader
{
    private const CHUNK = 1048576;

    private const BLOCKED = ['UPDATE', 'DELETE', 'TRUNCATE', 'REPLACE', 'CALL', 'PREPARE', 'EXECUTE', 'LOAD', 'HANDLER'];

    /** @var resource|\GdImage|null */
    private $handle = null;

    private string $buf = '';

    private bool $eof = false;

    private int $consumedBytes = 0;

    private int $line = 1;

    /** @var array<string, list<string>> */
    private array $columnsByTable = [];

    private SourceIntegrityReport $report;

    /** @var callable(string, array<string, mixed>, int): void */
    private $sink;

    /** @var array<string, bool> */
    private array $allowed = [];

    /** @var array<string, true> */
    private array $seenAllowedKeys = [];

    public function __construct(private readonly int $maxUncompressedBytes = 536870912, private readonly int $maxStatementBytes = 33554432) {}

    /**
     * @param  list<string>  $allowedTables  tables whose rows are emitted to $sink
     * @param  callable(string, array<string, mixed>, int): void  $sink  (table, row, rowNumberInTable)
     */
    public function read(string $path, array $allowedTables, callable $sink): SourceIntegrityReport
    {
        $this->report = new SourceIntegrityReport;
        $this->allowed = array_fill_keys(array_map('strtolower', $allowedTables), true);
        $this->sink = $sink;
        $this->buf = '';
        $this->eof = false;
        $this->consumedBytes = 0;
        $this->line = 1;
        $this->columnsByTable = [];
        $this->seenAllowedKeys = [];
        $this->open($path);
        try {
            $this->scan();
        } finally {
            if ($this->handle !== null) {
                gzclose($this->handle);
                $this->handle = null;
            }
        }
        $this->report->finish($this->consumedBytes);

        return $this->report;
    }

    private function open(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The legacy source file cannot be read.');
        }
        $size = (int) filesize($path);
        $this->report->compressedBytes = $size;
        $magic = (string) file_get_contents($path, false, null, 0, 4);
        $gz = str_starts_with($magic, "\x1f\x8b");
        if (str_ends_with(strtolower($path), '.gz') && ! $gz) {
            throw new RuntimeException('The file has a .gz extension but is not a gzip stream.');
        }
        if (! $gz && $size > $this->maxUncompressedBytes) {
            throw new RuntimeException('The legacy source file is too large.');
        }
        $this->report->compressed = $gz;
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The legacy source file cannot be opened.');
        }
        $this->handle = $handle;
    }

    private function fill(): bool
    {
        if ($this->eof) {
            return false;
        }
        $chunk = gzread($this->handle, self::CHUNK);
        if ($chunk === false) {
            throw new RuntimeException('The legacy source stream is corrupt.');
        }
        if ($chunk === '') {
            $this->eof = gzeof($this->handle);
            if (! $this->eof) {
                throw new RuntimeException('The legacy source stream ended unexpectedly.');
            }

            return false;
        }
        if (str_contains($chunk, "\0")) {
            throw new RuntimeException('The legacy source contains binary data and is not a SQL dump.');
        }
        $this->consumedBytes += strlen($chunk);
        if ($this->consumedBytes > $this->maxUncompressedBytes) {
            throw new RuntimeException('The decompressed legacy source exceeds the configured limit.');
        }
        if ($this->consumedBytes === strlen($chunk) && str_starts_with($chunk, "\xEF\xBB\xBF")) {
            $chunk = substr($chunk, 3);
        }
        $this->buf .= $chunk;

        return true;
    }

    private function scan(): void
    {
        $pos = 0;
        $seg = 0;
        $stmt = '';
        $quote = '';
        $first = null;
        while (true) {
            if ($pos >= strlen($this->buf)) {
                if (! $this->fill()) {
                    break;
                }

                continue;
            }
            if ($quote !== '') {
                $pos += strcspn($this->buf, $quote.'\\', $pos);
                if ($pos >= strlen($this->buf)) {
                    continue;
                }
                if ($this->buf[$pos] === '\\') {
                    if ($pos + 1 >= strlen($this->buf) && $this->fill()) {
                        continue;
                    }
                    $pos += 2;

                    continue;
                }
                $pos++;
                $quote = '';

                continue;
            }
            $skip = strcspn($this->buf, "'\"`;-#/", $pos);
            if ($first === null && $skip > 0) {
                $lead = strspn($this->buf, " \t\r\n", $pos);
                if ($lead < $skip) {
                    $first = $pos + $lead;
                }
            }
            $pos += $skip;
            if ($pos >= strlen($this->buf)) {
                continue;
            }
            $ch = $this->buf[$pos];
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $first ??= $pos;
                $quote = $ch;
                $pos++;

                continue;
            }
            if ($ch === ';') {
                $stmt .= substr($this->buf, $seg, $pos - $seg);
                $line = $this->line + substr_count($this->buf, "\n", 0, (int) ($first ?? $pos));
                $offset = $this->consumedBytes - strlen($this->buf) + (int) ($first ?? $pos);
                $this->line += substr_count($this->buf, "\n", 0, $pos + 1);
                $this->buf = (string) substr($this->buf, $pos + 1);
                $this->handleStatement($stmt, $line, $offset);
                $stmt = '';
                $pos = 0;
                $seg = 0;
                $first = null;

                continue;
            }
            $len = $this->skipComment($pos);
            if ($len === null) {
                $this->fill();

                continue;
            }
            if ($len === 0) {
                $first ??= $pos;
                $pos++;

                continue;
            }
            $comment = substr($this->buf, $pos, $len);
            $this->noteComment($comment);
            $stmt .= substr($this->buf, $seg, $pos - $seg).' ';
            $pos += $len;
            $seg = $pos;
        }
        $rest = trim($stmt.substr($this->buf, $seg));
        if ($rest !== '' && trim((string) preg_replace('/--[^\n]*|#[^\n]*/', '', $rest)) !== '') {
            $this->report->error('*', $this->line, $this->consumedBytes, 'Unterminated trailing statement.');
        }
    }

    /** Returns the comment byte length at $pos, 0 when not a comment, null when more data is needed. */
    private function skipComment(int $pos): ?int
    {
        $c = $this->buf[$pos];
        $rest = strlen($this->buf) - $pos;
        if ($c === '#') {
            $nl = strpos($this->buf, "\n", $pos);
            if ($nl === false) {
                return $this->eof ? $rest : null;
            }

            return $nl - $pos;
        }
        if ($rest < 3 && ! $this->eof) {
            return null;
        }
        if ($c === '-') {
            if (($this->buf[$pos + 1] ?? '') !== '-' || ! in_array($this->buf[$pos + 2] ?? "\n", [' ', "\t", "\r", "\n"], true)) {
                return 0;
            }
            $nl = strpos($this->buf, "\n", $pos);
            if ($nl === false) {
                return $this->eof ? $rest : null;
            }

            return $nl - $pos;
        }
        if (($this->buf[$pos + 1] ?? '') !== '*') {
            return 0;
        }
        $end = strpos($this->buf, '*/', $pos + 2);
        if ($end === false) {
            if ($this->eof) {
                $this->report->error('*', $this->line, $this->consumedBytes, 'Unterminated block comment.');

                return $rest;
            }

            return null;
        }

        return $end + 2 - $pos;
    }

    private function noteComment(string $comment): void
    {
        if (str_starts_with($comment, '-- ')) {
            $this->report->hint($comment);
        }
        if (preg_match('/SET\s+NAMES\s+([A-Za-z0-9_]+)/i', $comment, $m)) {
            $this->report->charset(strtolower($m[1]));
        }
    }

    private function handleStatement(string $sql, int $line, int $offset): void
    {
        $sql = trim($sql);
        if ($sql === '') {
            return;
        }
        if (strlen($sql) > $this->maxStatementBytes) {
            $this->report->error('*', $line, $offset, 'A single statement exceeds the configured maximum size.');

            return;
        }
        $this->report->statements++;
        if (! preg_match('/^([A-Za-z]+)/', $sql, $kw)) {
            $this->report->error('*', $line, $offset, 'Statement does not start with a keyword.');

            return;
        }
        $keyword = strtoupper($kw[1]);
        if (in_array($keyword, self::BLOCKED, true)) {
            $this->report->error('*', $line, $offset, "Unsupported data statement {$keyword}; the dump cannot be read as pure INSERT data.");

            return;
        }
        if ($keyword === 'INSERT') {
            $this->insert($sql, $line, $offset);

            return;
        }
        $this->report->otherStatement($keyword);
        if ($keyword === 'CREATE' && preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[^`\s.(]+`?\.)?`?([^`\s(]+)`?\s*\((.*)\)\s*([^)]*)$/is', $sql, $m)) {
            $this->create(strtolower($m[1]), $m[2], $m[3]);
        } elseif ($keyword === 'SET' && preg_match('/^SET\s+NAMES\s+([A-Za-z0-9_]+)/i', $sql, $m)) {
            $this->report->charset(strtolower($m[1]));
        }
    }

    private function create(string $table, string $body, string $tail): void
    {
        $cols = [];
        $depth = 0;
        $quote = null;
        $segment = '';
        $segments = [];
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $c = $body[$i];
            if ($quote !== null) {
                $segment .= $c;
                if ($c === '\\' && $i + 1 < $length) {
                    $segment .= $body[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $segments[] = $segment;
                $segment = '';

                continue;
            }
            $segment .= $c;
        }
        $segments[] = $segment;
        foreach ($segments as $l) {
            if (preg_match('/^\s*`([^`]+)`\s+([A-Za-z]+)/', $l, $m)) {
                $cols[] = $m[1];
            }
        }
        $this->columnsByTable[$table] = $cols;
        $this->report->table($table)->columns = $cols;
        if (preg_match('/CHARSET\s*=\s*([A-Za-z0-9_]+)/i', $tail, $m)) {
            $this->report->charset(strtolower($m[1]));
        }
    }

    private function insert(string $sql, int $line, int $offset): void
    {
        $p = 0;
        if (! preg_match('/^INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?/i', $sql, $m)) {
            $this->report->error('*', $line, $offset, 'Malformed INSERT keyword sequence.');

            return;
        }
        $p = strlen($m[0]);
        $table = $this->identifier($sql, $p);
        if ($table === null) {
            $this->report->error('*', $line, $offset, 'INSERT table name cannot be read.');

            return;
        }
        $name = strtolower($table);
        $columns = null;
        $this->skipSpace($sql, $p);
        if (($sql[$p] ?? '') === '(') {
            $columns = $this->columnList($sql, $p);
            if ($columns === null) {
                $this->report->error($name, $line, $offset, 'INSERT column list is malformed.');

                return;
            }
        }
        $this->skipSpace($sql, $p);
        if (! preg_match('/\G(VALUES?)\b/i', $sql, $vm, 0, $p)) {
            $this->report->error($name, $line, $offset, 'INSERT is not a plain VALUES statement (INSERT ... SELECT/SET are unsupported).');

            return;
        }
        $p += strlen($vm[0]);
        $table = $this->report->table($name);
        $table->statements++;
        $columns ??= $this->columnsByTable[$name] ?? null;
        if ($columns === null) {
            $table->rejected++;
            $this->report->error($name, $line, $offset, 'INSERT has no column list and the dump has no CREATE TABLE metadata for this table.');

            return;
        }
        if ($table->columns === [] && $columns !== []) {
            $table->columns = $columns;
        }
        $expected = $this->countTuples($sql, $p);
        $len = strlen($sql);
        $parsed = 0;
        $rejected = 0;
        $emit = isset($this->allowed[$name]);
        while (true) {
            $this->skipSpace($sql, $p);
            if ($p >= $len) {
                break;
            }
            if ($sql[$p] === ',') {
                $p++;

                continue;
            }
            $rowOffset = $p;
            $values = $this->tuple($sql, $p, $err);
            if ($values === null) {
                $rejected++;
                $this->report->error($name, $line + substr_count($sql, "\n", 0, $rowOffset), $offset + $rowOffset, $err ?? 'Row cannot be parsed.');
                // Resynchronise at the next top-level tuple start to count the remaining rows precisely.
                $next = $this->nextTuple($sql, $rowOffset + 1);
                if ($next === null) {
                    break;
                }
                $p = $next;

                continue;
            }
            if (count($values) !== count($columns)) {
                $rejected++;
                $this->report->error($name, $line + substr_count($sql, "\n", 0, $rowOffset), $offset + $rowOffset, 'Row has '.count($values).' values for '.count($columns).' columns.');

                continue;
            }
            $row = array_combine($columns, $values);
            $parsed++;
            $table->rows++;
            $bad = $this->checkEncoding($row);
            if ($bad !== null) {
                $table->rejected++;
                $table->rows--;
                $parsed--;
                $rejected++;
                $this->report->error($name, $line + substr_count($sql, "\n", 0, $rowOffset), $offset + $rowOffset, "Column {$bad} is not valid UTF-8 and no safe conversion is declared.");

                continue;
            }
            if ($emit) {
                ($this->sink)($name, $row, $table->rows);
            }
        }
        $table->rejected += $rejected;
        if ($parsed + $rejected !== $expected) {
            $this->report->error($name, $line, $offset, "Statement row reconciliation failed: counted {$expected} tuples, parsed {$parsed}, rejected {$rejected}.");
        }
    }

    /** @param array<string, mixed> $row */
    private function checkEncoding(array &$row): ?string
    {
        foreach ($row as $column => $value) {
            if (! is_string($value) || $value === '' || mb_check_encoding($value, 'UTF-8')) {
                continue;
            }
            $declared = $this->report->declaredCharset();
            if (in_array($declared, ['cp1256', 'windows-1256'], true)) {
                $converted = function_exists('iconv') ? @iconv('CP1256', 'UTF-8', $value) : false;
                if ($converted === false) {
                    return (string) $column;
                }
                $row[$column] = $converted;
                $this->report->converted++;

                continue;
            }

            return (string) $column;
        }

        return null;
    }

    private function identifier(string $sql, int &$p): ?string
    {
        $this->skipSpace($sql, $p);
        $part = null;
        $parts = [];
        while (true) {
            if (($sql[$p] ?? '') === '`') {
                $end = strpos($sql, '`', $p + 1);
                if ($end === false) {
                    return null;
                }
                $part = substr($sql, $p + 1, $end - $p - 1);
                $p = $end + 1;
            } elseif (preg_match('/\G[A-Za-z0-9_$]+/', $sql, $m, 0, $p)) {
                $part = $m[0];
                $p += strlen($part);
            } else {
                return null;
            }
            $parts[] = $part;
            if (($sql[$p] ?? '') === '.' && count($parts) < 2) {
                $p++;

                continue;
            }
            break;
        }

        return end($parts) ?: null;
    }

    /** @return list<string>|null */
    private function columnList(string $sql, int &$p): ?array
    {
        $p++;
        $cols = [];
        while (true) {
            $this->skipSpace($sql, $p);
            if (($sql[$p] ?? '') === '`') {
                $end = strpos($sql, '`', $p + 1);
                if ($end === false) {
                    return null;
                }
                $cols[] = substr($sql, $p + 1, $end - $p - 1);
                $p = $end + 1;
            } elseif (preg_match('/\G[A-Za-z0-9_$]+/', $sql, $m, 0, $p)) {
                $cols[] = $m[0];
                $p += strlen($m[0]);
            } else {
                return null;
            }
            $this->skipSpace($sql, $p);
            $c = $sql[$p] ?? '';
            $p++;
            if ($c === ')') {
                return $cols;
            }
            if ($c !== ',') {
                return null;
            }
        }
    }

    private function skipSpace(string $sql, int &$p): void
    {
        $p += strspn($sql, " \t\r\n", $p);
    }

    /** @return list<mixed>|null */
    private function tuple(string $sql, int &$p, ?string &$err): ?array
    {
        $err = null;
        if (($sql[$p] ?? '') !== '(') {
            $err = 'Expected "(" at the start of a row.';

            return null;
        }
        $p++;
        $values = [];
        $len = strlen($sql);
        while (true) {
            $this->skipSpace($sql, $p);
            if ($p >= $len) {
                $err = 'Row is not closed.';

                return null;
            }
            $v = $this->value($sql, $p, $err);
            if ($err !== null) {
                return null;
            }
            $values[] = $v;
            $this->skipSpace($sql, $p);
            $c = $sql[$p] ?? '';
            $p++;
            if ($c === ')') {
                return $values;
            }
            if ($c !== ',') {
                $err = 'Unexpected character inside a row.';

                return null;
            }
        }
    }

    private function value(string $sql, int &$p, ?string &$err): mixed
    {
        $c = $sql[$p];
        if ($c === "'" || $c === '"') {
            return $this->string($sql, $p, $c, $err);
        }
        if (preg_match('/\G_[A-Za-z0-9]+\s*(?=[\'"])/', $sql, $m, 0, $p)) {
            $p += strlen($m[0]);

            return $this->string($sql, $p, $sql[$p], $err);
        }
        if (preg_match('/\G-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?(?=[\s,)])/', $sql, $m, 0, $p)) {
            $p += strlen($m[0]);
            if (preg_match('/^-?\d+$/', $m[0]) && strlen($m[0]) <= 18) {
                return (int) $m[0];
            }

            return $m[0];
        }
        if (preg_match('/\G(NULL|TRUE|FALSE)(?=[\s,)])/i', $sql, $m, 0, $p)) {
            $p += strlen($m[0]);
            $u = strtoupper($m[1]);

            return $u === 'NULL' ? null : ($u === 'TRUE' ? 1 : 0);
        }
        if (preg_match('/\G0x([0-9A-Fa-f]+)(?=[\s,)])/', $sql, $m, 0, $p)) {
            $p += strlen($m[0]);

            return hex2bin(strlen($m[1]) % 2 ? '0'.$m[1] : $m[1]);
        }
        $err = 'Unsupported value token near "'.substr($sql, $p, 12).'".';

        return null;
    }

    private function string(string $sql, int &$p, string $quote, ?string &$err): ?string
    {
        $p++;
        $out = '';
        $len = strlen($sql);
        while (true) {
            $n = strcspn($sql, $quote.'\\', $p);
            $out .= substr($sql, $p, $n);
            $p += $n;
            if ($p >= $len) {
                $err = 'Unterminated string literal.';

                return null;
            }
            if ($sql[$p] === $quote) {
                if (($sql[$p + 1] ?? '') === $quote) {
                    $out .= $quote;
                    $p += 2;

                    continue;
                }
                $p++;

                return $out;
            }
            $e = $sql[$p + 1] ?? '';
            $p += 2;
            $out .= match ($e) {
                '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a",
                default => $e,
            };
        }
    }

    private function countTuples(string $sql, int $p): int
    {
        $len = strlen($sql);
        $depth = 0;
        $count = 0;
        while ($p < $len) {
            $n = strcspn($sql, "'\"()\\", $p);
            $p += $n;
            if ($p >= $len) {
                break;
            }
            $c = $sql[$p];
            if ($c === "'" || $c === '"') {
                $p++;
                while ($p < $len) {
                    $n = strcspn($sql, $c.'\\', $p);
                    $p += $n;
                    if ($p >= $len) {
                        break;
                    }
                    if ($sql[$p] === '\\') {
                        $p += 2;

                        continue;
                    }
                    if (($sql[$p + 1] ?? '') === $c) {
                        $p += 2;

                        continue;
                    }
                    $p++;

                    break;
                }

                continue;
            }
            if ($c === '(') {
                if ($depth === 0) {
                    $count++;
                }
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            $p++;
        }

        return $count;
    }

    private function nextTuple(string $sql, int $p): ?int
    {
        $m = [];
        if (preg_match('/\)\s*,\s*\(/', $sql, $m, PREG_OFFSET_CAPTURE, $p)) {
            return $m[0][1] + strlen($m[0][0]) - 1;
        }

        return null;
    }
}
