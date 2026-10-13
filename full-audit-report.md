# E-Zaki ERP Full Senior Audit

**Audit date:** 2026-10-13
**Audited commit:** `5dedae0bdd11938f1babfd8accd796d90c9a16f4` (`2026-10-12T06:01:05+03:00`)
**Scope:** Laravel 12 / PHP, Inertia React / Vite, financial and operational services, API, tenancy/security, migrations, test setup, requirements, and deployment documentation.
**Method:** Read-only source/doc review, safe static gates, JavaScript/PHP automated tests, and deliberately isolated SQLite probes under the OS temporary directory. `.env` and any live application database were not read. No browser was used and no application mutation was sent.

## 1. Executive Verdict

# BLOCKED/NEEDS_REMEDIATION

The repository has strong foundations and meaningful service/feature coverage, but the audit reproduced material differences between the GL and customer aging/credit-limit views, a warehouse-transfer valuation loss that produces zero COGS at the destination, and a balance sheet omitting current-period earnings. The default purchase stock-weight policy also conflicts with the binding SRS. These findings preclude reliance on the affected financial and inventory reports until reconciled and corrected. Separate production sign-off is also blocked by untested live MySQL, actual HTTPS hosting, mail/queue behavior, and restore drills.

No P0 finding was confirmed. Several P1 findings are nevertheless financial or authorization blockers for affected workflows.

## 2. Findings Summary

