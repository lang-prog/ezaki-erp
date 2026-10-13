# Account Statement Rebuild Results

## 1. Executive Summary & Root Cause Analysis

### The Reported Problem
When viewing customer or supplier account statements, two contradictory outcomes occurred depending on whether the user specified date filters or not:
- **Without date filters:** The header displayed an opening balance of approximately `-450,000`, the closing balance was approximately `-900,000`, and a synthetic `Beginning balance` row was inserted into the transaction table.
- **With a start and end date:** The header displayed an opening balance of `0.00`, the closing balance was approximately `-450,000`, and the actual purchase/payment activity totaled `-450,000`.

### Root Cause in Code
1. **Unbounded Historical Opening Query in `AccountingReportService::balanceForAccounts()`:**
   When `$from` was null (no date filter provided), the query:
   ```php
   ->when($before, fn ($query) => $query->whereDate('entries.entry_date', '<', $before))
   ```
   evaluated to false. As a consequence, `balanceForAccounts()` executed **without any date condition**, returning the lifetime sum of all journal lines ever posted to that account (e.g. `-450,000`).
2. **Double-Counting in Running Balance Initialization:**
   In `AccountingReportService::accountStatement()`:
   - When `$from` was null, `$openingBalance` was populated with the lifetime balance (`-450,000`).
   - The query for `$lines` also fetched all transactions without a date filter (which summed to `-450,000`).
   - The running balance calculation initialized `$running = $openingBalance + (float) $priorLines;`.
   - As each line was traversed, `(debit - credit)` was added to `$running` a second time: `-450,000 + (-450,000) = -900,000`.
3. **Synthetic Row Pollution in Frontend Presentation:**
   In `resources/js/Pages/Company/AccountingReport.jsx`, a synthetic `<tr className="statement-opening-row">` was rendered directly inside `<tbody>`. This placed a `-450,000` opening row ahead of transactions that already encompassed that exact balance, misleading accountants into seeing double the liability or receivable.
4. **Discrepancy with Date Filters:**
   When the user selected a date range such as `from = 2026-01-01`, `balanceForAccounts()` added `whereDate('entries.entry_date', '<', '2026-01-01')`. Because no activity predated `2026-01-01`, the opening balance correctly returned `0.00`, and the running balance ended at `-450,000.00`.

---

## 2. Standardized Accounting Model & Signed Convention

We have adopted and enforced one unified, signed balance convention across the entire accounting system:

```text
signed movement = debit - credit
closing balance = opening balance + period debits - period credits
```

### Date Range Rules:
1. **With an explicit `from` date:**
   - **Opening Balance:** Strict sum of posted, non-reversed journal lines with `entry_date < from` (exclusive of `from`), plus any unjournaled legacy opening balance with `opening_balance_date < from`.
   - **Period Movements:** All posted, non-reversed journal lines with `entry_date >= from` and (if specified) `entry_date <= to` (inclusive of `from` and `to`).
   - **Closing Balance:** `opening_balance + period_debit - period_credit`.
2. **Without date filters (`from = null`):**
   - Since all posted transactions from the beginning of time are included in the period movements, **no journal movements occurred prior to the displayed report period**.
   - Therefore, the journal-derived opening balance is strictly `0.00`.
   - Any legacy unjournaled party opening balance (which predates all journal entries) is included with its signed direction (`+` for debit, `-` for credit).
   - The closing balance equals `opening_balance + period_debit - period_credit`.
   - The closing balance without date filters is mathematically identical to a date-filtered query covering the entire activity range.
3. **With only `to` specified:**
   - Movements from inception through `to` are included in the report. Opening balance is `0.00` (plus unjournaled legacy opening balance).
4. **Handling of Configured Opening Balances:**
   - If a party opening balance was posted through a journal entry (`opening_balance_journal_entry_id` is set), the journal entry exists in `journal_lines` and is counted as a journal line. The raw field on `customer_suppliers` is **never added again**.
   - If an opening balance was legacy/unjournaled (`opening_balance_journal_entry_id` is null), it is added strictly once according to its `opening_balance_direction` (`debit` is positive, `credit` is negative).
   - No synthetic opening transaction rows are injected into `<tbody>`. Opening balance is presented in dedicated summary KPI cards and summary banners above the table.

---

## 3. Server-Side DTO & Architecture

