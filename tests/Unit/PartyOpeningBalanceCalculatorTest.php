<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Migration\PartyOpeningBalanceCalculator;
use PHPUnit\Framework\TestCase;

final class PartyOpeningBalanceCalculatorTest extends TestCase
{
    private function calculator(): PartyOpeningBalanceCalculator
    {
        return new PartyOpeningBalanceCalculator;
    }

    private function baseTables(): array
    {
        return [
            'customer' => [
                ['id_customer' => 1, 'name_customer' => 'Customer debit', 'start_balance' => '0.00'],
                ['id_customer' => 2, 'name_customer' => 'Customer credit', 'start_balance' => '20.00'],
                ['id_customer' => 3, 'name_customer' => 'Customer zero', 'start_balance' => '10.00'],
            ],
            'sales' => [
                ['id_sales' => 10, 'id_customer' => 1, 'insert_date' => '2024-01-01', 'total' => '100.00', 'cash' => '20.00'],
                ['id_sales' => 11, 'id_customer' => 2, 'insert_date' => '2024-01-02', 'total' => '50.00', 'cash' => '80.00'],
                ['id_sales' => 12, 'id_customer' => 3, 'insert_date' => '2024-01-03', 'total' => '10.00', 'cash' => '0.00'],
                ['id_sales' => 13, 'id_customer' => 1, 'insert_date' => '2024-07-01', 'total' => '500.00', 'cash' => '0.00'],
            ],
            'import' => [
                ['id_import' => 1, 'name' => 'Supplier legacy header'],
                ['id_import' => 2, 'name' => 'Supplier category two'],
                ['id_import' => 3, 'name' => 'Supplier sale'],
            ],
            'account_import' => [
                ['id_account_import' => 1, 'id_import' => 1, 'start_cash' => '20.00'],
                ['id_account_import' => 2, 'id_import' => 2, 'start_cash' => '0.00'],
                ['id_account_import' => 3, 'id_import' => 3, 'start_cash' => '0.00'],
            ],
            'categories' => [
                ['id_categories' => 1, 'categories' => '12', 'steel_or_other' => 1],
                ['id_categories' => 2, 'categories' => 'cement', 'steel_or_other' => 2],
            ],
            'buy' => [
                ['id_buy' => 100, 'id_import' => 1, 'date' => '2024-06-30', 'kg' => '1000', 'price' => '10', 'eda3' => '5', 'id_sales' => '0'],
                ['id_buy' => 101, 'id_import' => 1, 'date' => '2024-07-01', 'kg' => '5000', 'price' => '20', 'eda3' => '900', 'id_sales' => '0'],
                ['id_buy' => 200, 'id_import' => 2, 'date' => '2024-06-30', 'kg' => '2', 'price' => '10', 'eda3' => '0', 'id_sales' => '0'],
            ],
            'details_buy' => [
                ['id_details_buy' => 1000, 'id_buy' => 100, 'id_categories' => 1, 'kg' => '1000', 'price' => '10.00'],
                ['id_details_buy' => 2000, 'id_buy' => 200, 'id_categories' => 2, 'kg' => '2', 'price' => '10.00'],
            ],
            'details_sales' => [
                ['id_details_sales' => 3000, 'id_sales' => 30, 'id_categories' => 2, 'kg' => '2', 'price' => '25', 'total' => '50'],
            ],
        ];
    }

    public function test_customer_formula_preserves_sign_and_includes_cutoff_boundary_only(): void
    {
        $result = $this->calculator()->calculate($this->baseTables(), '2024-06-30');

        self::assertSame(-80.0, $result['customers']['1']['result']);
        self::assertSame('customer_owes_company', $result['customers']['1']['direction']);
        self::assertSame(80.0, $result['customers']['1']['amount']);
        self::assertSame(50.0, $result['customers']['2']['result']);
        self::assertSame('company_owes_customer', $result['customers']['2']['direction']);
        self::assertSame(0.0, $result['customers']['3']['result']);
        self::assertSame(100.0, $result['customers']['1']['sales_total']);
        self::assertSame(20.0, $result['customers']['1']['collections']);
        self::assertSame('2024-07-01', $result['opening_effective_date']);
        self::assertSame(-580.0, $this->calculator()->calculate($this->baseTables(), '2024-07-01')['customers']['1']['result']);
        self::assertNotContains('customer_sale_amount_invalid', array_column($result['issues'], 'code'));
    }

