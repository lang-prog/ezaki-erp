# E‑Zaki ERP — Invoice Revision Chains, Party Statements, Financial Reports, and UI/UX Remediation Results

## 1. Executive Summary & Root Cause Analysis

### The Reported Problem
When an approved sales or purchase bill was edited and revised:
1. `OperationsService::revise()` properly created a reversal journal entry for the original bill values and posted a new replacement journal entry for the amended values.
2. In double-entry accounting, this maintains an immutable audit trail.
3. However, `AccountingReportService::accountStatement()` presented every raw journal line and technical reversal directly inside the customer/supplier statement.
4. As a result, the statement displayed duplicate/triplicate rows for what the customer or supplier perceived as a single commercial invoice:
   - Initial invoice row (e.g. Debit 1,000 EGP)
   - Technical reversal entry (e.g. Credit 1,000 EGP)
   - Amended replacement invoice row (e.g. Debit 1,200 EGP)
5. While the net sum numerically reconciled, the operational customer statement was confusing, cluttered, and unprofessional.

### Defect Classification: Reporting Aggregation vs. Posting Defect
- **Posting Lifecycle:** The core double-entry posting mechanism (reversing the old journal and posting the replacement) was **conceptually correct for auditability**, but lacked durable foreign keys linking the original journal, reversal journal, and replacement journal together with an explicit revision chain ID.
- **Reporting Aggregation Defect:** The account statement failed to distinguish between a **Detailed Audit General Ledger** (which must show every debit, credit, and reversal entry) and an **Operational Customer/Supplier Statement** (which must present the effective commercial document history with clear `Revised` indicators and accessible audit trails).

---

## 2. Final Invoice Amendment Lifecycle Policy

### 1. Draft Bills
- Draft bills are edited in place (`savePurchase` / `saveSales` with `$approve = false`).
- No journal entries are created or reversed.
- No inventory, COGS, or payment allocation records are created or altered.
- All validations and company-scoped foreign key checks are preserved.

### 2. Posted/Approved Bills
- Amending an approved bill is governed by `OperationsService::revise()`.
- Protected by `lockForUpdate()`, company ownership verification, and permission checks (`purchases.update_approved` / `sales.update_approved`).
- **Fiscal Period Gate:** Before unposting, `openPeriodFor()` verifies that the bill date is inside an open fiscal period. If the period is closed, the request fails with HTTP 409 without modifying any records.
- **Transactional Atomicity:**
  1. The original journal entry ID (`original_journal_entry_id`) and original total amount (`original_amount`) are captured.
  2. Inventory and COGS are reversed via `InventoryValuationService`.
  3. The original journal entry is reversed via `reverseJournal()`, returning the reversal entry (`reversal_journal_entry_id`).
  4. The bill is updated and re-approved, generating the replacement journal entry (`replacement_journal_entry_id`).
  5. **Payment Allocation Guard:** The revised total is verified against active `party_payment_allocations` (`$revisedTotal - $paidAmount >= $activeAllocations`). If a user attempts to reduce an invoice below already allocated receipts/vouchers, a validation exception is thrown.
  6. Durable revision metadata is recorded in `bill_revisions` and on the bill (`revision_chain_id`, `is_revised = true`, `revision_count`).
  7. Detailed audit event `bills.revised` is recorded.

### 3. Returns and Cancellations
- Reversals/cancellations (`reversePurchase` / `reverseSales`) mark the bill as `status = 'reversed'` with `cancelled_at` and `reversed_by`.
- No replacement bill or `BillRevision` is created.
- Returns and cancellations appear as real, independent credit/contra events in both operational statements and the general ledger.

---

## 3. Detailed General Ledger vs. Operational Party Statement

We have cleanly separated two complementary reporting views on `GET /reports/account-statement`:

