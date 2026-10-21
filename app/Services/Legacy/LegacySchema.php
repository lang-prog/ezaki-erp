<?php

declare(strict_types=1);

namespace App\Services\Legacy;

/** The real old-program schema (verified against the supplied dump): staged tables and the columns the importer relies on. */
final class LegacySchema
{
    /** @var array<string, list<string>> */
    public const REQUIRED = [
        'customer' => ['id_customer', 'name_customer', 'address', 'phon', 'start_balance'],
        'import' => ['id_import', 'name'],
        'account_import' => ['id_account_import', 'id_import', 'start_cash'],
        'categories' => ['id_categories', 'categories', 'steel_or_other'],
        'type_mm' => ['id_type', 'name', 'steel_or_other'],
        'stores' => ['id_stores', 'name_stores'],
        'buy' => ['id_buy', 'date', 'id_import', 'id_type', 'bayan', 'id_stores', 'kg', 'no', 'price', 'exp', 'eda3', 'id_eda3at', 'id_sales'],
        'details_buy' => ['id_details_buy', 'id_buy', 'id_categories', 'id_type', 'id_stores', 'kg', 'no', 'price'],
        'sales' => ['id_sales', 'insert_date', 'id_customer', 'id_stores', 'bayan', 'numper', 'kg', 'tips', 'mashal', 'total', 'cash', 'id_cash'],
        'details_sales' => ['id_details_sales', 'id_sales', 'id_categories', 'id_type', 'id_stores', 'kg', 'price', 'total'],
        'store' => ['id_stoer', 'id_stores', 'id_categories', 'id_details_buy', 'id_details_sales', 'buy', 'sale', 'date_add'],
        'starting_balance' => ['id_starting_balance', 'id_categories', 'id_type', 'K_G'],
        'name_treasury' => ['id_name_treasury', 'name_treasury'],
        'treasury' => ['id_treasury', 'id_name_treasury', 'name_table', 'id_table_name', 'cash_in', 'cash_out', 'inserted_date'],
        'cash' => ['id_cash', 'cash'],
        'eda3at' => ['id_eda3at', 'cash', 'bank'],
        'name_bank' => ['id_name_bank', 'name_bank'],
        'bank_accounts' => ['id_bank_accounts', 'id_name_bank', 'account_number', 'starting_balance'],
        'bank_operations' => ['id_bank_operations', 'id_bank_accounts', 'cash_in', 'cash_out', 'inserted_date'],
        'all_expenses' => ['id_all_expenses', 'id_expenses', 'amount', 'insert_date'],
    ];

    /** @return list<string> */
    public static function staged(): array
    {
        return array_keys(self::REQUIRED);
    }
}