    public function test_supplier_detail_formula_uses_category_flag_and_preserves_old_header_interpretation(): void
    {
        $result = $this->calculator()->calculate($this->baseTables(), '2024-06-30');

        self::assertSame(15.0, $result['suppliers']['1']['result']);
        self::assertSame(10.0, $result['suppliers']['1']['purchase_cost_details']);
        self::assertSame(10.0, $result['suppliers']['1']['purchase_cost_legacy_header']);
        self::assertSame(20.0, $result['suppliers']['2']['purchase_cost_details']);
        self::assertSame(-20.0, $result['suppliers']['2']['result']);
        self::assertSame('company_owes_supplier', $result['suppliers']['2']['direction']);
        self::assertTrue($result['suppliers']['2']['formula_review_required']);
        self::assertSame([], $result['suppliers']['1']['issues']);
    }

    public function test_supplier_sale_link_is_classified_once_and_excluded_from_purchase_cost(): void
    {
        $tables = $this->baseTables();
        $tables['buy'][] = ['id_buy' => 300, 'id_import' => 3, 'id_account_import' => 3, 'date' => '2024-06-30', 'kg' => '0', 'price' => '0', 'eda3' => '50', 'id_sales' => '30'];
        $tables['sales'][] = ['id_sales' => 30, 'id_customer' => 0, 'id_account_import' => 3, 'insert_date' => '2024-06-30', 'total' => '50.00', 'cash' => '0.00'];
        $result = $this->calculator()->calculate($tables, '2024-06-30');

        self::assertCount(1, $result['supplier_sales']);
        self::assertSame('sale_to_supplier', $result['supplier_sales'][0]['classification']);
        self::assertSame(50.0, $result['supplier_sales'][0]['included_supplier_component']);
        self::assertTrue($result['supplier_sales'][0]['excluded_from_purchase_cost']);
        self::assertSame(50.0, $result['suppliers']['3']['payment_component']);
        self::assertSame(50.0, $result['suppliers']['3']['result']);
        self::assertSame(0.0, $result['suppliers']['3']['purchase_cost_details']);
    }

    public function test_ambiguous_supplier_sale_is_not_included_silently(): void
    {
        $tables = $this->baseTables();
        $tables['buy'][] = ['id_buy' => 300, 'id_import' => 3, 'id_account_import' => 3, 'date' => '2024-06-30', 'kg' => '0', 'price' => '0', 'eda3' => '50', 'id_sales' => '30'];
        $tables['sales'][] = ['id_sales' => 30, 'id_customer' => 9, 'id_account_import' => 3, 'insert_date' => '2024-06-30', 'total' => '50.00', 'cash' => '0.00'];
        $result = $this->calculator()->calculate($tables, '2024-06-30');

        self::assertSame('needs_review', $result['supplier_sales'][0]['classification']);
        self::assertSame(0.0, $result['supplier_sales'][0]['included_supplier_component']);
        self::assertSame(0.0, $result['suppliers']['3']['payment_component']);
        self::assertContains('supplier_sale_has_customer', array_column($result['issues'], 'code'));
    }

    public function test_missing_amount_date_and_category_are_visible_as_review_issues(): void
    {
        $tables = $this->baseTables();
        $tables['sales'][] = ['id_sales' => 99, 'id_customer' => 1, 'insert_date' => null, 'total' => '5', 'cash' => '0'];
        $tables['buy'][] = ['id_buy' => 400, 'id_import' => 1, 'date' => '2024-06-30', 'kg' => '1', 'price' => '2', 'eda3' => '0', 'id_sales' => '0'];
        $tables['details_buy'][] = ['id_details_buy' => 4000, 'id_buy' => 400, 'id_categories' => 99, 'kg' => '1', 'price' => '2'];
        $tables['buy'][] = ['id_buy' => 401, 'id_import' => 1, 'date' => '2024-06-30', 'kg' => '1', 'price' => '2', 'eda3' => '0', 'id_sales' => '0'];
        $tables['details_buy'][] = ['id_details_buy' => 4001, 'id_buy' => 401, 'id_categories' => 1, 'kg' => null, 'price' => '2'];
        $result = $this->calculator()->calculate($tables, '2024-06-30');

        $codes = array_column($result['issues'], 'code');
        self::assertContains('sales_business_date_missing', $codes);
        self::assertContains('purchase_category_flag_missing', $codes);
        self::assertContains('purchase_line_amount_invalid', $codes);
    }
}