### 1. Operational Customer/Supplier Statement (`view=operational` — Default for Party Accounts)
- Displays the **effective commercial document history**.
- Self-cancelling technical revision pairs (the superseded journal entry and its reversing journal entry) are collapsed into one logical row: the active, effective bill.
- The row is clearly badged with `Revised` / `معدلة`.
- Row particulars indicate `Sales invoice (Amended) · SB-... · Customer` / `فاتورة بيع (معدلة) · SB-... · العميل`.
- A dedicated **Revision History** button (`سجل التعديلات`) and action in `DocumentModal` provides complete audit drill-down:
  - Initial invoice value, replacement value, delta amount.
  - Full revision timeline: date, actor name, stated reason, original journal number, reversal journal number, replacement journal number.
- Effective running balance and closing balance remain mathematically exact and identical to the detailed ledger.

### 2. Detailed Audit Ledger (`view=detailed`)
- An accounting audit report that renders **every single posted and reversed journal line**.
- Shows all journal numbers, debit, credit, running balance, and reversal relationships.
- Technical reversal lines clearly state the revision reason: `Reversal of JE-... (Amended: Reason)`.
- Reconciles to the penny with the trial balance and journal entries.

### User View Mode Switcher
The statement page includes an in-page switcher:
`[Operational Statement / كشف حساب تشغيلي]` | `[Detailed Audit Ledger / دفتر الأستاذ التفصيلي للتدقيق]`
Users can seamlessly toggle between the commercial document view and the raw accounting audit trail.

---

## 4. Revision-Chain Data Model & Migration Details

### Migration: `2026_10_13_000002_add_invoice_revision_chain_controls.php`
Additive migration introducing durable relationships across models:

1. **`bill_revisions` table:**
   - `revision_chain_id` (`string(100)`, indexed with `company_id`)
   - `original_journal_entry_id` (`foreignId`, references `journal_entries.id`, `nullOnDelete`)
   - `reversal_journal_entry_id` (`foreignId`, references `journal_entries.id`, `nullOnDelete`)
   - `replacement_journal_entry_id` (`foreignId`, references `journal_entries.id`, `nullOnDelete`)
   - `original_amount` (`decimal(18, 2)`, nullable)
   - `revised_amount` (`decimal(18, 2)`, nullable)
   - `delta_amount` (`decimal(18, 2)`, nullable)
2. **`sales_bills` table:**
   - `revision_chain_id` (`string(100)`, indexed with `company_id`)
   - `is_revised` (`boolean`, default `false`)
   - `revision_count` (`unsignedInteger`, default `0`)
3. **`purchase_bills` table:**
   - `revision_chain_id` (`string(100)`, indexed with `company_id`)
   - `is_revised` (`boolean`, default `false`)
   - `revision_count` (`unsignedInteger`, default `0`)
4. **Historical Backfill:**
   The migration automatically parses `before_data` and `after_data` on all existing `bill_revisions` records to populate `original_journal_entry_id`, `reversal_journal_entry_id`, `replacement_journal_entry_id`, amounts, deltas, and sets `is_revised = true` and `revision_count` on all historical amended bills.

---

## 5. Financial Reports & Accounting Reconciliation

All core financial reports were reviewed and verified:

1. **Trial Balance (`trialBalance`):**
   - Total debits equal total credits.
   - For any amended bill, the original debit/credit and reversal credit/debit net to zero across all affected accounts.
2. **Income Statement (`incomeStatement`):**
   - Revenue and expense accounts reflect only the net effective values (original revenue and reversal revenue net to zero; replacement revenue reflects the amended invoice).
3. **Balance Sheet (`balanceSheet`):**
   - Assets equal Liabilities plus Equity.
   - Cumulative unclosed earnings reconcile with net profit/loss.
   - Reconciliation difference is strictly `0.00`.
4. **Customer / Supplier Aging Reports (`receivables`, `payables`, `debtors`):**
   - Revised bills appear exactly once with their effective outstanding total.
   - Payment allocations are correctly accounted for.
5. **Party Summary Report (`partySummary`):**
   - Uses effective approved bill totals and journal movement, ensuring weight, value, and balances match the operational statements.

---

