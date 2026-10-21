<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Legacy\SourceIntegrityReport;
use App\Services\Legacy\SqlDumpReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqlDumpReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function file(string $content, string $ext = 'sql'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dmr').'.'.$ext;
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /** @return array{0: array<string, list<array<string, mixed>>>, 1: SourceIntegrityReport} */
    private function read(string $path, array $tables = ['t'], ?SqlDumpReader $reader = null): array
    {
        $rows = [];
        $report = ($reader ?? new SqlDumpReader)->read($path, $tables, function (string $t, array $row) use (&$rows): void {
            $rows[$t][] = $row;
        });

        return [$rows, $report];
    }

    public function test_reads_multi_line_inserts_with_column_lists(): void
    {
        [$rows, $report] = $this->read($this->file("CREATE TABLE `t` (`id` int, `name` varchar(9));\nINSERT INTO `t` (`id`, `name`) VALUES\n(1, 'a'),\n(2, 'b');\n"));
        $this->assertCount(2, $rows['t']);
        $this->assertSame('b', $rows['t'][1]['name']);
        $this->assertSame(0, $report->errorCount);
        $this->assertSame(2, $report->tables['t']->rows);
    }

    public function test_semicolons_quotes_escapes_and_comments_inside_strings_are_data(): void
    {
        $sql = "-- header; comment\n/* block; */\nINSERT INTO `t` (`id`, `v`) VALUES (1, 'a;b'), (2, 'it''s'), (3, 'x\\'y'), (4, '-- not a comment'), (5, '/* nor this */');\n";
        [$rows, $report] = $this->read($this->file($sql));
        $this->assertSame(['a;b', "it's", "x'y", '-- not a comment', '/* nor this */'], array_column($rows['t'], 'v'));
        $this->assertSame(0, $report->errorCount);
    }

    public function test_insert_ignore_qualified_names_and_null_values(): void
    {
        [$rows] = $this->read($this->file("INSERT IGNORE INTO `db`.`t` (`id`, `v`) VALUES (1, NULL), (2, 3.50);\n"));
        $this->assertCount(2, $rows['t']);
        $this->assertNull($rows['t'][0]['v']);
    }

    public function test_column_less_insert_uses_create_table_metadata(): void
    {
        [$rows, $report] = $this->read($this->file("CREATE TABLE `t` (\n  `id` int NOT NULL,\n  `v` varchar(5) DEFAULT NULL,\n  PRIMARY KEY (`id`)\n);\nINSERT INTO `t` VALUES (1,'x');\n"));
        $this->assertSame(['id' => 1, 'v' => 'x'], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $rows['t'][0]));
        $this->assertSame(0, $report->errorCount);
    }

    public function test_column_less_insert_without_metadata_is_an_error_not_a_guess(): void
    {
        [$rows, $report] = $this->read($this->file("INSERT INTO `t` VALUES (1,'x');\n"));
        $this->assertArrayNotHasKey('t', $rows);
        $this->assertGreaterThan(0, $report->errorCount);
    }

    public function test_tuple_with_wrong_column_count_is_rejected_and_counted(): void
    {
        [$rows, $report] = $this->read($this->file("INSERT INTO `t` (`id`, `v`) VALUES (1, 'a'), (2);\n"));
        $this->assertGreaterThan(0, $report->errorCount);
        $this->assertLessThanOrEqual(1, count($rows['t'] ?? []));
    }

    public function test_destructive_statements_are_never_executed_or_emitted(): void
    {
        [$rows, $report] = $this->read($this->file("DELETE FROM t;\nUPDATE t SET v=1;\nREPLACE INTO t (id) VALUES (1);\nINSERT INTO `t` (`id`) VALUES (7);\n"));
        $this->assertSame([7], array_column($rows['t'] ?? [], 'id'));
        $this->assertGreaterThan(0, $report->errorCount + array_sum($report->otherStatements));
    }

    public function test_tables_outside_the_whitelist_are_ignored(): void
    {
        [$rows] = $this->read($this->file("INSERT INTO `other` (`id`) VALUES (1);\nINSERT INTO `t` (`id`) VALUES (2);\n"));
        $this->assertArrayNotHasKey('other', $rows);
        $this->assertCount(1, $rows['t']);
    }

    public function test_gzip_is_streamed_and_a_fake_gz_extension_is_refused(): void
    {
        $gz = $this->file(gzencode("INSERT INTO `t` (`id`) VALUES (1),(2),(3);\n"), 'gz');
        $this->assertCount(3, $this->read($gz)[0]['t']);
        $this->expectException(RuntimeException::class);
        $this->read($this->file("INSERT INTO `t` (`id`) VALUES (1);\n", 'gz'));
    }

    public function test_binary_content_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->read($this->file("\x00\x01\x02 binary\x00"));
    }

    public function test_decompressed_size_limit_is_enforced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->read($this->file(gzencode(str_repeat("INSERT INTO `t` (`id`) VALUES (1);\n", 5000)), 'gz'), ['t'], new SqlDumpReader(1000, 1000));
    }

    public function test_statement_size_limit_is_enforced(): void
    {
        [$rows, $report] = $this->read($this->file("INSERT INTO `t` (`v`) VALUES ('".str_repeat('x', 5000)."');\n"), ['t'], new SqlDumpReader(1000000, 1000));
        $this->assertArrayNotHasKey('t', $rows);
        $this->assertGreaterThan(0, $report->errorCount);
    }

    public function test_non_utf8_legacy_bytes_do_not_crash_the_reader(): void
    {
        $cp1256 = "/*!40101 SET NAMES cp1256 */;\nINSERT INTO `t` (`id`, `v`) VALUES (1, '".chr(0xC7).chr(0xE1)."');\n";
        [$rows, $report] = $this->read($this->file($cp1256));
        $this->assertCount(1, $rows['t']);
        $this->assertSame(0, $report->errorCount);
        $this->assertTrue(mb_check_encoding((string) $rows['t'][0]['v'], 'UTF-8'));
    }
}