`AccountingReportService::accountStatement()` returns a structured DTO with complete separation of concerns:

```php
return [
    'account' => $account,
    'account_ids' => $accountIds,
    'opening_balance' => $openingBalance,
    'lines' => $lines, // LengthAwarePaginator with query string preserved
    'from' => $from,
    'to' => $to,
    'meta' => [
        'account_id' => $account->id,
        'account_code' => $account->code,
        'account_name' => $account->name,
        'party_id' => $party?->id,
        'party_name' => $party?->name,
        'party_type' => $partyType, // 'customer' | 'supplier' | 'both' | null
        'from' => $from,
        'to' => $to,
        'date_filter_mode' => $from ? 'filtered' : 'all_activity',
    ],
    'summary' => [
        'opening_balance' => $openingBalance,
        'period_debit' => $periodDebit,
        'period_credit' => $periodCredit,
        'period_net' => $periodNet,
        'closing_balance' => $closingBalance,
        'transaction_count' => $transactionCount,
    ],
    'closing_balance' => $closingBalance,
    'period_debit' => $periodDebit,
    'period_credit' => $periodCredit,
    'period_net' => $periodNet,
    'transaction_count' => $transactionCount,
];
```

### Continuous Pagination Across Pages:
- When page 2 is loaded, `$summary` (opening balance, total debits, total credits, net movement, closing balance, total transaction count) is calculated across the **entire filtered dataset**, not restricted to the current page.
- The running balance of the first item on page 2 begins from `opening_balance + movementBeforeLine()`, maintaining seamless continuity with the last row of page 1.

---

## 4. Human-Readable Description & Particulars

Every line in the statement provides localized, human-readable particulars:
- **Sales Invoices:** `Sales invoice · SB-2026-000014 · Customer name` / `فاتورة بيع · SB-2026-000014 · اسم العميل`
- **Purchase Bills:** `Purchase bill · PB-2026-000002 · Supplier name` / `فاتورة شراء · PB-2026-000002 · اسم المورد`
- **Customer Receipts:** `Customer receipt · RC-2026-000003 · Customer name` / `إيصال تحصيل · RC-2026-000003 · اسم العميل`
- **Supplier Payments:** `Supplier payment · PV-2026-000003 · Supplier name` / `سند صرف · PV-2026-000003 · اسم المورد`
- **Opening Balance Journals:** `Opening balance · Party name` / `رصيد افتتاحي · اسم الجهة`
- **Reversals:** `Reversal of [number] · [reason]` / `عكس قيد · [البيان]`
- **General Journals:** `Journal entry · JE-2026-000001 · Description` / `قيد يومية · JE-2026-000001 · البيان`

---

## 5. Document Preview Modal & Actions

Clicking any transaction row or action button opens `DocumentModal` with in-page inspection:
1. **Document Identification:** Document number, type badge, date, customer/supplier name.
2. **Financial Overview:** Total amount, settled/paid amount, remaining unpaid/unallocated balance, payment source (cashbox/bank).
3. **Audit & Reversal Trail:** Status pill (`posted`, `approved`, `reversed`), reversal reason, and cancellation date.
4. **Item Lines:** Table of line items (product, weight, packages, unit price, total).
5. **Allocation History:**
   - Invoices show payments applied to them (receipt/voucher number, amount, date).
   - Receipts and payment vouchers show invoices settled by them.
6. **Action Buttons:**
   - **Print:** Opens print view in a new tab (`/operations/sales/{id}/print`, `/operations/purchase/{id}/print`, `/accounting/receipts/{id}/print`, `/accounting/payment-vouchers/{id}/print`), guarded by permissions (`sales.print`, `purchases.print`, `accounting.view`).
   - **Open full document:** Opens full document show page (`/operations/sales/{id}`, `/operations/purchase/{id}`, `/accounting/receipts/{id}`, `/accounting/payment-vouchers/{id}`), guarded by permissions (`sales.view`, `purchases.view`, `accounting.view`).
   - Receipts are strictly routed to `/accounting/receipts`, and vouchers are strictly routed to `/accounting/payment-vouchers`.

---

## 6. Files Changed & Added

