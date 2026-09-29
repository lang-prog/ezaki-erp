# E‑ZAKI ERP — EDIT AND NEW REQUESTS

This file is the operator backlog for product corrections after Phase 3 started.

The implementing agent must:

1. Read this file before writing code.
2. Move an item from **Requested** to **Completed** only after implementation, tests, and **operator-visible MySQL/browser evidence**.
3. Never delete a completed item; move it with date, evidence, and files.
4. Do not start Phase 4 or Velzon until the operator accepts the remaining Phase 3 corrections.
5. Keep `e-zaki-implementation-progress.md` updated as well.
6. Automated SQLite tests are not sufficient for voucher reporting. Operator MySQL is `ezaki_erp` on `127.0.0.1`. Do not read `.env`. Never `migrate:fresh`.

Binding files:

- `e-zaki-erp-srs.md` v1.1
- `e-zaki-laravel-master-prompt.md`
- `e-zaki-implementation-progress.md`
- this file: `editandnew.md`

Status values: `OPEN` | `IN_PROGRESS` | `DONE` | `DEFERRED`

---

## Requested

### A. Bills

| ID | Item | Status | Notes |
|---|---|---|---|
| A1 | Visible تعديل | DONE | Operator 2026-09-29 confirmed. |
| A2 | Visible اعتماد | DONE | Operator confirmed. |
| A3 | After approve, status معتمدة | DONE | Operator confirmed. |
| A4 | Purchase lines type + diameter + factory weight only | IN_PROGRESS | Implemented and covered by type/diameter purchase tests; operator visual re-verification pending. |
| A5 | Header fields at TOP including factory total + actual review weight | IN_PROGRESS | Implemented in BillForm; automated bill payload coverage passes; operator visual re-verification pending. |
| A6 | Header factory weight equals sum of line factory weights | IN_PROGRESS | Server-side totals validation and focused tests pass; operator visual re-verification pending. |
| A7 | Purchase stock/value uses factory weight; actual is review-only | IN_PROGRESS | Approval service uses factory weight and tests pass; operator visual re-verification pending. |
| A8 | Sales optional packages; never affect stock | IN_PROGRESS | Nullable packages and approval coverage pass; operator visual re-verification pending. |
| A9 | Sales cannot oversell | IN_PROGRESS | Approval stock guard and focused test pass; operator visual re-verification pending. |
| A10 | Duplicate bill number blocked; near-duplicate does not wipe form | IN_PROGRESS | Duplicate validation and warning-preservation tests pass; operator visual re-verification pending. |
| A11 | Arabic-first RTL print | IN_PROGRESS | Arabic RTL print test passes; operator visual re-verification pending. |
| A12 | No querySelector crash; no ownedId TypeError | IN_PROGRESS | Optional chaining and string-ID regression coverage pass; operator visual re-verification pending. |

### B. Receipts, payments, accounting — BLOCKING

| ID | Item | Status | Notes |
|---|---|---|---|
| B14 | MySQL missing `other_account_id` on receipts | DONE | Operator ran `php artisan migrate`. Creating a receipt/voucher now **saves**. Do not reopen schema unless a new missing-column error appears. |
| B1 | **BLOCKING** Posted vouchers do not appear in اليومية, دفتر الأستاذ, or customer/supplier statements | DONE | Operator confirmed journal rows are visible after the `AccountingReport.jsx` empty-array fix. MySQL diagnostic found 3 posted receipts + 2 posted payments, all linked to journals, and 10 report-query-visible lines. Regression assertion covers paginated `entries.data`. |
| B15 | Journal, ledger, account statement, customer statement, supplier statement need **من تاريخ : إلى تاريخ** | IN_PROGRESS | Inclusive `from_date` / `to_date` now reaches journal, ledger, account statement, and debtor/customer balance queries; Arabic labels and print use the same form range. Automated dated movement coverage passes. Operator visual confirmation of every report remains pending. |
| B3 | Counterparty Customer / Supplier / Other account | IN_PROGRESS | Customer, supplier, and Other-account controls plus posting coverage are implemented; operator visual confirmation pending. |
| B16 | Other-account list must not be debit-only on receipts or credit-only on payments | IN_PROGRESS | UI and service list any active same-company posting account, excluding selected cash/bank account and headers, without natural debit/credit filtering. Existing focused API coverage posts both revenue receipt and expense payment. Operator visual confirmation remains pending. |
| B2 | Create voucher is posting unless UI clearly has مسودة + اعتماد | IN_PROGRESS | Receipt/payment save paths post immediately and display posted status; operator visual confirmation pending. |
| B4 | Other payment: عهدة سائق، مندوب، مصروف جهة أخرى، موظف | IN_PROGRESS | Generic Other-account payment posts to any posting-capable account; operator visual confirmation pending. |
| B5 | Other receipt: تصفية عهدة، إيراد سيارة، إيراد آخر | IN_PROGRESS | Generic Other-account receipt posts to any posting-capable account; operator visual confirmation pending. |
| B6 | Cashbox/bank always cash side. Searchable selects. No typed codes | IN_PROGRESS | Cash/bank source validation and select-only controls are implemented; searchable-select portion remains part of E9. |
| B7 | Click chart account opens statement, Arabic print | IN_PROGRESS | Chart links to account statements and report print exists; operator visual confirmation pending. |
| B8 | Parent statement includes descendants | IN_PROGRESS | Descendant aggregation and focused statement coverage pass; operator visual confirmation pending. |
| B9 | Report links at TOP of accounting page | IN_PROGRESS | Top report links are implemented and page-contract coverage passes; operator visual confirmation pending. |
| B10 | `/accounting` after cashbox, no missing account relation | IN_PROGRESS | Regression page test passes after cashbox creation; operator visual confirmation pending. |
| B11 | Chart ADD account | IN_PROGRESS | API/UI create-child flow and page coverage exist; operator visual confirmation pending. |
| B12 | Chart edit name and parent, recode, no cycles | IN_PROGRESS | Update/move validation and focused test pass; operator visual confirmation pending. |
| B13 | Party parent account persists on Save | IN_PROGRESS | Party parent/account identity regression passes; operator visual confirmation pending. |

