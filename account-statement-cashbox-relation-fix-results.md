# Account Statement Cashbox Relationship Fix Results

## 1. Root Cause Analysis

When opening an account statement (`GET /reports/account-statement`), the application encountered an HTTP 500 fatal exception:
```text
Call to undefined relationship [cashbox] on model [App\Models\PurchaseBill].
```
and
```text
Call to undefined relationship [cashbox] on model [App\Models\SalesBill].
```

### Why Did This Occur?
1. **Schema Existence:**
   - In migration `2026_09_30_000013_add_accounting_integrity_fields.php`, `cashbox_id` and `bank_id` columns and foreign keys were added to both `purchase_bills` and `sales_bills`:
     ```php
     foreach (['purchase_bills', 'sales_bills'] as $tableName) {
         Schema::table($tableName, function (Blueprint $table): void {
             $table->foreignId('cashbox_id')->nullable()->after('payment_method')->constrained()->restrictOnDelete();
             $table->foreignId('bank_id')->nullable()->after('cashbox_id')->constrained()->restrictOnDelete();
             $table->decimal('paid_amount', 18, 2)->default(0)->after('bank_id');
         });
     }
     ```
   - Both models also added `'cashbox_id'` and `'bank_id'` to their `$fillable` array.
   - When operations are performed (such as cash/partial payment bills in `OperationsService`), `cashbox_id` or `bank_id` is populated on the bills.
2. **Missing Eloquent Relationship Methods:**
   - Neither `App\Models\PurchaseBill` nor `App\Models\SalesBill` defined the corresponding `cashbox(): BelongsTo` or `bank(): BelongsTo` relationship methods.
3. **Eager Loading Trigger:**
   - In `app/Services/AccountingReportService.php`, `attachSourceDetails()` executed:
     ```php
     $sales = SalesBill::query()->where('company_id', $company->id)->whereIn('id', $salesIds)->with(['lines.product:id,name', 'customer:id,name', 'cashbox:id,name', 'bank:id,name'])->get()->keyBy('id');
     $purchases = PurchaseBill::query()->where('company_id', $company->id)->whereIn('id', $purchaseIds)->with(['lines.product:id,name', 'supplier:id,name', 'cashbox:id,name', 'bank:id,name'])->get()->keyBy('id');
     ```
   - When Eloquent attempted to eager-load `cashbox` on `PurchaseBill` or `SalesBill`, it threw a `LogicException: Call to undefined relationship [cashbox]`.

---

## 2. Relationship & Schema Decision

Because `purchase_bills` and `sales_bills` legitimately possess foreign keys `cashbox_id` and `bank_id` in their database tables and use them to record immediate cash/partial payment sources, adding properly typed Eloquent relationships directly fulfills the Eloquent model contract:

In `App\Models\PurchaseBill`:
```php
public function cashbox(): BelongsTo
{
    return $this->belongsTo(Cashbox::class);
}

public function bank(): BelongsTo
{
    return $this->belongsTo(Bank::class);
}
```

In `App\Models\SalesBill`:
```php
public function cashbox(): BelongsTo
{
    return $this->belongsTo(Cashbox::class);
}

public function bank(): BelongsTo
{
    return $this->belongsTo(Bank::class);
}
```

### Query Optimization in `AccountingReportService`
In addition to defining the relationships, `attachSourceDetails()` was updated to only execute eager-loaded queries when ID collections are not empty:
```php
$sales = $salesIds->isNotEmpty()
    ? SalesBill::query()->where('company_id', $company->id)->whereIn('id', $salesIds)->with(['lines.product:id,name', 'customer:id,name', 'cashbox:id,name', 'bank:id,name'])->get()->keyBy('id')
    : collect();

$purchases = $purchaseIds->isNotEmpty()
    ? PurchaseBill::query()->where('company_id', $company->id)->whereIn('id', $purchaseIds)->with(['lines.product:id,name', 'supplier:id,name', 'cashbox:id,name', 'bank:id,name'])->get()->keyBy('id')
    : collect();
```
This saves database queries whenever an account statement does not contain sales or purchase documents.