## 6. UI/UX Improvements

1. **Statement View Mode Switcher:**
   Styled toggle between `Operational Statement` and `Detailed Audit Ledger`, preserving date filters and account selections.
2. **Document Badges:**
   Added `.document-badge--revised` with clear styling (`Revised` / `معدلة`) in both light and dark modes.
3. **Revision History Modal & Action:**
   - Action button in statement rows: `Revision history` / `سجل التعديلات`.
   - Opens `RevisionModal` presenting initial invoice total, revised total, delta, and chronological list of revisions with author, date, reason, and journal vouchers.
4. **Enhanced Document Preview Modal (`DocumentModal`):**
   Displays document details, settlement status, payment source, and an integrated audit revision trail.
5. **Localization:**
   Added comprehensive Arabic and English strings in `resources/js/i18n.js` with RTL and LTR support.

---

## 7. Security, Permissions, and Tenant Isolation

- **Tenant Scoping:** All queries on bills, revisions, journal lines, and source previews are strictly scoped by `company_id`. Cross-company bill IDs are never resolved.
- **Permissions:**
  - `sales.update_approved` and `purchases.update_approved` guard bill revision endpoints.
  - `accounting.view` guards statements and vouchers.
  - View, print, and full-document buttons respect individual user capabilities.
- **Database Locks & Fiscal Periods:** Row-level locks (`lockForUpdate()`) prevent race conditions. Closed fiscal periods strictly reject revisions.

---

## 8. Validation Gates & Execution Results

All quality gates passed cleanly:

1. **Artisan Test / PHPUnit:**
   ```text
   Tests: 131, Assertions: 1581, Skipped: 2.
   OK (131 tests, 1581 assertions)
   ```
   Including 17 dedicated tests in `AccountingStatementRebuildTest.php`:
   - `test_draft_bill_edit_updates_in_place_without_creating_journal_or_reversal`
   - `test_posted_bill_revision_creates_exact_reversal_and_replacement_linked_in_bill_revision`
   - `test_closed_period_revision_is_rejected_without_accounting_changes`
   - `test_operational_statement_collapses_revision_chain_while_detailed_ledger_shows_all_entries`
   - `test_revised_bill_with_payment_allocations_rejects_reduction_below_allocated_amount`
   - `test_customer_account_statement_renders_http_200_with_sales_bill_and_receipt`
   - `test_supplier_account_statement_renders_http_200_with_purchase_bill_and_voucher`
   - `test_bills_with_cashbox_and_bank_sources_expose_correct_payment_source_in_statement`
   - `test_cross_company_source_document_cannot_be_resolved_through_statement`
2. **NPM Test Suite (`npm test`):**
   ```text
   ℹ tests 18
   ℹ suites 0
   ℹ pass 18
   ℹ fail 0
   ```
3. **Frontend Production Build (`npm run build`):**
   ```text
   vite v6.4.3 building for production...
   ✓ 823 modules transformed.
   ✓ built in 10.35s
   ```
4. **Code Standards (`php vendor/bin/pint --test`):**
   Passed with zero errors.
5. **Composer Integrity (`composer validate --no-check-publish`):**
   `./composer.json is valid`.
6. **OpenAPI Lint (`php scripts/lint-openapi.php`):**
   Contract OK: 17 paths, 19 operations, version 1.0.0.
7. **Security Audit (`npm audit --omit=optional`):**
   Found 0 vulnerabilities.
8. **Git Check (`git diff --check`):**
   Clean - 0 whitespace/conflict errors.
9. **Migration Check (`php artisan migrate:status` & `php artisan migrate --pretend`):**
   All 26 migrations ran. Nothing pending.

---

## 9. Deployment Instructions & Operational Notes

1. Run standard migration:
   ```bash
   php artisan migrate --force
   ```
   The migration is non-destructive and automatically backfills revision chains for all historical revisions.
2. Build frontend assets:
   ```bash
   npm run build
   ```
3. No configuration or `.env` changes required.