### C. Inventory

| ID | Item | Status | Notes |
|---|---|---|---|
| C1 | إضافة نوع name only | IN_PROGRESS | Name-only type creation and coverage exist; operator visual confirmation pending. |
| C2 | إضافة قطر value only; no seed | IN_PROGRESS | Value-only diameter creation and coverage exist; operator visual confirmation pending. |
| C3 | No Product SKU form | IN_PROGRESS | Inventory UI exposes type/diameter and matrix, not a Product SKU form; operator visual confirmation pending. |
| C4 | Optional opening balance / min stock | IN_PROGRESS | Separate inventory action now accepts warehouse + type + diameter with opening quantity and minimum stock independently optional; no SKU, item code, or Product form is exposed. Focused test passes; operator visual confirmation pending. |
| C5 | Type × diameter matrix | IN_PROGRESS | Matrix implementation and focused coverage pass; operator visual confirmation pending. |

### D. Customers and suppliers

| ID | Item | Status | Notes |
|---|---|---|---|
| D1 | Separate /customers and /suppliers | IN_PROGRESS | Separate filtered routes and page-contract tests pass; operator visual confirmation pending. |
| D2 | Customer تحصيل from row and profile | IN_PROGRESS | Customer rows link to Accounting with the customer preselected, and tenant-scoped customer profiles now expose statement and collection actions. Operator visual confirmation pending. |
| D3 | Supplier دفعة from row and profile | IN_PROGRESS | Supplier rows link to Accounting with the supplier preselected, and tenant-scoped supplier profiles now expose statement and payment actions. Operator visual confirmation pending. |

### E. Navigation, locale, selects

| ID | Item | Status | Notes |
|---|---|---|---|
| E1 | Company sidebar identical on every page | IN_PROGRESS | Shared CompanyLayout is used by implemented company pages; operator visual confirmation pending. |
| E2 | Owner opens every implemented page | IN_PROGRESS | Owner Gate and authenticated route coverage pass for implemented pages; live operator confirmation pending. |
| E3 | Owner self-profile; others cannot alter owner | IN_PROGRESS | Existing profile/owner authorization tests pass; operator visual confirmation pending. |
| E4 | Sidebar links 200 / 403 | IN_PROGRESS | Route middleware and navigation permission tests cover implemented links; operator visual confirmation pending. |
| E5 | Super Admin sidebar not Overview only | IN_PROGRESS | Super Admin dashboard contains company/plan/registration controls; operator visual confirmation pending. |
| E6 | Arabic default RTL | IN_PROGRESS | Arabic locale/default and RTL tests pass; operator visual confirmation pending. |
| E7 | No English placeholders in Arabic | IN_PROGRESS | Shared Inertia bootstrap now translates remaining static bill/fleet/control labels and hints when Arabic is active. Operator visual confirmation pending. |
| E8 | Hover tooltip on every field | IN_PROGRESS | Inertia bootstrap now assigns active-locale titles to every input, select, and textarea, using existing localized labels or an active-locale fallback. Operator visual confirmation pending. |
| E9 | Searchable select when options > 7 | IN_PROGRESS | Inertia bootstrap adds an inline search field to every select with more than seven options and filters its options without a new dependency. Operator visual confirmation pending. |

### F. Do not forget

| ID | Item | Status | Notes |
|---|---|---|---|
| F1 | Sales type+diameter draft/approve test | IN_PROGRESS | End-to-end type/diameter sales draft/approval coverage exists; operator visual confirmation pending. |
| F2 | Pure type+diameter schema | DEFERRED | |
| F3 | Fleet remaining | IN_PROGRESS | Fleet CRUD, trips, expenses, maintenance, approval/reversal, and reports are implemented and covered; operator visual confirmation pending. |
| F4 | Dashboard charts | IN_PROGRESS | Dashboard daily-sales and payment/receipt visual aggregates are implemented and covered; operator visual confirmation pending. |
| F5 | No Phase 4 / Velzon | DONE | Constraint honored throughout this continuation. |

---

## Completed

### Phase boundary