| File | Purpose |
|---|---|
| `app/Services/AccountingReportService.php` | Rebuilt `accountStatement()`, `movementBeforeLine()`, and `attachSourceDetails()` with bounded date logic, pagination continuity, allocations, and particulars. |
| `app/Services/AccountingService.php` | Ensured `createParty()` returns refreshed model with `opening_balance_journal_entry_id`. |
| `app/Http/Controllers/Web/CoreErpPageController.php` | Forwarded `sales.view`, `sales.print`, `purchases.view`, `purchases.print` capabilities to the accounting report. |
| `resources/js/Pages/Company/AccountingReport.jsx` | Redesigned statement view with KPI summary cards, clear table columns (Date, Particulars, Document, Debit, Credit, Settled, Balance), and rich `DocumentModal`. |
| `resources/js/i18n.js` | Added localization keys for period debits, period credits, net movement, closing balance, all activity, settled amount, remaining amount, and particulars in English and Arabic. |
| `resources/css/navigation.css` | Added styling for statement KPI cards, metadata banners, document badges, and print media rules. |
| `tests/Feature/AccountingStatementRebuildTest.php` | Comprehensive PHPUnit regression tests for no-filter vs filtered parity, legacy opening, reversals, pagination, and multi-tenant isolation. |
| `tests/statement.test.mjs` | Node tests validating statement UI elements, KPI separation, modal behavior, and localization. |
| `package.json` | Registered `tests/statement.test.mjs` in test runner. |

---

## 7. Validation Gates & Execution Results

All project validation gates were run and confirmed clean:

1. **Backend Tests (`php vendor/bin/phpunit`):**
   ```text
   Tests: 122, Assertions: 1459, Skipped: 2.
   OK (122 tests, 1459 assertions)
   ```
2. **Frontend Tests (`npm test`):**
   ```text
   ✔ save button is a submit button inside the form wired to the save handler
   ✔ submit posts the spoofed PUT through form.post without chaining transform()
   ✔ failures surface a message and success resets upload state
   ✔ sends the decoded encrypted XSRF cookie in the Laravel header
   ✔ csrfFetch preserves FormData and sends same-origin credentials and CSRF header
   ✔ all company and platform destinations have unique IDs, labels and reachable routes
   ✔ owner receives all permitted destinations and correct owner-only settings
   ✔ staff sees only allowed destinations without empty groups or owner settings
   ✔ search filters by Arabic and English labels without exposing denied links
   ✔ active matching distinguishes hashes, document creation and reporting routes
   ✔ statement renders opening balance and metrics in summary KPI cards separately from table rows
   ✔ particulars column displays localized description with party subtitle
   ✔ document modal presents full audit details including settled, remaining, payment source, and reversal reason
   ✔ document modal action buttons have explicit type="button" and respect permissions
   ✔ filter bar preserves account_id, from_date, and to_date with apply button
   ✔ all statement translation keys are localized in Arabic and English
   ℹ tests 16
   ℹ pass 16
   ℹ fail 0
   ```
3. **Frontend Production Build (`npm run build`):**
   ```text
   vite v6.4.3 building for production...
   ✓ 823 modules transformed.
   ✓ built in 10.03s
   ```
4. **Code Standards (`php vendor/bin/pint --test`):**
   ```json
   {"tool":"pint","result":"passed"}
   ```
5. **Composer Integrity (`composer validate --no-check-publish`):**
   ```text
   ./composer.json is valid
   ```
6. **OpenAPI Documentation (`php scripts/lint-openapi.php`):**
   ```text
   OpenAPI contract OK: 17 paths, 19 operations, version 1.0.0
   ```
7. **Security Audit (`npm audit --omit=dev`):**
   ```text
   found 0 vulnerabilities
   ```
8. **Git Check (`git diff --check`):**
   ```text
   Clean - 0 whitespace/conflict errors.
   ```
9. **Migration Check (`php artisan migrate:status` & `php artisan migrate --pretend`):**
   ```text
   All 25 migrations ran. Nothing pending to migrate.
   ```

---

## 8. Deployment & Operational Notes

- No destructive database changes or data rewrites were performed.
- All existing historical records remain valid and intact.
- The account statement calculates running balances dynamically and correctly without requiring historical mutations.
- When opening an account statement without date filters, the system automatically presents all posted activity starting from `0.00` opening balance (or legacy unjournaled opening balance) and concludes with the mathematically correct closing balance matching date-filtered reports.