---

## 3. Files Changed

| File | Change Description |
|---|---|
| `app/Models/PurchaseBill.php` | Added `cashbox(): BelongsTo` and `bank(): BelongsTo` relationships. |
| `app/Models/SalesBill.php` | Added `cashbox(): BelongsTo` and `bank(): BelongsTo` relationships. |
| `app/Services/AccountingReportService.php` | Optimized `attachSourceDetails()` to guard relation queries with `isNotEmpty()`. |
| `tests/Feature/AccountingStatementRebuildTest.php` | Added 4 new regression tests covering customer statements with sales bills & receipts, supplier statements with purchase bills & vouchers, cashbox vs. bank payment source resolution, and cross-company tenant isolation. |

---

## 4. Regression Tests & Verification Results

### Test Suite: `Tests\Feature\AccountingStatementRebuildTest`
All 12 feature tests passed with 128 assertions:
- `test_account_statement_without_date_filter_and_with_full_date_range_produce_consistent_closing_balance` (Passed)
- `test_opening_movement_strictly_before_from_is_counted_once_and_period_movement_is_not_in_opening` (Passed)
- `test_configured_opening_balance_with_journal_is_not_double_counted` (Passed)
- `test_configured_opening_balance_without_journal_is_included_once_with_correct_sign` (Passed)
- `test_reversals_net_to_zero_and_remain_auditable` (Passed)
- `test_pagination_running_balance_is_continuous_and_summary_totals_are_not_page_restricted` (Passed)
- `test_customer_statement_enriches_particulars_and_source_document_metadata` (Passed)
- `test_cross_company_isolation_on_account_statement` (Passed)
- `test_customer_account_statement_renders_http_200_with_sales_bill_and_receipt` (Passed - asserts HTTP 200, Inertia component, lines count, title, cashbox payment_source, view_url, print_url)
- `test_supplier_account_statement_renders_http_200_with_purchase_bill_and_voucher` (Passed - asserts HTTP 200, Inertia component, lines count, title, bank payment_source, view_url, print_url)
- `test_bills_with_cashbox_and_bank_sources_expose_correct_payment_source_in_statement` (Passed - verifies correct cashbox and bank names resolved for each bill)
- `test_cross_company_source_document_cannot_be_resolved_through_statement` (Passed - ensures cross-company bill IDs are never loaded or leaked)

---

## 5. Complete Quality Gates Output

1. **PHPUnit / Artisan Test:**
   ```text
   Tests: 126, Assertions: 1536, Skipped: 2.
   OK (126 tests, 1536 assertions)
   ```
2. **NPM Test Suite (`npm test`):**
   ```text
   ℹ tests 16
   ℹ suites 0
   ℹ pass 16
   ℹ fail 0
   ```
3. **Frontend Build (`npm run build`):**
   ```text
   vite v6.4.3 building for production...
   ✓ 823 modules transformed.
   ✓ built in 9.18s
   ```
4. **Code Standards Check (`vendor/bin/pint --test`):**
   ```json
   {"tool":"pint","result":"passed"}
   ```
5. **Composer Validation (`composer validate --no-check-publish`):**
   ```text
   ./composer.json is valid
   ```
6. **OpenAPI Lint (`php scripts/lint-openapi.php`):**
   ```text
   OpenAPI contract OK: 17 paths, 19 operations, version 1.0.0
   ```
7. **Security Audit (`npm audit --omit=optional`):**
   ```text
   found 0 vulnerabilities
   ```
8. **Git Check (`git diff --check`):**
   ```text
   Clean - 0 whitespace/conflict errors.
   ```

---

## 6. Limitations & Notes

- No migrations were added or modified; the database columns `cashbox_id` and `bank_id` were already in place from migration `2026_09_30_000013_add_accounting_integrity_fields.php`.
- The fix restores the expected Eloquent relationship definitions, allowing the account statement to eager-load payment sources and render without 500 errors.