| ID | Item | Date | Evidence |
|---|---|---|---|
| P1 | Phase 1 | 2026-09-28 | COMPLETED |
| P2 | Phase 2 code | 2026-09-28 | COMPLETED; later corrections apply |
| P3 | Phase 3 API started | 2026-09-28 | PARTIALLY_COMPLETED |
| P4 | Super Admin create-company | 2026-09-28 | Confirmed |
| P5 | No Phase 4 / Velzon | ongoing | Honored |

### Operator-confirmed 2026-09-29

| ID | Item | Date | Evidence |
|---|---|---|---|
| A1 | تعديل ظاهر | 2026-09-29 | Operator |
| A2 | اعتماد يعمل | 2026-09-29 | Operator |
| A3 | اعتماد المسودة | 2026-09-29 | Operator |
| B14 | Receipt insert after migrate | 2026-09-29 | Operator ran `php artisan migrate`; adding voucher now works. Journal still empty. |

---

## This turn

Do **not** treat missing `other_account_id` as the current bug.

Work in order: **B1**, **B16**, **B15**.

B1 is blocking: a voucher that saves on MySQL must create/show journal lines. Inspect live posting vs report queries. Backfill/display already-saved receipts that have no journal_entry_id if that is the root cause (post them or attach journals without deleting operator data).

Do not start C/D/E.

### Live voucher diagnostic — 2026-09-29

- Ran `php artisan accounting:backfill-voucher-journals --company=1` against the configured operator database without reading `.env` or running migrations.
- Company 1 has 3 posted receipts and 2 posted payments; 0 are unlinked. The journal table has 3 receipt-source and 2 payment-source entries.
- The journal report-equivalent joins/status filter return 10 voucher journal lines. Backfill repaired 0 rows.
- `/reports/journal` redirects to `/login` in the available browser; no authenticated company session was available. At the initial diagnostic stage, B1 remained OPEN because the reported UI symptom could not be reproduced or visually confirmed despite report-eligible rows being present in MySQL.
- Follow-up: fixed the frontend row-source precedence that selected default `rows=[]` before paginated `entries.data`/`lines.data`; added a regression assertion for four web journal rows. B1 is now IN_PROGRESS pending operator-authenticated browser confirmation.
- No migration, reset, destructive command, Phase 4, or Velzon work was performed.

### Continuation evidence — 2026-09-29

- B16 focused regression now passes: 1 test, 29 assertions. It covers revenue receipt and expense payment options, exclusion of the selected cash account, and exclusion of non-postable header accounts.
- Separate `/customers` and `/suppliers` route contracts plus Accounting access pass in the Phase Two page test; the full test now reports 100 assertions for that test.
- B15 debtor date-range coverage passes with inclusive current-day movement and future-range exclusion.
- C4 focused regression passes: 1 test, 8 assertions. The separate type/diameter action accepts warehouse + type + diameter and independently optional opening/minimum values without exposing SKU/product fields.
- E8/E9 frontend build passes with 802 modules. The Inertia bootstrap now titles all controls in the active locale and adds search filtering to selects with more than seven options.
- D2/D3 profile routes and tenant isolation are covered by the Phase Two page test; the shared `PartyProfile` page exposes statement plus collection/payment actions.

Do not read `.env`. No `migrate:fresh`.


### Implementation completion evidence — 2026-09-30

The code-level completion pass for all `IN_PROGRESS` groups is implemented and regression-tested. Statuses remain `IN_PROGRESS` only because this file requires operator-visible MySQL/XAMPP/browser evidence before `DONE`.

| Scope | New completion evidence |
|---|---|
| A4/A5/A11 | Purchase line actual weight is removed from UI/request persistence and the legacy column is additive-migrated to nullable; header totals are top-grouped; Arabic print uses translated statuses and factory-weight-only lines. |
| B7/B16 | Accounting report controls/actions are localized and print-safe; Other-account options reject inactive, header, and selected cash/bank accounts. |
| C5 | Matrix now supports type search, warehouse filtering, print, and `inventory.export`-protected CSV export. |
| D2/D3 | Customer/supplier names open type-enforced, tenant-scoped profiles; statement and payment/collection actions respect accounting permissions. |
| E3/E7 | Company Owner direct-permission mutation is server-rejected; remaining bill and access controls are localized in the active locale. |
| F3 | Fleet foreign keys are tenant-scoped; persisted CRUD fields are exposed; eight date/branch-filtered reports share one service; CSV export requires `fleet.export`. |
| F4 | Debtors KPI is an amount, posted receipts/payments are used, daily-sales scaling is data-driven, and product/customer breakdowns are rendered and returned by API. |
| Regression | `71 tests / 919 assertions`, Pint 125 files, Composer valid, Vite build 802 modules, npm audit 0 vulnerabilities. |

Operator acceptance still required before changing these rows to `DONE`: run `php artisan migrate` on the XAMPP/MySQL copy, then visually verify Arabic RTL bill print, report print/date filters, inventory CSV, customer/supplier profile actions, fleet CRUD/reports/CSV, and Dashboard charts using real company data. `F2` remains intentionally `DEFERRED` pending a historical mapping decision.
