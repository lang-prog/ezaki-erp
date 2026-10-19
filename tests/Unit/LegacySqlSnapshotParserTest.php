<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LegacyAnalysisService;
use App\Services\LegacySqlSnapshotParser;
use PHPUnit\Framework\TestCase;

final class LegacySqlSnapshotParserTest extends TestCase
{
    public function test_parser_reads_only_allowed_insert_tables_and_preserves_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ezaki-legacy-');
        file_put_contents($path, <<<'SQL'
CREATE TABLE `customer` (`id_customer` int, `name_customer` varchar(60));
INSERT INTO `customer` (`id_customer`, `name_customer`) VALUES (1, 'عميل، أول'), (2, 'O\'Reilly');
INSERT INTO `users` (`id`, `name`) VALUES (99, 'must not be read');
SQL);

        try {
            $tables = (new LegacySqlSnapshotParser)->parse($path);
            $this->assertCount(2, $tables['customer']);
            $this->assertSame(1, $tables['customer'][0]['id_customer']);
            $this->assertSame('عميل، أول', $tables['customer'][0]['name_customer']);
            $this->assertSame("O'Reilly", $tables['customer'][1]['name_customer']);
            $this->assertArrayNotHasKey('users', $tables);
        } finally {
            @unlink($path);
        }
    }

    public function test_analysis_classifies_invoice_candidates_and_review_records(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ezaki-legacy-');
        file_put_contents($path, <<<'SQL'
INSERT INTO `customer` (`id_customer`, `name_customer`) VALUES (1, 'Customer');
INSERT INTO `import` (`id_import`, `name`) VALUES (2, 'Supplier');
INSERT INTO `type_mm` (`id_type`, `name`) VALUES (3, 'Steel');
INSERT INTO `categories` (`id_categories`, `categories`) VALUES (4, '12');
INSERT INTO `stores` (`id_stores`, `name_stores`) VALUES (5, 'Main');
INSERT INTO `buy` (`id_buy`, `date`, `id_import`, `id_type`, `id_stores`) VALUES (10, '2026-10-02', 2, 3, 5), (11, '2026-10-03', 2, NULL, NULL);
INSERT INTO `details_buy` (`id_details_buy`, `id_buy`, `id_categories`, `id_type`, `id_stores`, `kg`, `no`, `price`, `note`) VALUES (100, 10, 4, 3, 5, 1000, 1, 20, 'ok');
INSERT INTO `sales` (`id_sales`, `insert_date`, `id_customer`, `id_stores`) VALUES (20, '2026-10-04', 1, 5), (21, '2026-10-05', NULL, NULL);
INSERT INTO `details_sales` (`id_details_sales`, `id_sales`, `id_categories`, `id_type`, `id_stores`, `kg`, `price`, `total`, `note`) VALUES (200, 20, 4, 3, 5, 500, 25, 12500, 'ok');
SQL);

        try {
            $summary = (new LegacyAnalysisService(new LegacySqlSnapshotParser))->analyze($path, '2026-10-01', '2026-10-18');
            $this->assertSame(1, $summary['documents']['purchase_candidates']);
            $this->assertSame(1, $summary['documents']['purchase_candidate_lines']);
            $this->assertSame(1, $summary['documents']['purchase_needs_review']);
            $this->assertSame(1, $summary['documents']['sales_candidates']);
            $this->assertSame(1, $summary['documents']['sales_candidate_lines']);
            $this->assertSame(1, $summary['documents']['sales_needs_review']);
            $this->assertSame(1, $summary['master_counts']['customers']);
        } finally {
            @unlink($path);
        }
    }
}