| ID | Severity | Area | Status | Evidence |
|---|---|---|---|---|
| F-01 | P1 | Party subledger, aging, credit limit | Confirmed defect | Temporary SQLite scenario: customer control account debit 1,500; aging 0; an 800 credit sale was accepted over a 2,000 limit after 1,500 was already due. See [OperationsService](app/Services/OperationsService.php#L539) and [AccountingReportService](app/Services/AccountingReportService.php#L245). |
| F-02 | P1 | Inventory transfers / weighted-average COGS | Confirmed defect | Temporary SQLite transfer: destination quantity 5, value 0; next-unit issue cost 0 although source average cost was 150. See [InventoryService](app/Services/InventoryService.php#L133). |
| F-03 | P1 | Balance sheet | Confirmed defect | Isolated trial balance balanced 6,500/6,500, but a 500 profit case reported assets 3,500, liabilities 3,000, equity 0. See [AccountingReportService](app/Services/AccountingReportService.php#L106). |
| F-04 | P1 | Purchase stock basis / requirements | Confirmed requirements conflict | SRS says stock enters at actual weight; default config and current backlog say factory weight. See [SRS](e-zaki-erp-srs.md#L181), [policy service](app/Services/CompanyAccountingPolicyService.php#L26), [backlog](editandnew.md#L37). |
| F-05 | P1 | Receipt/voucher reversal reporting | Confirmed defect | Journal reversal is allowed for receipt/voucher source types but the source remains `posted`; party summaries continue subtracting it. See [AccountingService](app/Services/AccountingService.php#L269) and [AccountingReportService](app/Services/AccountingReportService.php#L212). |
| F-06 | P1 | Local licensing / copied database | Confirmed enforcement gap | Activation compares signed claims with caller-supplied IDs; runtime verification does not compare installation ID against a current installation identity. See [LocalLicenseService](app/Services/LocalLicenseService.php#L14) and [licensing config](config/licensing.php#L3). |
| S-01 | P1 | API session revocation | Confirmed middleware gap | `statefulApi()` enables first-party session requests; web routes apply `session.version`, API group does not. Old session cookies can remain usable after password change while token auth is revoked. See [bootstrap](bootstrap/app.php#L26), [API routes](routes/api.php#L19), [EnsureSessionVersion](app/Http/Middleware/EnsureSessionVersion.php#L18). |
| F-07 | P1 risk | Fiscal-period close concurrency | Probable risk; MySQL concurrency test required | Posting reads an open period without a row lock; close checks and updates without a transaction/lock. See [period lookup](app/Services/AccountingFoundationService.php#L154) and [closePeriod](app/Services/AccountingService.php#L302). |
| F-08 | P1 | Fleet expense posting | Confirmed accounting control defect | An approved expense without a supplied source credits parent account `1.2`; maintenance always credits that parent. This bypasses cashbox/bank reconciliation and has no explicit payable choice. See [OperationsService](app/Services/OperationsService.php#L400). |
| Q-01 | P2 | CSRF regression coverage | Confirmed coverage limitation | Base `Tests\TestCase` disables CSRF globally; only selected regression tests re-enable it. See [TestCase](tests/TestCase.php#L9). |
| Q-02 | P2 | API contract drift | Confirmed coverage limitation | OpenAPI has 12 paths/14 operations; linter validates structure and six route strings, not full route parity. See [linter](scripts/lint-openapi.php#L25). |
| Q-03 | P2 | Production/MySQL/browser/release evidence | Missing evidence | Suite uses in-memory SQLite and array sessions. No live MySQL, browser E2E, production proxy, SMTP, worker, or restore drill was run. See [phpunit.xml](phpunit.xml#L27) and [deployment runbook](DEPLOYMENT.md#L118). |
| Q-04 | P2 | Fleet / party report query scalability | Confirmed design risk | Reports load unbounded collections; party aging issues a per-party bill query. See [FleetReportService](app/Services/FleetReportService.php#L60) and [AccountingReportService](app/Services/AccountingReportService.php#L269). |
| Q-05 | P2 | Draft audit coverage | Confirmed defect | Draft fleet expense and maintenance creation return without an audit event. See [OperationsService](app/Services/OperationsService.php#L374). |
| Q-06 | P2 | Company profile/settings audit trail | Confirmed defect | Company settings update persists validated fields and file path but records no audit event. See [CompanyProfileController](app/Http/Controllers/Web/CompanyProfileController.php#L26). |
| Q-07 | P2 | Error handling | Confirmed UX limitation | Several platform Axios actions have no rejection handler; global Inertia error handling uses blocking alert and omits 429. See [SuperAdmin Dashboard](resources/js/Pages/SuperAdmin/Dashboard.jsx#L19) and [app.jsx](resources/js/app.jsx#L70). |
| Q-08 | P2 risk | `.rnd` repository artifact | Probable security hygiene risk | `.rnd` is tracked and not ignored; contents intentionally not read. If it is OpenSSL random state, it should not be versioned. |
| Q-09 | P2 | Content security policy | Hardening gap | Security headers omit CSP. See [SecurityHeaders](app/Http/Middleware/SecurityHeaders.php#L17). |
| Q-10 | P2 risk | Fleet transport allocation rounding | Confirmed display discrepancy | Per-line rounding can leave allocated extras short by 0.01; three equal shares of 1,000 produce 999.99. See [OperationsService](app/Services/OperationsService.php#L454). |
| U-01 | P3 risk | Pagination HTML rendering | Probable risk, not demonstrated XSS | Laravel-generated labels are injected with `dangerouslySetInnerHTML`; no attacker-controlled label was established. See [Parties.jsx](resources/js/Pages/Company/Parties.jsx#L37). |
| U-02 | P3 | UI / responsive evidence | Missing evidence | No 320/390/768/1024/1440px, keyboard, screen-reader, or print-browser pass was run. |

## 3. Confirmed Strengths

- Laravel service boundaries exist for accounting, inventory valuation, operations, accounting reports, fleet reports, subscriptions, licensing, and audit recording.
- Bill approval and reversal paths use database transactions and row locks; sales approval issues weighted-average stock, and journal posting rejects unbalanced/non-positive totals in [AccountingService](app/Services/AccountingService.php#L338).
- Draft bills do not post inventory/GL; approval creates stock, journal, payment-allocation and audit records. Existing tests cover this core lifecycle, though predominantly on SQLite.
- Tenant middleware establishes company context; operational services and controllers frequently scope record lookups to `company_id`. Company and platform sessions are separated; current CSRF helper uses the decoded encrypted XSRF cookie and same-origin credentials.
- Authentication includes account/IP throttling and account lockout; permission assignment has assignable-permission checks; tenant owner UI is not treated as the security boundary.
- Print-background uploads are owner-authorized, stored below a company-specific public-disk path, and limited to image formats/5 MB server-side. This audit did not run an upload against live storage.
- Backup artifacts include checksums/manifests; restore validates checksums and confines artifact paths. Runbook documents encrypted offsite storage as an operator responsibility, not an implemented encryption feature.

## 4. Detailed Findings

### P0: Financial/Security Blockers

None confirmed at P0 from the accessible source and isolated probes. The P1 items below still block reliance on specific ledgers, balances, transfers, and local-license guarantees.

### P1: High-Risk Findings

#### F-01 — Party balances net to zero; credit limits can be bypassed

**Location:** [OperationsService credit check](app/Services/OperationsService.php#L539), [sales journal party tagging](app/Services/OperationsService.php#L655), [party aging aggregation](app/Services/AccountingReportService.php#L245).

**Observed behavior:** `creditCheck()` sums every journal line for the party, regardless of account, then treats `SUM(debit-credit)` as the balance. Sales journal creation tags the source cash/bank, customer control, revenue, VAT, COGS and inventory lines with the same `party_id`. A balanced sale therefore nets to zero in this query. Receipts and vouchers tag both cash and counterparty lines, also netting to zero. `partyAging()` repeats this approach over all non-null `party_id` lines.

**Reproduction/evidence:** In isolated SQLite, a 1,500 credit sale posted 1,500 to the customer control account; `debtors(..., 0)` returned total outstanding 0, and the customer’s party-tagged journal lines netted to 0. With a 2,000 credit limit, another 800 credit sale was accepted even though total receivables would become 2,300. This probe did not access the local/production database.

**Why it matters:** Credit-limit enforcement can allow excess exposure. Debtors, receivables and payables may omit posted invoices entirely; dashboard totals and aging no longer reconcile to control accounts. Opening balances can also be counted twice by the credit formula when both the scalar opening balance and its posted party-control line are included.

**Recommended remediation:** Derive party balances from the party’s control account only (or create an explicit, balanced party subledger/allocation model); do not use party tags on revenue, inventory, tax, or cash lines for receivable/payable balance summation. Reconcile each party sum to its control account. Resolve opening balance inclusion once, not both scalar and journal.

**Required regression/acceptance test:** Create supplier/customer invoices, VAT, partial payments, receipts/vouchers and opening balances; assert party aging equals control-account balances, reports net reversal effects correctly, and credit limit rejects an over-limit sale while permitting a valid under-limit one.

#### F-02 — Warehouse transfer moves quantity but not inventory value

**Location:** [InventoryService transfer](app/Services/InventoryService.php#L133), source quantity decrement at [line 155](app/Services/InventoryService.php#L155), destination increment at [line 160](app/Services/InventoryService.php#L160), weighted-average issue at [InventoryValuationService](app/Services/InventoryValuationService.php#L26).

**Observed behavior:** Transfer decrements/increments only `quantity`; it does not calculate the source average value, update either `inventory_value`, or store value on the transfer/movement. Destination balances are created with value zero. Future sales calculate cost from destination value divided by quantity.

**Reproduction/evidence:** A throwaway SQLite setup purchased 20 units valued at 3,000 (average 150), transferred 5, then observed destination `{quantity: 5, inventory_value: 0}`. Issuing one unit at destination returned cost 0, rather than 150.

**Why it matters:** The receiving warehouse sells stock with zero COGS. Source and destination stock values no longer represent where the inventory resides; COGS and gross profit are misstated. Company-level inventory value can temporarily mask the issue until stock is issued at destination.

**Recommended remediation:** Transfer both quantity and value at the source weighted-average cost under row locks, store the transferred value on transfer lines/movements, and reverse it symmetrically. Preserve total company inventory value and reconcile per-warehouse values to the inventory GL control account.

**Required regression/acceptance test:** Receive at mixed unit costs, transfer fractional and full quantities, sell at destination, and assert source + destination value conservation, destination COGS, stock movement value, and GL inventory/COGS reconciliation.

#### F-03 — Balance sheet excludes current-period profit/loss

**Location:** [balanceSheet](app/Services/AccountingReportService.php#L106), [account-type aggregation](app/Services/AccountingReportService.php#L299), [system accounts](app/Services/AccountingFoundationService.php#L29). There is no retained/current-earnings system account in the listed system posting accounts and period close only changes period status at [AccountingService](app/Services/AccountingService.php#L302).

**Observed behavior:** Balance sheet sums only `asset`, `liability`, and `equity` accounts. It does not include current-period net income/loss in equity, and no period-close journal transfers income/expense to retained earnings.

**Reproduction/evidence:** In isolated SQLite: purchases created inventory 3,000 and supplier liabilities 3,000; a credit sale generated 2,000 revenue and 1,500 COGS. Trial balance remained balanced at 6,500 debit/credit. Balance sheet response totaled assets 3,500, liabilities 3,000, equity 0, leaving a 500 difference equal to net profit.

**Why it matters:** A statement presented as a balance sheet does not balance when current-period profit/loss exists. Users may rely on incomplete equity/financial position figures despite a balanced trial balance.

**Recommended remediation:** Choose and document the reporting policy: calculate current earnings as a balance-sheet equity line through the as-of date, or implement tested period-end closing into retained earnings. Ensure the report reconciles assets = liabilities + equity for profit and loss cases and after reversals.

**Required regression/acceptance test:** Run profitable and loss-making periods with opening balances, VAT and reversals; assert balance-sheet totals reconcile to zero difference both before and after closing.

#### F-04 — Default purchase stock quantity conflicts with the binding SRS

**Location:** [SRS purchase rule](e-zaki-erp-srs.md#L181), [default policy](app/Services/CompanyAccountingPolicyService.php#L26), [quantity selection](app/Services/OperationsService.php#L595), [operator backlog](editandnew.md#L37).

**Observed behavior:** Binding SRS states purchase valuation/supplier price uses factory weight while stock enters by actual weight. Current default `purchase_inventory_basis` is `factory_weight`; the operator backlog also says purchase stock/value uses factory weight and actual is review-only. The policy is configurable and locks after the first approved purchase.

**Why it matters:** For 10 factory tonnes and 9 actual tonnes, the default adds 10 tonnes to available inventory, 11.1% more than the SRS quantity. That changes oversell limits and the cost/quantity basis of later sales. It also creates a costly migration/valuation decision after approval because the policy locks.

**Recommended remediation:** Do not silently change historical behavior. Obtain an explicit product-owner decision about whether the later backlog supersedes SRS 1.1; record the decision in the authoritative requirements and progress documents, then choose an effective-date/historical migration policy and acceptance examples.

**Required regression/acceptance test:** Given factory 10, actual 9 and a known unit price, assert persisted stock quantity, inventory value, average cost, sale availability and COGS according to the approved decision; include policy-lock and historical conversion behavior.

#### F-05 — Reversing a receipt/voucher leaves the source marked posted for party summaries

**Location:** [generic journal reversal](app/Services/AccountingService.php#L269), source-type restriction list at [line 273](app/Services/AccountingService.php#L273), [party payment totals](app/Services/AccountingReportService.php#L212), routes for journal reversal at [routes/api.php](routes/api.php#L82).

**Observed behavior:** Generic reversal prohibits direct reversal of purchase/sales bills, fleet expenses and maintenance, but permits receipts and payment vouchers. `reverseJournal()` changes journal status and posts a reversal, but does not change receipt/voucher source status or link the source to its reversal. `partyPaymentTotals()` continues summing source records whose status remains `posted`.

**Why it matters:** The GL correctly nets the original receipt and reversal, while customer/supplier summary continues treating the cash movement as effective. A 500 receipt followed by reversal can leave a customer summary 500 lower than the control-account balance.

**Recommended remediation:** Give receipt/voucher reversal a source workflow that atomically marks the document reversed/links the reversal, or compute party summaries from effective journal/allocation state. Retain immutable source and reversal records.

**Required regression/acceptance test:** Post and reverse customer receipt and supplier voucher; verify source status/link, journal net, party statement, aging, cash/bank report, and audit trail all reconcile.

#### F-06 — Local license runtime verification does not bind to current installation identity

**Location:** [activation](app/Services/LocalLicenseService.php#L14), [runtime verification](app/Services/LocalLicenseService.php#L45), [licensing config](config/licensing.php#L3), example installation settings [.env.example](.env.example#L90).

**Observed behavior:** Activation validates the signed `installation_id` against the installation ID supplied in that activation request, then stores the ID and signed payload. Runtime `verifyForCompany()` checks company ID and compares the signed customer binding to the customer binding stored in that same license row; it does not compare the stored installation ID with a current installation identity. `config/licensing.php` does not load the example installation/customer identity variables.

**Why it matters:** Copying the database (and the normal application key/config needed to decrypt sessions) to another host carries the locally stored license and all values runtime verification compares. The audit found no independent runtime host/install binding check. This weakens the promised local-license transfer/copy protection.

**Recommended remediation:** Define the offline threat model and bind activation/runtime checks to a locally provisioned installation identity that is not merely copied with the database. Because a fully offline application cannot prove physical hardware identity against a privileged operator, state that limitation and design signed transfer/revocation workflow accordingly. Avoid claiming stronger anti-copy guarantees than the mechanism provides.

**Required regression/acceptance test:** Activate on installation A; copy only the documented database/application artifacts to installation B; assert the intended policy (fail closed or explicit supported transfer) without online calls; test customer/company binding and transfer audit.

#### S-01 — Stateful API requests omit the session-version revocation check

**Location:** [stateful API setup](bootstrap/app.php#L26), [API middleware group](routes/api.php#L19), [session-version middleware](app/Http/Middleware/EnsureSessionVersion.php#L18), password update/revoke path [API CompanyProfileController](app/Http/Controllers/Api/V1/CompanyProfileController.php#L39).

**Observed behavior:** Web route groups include the custom `session.version` middleware; the API group uses Sanctum, account type, tenant, subscription and license middleware but not `session.version`. Stateful Sanctum permits same-origin cookie sessions. Password changes increment the user session version and delete personal access tokens, but an older browser session cookie can still authenticate an API request because account type/status remains active and the API stack does not compare the stored version.

**Why it matters:** Password-change session revocation is incomplete for cookie-authenticated API writes until the stale session is invalidated through a web route/expiry. Bearer tokens are deleted; the gap concerns stateful browser sessions.

**Recommended remediation:** Apply session-version validation to stateful API requests, or make the auth/session revocation path invalidate all relevant browser sessions immediately. Keep bearer-token behavior and CSRF protections intact.

**Required regression/acceptance test:** Authenticate a browser session, increment session version through password change, then attempt a stateful API read and mutation using the old cookie; assert 401 and no mutation. Also verify the newly authenticated session succeeds.

#### F-07 — Fiscal-period close/post race can admit a posting after closure

**Location:** [openPeriodFor](app/Services/AccountingFoundationService.php#L154), [period-status check](app/Services/AccountingFoundationService.php#L163), [posting transaction](app/Services/AccountingService.php#L293), [closePeriod](app/Services/AccountingService.php#L302).

**Observed behavior:** Period lookup/status validation does not lock the period row. `closePeriod()` checks and saves without its own transaction/row lock. Two MySQL requests can interleave: posting reads `open`, another request closes the period, then the first posting commits.

**Status:** Probable concurrency risk; not reproduced because no MySQL concurrency environment was available.

**Why it matters:** SRS says closed periods are not editable. An entry can be committed to a period already reported closed, making trial-balance and period-close controls unreliable.

**Recommended remediation:** Serialize close and posting on the same locked period row inside transactions; re-check status immediately before posting and make close/report close a consistent boundary.

**Required regression/acceptance test:** MySQL integration test with two concurrent transactions racing a close against bill/receipt posting; exactly one valid ordering must commit, and no entry may post after closure.

#### F-08 — Fleet expense/maintenance can post against the current-assets header, not a cash/payable account

**Location:** [expense approval](app/Services/OperationsService.php#L385), fallback account selection at [line 400](app/Services/OperationsService.php#L400), [maintenance approval](app/Services/OperationsService.php#L420), optional payment-source validation [expenseRelations](app/Services/OperationsService.php#L759) and API validation [OperationsController](app/Http/Controllers/Api/V1/OperationsController.php#L209).

**Observed behavior:** Expense inputs allow no `cashbox_id`, `bank_id` or `account_id`. On approval, absence of a source falls back to account code `1.2` (current-assets header). Maintenance approval always credits `1.2`. No payable/source document is created.

**Why it matters:** A 100 EGP approved expense can post Dr expense 100 / Cr current-assets header 100 while no cashbox/bank balance changes and no payable is recorded. Cash reconciliation is unavailable and the GL may post directly to a header that the Other-account workflow otherwise rejects.

**Recommended remediation:** Require exactly one active cash/bank/postable payment account for a paid expense, or represent unpaid costs as an explicit payable. Maintenance should use the same explicit settlement policy; do not silently credit a header account.

**Required regression/acceptance test:** Test expense and maintenance with cashbox, bank, explicit other account, unpaid/payable, and missing-source cases; assert exact journal accounts and cashbox/GL reconciliation.

### P2: Medium-Risk Findings

#### Q-01 — Most feature tests bypass CSRF globally

**Location:** [tests/TestCase.php](tests/TestCase.php#L9), test DB/session setup [phpunit.xml](phpunit.xml#L27).

The shared Laravel test base calls `withoutMiddleware(ValidateCsrfToken::class)`. The new company-settings test explicitly re-enables the middleware and tests multipart `_method=put`, but most feature tests still do not exercise CSRF, cookie rotation, SameSite/Secure, or stateful Sanctum session behavior. Keep a focused CSRF-enabled suite per browser mutation family and do not interpret the general feature suite as proof of CSRF behavior.

**Required test:** CSRF-enabled Inertia, FormData, JSON API, session-cookie separation, stale token and wrong-session cases; assert invalid requests never mutate the database.

#### Q-02 — OpenAPI lint does not detect API route drift

**Location:** [lint-openapi.php](scripts/lint-openapi.php#L25), [API routes](routes/api.php#L1), [OpenAPI specification](docs/openapi/v1/openapi.json#L1).

The contract declares 12 paths/14 operations and the linter checks required paths/operation IDs plus a few route substrings. It does not compare registered routes, methods, auth, payloads, pagination, or response schemas to the spec. Many operational endpoints (product types, cashboxes, receipts, vouchers, bills, fleet, users/roles, etc.) are outside the published contract. This is a contract completeness/drift gap, not evidence those runtime routes are broken.

**Required test:** Generate/compare all versioned route names/methods to OpenAPI in CI and add schemas/auth/error/pagination examples for every public operation.

#### Q-03 — Live production acceptance evidence is absent

**Location:** [phpunit.xml](phpunit.xml#L27), [DEPLOYMENT.md](DEPLOYMENT.md#L118), [progress status](e-zaki-implementation-progress.md#L26).

The current test configuration is in-memory SQLite, array session/cache and sync queue. No live MySQL migration/constraint/index test, real XAMPP browser walkthrough, production HTTPS/proxy, SMTP delivery, persistent queue, production storage permission, or MySQL backup/restore drill was performed during this audit. The progress/backlog explicitly retain Phase 3 operator-visible items as incomplete.

**Required acceptance:** MySQL integration CI, authenticated browser E2E at desktop/mobile sizes, HTTPS proxy test, upload/storage-link checks, SMTP/worker/scheduler health, restore drill, and production preflight/smoke evidence.

#### Q-04 — Party and fleet reports have unbounded/N+1 data access

**Location:** [party summary](app/Services/AccountingReportService.php#L153), [party aging](app/Services/AccountingReportService.php#L269), [aging buckets](app/Services/AccountingReportService.php#L373), [fleet report queries](app/Services/FleetReportService.php#L60).

`partySummary` loads all active parties and constructs pagination after mapping; each party invokes `agingBuckets()` with a bill query. Fleet report helpers call `get()` for all matching trips/expenses/maintenance and aggregate in PHP. No maximum period or result cap is enforced.

**Why it matters:** Large tenants can cause query counts proportional to parties and memory/time proportional to the full range. SRS specifies pagination and report performance targets but no P95/load evidence exists.

**Recommended remediation/test:** Aggregate aging in SQL or batch by party, paginate or bound fleet source rows, add indexes/EXPLAIN reviews, and benchmark representative tenant volumes.

#### Q-05 — Draft fleet expense and maintenance creation are not audited

**Location:** [createExpense](app/Services/OperationsService.php#L374), [createMaintenance](app/Services/OperationsService.php#L409).

Both methods create a draft and return without recording creation; only approval is audited. A created-but-never-approved draft is absent from audit history despite SRS audit requirements for operational changes.

**Required test:** Create/update/cancel draft and approved records; assert actor, company, subject, event and reversal metadata for every transition.

#### Q-06 — Company settings changes are not audit-recorded

**Location:** [CompanyProfileController updateCompany](app/Http/Controllers/Web/CompanyProfileController.php#L26).

Validated company identity, notes and print-background path are updated without an `AuditRecorder` event. This is an owner-only action but changes legal/printed company identity and should be attributable. File deletion occurs before replacement storage, so a storage write failure can also remove the prior background; this failure ordering was not induced.

**Recommended remediation/test:** Record before/after-safe metadata (exclude sensitive content), and stage new upload before deleting old asset; test storage failure and successful remove/replace audit.

#### Q-07 — Some user-facing failures remain silent or generic

**Location:** [SuperAdmin Dashboard Axios mutations](resources/js/Pages/SuperAdmin/Dashboard.jsx#L19), [global Inertia invalid handler](resources/js/app.jsx#L70), [API helpers](resources/js/useCoreApi.js#L10).

Several platform Axios `.then()` mutations have no `.catch()` or form-level error state. The global Inertia handler shows blocking `window.alert` for 419/403/5xx but does not handle 429; a non-Inertia status may use Inertia's default modal. Some API callers parse JSON unconditionally, so an HTML proxy/error page can surface a parser message instead of a stable localized error. This is a confirmed UX limitation; no security bypass is present.

**Required test:** Simulate 403/419/422/429/500/network/non-JSON for each request strategy, assert a localized inline notice and processing reset, and ensure no success reload occurs on failure.

#### Q-08 — Tracked `.rnd` random-state file

**Location:** repository root `.rnd` (tracked; content was not opened), [.gitignore](.gitignore#L1).

A file named `.rnd` is tracked and not ignored. If it contains OpenSSL random seed state, distribution in source history is unnecessary and may expose randomness-related state. Contents were deliberately not inspected under the no-secrets rule.

**Recommended remediation:** Have the repository owner classify it locally; if it is generated OpenSSL state, rotate/regenerate as appropriate, remove it from future tracking and add an ignore rule. Do not print its content into issue logs.

#### Q-09 — No Content-Security-Policy header

**Location:** [SecurityHeaders](app/Http/Middleware/SecurityHeaders.php#L17).

The middleware sets nosniff, frame, referrer, permissions and HTTPS-only HSTS headers but no CSP. This is a hardening gap, not a demonstrated exploitable XSS. Any CSP rollout needs Inertia/Vite nonce/hash and image/storage origin testing.

**Required test:** CSP report-only first; exercise Vite, inline styles, print backgrounds and storage assets before enforcement.

#### Q-10 — Transport allocation display may not sum to header extras

**Location:** [transportAllocation](app/Services/OperationsService.php#L450).

Each line's allocated extra cost is rounded independently. Three equal weight lines at 1,000 total extra cost display 333.33 each, totaling 999.99. The report should assign rounding remainder deterministically to a final line or show a documented tolerance. This is a confirmed one-cent display discrepancy; it does not show that posted inventory value is wrong.

#### Q-11 — Period close is not serialized with posting

This is the concurrency consequence in F-07. Treat it as a MySQL acceptance blocker for financial period controls; no statement is made that a race was reproduced on the current SQLite suite.

#### Q-12 — Fleet branch report may allocate a vehicle's costs to multiple trip branches

**Location:** [branch-performance aggregation](app/Services/FleetReportService.php#L47).

The report groups trips by `branch_id`, then aggregates other costs by the set of vehicle IDs in each trip group. If one vehicle has trips in two branches during the period, vehicle-level expenses/maintenance can be included in both branch rows. This is a probable report allocation risk requiring a branch/vehicle movement test; no sample was run.

### P3: Low-Risk Improvements

#### U-01 — Framework-generated pagination labels use `dangerouslySetInnerHTML`

**Location:** [Parties.jsx](resources/js/Pages/Company/Parties.jsx#L37).

Current labels are Laravel paginator-generated, not proven attacker-controlled, so this is not reported as a confirmed XSS. Prefer safe entity decoding/text rendering to avoid creating a future HTML injection sink.

#### U-02 — Modal semantics and keyboard/focus evidence are incomplete

Several modal backdrops in company pages render forms without consistent `role="dialog"`, `aria-modal`, labelled title linkage and focus return/trapping. This is an accessibility/UX review finding based on source; keyboard/screen-reader behavior was not browser-tested. Standardize a shared dialog pattern and verify keyboard-only and screen-reader flows.

#### U-03 — Global DOM augmentation is hidden coupling

**Location:** [app.jsx](resources/js/app.jsx#L16) and [observer](resources/js/app.jsx#L86).

A TreeWalker rewrites legacy Arabic text and a MutationObserver injects search inputs/titles into React-owned controls. The dataset guard limits repeated select search insertion, so an infinite loop was not established. However, this bypasses React's ownership and creates reconciliation/accessibility/performance risk on dynamically rendered forms. Migrate the enhancements into explicit localized/searchable components and browser-test before removal.

## 5. Requirements Reconciliation

| Source | Requirement/status | Audit reconciliation |
|---|---|---|
| [SRS 1.1](e-zaki-erp-srs.md#L15) | Multi-company ERP; MySQL; local XAMPP and hosted deployment | Architecture exists; only isolated SQLite test execution was performed here. MySQL/XAMPP/hosted runtime remain unverified. |
| [SRS 3.2](e-zaki-erp-srs.md#L66) | Business rules in services; same services for web/API; tenant scope | Generally reflected in service layer. Reviewed risks are exceptions at report/valuation/revocation boundaries above. |
| [SRS 4.1](e-zaki-erp-srs.md#L91) | Debtor KPI and debtor list threshold at 50 EGP | Threshold code exists; party balance source is defective, so threshold is applied to wrong/zero base. |
| [SRS 4.4](e-zaki-erp-srs.md#L126) | Grace/read-only/suspended/archive lifecycle and central middleware | Middleware present; production schedule/worker and subscription boundary coverage need live operations verification. |
| [SRS 4.8](e-zaki-erp-srs.md#L174) | Purchase cost uses factory weight; inventory enters on actual weight | Conflicts with [edit backlog A7](editandnew.md#L37) and implementation's factory default. Must be explicitly resolved before approving more purchasing data. |
| [SRS 4.9](e-zaki-erp-srs.md#L190) | Sales actual weight; cash/credit/partial, due date and credit limit | UI/services support these; credit limit uses defective party-line aggregation (F-01). |
| [SRS 4.10](e-zaki-erp-srs.md#L204) | Customer/supplier opening, movements, receipts/payments, balances and 50 EGP threshold | Implemented views exist; aging/credit figures fail reconciliation after posted activity (F-01/F-05). |
| [SRS 4.11](e-zaki-erp-srs.md#L218) | GL reports, VAT, closed periods, balance sheet | Balanced journals are enforced, but current earnings are absent from balance sheet (F-03); period concurrency needs MySQL test (F-07). |
| [SRS 4.12](e-zaki-erp-srs.md#L235) | Vehicle/trip/expenses/maintenance and financial reporting | Modules exist. Transfer valuation and fleet posting/report allocation issues need resolution; cost/revenue agreement with GL needs focused tests. |
| [SRS 5](e-zaki-erp-srs.md#L254) | Draft no effect; approved posts once; reverse rather than delete | Bill path is transactional; generic receipt/voucher reversals are not reflected in source status/summary (F-05). |
| [SRS 6](e-zaki-erp-srs.md#L282) | Versioned API, JSON errors, pagination/filtering, no tenant leakage | API exists; OpenAPI is a small subset and does not drift-check routes (Q-02). |
| [SRS 7](e-zaki-erp-srs.md#L300) | CSRF, session, audit, tenant, performance requirements | CSRF remains enabled in app and focused test; most PHPUnit tests disable it. Session version not applied to stateful API. P95 not measured. |
| [SRS 12](e-zaki-erp-srs.md#L420) | Acceptance requires matching reports, browser/mobile, production deployment and backup/restore | Not achieved by source/tests alone; live evidence remains required. |
| [Master prompt 1](e-zaki-laravel-master-prompt.md#L40) | Master for phase gates; SRS for business; do not call release-ready without acceptance | Audit verdict follows this gate. |
| [Progress current phase](e-zaki-implementation-progress.md#L23) | Phase 3 `PARTIALLY_COMPLETED`, Phase 4 says not started at its section | Later progress entry says Phase 4 is `IN_PROGRESS` ([line 475](e-zaki-implementation-progress.md#L475)); this is an internal progress-document contradiction. Reconcile the header/board before release planning. |
| [editandnew](editandnew.md#L34) | Many items remain `IN_PROGRESS` pending operator-visible confirmation | These are missing acceptance evidence, not automatically code defects. Do not mark complete from SQLite/build alone. |
| [README](README.md#L1) | Laravel framework boilerplate | Does not describe E-Zaki setup, modules, test commands or runbook; [DEPLOYMENT.md](DEPLOYMENT.md#L1) is the actionable setup document. Documentation onboarding remains weak. |

Deferred/decision-required: SRS 1.2 excludes payment gateways and mobile app; master prompt defers Velzon and phase progression. `editandnew.md` marks a pure type/diameter schema change deferred. Purchase stock basis is not a safe implicit deferral because requirements conflict and existing data/policy locks make later conversion expensive.

## 6. Accounting-Control Matrix

The expected entries below are conventional accrual-accounting entries and are compared with current service-generated entries. They are not an external accountant's sign-off of Egyptian tax treatment. Amounts are illustrative; VAT is only active when company policy enables it.

| Event | Expected entries | Current implementation | Control assessment |
|---|---|---|---|
| Purchase, no VAT | Dr inventory (net goods + capitalizable extras - discount); Cr cash/bank for paid amount; Cr supplier control for remainder | `purchaseJournalLines()` debits inventory=`total - VAT`, credits source paid and supplier outstanding; balanced check in `postJournal()` | Entry arithmetic is balanced. Stock quantity default conflicts with SRS; party tags corrupt party aggregation. |
| Purchase with VAT | Dr inventory base; Dr input VAT; Cr cash/bank; Cr AP | Implemented using system `inventory_asset` and `input_vat`, with paid/outstanding split | Structurally correct. Tax eligibility/rate treatment was not validated against local tax law or tax invoices. |
| Sales, no VAT | Dr cash/bank for paid; Dr customer control for outstanding; Cr sales revenue; Dr COGS / Cr inventory | `salesJournalLines()` implements paid/outstanding, revenue base, COGS and inventory issue in balanced entry | Entry arithmetic/weighted average exists; party ID is attached to non-control lines, breaking debtor calculations. |
| Sales with VAT | Dr cash/bank and customer control for total; Cr revenue net; Cr output VAT; Dr COGS / Cr inventory | Implemented with output VAT account and total split | Structure correct; credit-limit enforcement/reporting defect remains. VAT law/rate/rounding not externally validated. |
| Partial bill payment | Purchase: Dr inventory/VAT; Cr cash paid; Cr AP remainder. Sale: Dr cash paid/AR remainder; Cr revenue/VAT; COGS pair | Paid amount included in invoice posting; separate allocations recorded | Amounts balance. Later settlement allocation aging/overpayment policy needs end-to-end tests. |
| Customer receipt | Dr selected cashbox/bank; Cr customer control | `AccountingService::receive()` posts these lines | Journal shape correct; subsequent generic reversal leaves source posted for party summaries (F-05). |
| Supplier voucher | Dr supplier control; Cr selected cashbox/bank | `paySupplier()` posts these lines | Journal shape correct; reversal/source reporting has same F-05. |
| Other receipt/payment | Receipt Dr cash / Cr selected posting account; payment Dr selected posting account / Cr cash | `postableOtherAccount()` rejects cash-equal and header accounts | Code is structurally sound; no full live-browser reconciliation. |
| Opening balance | Customer debit: Dr control / Cr opening equity; supplier credit: Dr opening equity / Cr control | `postOpeningBalance()` posts the selected direction | Correct direction by configured field; mixed customer/supplier defaults and historical re-posts need targeted edge tests; do not infer a universal mixed-party direction. |
| VAT | Input VAT debit on purchase; output VAT credit on sale; reverse with source | VAT is calculated as rounded rate on `subtotal - discount + extras`; separate accounts used | Balanced entry. Exemption, recoverability, credit notes and statutory rounding treatment lack external policy validation. |
| COGS/Inventory | Weighted-average cost: Dr COGS / Cr inventory on sale | `InventoryValuationService::issue()` computes average from balance value/quantity | Sales issue supports this method. Transfers do not carry value (F-02), so destination COGS can be zero. |
| Transfer | Within-company: reduce source qty/value and increase destination by identical qty/value; normally no company-level P&L | Current transfer only changes quantity and logs quantity movements | Incorrect per-warehouse valuation and subsequent COGS, confirmed F-02. |
| Reversal | New entry swaps original debit/credit on reversal date; do not delete original | `reverseJournal()` flips lines and blocks direct reversal of selected source workflows | Journal reversal model is sound; source receipt/voucher status/summary remains inconsistent (F-05). |
| Approved revision | Reverse original; post revised document and its stock effects atomically; retain before/after revision and reason | `OperationsService::revise()` transactionally unposts/reposts and creates `BillRevision` | Structure is appropriate. MySQL concurrency and failure/rollback coverage remain unverified. |
| Fleet expense/maintenance | Dr expense; Cr actual cash/bank or explicit payable/source account | No-source paths credit current asset header `1.2`; maintenance always credits it | Cash/payment reconciliation problem, F-08. |
| Period close | Reject all postings dated in closed period; record close; define year-end P&L close to equity | Open period lookup and close are present; no shared row-lock boundary or P&L closing transfer | Concurrency risk F-07 and balance-sheet earnings defect F-03. |

## 7. Security-Control Matrix

| Control | Status | Evidence and limitation |
|---|---|---|
| Authentication | Implemented; production proof missing | Web/API login, generic authentication errors, account/IP limiter and lockout services exist. No live SMTP/password-reset flow was exercised. |
| Authorization | Mostly implemented; test/live gaps | Route `can` gates, owner behavior, user/role permission ceilings, account-type checks. Owner intentionally has broad company access. Server is authoritative; browser links are UX only. |
| Tenant isolation | Strong pattern; no live MySQL attack test | Company context middleware and repeated company-scoped lookups. Composite cross-company FK constraints are not uniformly expressible; direct/manual data corruption scenarios need MySQL tests. |
| CSRF/XSRF | Enabled in application | Laravel validates web and stateful API mutations; frontend uses encrypted-cookie `X-XSRF-TOKEN`. Shared PHPUnit base bypass limits broad proof. |
| Sessions | Secure defaults documented; API revocation gap | Secure/HttpOnly/SameSite settings and separate company/platform cookies exist; stateful API missing custom session-version middleware (S-01). Production proxy/cookie flags not inspected at runtime. |
| API | Auth/rate-limit/tenant middleware exist; contract incomplete | Sanctum, versioned routes and throttles. OpenAPI covers 12 paths/14 operations, not the operational API surface; no complete drift check. |
| Uploads | Server validations/path separation present | Print image accepts jpg/jpeg/png/webp, 5MB, company directory on public disk. No production disk/MIME/storage-link test; old file is deleted before new store succeeds. |
| Licensing | Signature code present; installation binding gap | Signed activation checks caller-supplied IDs; runtime doesn't check the active installation ID (F-06). Do not rely on copy resistance until fixed or limitation accepted. |
| Subscriptions | Central middleware exists | Grace/read-only/suspended/archive code and scheduled commands are present. Schedule execution, worker and provider mail not tested. |
| Audit logs | Many high-value transitions recorded; gaps | AuditRecorder supports company/user/subject/metadata/IP; draft fleet creation and company settings are absent (Q-05/Q-06). No retention/immutability operational verification. |
| CORS/proxy/HTTPS | Configurable/documented | Explicit origins and trusted-proxy middleware exist. No production HTTPS/cPanel/Passenger or `X-Forwarded-Proto` browser pass in this audit. |
| Security headers | Partial | HSTS only on secure requests plus nosniff/frame/referrer/permissions; CSP absent (Q-09). |
| Backups | Checksummed with restore guard; operational proof missing | Backup/restore code and runbook exist. No MySQL dump, encryption/offsite, checksum restore or RPO/RTO drill was performed. |

## 8. QA / Release Gates

| Check | Result | Limitation |
|---|---|---|
| `npm test` | PASS — 10 tests, 0 failures | Includes source-contract, CSRF helper/FormData and navigation tests; not React DOM/browser E2E. |
| `php vendor/bin/phpunit --do-not-cache-result --cache-directory <OS temp>` | PASS — 105 passed, 1 skipped (106 total), 1,307 assertions | PHPUnit uses in-memory SQLite/array session. Skipped test is ephemeral OpenSSL keypair generation in `PhaseOneFoundationTest`; no live MySQL/concurrency coverage. |
| `php vendor/bin/pint --test` | PASS | Read-only format check. |
| `composer validate --no-check-publish` | PASS — `composer.json is valid` | No dependency installation performed. |
| `php scripts/lint-openapi.php` | PASS — 12 paths, 14 operations, v1.0.0 | Structural/route substring linter only; does not detect route drift. |
| `npm audit --omit=optional` | PASS — 0 vulnerabilities | Advisory check only; does not prove dependency suitability or runtime hardening. |
| `git diff --check` | PASS — clean before report creation | The requested report is the only new project file. |
| `npm run build` | NOT RUN | Vite build overwrites generated `public/build` artifacts; audit constraints prohibit modifying generated project files. Existing build status is not treated as a current-source build proof. |
| Browser E2E at 320/390/768/1024/1440px | NOT RUN | User explicitly prohibited browser mutations; browser was not used at all. |
| MySQL migration/query/concurrency, production HTTPS/proxy, mail, queue, storage, restore | NOT RUN | Requires deployment-like infrastructure and operator-owned data/services. No `.env` or live DB access. |
| Performance/P95, EXPLAIN, screen reader, print acceptance | NOT RUN | Requires representative production-like data/browser/device or assistive technology. |

## 9. Prioritized Remediation Roadmap

### P1 — Before financial reliance

1. Fix party subledger semantics first; reconcile receivables/payables, credit limits, dashboard debtors and party statements to control accounts. Add opening-balance and reversal cases.
2. Preserve inventory value when transferring between warehouses and test destination COGS/GL conservation.
3. Define and implement balance-sheet current-earnings policy; require assets = liabilities + equity tests.
4. Resolve SRS actual-weight versus backlog factory-weight policy with an explicit product decision and migration plan before further approvals.
5. Add source-level receipt/voucher reversals so reports exclude reversed cash events while preserving journal history.
6. Close local license runtime installation-binding gap or formally narrow the offline anti-copy guarantee.
7. Apply session-version validation to stateful API requests after password changes.
8. Require explicit cash/bank/payable accounts for fleet costs; eliminate silent credit to `1.2` header.
9. Serialize period close and posting on MySQL and add a concurrency test.

### P2 — Before production sign-off

1. Expand CSRF-enabled feature coverage while retaining isolated SQLite tests; add MySQL and browser E2E CI jobs.
2. Generate/compare OpenAPI against registered API routes and cover every supported endpoint.
3. Batch/paginate report data, remove party-aging N+1, add report query plans and volume benchmarks.
4. Add audit events for company settings and draft fleet transitions; stage file replacement safely.
5. Improve platform Axios/Inertia/API error feedback for network, 419, 422, 429 and 5xx; avoid blocking alert-only UX.
6. Add CSP in report-only mode, then enforce after Vite/storage/print validation; classify `.rnd` offline and remove/ignore if generated state.
7. Correct extra-cost allocation remainder and test fleet branch allocation for cross-branch vehicle trips.

### P3 — Usability and maintainability

1. Replace DOM mutation-based localization/search enhancements with explicit React components.
2. Replace pagination `dangerouslySetInnerHTML` with safe label text conversion.
3. Standardize modal semantics, focus trap/return and responsive/keyboard/screen-reader test coverage.
4. Replace boilerplate README with project-specific setup, architecture, accounting policy and test/run commands.

## 10. Safe Use and Conclusion

The app's authentication, tenant middleware, service structure, basic balanced journal guard, source-document reversal pattern, and CSRF mechanisms are meaningful foundations. The isolated tests prove selected lifecycle invariants, not financial correctness. Current source behavior is unsafe to rely on for debtor/payable aging, credit limits, cross-warehouse stock costing, balance-sheet completeness, or local-license copy resistance. Resolve the P1 findings and get an accountant/product owner to approve the weight-basis conflict before using these workflows for financial statements or production stock control.

Safe now, with qualifications: read-only code review; development/test use against isolated data; basic UI/auth flows covered by existing feature tests. Not signed off: live financial use, production sign-off, MySQL compatibility, concurrent close/post behavior, backup restore, or full browser acceptance.

## 11. Evidence and Limitations

- The audited HEAD was `5dedae0bdd11938f1babfd8accd796d90c9a16f4`; worktree was clean before the report was created.
- No code, migration, test, config, dependency, database, build artifact, deployment file, or `.env` was modified/read. The only repository deliverable is this Markdown report.
- Numeric experiments ran against separate temporary SQLite files under the OS temp directory; they did not connect to MySQL or the application's local database.
- The isolated probes verified the party-balance/credit-limit discrepancy, zero-value warehouse transfer and resulting zero issue cost, and balance-sheet omission with a balanced trial balance.
- Generic receipt/voucher reversal inconsistency, fleet fallback account, license cloning, stale stateful API sessions and close/post race were established by code-path review; no live exploit or production request was executed.
- Browser testing and all application mutations were intentionally omitted per instruction.
- Live MySQL migrations/constraints/indexes, cPanel/Passenger/OpenResty HTTPS, cookie attributes, SMTP, queue worker, scheduler execution, public-storage symlink, private storage permissions, MySQL backup/restore, encryption/offsite backup, and measured RPO/RTO remain unverified.
- One OpenSSL-backed license test was skipped because the configured CLI could not generate an ephemeral keypair; the remaining 106 tests passed.
- The generated Vite build was not rerun because doing so would overwrite `public/build`, which is outside the permitted read-only audit scope.
