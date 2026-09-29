# E‑ZAKI ERP IMPLEMENTATION PROGRESS

## Purpose

This file is the only progress source of truth for the Laravel rebuild.

The implementing agent must:

1. Read this file before writing code.
2. Continue from the first incomplete phase.
3. Never restart a completed phase.
4. Never erase historical records.
5. Update this file immediately after each phase or regression fix.
6. Stop after the current phase unless the user explicitly accepts it and asks to continue.

Binding specification: `e-zaki-erp-srs.md` version 1.1  
Binding prompt: `e-zaki-laravel-master-prompt.md`

Allowed status values: `NOT_STARTED` | `IN_PROGRESS` | `COMPLETED` | `PARTIALLY_COMPLETED` | `BLOCKED`

---

## CURRENT PHASE

Phase 3 — Operations & Fleet  
Status: `PARTIALLY_COMPLETED`  
Next action: Continue the Phase 2/3 product-correction pass only. Do not start Phase 4.

---

## Phase status board

| Phase | Name | Status |
|---|---|---|
| 1 | Foundation & Platform | COMPLETED |
| 2 | Core ERP & Accounting | COMPLETED |
| 3 | Operations & Fleet | PARTIALLY_COMPLETED |
| 4 | Security, UI, QA & Release | NOT_STARTED |

---

## Locked product decisions (do not reopen)

| Decision | Value |
|---|---|
| Self-registration email verification | Required before a request is complete/approvable |
| Data retention after 6 months | Archive by default. No automatic permanent deletion |
| XAMPP license | Fully offline after activation. No periodic online check |
| Draft documents | No stock and no journal |
| Approved documents | Stock + journal + audit, once, in a transaction |
| v1 payments | Manual by Super Admin |
| Mobile app | Out of v1; REST API must be ready |
| Velzon | Integrate when licensed files exist, preferably Phase 4 |

---

## Phase 1 — Foundation & Platform

Prior recorded status before this resume: `NOT_STARTED` (the prior next action said to create the Laravel app; the user confirmed that Laravel 12 already existed at the project root, so the existing app was retained and extended in place).

Status: `COMPLETED`

Scope:

- Laravel + React/Inertia + Sanctum + MySQL
- Super Admin and company auth
- Users, roles, direct permissions
- Tenancy
- Plans, subscriptions, coupons, trials, manual payments abstraction
- Self-registration with email verification
- Subscription lifecycle middleware
- Local offline license activation
- Layout shells, locale, RTL/LTR
- `/api/v1` auth and health
- Audit/login log foundation
- XAMPP-compatible public document root

Files created/modified: Composer and npm manifests/lockfiles; Laravel bootstrap, auth models/controllers/services/middleware, permission and platform configs; Inertia React app, company/Super Admin layouts and Phase 1 pages; API/web routes; `.env.example`; repository-root `.htaccess` source guard; `public/.htaccess`; `DEPLOYMENT.md`; additive user-name, expiry-notice, and company-notes migrations; Phase 1 feature tests. The user's `.env` was not read or modified.

Migrations:

- `2026_09_27_215126_create_permission_tables.php` (published Spatie schema, company team scope enabled)
- `2026_09_28_000001_create_platform_foundation_tables.php` (companies, user identity fields, plans, coupons, subscriptions, manual payments, registration requests, local licenses, audit/login logs)
- `2026_09_28_000002_create_personal_access_tokens_table.php` (Sanctum)
- `2026_09_28_000003_create_platform_settings_table.php`
- `2026_09_28_000004_create_subscription_expiry_notices_table.php` (per-subscription, per-owner, per-day deduplication)
- `2026_09_28_000005_add_user_name_parts.php` (additive first/second name fields)
- `2026_09_28_000006_add_notes_to_companies_table.php` (additive company admin notes)

Implemented foundation: Laravel 12.69.2 remains on PHP 8.2; Inertia Laravel/React, React, Vite, Sanctum, and Spatie roles/permissions installed; separate company and Super Admin session sign-in; Sanctum API login/logout; company tenant/team context; plans, calendar-based subscription lifecycle and write restrictions; public email-verified self-registration and a separate Super Admin manual-company creation flow; transactional company/verified-owner/default-role/initial-subscription provisioning shared by registration approval and manual creation; optional coupon application and auditable admin notes; duplicate owner emails rejected; signature-verified offline license foundation storing only a key hash; audit/login logs; company user UI with registration/login/failure metrics, edit, profile, status, reset-password, login history and activity; create/edit role ceilings prevent privilege escalation; user seat limits are transactionally locked and support Super Admin add-ons; reusable branch/warehouse limit counters and add-ons exist without implementing those modules; Super Admin company activate/suspend/archive/restore/renew/plan-change/limit-override actions; daily owner email expiry warnings from seven days before expiry with per-day deduplication; Arabic/English locale and RTL/LTR; company and Super Admin shells; `/api/v1` health/auth/subscription routes; XAMPP public-only rewrite/deployment notes; interactive `app:create-super-admin` command (no seeded credentials).

Prior-session commands and results (the follow-up below supersedes items where explicitly stated):

- `php -v` — PHP 8.2.4.
- `composer --version` — Composer 2.6.6.
- `node --version` — Node 20.9.0; `npm.cmd --version` — npm 10.1.0.
- `php artisan --version` — Laravel Framework 12.69.2.
- `composer require "inertiajs/inertia-laravel:2.*" "laravel/sanctum:4.*" "spatie/laravel-permission:6.*" -W` — succeeded; locked Inertia Laravel 2.0.28, Sanctum 4.3.3, Spatie Permission 6.25.0. An earlier caret-constraint attempt failed resolution and Composer restored its files; it did not change the Laravel version.
- `npm.cmd install react react-dom @inertiajs/react` — succeeded; adapter aligned to Inertia 2.x.
- `npm.cmd install --save-dev @vitejs/plugin-react@5.0.0 vite@6.4.1 laravel-vite-plugin@1.3.0`, followed by `npm.cmd install --save-dev @vitejs/plugin-react@4.7.0` — Node-compatible frontend toolchain established.
- `npm.cmd audit fix` — patched Vite to 6.4.3 within the existing Vite 6 range; zero advisories afterward.
- `php artisan test` — 11 passed, 1 skipped, 53 assertions. The skipped test is the valid offline-license signature test because `openssl_pkey_new()` returns `false` in this CLI; the fail-closed license API test passed.
- `vendor\bin\pint.bat --test app config routes database\migrations tests bootstrap\app.php` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 788 modules transformed and production manifest/assets generated.
- `composer validate --no-check-publish` — `composer.json is valid`.
- `npm.cmd audit` — found 0 vulnerabilities.
- `php artisan route:list --path=api/v1` — 15 versioned API routes registered.
- `php artisan route:list --path=super-admin` — 9 Super Admin routes registered.
- `php artisan list app` — confirmed `app:create-super-admin` is registered.

HTTP/test results: in-process feature tests verify JSON `GET /api/v1/health`, signed registration email verification and approval provisioning, rejection of Super Admin credentials on company sign-in, Sanctum token issuance/revocation, tenant-scoped role listing, read-only write rejection with subscription status/password-change allowances, Arabic Inertia locale/document direction, and fail-closed local-license configuration. Live Laravel server started with `php artisan serve --host=127.0.0.1 --port=8000`; browser `GET /api/v1/health` returned `{"status":"ok","version":"v1"}`, `/` rendered the Inertia shell, and the Arabic switch rendered Arabic UI text. Apache/XAMPP and MySQL HTTP deployment were not available for live-browser validation.

Limitations:

- The valid-license-signature integration test is `SKIPPED` because this PHP CLI cannot generate its ephemeral test keypair. No private signing key is stored in the repository; the deployed local edition must receive only its public verification key.
- MySQL/XAMPP and production mail delivery were not exercised. Tests use PHPUnit's in-memory SQLite configuration; local/production database and SMTP setup remain deployment tasks.
- SQLite validation mishap: an attempted `$env:DB_DATABASE=':memory:'; php artisan migrate:fresh --database=sqlite --force` had its PowerShell environment assignment prefixed/corrupted by the terminal wrapper. Artisan therefore ran `migrate:fresh` against `database/database.sqlite`, reported dropping tables, then completed all migrations. The initial workspace inventory did not contain `database.sqlite`; it now exists as the generated/rebuilt ignored local SQLite database. Do not repeat `migrate:fresh` against any user database. No GitHub token was requested, logged, or stored.

Prior-session next action (superseded by the follow-up below): enable OpenSSL key generation in the selected PHP CLI, rerun the skipped license activation test and Phase 1 tests, then reassess whether Phase 1 can be marked `COMPLETED`. Stop at the Phase 1 boundary; no Phase 2 modules have been started.

### Phase 1 follow-up verification — 2026-09-28

The earlier OpenSSL skip is resolved. The active Apache installation is `D:\xampp`; its matching CLI `D:\xampp\php\php.exe` is PHP 8.2.4, has OpenSSL loaded, and successfully generated an ephemeral in-memory RSA keypair. No key was exported to disk or printed. `C:\xampp\php\php.exe` also has OpenSSL enabled, but its default OpenSSL config points to the absent `C:\xampp\apache\bin\openssl.cnf`; passing `OPENSSL_CONF=D:\xampp\apache\bin\openssl.cnf` to that process also made key generation succeed. The D-drive PHP emits a harmless `Module "openssl" is already loaded` warning, but key generation and signing tests pass. No `php.ini` or system environment setting was modified.

Follow-up commands and evidence:

- `& 'D:\xampp\php\php.exe' -r "var_dump(openssl_pkey_new() !== false);"` — `bool(true)`; key remained in memory only.
- `& 'D:\xampp\php\php.exe' artisan test --filter=PhaseOneFoundationTest` — 15 passed, 73 assertions, including the valid installation-bound offline signature test (no skips).
- `& 'D:\xampp\php\php.exe' artisan test` — 17 passed, 75 assertions; no skips.
- `vendor\bin\pint.bat --test app config routes database\migrations tests bootstrap\app.php` — passed.
- `& 'D:\xampp\php\php.exe' artisan serve --host=127.0.0.1 --port=8010` — started; browser `GET http://127.0.0.1:8010/api/v1/health` returned HTTP 200 and `{"status":"ok","version":"v1"}`.
- In the Phase 1 HTTP feature tests, company `POST /login` returned 302 to the company dashboard and authenticated a company user; Super Admin `POST /super-admin/login` returned 302 to the platform dashboard and authenticated a Super Admin. A Super Admin credential sent to company login is rejected.
- In the Phase 1 HTTP feature tests, `POST /locale/ar` returned 302; the next `GET /` returned 200 with `lang="ar"` and `dir="rtl"`.
- With a Sanctum-authenticated read-only company user, `GET /api/v1/subscription` returned 200 with `read_only`; `PUT /api/v1/password` returned 200. With an authenticated browser session, `POST /password` returned 302 and changed the password.
- Without authentication, `GET /api/v1/subscription` returned HTTP 401. With an authenticated read-only company user, an operational `POST /api/v1/users` returned HTTP 403.
- XAMPP Apache is running with `DocumentRoot "D:/xampp/htdocs"`, not this application's `public/`. After correcting malformed duplicated `public/.htaccess` content and adding a repository-root rewrite guard, browser `GET http://localhost/ezaki-erp/` and `GET http://localhost/ezaki-erp/composer.json` returned HTTP 403; `GET http://localhost/ezaki-erp/public/api/v1/health` returned the expected JSON; `/ezaki-erp/public/login` rendered HTML. Its generated `/build/...` asset URLs returned HTTP 404 because this URL is a nested physical path, not a configured vhost/Alias whose document root is `public/`. Therefore Apache source protection and the Laravel public health route are verified, but full Apache public-root UI validation is NOT PASSED; configure the documented vhost/Alias and retest.

Safe MySQL setup (documented in `DEPLOYMENT.md`; not executed against any database): start XAMPP MySQL; create a new empty `ezaki_erp` database with `utf8mb4_unicode_ci`; create `ezaki_erp_user` for `localhost` with a local-only password and privileges only on `ezaki_erp.*`; use `.env.example` as the template. Copy it only when `.env` does not exist. If `.env` already exists, do not overwrite it; the operator should back it up outside the web root and change only `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=ezaki_erp`, `DB_USERNAME=ezaki_erp_user`, and `DB_PASSWORD` locally, preserving the existing `APP_KEY`. Back up an existing database before applying forward migrations; run `php artisan migrate --force`, never `migrate:fresh`, `migrate:refresh`, or a drop/recreate command.

No `migrate:fresh` or database migration command was run during this follow-up. The user's `.env` remains unopened and unchanged. Apache and MySQL were not reconfigured; no GitHub token was requested, logged, or stored.

Prior-session remaining Phase 1 gaps (superseded by the 2026-09-28 update below):

- The current XAMPP Apache vhost/Alias is not configured to use `D:\xampp\htdocs\ezaki-erp\public` as its document root. Under the stock parent `htdocs` mapping, the app HTML responds but built assets use root-relative `/build/...` URLs and fail. The documented vhost/Alias setup must be applied and retested.
- MySQL setup and forward migrations have not been executed or validated. Follow the safe new-database steps above; do not point at an existing user database without a backup and explicit operator approval.
- The complete company users UI is not finished to the master-prompt minimum: last-login/registration/login-count/failed-attempt columns, edit/profile and visible activate/deactivate/password-reset/activity actions are not all present in the page, although supporting fields and some API endpoints exist.
- Plan limit enforcement/add-on overrides, full Super Admin company lifecycle/restore actions, and scheduled/repeating expiry notifications are not implemented/validated. These remain Phase 1 scope and must be closed or explicitly tracked before Phase 1 can be marked `COMPLETED`.

No branches, warehouses, products, sales, purchases, inventory, accounting documents, or fleet work was started. Phase 2 remains `NOT_STARTED`.

### Phase 1 product-gap completion pass — 2026-09-28

Completed within the requested Phase 1 boundary:

- Company users table now presents registration date, last login, successful login count, failed attempts, role/status, profile, permission-gated login history/activity links, and edit/activate/deactivate/reset-password actions. First/second names are stored additively. User create/edit supports multiple roles and direct permissions grouped by module; server-side checks prevent granting permissions not held by the acting user. Deactivation and password reset revoke sessions/tokens. User creation/reactivation checks the active non-owner seat count under a subscription row lock.
- Plan user/branch/warehouse limits and Super Admin `limit_addons` are stored and combined. Branch/warehouse counters are generic and count non-archived rows when those tables exist; those modules and create handlers were deliberately not added.
- Super Admin company lifecycle supports registration approve/reject and company activate, suspend, archive, restore, renewal with manual payment record, plan changes, and user/branch/warehouse add-on limits. Mutations are audited.
- `subscriptions:send-expiry-warnings` is scheduled daily at 08:00. It sends owner email reminders while active subscriptions are within seven days of expiry, repeats once per day, and uses a unique per-subscription/user/date ledger. XAMPP Task Scheduler and hosted cron commands are documented in `DEPLOYMENT.md`.
- Apache public-root retest used the user's `ezaki.test` vhost without reconfiguration. `/login` rendered the React page, `/api/v1/health` returned JSON, built Access JS and CSS assets were served, and `composer.json` returned 404.

Current verification commands and evidence:

- `& 'D:\xampp\php\php.exe' artisan test` — **24 passed, 169 assertions, 0 skipped**. Includes user-list metrics/profile, user quota rejection and add-on success, role create/update and privilege-escalation rejection, company lifecycle/renewal/restore, suspended status/logout policy, and daily-notice deduplication.
- `vendor\bin\pint.bat --test app config routes database\migrations tests bootstrap\app.php` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 791 modules transformed after grouped-permission UI changes; manifest includes Company Access/Profile/Activity/Login History and Super Admin dashboard bundles.
- `& 'D:\xampp\php\php.exe' artisan schedule:list` — `subscriptions:send-expiry-warnings` scheduled `0 8 * * *`.
- `& 'D:\xampp\php\php.exe' artisan route:list --path=super-admin` — 16 routes, including company activate/suspend/archive/restore/renew/plan/limit-addons plus registration review.
- Browser `GET http://ezaki.test/login` — rendered the company sign-in page; browser `GET http://ezaki.test/api/v1/health` — `{"status":"ok","version":"v1"}`; direct `Access-BtHlwFOg.js` and `app-B5SRRp50.css` assets from the final manifest rendered their built content; `GET http://ezaki.test/composer.json` — 404.
- Browser host root `http://ezaki.test/` was previously confirmed rendering the E-Zaki Inertia home page; no vhost configuration was changed.
- OpenSSL signing integration remains executable on `D:\xampp\php\php.exe`; no private key was persisted or printed.

Deployment and later-phase notes (not Phase 1 product gaps):

- MySQL live connection/setup and the additive migrations against MySQL: **NOT EXECUTED**. No database connection was attempted, `.env` was not read/printed/modified, and no migration/reset/drop command was run. This is an operator deployment task; test coverage used isolated in-memory SQLite and does not claim MySQL validation.
- Branch/warehouse creation handlers remain intentionally out of scope until Phase 2. `CompanyLimitService` already calculates plan plus Super Admin add-on limits and counts existing non-archived branch/warehouse records if those tables exist. Integrating the guard into future create transactions is deferred with those modules and is not a remaining Phase 1 gap.
- Production mail transport and real SMTP delivery: **NOT EXECUTED**. This is a deployment task; reminder scheduling, recipient selection, seven-day window, and daily deduplication behavior were validated with Laravel's notification fake.

Phase 1 has no remaining in-scope product gaps and is marked `COMPLETED` based on the recorded implementation and validation evidence. Phase 2 remains `NOT_STARTED` pending operator acceptance. No branches, warehouses, products, sales, purchases, inventory, accounting documents, or fleet code was created.

### Manual Super Admin company creation — 2026-09-28

Added a separate `POST /super-admin/companies` action and form; it does not call the public registration route, does not create a registration-request row, and does not depend on the self-registration open/closed setting. The form captures company/trade/tax/contact/address fields, owner name/email/password, active plan, optional coupon, and notes. The provisioning service now shares one transaction for company + verified owner + default team-scoped roles + initial subscription/coupon accounting across both verified registration approval and manual Super Admin creation. Owner email is checked against users and registration requests and rejected on duplicate. Company notes use additive migration `2026_09_28_000006_add_notes_to_companies_table.php`. Newly-created owner is active, email-verified at creation, company-approved, and immediately has the selected subscription; later lifecycle actions use the existing Super Admin company controls.

Validation evidence:

- `& 'D:\xampp\php\php.exe' artisan test --filter='super_admin_can_create_a_verified_company_owner|manual_company_creation_rejects_duplicate'` — 2 passed, 24 assertions. Confirms company and verified owner creation, hashed password, three default roles, selected plan, coupon discount/redemption, notes, no registration request, tenant-scoped role isolation, and duplicate email rejection without a second company.
- `& 'D:\xampp\php\php.exe' artisan test` — **26 passed, 193 assertions, 0 skipped** after the manual-company tests were added.
- `vendor\bin\pint.bat --test app config routes database\migrations tests bootstrap\app.php` — passed after Pint formatting.
- `npm.cmd run build` — passed on Vite 6.4.3; 791 modules transformed; generated Super Admin dashboard bundle includes manual company form.
- Browser `GET http://ezaki.test/` and `GET http://ezaki.test/super-admin/login` rendered the React pages; `GET http://ezaki.test/api/v1/health` returned `{"status":"ok","version":"v1"}`; `GET http://ezaki.test/build/assets/Dashboard-C7Z7JBGt.js` served the compiled Super Admin company form; `GET http://ezaki.test/composer.json` returned 404. Apache configuration was not changed.
- MySQL connection and migrations remain **NOT EXECUTED**. No migration command, database reset/drop, `.env` read/write, or Apache reconfiguration was performed in this task. PHPUnit uses isolated in-memory SQLite.

Phase 1 is `COMPLETED`. MySQL live validation and production SMTP are deployment tasks, and branch/warehouse create-path limit integration is deferred to Phase 2 with those modules. No Phase 2 work started in this turn.

---

## Phase 2 — Core ERP & Accounting

Status: `COMPLETED`

Completed within the accepted Phase 2 boundary:

- Additive tenant-scoped schema and models for branches, warehouses, product types, diameters, products, stock balances/movements, transfers, accounts, unified customers/suppliers, cashboxes, banks, fiscal periods, journal entries/lines, receipts, and supplier payment vouchers.
- Branch creation transactionally creates a default warehouse. Branch and warehouse quotas are checked independently; branch/warehouse edits and archive rules retain historical rows and block archival while stock or blocking documents remain.
- Master data supports steel and other item types, standard and custom diameters, ton/kilogram units, minimum stock, opening balances, zero-balance visibility, stock transfers, and a warehouse/company inventory matrix with row and column totals.
- Unified customer/supplier parties receive one tenant-scoped, automatically coded child account. The chart skeleton, child account coding, cashbox/bank accounts, fiscal periods, overlap protection, and closed-period posting protection are implemented.
- Manual receipts and supplier payment vouchers post balanced journals transactionally. Journal reversal creates a reversing entry and retains the original entry.
- Journal, ledger, account statement with running balances, trial balance, income statement, balance sheet, and >=50 EGP debtor reports are implemented with tenant-scoped aggregation.
- Inertia pages and REST APIs exist for branches, inventory, parties, accounting, reports, dashboard metrics, and capability-aware navigation. Sales and purchase metrics remain zero because bills are explicitly outside this phase.
- Company provisioning seeds the Phase 2 chart, fiscal period, standard diameters, and owner permissions.

Validation evidence — 2026-09-28:

- `& 'D:\xampp\php\php.exe' artisan test --filter=PhaseTwoCoreTest` — 13 passed, 138 assertions.
- `& 'D:\xampp\php\php.exe' artisan test` — 39 passed, 336 assertions, 0 skipped. This includes Phase 1 regression coverage and all Phase 2 acceptance tests.
- `& 'D:\xampp\php\php.exe' vendor\bin\pint ...` — Phase 2 implementation/test files formatted; the focused `pint --test` then passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 797 modules transformed and production assets generated.
- `& 'D:\xampp\php\php.exe' artisan route:list --path=api/v1/reports` — report API route registered.
- `Invoke-WebRequest http://ezaki.test/api/v1/health` — HTTP 200, `{"status":"ok","version":"v1"}`.
- `Invoke-WebRequest http://ezaki.test/login` — HTTP 200 and Inertia login HTML rendered.

Boundaries and limitations:

- Phase 3 remains untouched: no purchase bills, sales bills, fleet, trips, expenses, maintenance, or Velzon integration was started.
- The operator confirmed that the local MySQL migration completed successfully. This was not independently verified: `.env` was not read, no database connection was attempted, and no migration, reset, drop, or destructive database command was run by the agent. Automated validation remains based on isolated in-memory SQLite. Production SMTP was not exercised.
- The PHP CLI emits a harmless duplicate OpenSSL warning during commands; it does not affect the passing tests.

---

## Phase 3 — Operations & Fleet

Status: `PARTIALLY_COMPLETED`

Implemented within the Phase 3 boundary:

- Additive operations/fleet migration and tenant-scoped models for purchase bills/lines, sales bills/lines, revisions, vehicles, drivers, trips, fleet expenses, and maintenance records.
- Purchase and sales draft persistence with internal sequential numbers, supplier/customer bill numbers, duplicate blocking, near-duplicate warning endpoints, exact header/line weight and package validation, optional VAT, header extras, and display-only per-ton allocation endpoint.
- Purchase approval uses factory weight for value and actual weight for stock. Sales uses actual weight and rejects insufficient stock. Drafts do not change stock or GL; approval posts stock movements, one balanced journal, and audit in one transaction.
- Company vehicle trips are created from approved purchase/sales bills. Fleet expense and maintenance approval paths post journals; drafts remain non-posted.
- Approved bill revision records before/after snapshots and audit metadata and validates an open fiscal period. Reversal restores stock and delegates journal reversal to the completed accounting service.
- Permission-aware API routes and Inertia operations, bill-form, Fleet, and Fleet-report pages for bills, vehicles, drivers, trips, expenses, maintenance, and operational reports. Dashboard monthly purchase/sales cards and chart datasets now use approved bill/payment data and operations navigation is capability-aware.
- Phase 3 permissions are provisioned for purchases, sales, and fleet without changing completed Phase 1/2 modules.

Validation evidence — 2026-09-28:

- `& 'D:\xampp\php\php.exe' artisan test --filter=PhaseThreeOperationsTest` — 10 passed, 137 assertions.
- `& 'D:\xampp\php\php.exe' artisan test` — 49 passed, 473 assertions, 0 skipped. Phase 1 and Phase 2 tests remain green.
- `& 'D:\xampp\php\php.exe' 'vendor\bin\pint' --test` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 801 modules transformed and bill/Fleet/report bundles generated.
- `& 'D:\xampp\php\php.exe' artisan route:list --path=api/v1` — purchase-bill, sales-bill, stock allocation, draft update, revision, reversal, fleet CRUD, fleet approval/reversal, and fleet report routes registered.
- `& 'D:\xampp\php\php.exe' artisan route:list --path=fleet` — Fleet browser and Fleet-report Inertia routes registered.
- `Invoke-WebRequest http://ezaki.test/api/v1/health` — `{"status":"ok","version":"v1"}`; `Invoke-WebRequest http://ezaki.test/login` — HTTP 200. No Apache reconfiguration was performed.

Phase 3 acceptance evidence:

- Draft purchase/sale leaves stock and GL unchanged; approval posts stock, one journal, and audit exactly once.
- Purchase value uses factory weight while inventory uses actual weight; sales use actual weight and reject oversell.
- Duplicate bill numbers, header/line mismatches, credit-limit violations, unauthorized discounts, closed-period posting/revision, and cross-tenant access are rejected.
- Near-duplicate warnings preserve input; transport allocation is display-only; approved bill reversal restores stock and creates a reversing journal.
- Company vehicle trips are stored on approval; fleet expense and maintenance approval posts GL and reversal posts reversing journals.
- Approved revisions write before/after revision records and audit metadata.
- Authenticated Inertia create/edit forms, Fleet workflows, Fleet reports, print/export HTML, and print/export permission denial are tested.

Verified company sidebar/navbar links — authenticated tenant owner context:

- Dashboard: `/dashboard` → `Company/Dashboard`, permission `dashboard.view`, HTTP/Inertia 200.
- Users & roles: `/settings/access` → `Company/Access`, permission `users.view`, HTTP/Inertia 200.
- Settings/subscription: `/subscription` → `Company/Subscription`, permission `subscriptions.view`, HTTP/Inertia 200.
- Branches and warehouses: `/branches` → `Company/Branches`, permission `branches.view`, HTTP/Inertia 200. Warehouse management is intentionally represented on the implemented branches page; no dead warehouse-only link is shown.
- Products/inventory: `/inventory` → `Company/Inventory`, permission `inventory.view`, HTTP/Inertia 200.
- Customers/suppliers: `/parties` → `Company/Parties`, permission `parties.view`, HTTP/Inertia 200.
- Purchases: `/operations#purchases` → `Company/Operations`, permission `purchases.view`, HTTP/Inertia 200.
- Sales: `/operations#sales` → `Company/Operations`, permission `sales.view`, HTTP/Inertia 200.
- Accounting/reports: `/accounting` → `Company/Accounting` and `/reports/journal` → `Company/AccountingReport`, permissions `accounting.view` and `reports.view`, HTTP/Inertia 200.
- Fleet: `/fleet` → `Company/Fleet`, permission `fleet.view`, HTTP/Inertia 200. Fleet reports are linked from that page only.

Navigation verification:

- `PhaseThreeOperationsTest::test_company_navigation_targets_are_inertia_pages_and_server_protected` verifies every link target above with an authenticated tenant owner, then verifies the same target set returns HTTP 403 for a same-company restricted user with no permissions.
- The same test verifies the dashboard remains HTTP/Inertia 200 after `POST /locale/ar` and receives the Arabic locale, confirming the RTL/Arabic route path does not break.
- `npm.cmd run build` passed with Vite 6.4.3 and generated the sidebar, dashboard, operations, Fleet, Fleet-report, subscription, and bill-form assets. No missing Vite asset was observed in the build.
- Live operator-host checks: `GET http://ezaki.test/api/v1/health` returned HTTP 200 JSON `{"status":"ok","version":"v1"}` and `GET http://ezaki.test/login` returned HTTP 200 HTML. Authenticated page checks use Laravel feature HTTP requests with an authenticated company user because no browser session credentials were requested or used.
- No unimplemented module link is shown. Velzon and Phase 4 remain untouched.

Authorization fix — 2026-09-28:

- Root cause: company authorization depended on the current Spatie permission list. Existing owner roles could become stale when later module permissions were added, while Laravel `can:` middleware evaluates the Gate directly. The owner also had no self-profile update action; the existing user-management update intentionally blocked editing the owner.
- Owner access: `User::isCompanyOwner()` now requires an active company account with the `Company Owner` role. `AppServiceProvider` registers a `Gate::before` owner bypass, so every current and future company `can:` web/API action is allowed after the existing account-type, tenant, subscription, and license middleware. Controllers still enforce tenant ownership and record-level protections.
- Owner capability sharing: Inertia shares all current permission names for an active owner, so owner navigation does not depend on stale permissions. Existing owner roles are backfilled by additive migration `2026_09_28_000009_backfill_company_owner_permissions.php`; new provisioning already synchronizes the owner role with all current permissions.
- Owner profile: `PUT /api/v1/profile` and the company profile page now allow the authenticated owner to edit first/second name, email, and optionally password with current-password confirmation. Role changes, permission changes, and owner deactivation remain blocked for other company users.

Authorization validation evidence:

- `& 'D:\xampp\php\php.exe' artisan test` — 52 passed, 624 assertions, including all Phase 1/2/3 tests.
- `PhaseThreeOperationsTest::test_active_owner_bypasses_stale_permissions_and_can_edit_own_profile` removes all owner-role permissions, then verifies the active owner receives HTTP 200 on every implemented company page and representative API (`branches`, `products`, `parties`, `accounts`, reports, purchase/sales bills, and fleet), updates their own profile/password, while a no-permission non-owner receives HTTP 403.
- The same test verifies another company owner cannot cross tenant boundaries; existing tenant-isolation tests remain green.
- `& 'D:\xampp\php\php.exe' 'vendor\bin\pint' --test` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; owner profile bundle generated.
- Authenticated HTTP context: Laravel feature requests use an active company owner with Sanctum/session authentication. Unauthorized context uses an active same-company non-owner with no permissions and receives 403. Live checks: `GET http://ezaki.test/api/v1/health` returned HTTP 200 JSON and `GET http://ezaki.test/login` returned HTTP 200 HTML.
- Super Admin company-login separation remains covered by Phase 1 tests; the owner bypass applies only to active company users with their own tenant.

Operational limitation: MySQL migration completion remains operator-confirmed but was not independently verified. The agent did not read `.env`, connect to MySQL, or run any migration/destructive database command. Automated tests use isolated in-memory SQLite.

Phase 2/3 product-correction pass — 2026-09-28:

- Root cause fixed: `Cashbox` and `Bank` stored `account_id` but lacked `account()` relationships, while `/accounting` eager-loaded those relations. Both relationships now exist; cashbox/bank creation continues to auto-create/link child accounts. Regression verifies `/accounting` returns Inertia 200 after cashbox creation.
- Diameter policy corrected: provisioning and inventory page access no longer seed standard diameters. Fresh companies start with no diameters; users add values. Existing historical diameter/product rows are retained.
- Locale policy corrected: default Laravel locale/fallback and `.env.example` are Arabic (`ar`); session locale still permits explicit English switching. Operator `.env` was not read or modified.
- Purchase policy corrected: actual received weight is review-only, purchase value remains factory-weight based, and approved purchase stock/reversal uses factory weight. Purchase line actual-weight input was removed from the form. The `BillForm` crash was fixed by using `globalThis.document` because the Inertia `document` prop shadowed the browser `document` object.
- New bill lines accept tenant-owned type and diameter IDs and resolve them to compatibility stock records; legacy product-backed historical lines remain readable. SKU product creation controls are hidden from the inventory page.
- Blocking master-data gap fixed: Inventory now provides standalone Add Type and Add Diameter actions. Type accepts name only; diameter accepts value only. Neither form requires SKU, warehouse, unit, minimum stock, or opening balance. New records appear in the type/diameter selectors, inventory matrix data, and user-facing master-data summary.
- Inventory matrix now adds zero-balance rows for active types, so a newly created type is visible immediately even before stock exists. Diameter columns remain user-defined and start empty for fresh companies.
- Company navigation now exposes separate Customers and Suppliers pages backed by tenant-scoped filters. Super Admin navigation now links to implemented Companies, Plans, Coupons, and Registration sections on the existing platform dashboard; unimplemented licenses/audit/settings links remain hidden.

Correction-pass validation:

- `& 'D:\xampp\php\php.exe' artisan test` — 54 passed, 663 assertions. Phase 1, Phase 2, and Phase 3 tests remain green.
- `& 'D:\xampp\php\php.exe' 'vendor\bin\pint' --test` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 801 modules transformed.
- `GET http://ezaki.test/api/v1/health` — HTTP 200, `{"status":"ok","version":"v1"}`; `GET http://ezaki.test/login` — HTTP 200. No Apache or database configuration was changed.
- Focused tests cover cashbox/accounting 200, empty fresh diameters, standalone type/diameter creation without SKU, immediate matrix appearance, user-defined type/diameter purchase data, optional/differing purchase actual weight, factory-weight stock posting, draft/approve, sales stock checks, owner access, tenant isolation, split customer/supplier routes, and the purchase form crash regression.

Remaining product-correction items, so Phase 3 stays `PARTIALLY_COMPLETED`:

- Stock and bill persistence still retain the legacy `products.id` compatibility layer; a full additive type+diameter stock-balance/line schema migration and historical mapping were not completed. New type+diameter lines resolve through internal compatibility records rather than a pure SKU-free schema.
- Sales type+diameter end-to-end approval has not received a dedicated new acceptance test.
- Optional opening balance/minimum-stock action UI by warehouse + type + diameter is not complete; standalone master-data creation is complete.
- Customers and suppliers have separate list routes/pages, but row/profile receipt and supplier-payment actions are not yet split into those pages.
- Whole-app localized placeholders/tooltips and searchable selects for all long option sets remain incomplete.

Continuation correction pass — 2026-09-28:

- Purchase TypeError root cause fixed: request IDs are string values even after Laravel integer validation. Purchase/sales service boundaries now cast supplier/customer, branch, warehouse, and vehicle IDs before tenant-owned lookups. Regression submits browser-style string IDs for purchase and sales draft/approval successfully.
- Parent-account changes fixed: party edits now move the existing child account under the selected tenant-owned parent while preserving account ID and historical journal links. Chart account edits accept a parent ID, preserve account IDs, recode the moved account and descendants, reject root moves, self-parenting, descendant cycles, and cross-tenant targets.
- Accounting UI now exposes a parent selector/datalist instead of a name-only prompt for account edits. Party edit already submits the visible `parent_account_id` and now persists through the shared move service.

Continuation validation:

- Full regression: 56 tests, 674 assertions, including Phase 1/2/3 coverage plus string-ID bill paths and parent-account recoding/cycle/tenant tests.
- `& 'D:\xampp\php\php.exe' 'vendor\bin\pint' --test` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 801 modules transformed.
- `GET http://ezaki.test/api/v1/health` — HTTP 200 JSON `{"status":"ok","version":"v1"}`; `GET http://ezaki.test/login` — HTTP 200. No `.env` read, database command, Apache change, Phase 4 work, or Velzon integration.

Still incomplete at the Phase 2/3 boundary:

- Optional opening balance/minimum-stock action UI by warehouse + type + diameter is not yet implemented.
- Customer row/profile receipt and supplier row/profile payment actions are not yet split into the customer/supplier pages, though Accounting already posts receipts/vouchers to cashbox/bank.
- Sales type+diameter has compatibility support but still needs a dedicated new end-to-end type/diameter draft/approve test.
- Pure type+diameter persistence without the legacy Product compatibility record, whole-app searchable selects/tooltips, and complete locale coverage remain outstanding.
- The progress record intentionally does not mark this correction pass complete. Phase 4 and Velzon remain untouched.

Blocking bill continuation — 2026-09-29:

- Draft approval gap fixed: saved draft edit forms now show an explicit Approve button when the user has the matching `purchases.approve` or `sales.approve` capability, including owners through the owner Gate bypass. New forms retain Save as draft and Save & Approve. Approval continues through the existing transactional stock + journal + audit service and cannot repeat after posting.
- Arabic print gap fixed: purchase and sales print templates now follow `app()->getLocale()`, default to Arabic RTL, use Arabic labels/totals, and switch to English labels when the active locale is English. Sales print includes optional packages.
- Optional sales packages fixed with additive migration `2026_09_29_000010_add_packages_to_sales_bill_lines.php`. Packages are nullable, persisted/displayed when supplied, never used in stock calculations, and omitted safely when empty.
- The existing ownedId string-ID fix remains active; new approval/package tests submit browser-style string IDs and pass.

Blocking-bill validation:

- Phase 3 focused suite: 18 passed, 315 assertions, including saved draft approval, unauthorized-path coverage, optional sales packages with and without values, Arabic RTL print, factory-weight purchase posting, and string-ID purchase/sales approval.
- Phase 2 focused suite: 14 passed, 176 assertions.
- Full suite passed before the final test-only print assertion correction; the final Phase 3 and Phase 2 focused suites are green afterward.
- `& 'D:\xampp\php\php.exe' 'vendor\bin\pint' --test` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 801 modules transformed.
- `GET http://ezaki.test/api/v1/health` — HTTP 200 JSON `{"status":"ok","version":"v1"}`; `GET http://ezaki.test/login` — HTTP 200. No `.env` read or modified, no destructive database command, Phase 4, or Velzon work.

Phase 3 remains `PARTIALLY_COMPLETED`; optional stock/minimum UI, pure type+diameter persistence, party row/profile payment actions, and whole-app searchable/localized controls remain outside this focused blocking-bill continuation.

Operator-visible bill/accounting continuation — 2026-09-29:

- Edit root cause: the bill list exposed Edit only for drafts, so approved bills with `update_approved` had no first-view action; the edit route also only guarded `update`. Purchase/sales rows now show Arabic `تعديل` for drafts with `update` and approved bills with `update_approved`, while edit page routes enforce that status-specific permission. Draft/approved route tests pass; browser confirmation with an operator-authenticated session is still pending.
- Approve root cause: `/operations` exposed approval indirectly through navigation to edit. Draft rows still expose Arabic `اعتماد` only with the matching permission, and edit pages expose the explicit approve action; posted bills use reverse/cancel.
- Receipt/voucher audit: existing `AccountingService::receive()` and `paySupplier()` already create balanced posted journals in the same transaction, link `journal_entry_id`, and populate `journal_lines.party_id`. `AccountingReportService` includes posted/reversed source types in journal/ledger/statement queries, so customer receipt and supplier payment entries are not filtered out. No accounting posting rewrite was necessary; the operator-facing accounting forms already use POST actions and show posted records.
- Parent account statements now include descendant account movements, with account code/name columns for child movements and a parent running total.
- Accounting report links were added near the top of the Accounting page, beside the chart, instead of being discoverable only at the footer.

Validation evidence:

- Full suite after this continuation: 61 tests passed, 781 assertions. Phase 1/2/3 tests remain green.
- Phase 3 focused suite: 18 passed, 315 assertions, including explicit saved-draft approval, Arabic RTL print, optional sales packages, string-ID bills, parent moves, and tenant isolation.
- `& 'D:\xampp\php\php.exe' 'vendor\bin\pint' --test` — passed.
- `npm.cmd run build` — passed with Vite 6.4.3; 801 modules transformed.
- Live checks remain `GET http://ezaki.test/api/v1/health` HTTP 200 JSON and `GET http://ezaki.test/login` HTTP 200 HTML. No `.env` read/modified and no destructive database command run.
- Edit-action HTTP feature evidence: 2 tests passed, 82 assertions; authenticated Inertia operations payload and draft/approved edit pages return 200 for owner, while a restricted user gets 403. This is test HTTP evidence; a live operator browser session was not available for independent visual confirmation.
- Voucher reporting focused evidence: 2 tests passed, 39 assertions across posted customer/supplier and Other-account vouchers in journal, ledger, cashbox, party, and selected account statements.

Still incomplete and intentionally not claimed complete:

- Receipt/payment UI and API support Customer/Supplier/Other account selection and journal/report posting. Operator-authenticated browser confirmation remains pending.
- Customer/supplier row/profile receipt/payment actions are not yet split into those pages.
- Optional stock/minimum action UI, pure type+diameter persistence, whole-app searchable selects/tooltips, and other previously documented backlog remain. Phase 3 stays `PARTIALLY_COMPLETED`.

### Live MySQL voucher diagnostic — 2026-09-29

- Ran the idempotent `accounting:backfill-voucher-journals --company=1` command against the configured database. It repaired 0 links: Company 1 has 3 posted receipts and 2 posted payments, all linked, with 3 and 2 corresponding journal source entries.
- An aggregate query matching the journal report's company/status/line/account joins returned 10 voucher journal lines. The report service has no receipt/payment source-type exclusion, and the web controller passes the authenticated company and inclusive date range to it.
- Frontend root cause found: `AccountingReport` defaulted `rows` to `[]` and selected it before paginated `entries.data` or `lines.data`, causing journal, ledger, and account-statement views to render empty while report rows existed. Fixed the precedence and added a web regression assertion that the journal paginator contains its four expected lines.
- At the diagnostic time, the available browser had no authenticated company session and `/reports/journal` redirected to `/login`; this limitation was later superseded by the operator confirming journal rows were visible after the frontend fix.
- Focused regression: `artisan test --filter=other_account_receipt_and_payment_appear_in_journal_ledger_and_account_statements` — 1 passed, 27 assertions. `npm.cmd run build` passed with Vite 6.4.3; 801 modules transformed.
- Operator subsequently confirmed journal rows are visible after the React empty-array fix; B1 is now complete in the backlog. B15 remains in progress pending visual confirmation of all date-filtered report views.
- Customer and supplier tables now provide row-level links into Accounting with the matching party preselected for collection/payment; profile-specific actions remain pending.
- No `.env` access, migrations, reset/destructive operations, Phase 4, or Velzon work occurred.

### Remaining-backlog continuation — 2026-09-29

- B16 focused coverage now proves Other-account receipt/payment behavior is natural debit/credit neutral, rejects the selected cash account, and rejects non-postable header accounts: 1 test, 29 assertions.
- B15 debtor/customer range filtering now accepts inclusive `from_date`/`to_date` through web and API report paths. Opening balances remain carried into ranges; dated receivable movements are filtered. The focused debtor test passes with 12 assertions.
- Separate `/customers` and `/suppliers` routes now pass a `kind` prop to the shared page, and customer/supplier rows link to Accounting with the matching party preselected. The page-contract test passes with 100 assertions.
- Existing Phase 2/3 implementation coverage was reconciled into the backlog for A4-A12, B2-B13, C1-C3/C5, D1, E1-E7, F1, F3, and F4. These remain `IN_PROGRESS` pending operator-visible browser evidence rather than being falsely marked complete.
- C4 is implemented as a separate warehouse/type/diameter action with independently optional opening quantity and minimum stock; it exposes no SKU, item code, or Product form. E8 is implemented by active-locale control titles in the Inertia bootstrap. E9 is implemented by inline filtering fields on selects with more than seven options.
- C4 focused regression passes with 8 assertions. The frontend build passes after the E8/E9 bootstrap enhancement. These operator-visible items remain `IN_PROGRESS` pending browser confirmation and are not marked `DONE` from tests/build alone.
- F2 remains `DEFERRED`; F5 remains honored. Phase 3 remains `PARTIALLY_COMPLETED`; Phase 4 and Velzon remain untouched.
- Customer/supplier profile routes were added with tenant checks and direct statement plus collection/payment actions. The focused page tests pass.
- The root `.htaccess` was inspected and left unchanged after user edits.
- Final validation for this continuation: Phase Two suite passed 17 tests and 266 assertions; the complete suite showed no failures; Pint passed; Vite build passed with 802 modules transformed.
- C4's separate inventory action, E8 active-locale control titles, E9 large-select search filtering, and tenant-scoped customer/supplier profile routes are implemented. Their statuses remain `IN_PROGRESS` where operator-visible confirmation is required.
- E7's remaining static English bill/fleet labels and control hints now receive Arabic translations in the shared bootstrap; user-entered values are not changed.

---

## Phase 4 — Security, UI, QA & Release

Status: `NOT_STARTED`  
Do not start until Phase 3 is `COMPLETED` and accepted.

---

## Resume instruction

If a later session begins, the agent must:

1. Read `e-zaki-erp-srs.md`.
2. Read `e-zaki-laravel-master-prompt.md`.
3. Read this file.
4. Inspect the actual repository.
5. Continue the first phase that is not `COMPLETED`.
6. Stop after that phase.


### GitHub integration and local release verification — 2026-09-29

The public repository `https://github.com/lang-prog/ezaki-erp` was cloned and treated as the Laravel source of truth; the separate React prototype was not copied over the existing Laravel/Inertia application. The clone contains Laravel 12, Inertia React, Sanctum, tenant middleware, Phase 1/2/3 services, APIs, pages, and migrations.

For reproducible tests in a clean clone, `phpunit.xml` now supplies an isolated non-production test `APP_KEY`, and `tests/TestCase.php` disables only CSRF middleware for PHPUnit requests. No `.env` was opened, created, or modified. These changes are test-environment-only.

Verification in the clone:

- `vendor/bin/phpunit` — **64 tests, 864 assertions, 0 failures**.
- `vendor/bin/pint --test` — **120 files passed**.
- `composer validate --no-check-publish` — valid.
- `npm run build` — Vite 6.4.3 build passed; 802 modules transformed.
- `npm audit --omit=optional` — 0 vulnerabilities.
- Temporary Laravel HTTP server with isolated env: `/` HTTP 200, `/login` HTTP 200, `/api/v1/health` returns `{"status":"ok","version":"v1"}`.
- API route listing returned 96 rows; Super Admin route listing returned 17 rows.

The repository was not force-pushed or overwritten. A local integration commit/patch is prepared separately for operator review and push from the authenticated Windows GitHub environment.


### React/Vite visual integration into Laravel/Inertia — 2026-09-29

The standalone React/Vite prototype was integrated at the presentation layer without copying its demo state or replacing Laravel business logic. The Laravel/Inertia CompanyLayout and SuperAdminLayout now use the E‑Zaki teal/dark-shell design language, workspace navigation, responsive sidebar, branded topbar, cards, tables, RTL typography, and the prototype's Arabic/English visual direction. Company Dashboard and Auth Login were rebuilt with the first prototype's dashboard/login composition while consuming real Laravel metrics, capabilities, routes, subscription status, operations, reports, and authentication. The prototype CSS was incorporated into `resources/css/app.css` with responsive and form/table overrides.

No business rules were moved into React, no localStorage/demo records were introduced, and no existing migrations, controllers, API routes, or tenant services were replaced. The standalone `/home/ubuntu/e-zaki-erp` remains a reference prototype; the Laravel repository is the integrated source of truth.

Validation after integration: Vite build passed with 802 modules and npm audit reported 0 vulnerabilities; the Laravel PHPUnit suite passed with 64 tests and 864 assertions, Pint passed 120 files, and Composer validation passed. Operator must push the local integration branch from the authenticated Windows checkout after review.


### Phase 4 security/API/release continuation — 2026-09-30

The operator explicitly requested continuation through the remaining program. A code-audited Phase 4 slice was implemented without changing tenant business rules: a global `SecurityHeaders` middleware now adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, and HSTS only when the request is HTTPS. The versioned API now exposes public `/api/v1/version`, and authenticated company consumers have `/api/v1/me` and permission-protected `/api/v1/dashboard` using the same tenant/subscription/license middleware and dashboard aggregates as the Inertia dashboard.

Validation evidence from the Sandbox clone: `vendor/bin/phpunit` passed **66 tests and 884 assertions**; `vendor/bin/pint --test` passed **122 files**; `composer validate --no-check-publish` passed; `npm run build` passed with Vite 6.4.3 and 802 modules; `npm audit --omit=optional` reported 0 vulnerabilities. Temporary HTTP validation returned `/api/v1/health` HTTP 200 with all security headers, `/api/v1/version` HTTP 200 with application/Laravel version data, and `/login` HTTP 200 with security headers.

Phase 3 remains `PARTIALLY_COMPLETED` for the backlog statuses that explicitly require operator-visible MySQL/browser evidence. Phase 4 is now `IN_PROGRESS`, not `COMPLETED`: MySQL/XAMPP execution, authenticated browser walkthrough, production mail, and any real deployment acceptance still require operator-side validation. The pure type+diameter schema migration remains intentionally deferred because it changes persisted inventory/bill identity and requires a controlled historical mapping decision; the current compatibility layer is retained.


### editandnew implementation-completion pass — 2026-09-30

A full code-level audit of all `IN_PROGRESS` groups in `editandnew.md` was completed. This pass closes the implementation gaps that remained after the earlier reconciliation while preserving the backlog rule that operator-visible MySQL/browser confirmation is required before changing an item to `DONE`.

Implemented in this pass:

- **Bills A4/A5/A11:** purchase lines now remove/ignore legacy line-level `actual_weight`; the persisted column is nullable through an additive migration; purchase stock/value remains factory-weight based; purchase header totals and actual review weight are grouped at the top; Arabic print maps statuses and no longer renders line-level actual weight.
- **Accounting B7/B16:** report controls and table actions use localized labels; print output hides navigation/forms and applies A4 table rules; inactive posting accounts are excluded from the Other-account selectors.
- **Inventory C5:** type search, warehouse filtering, printable matrix, and permission-protected CSV export were added. Matrix totals recalculate for the visible search result.
- **Customers/suppliers D2/D3:** list names now open tenant-scoped profiles; customer and supplier routes enforce the requested kind; statement/collection/payment actions are permission-gated.
- **Access E3/E7:** direct permission updates for a Company Owner are rejected server-side; user/access and bill workflows use active-locale labels for their remaining operator-facing controls.
- **Fleet F3:** all relationship IDs (branch, vehicle, driver, trip) are resolved inside the active company; cross-company IDs return 404. Vehicle/driver/trip/expense/maintenance forms now expose the persisted operational fields. Maintenance notes were added through an additive migration. A single `FleetReportService` now powers vehicle P&L, trip cost, fuel cost, driver performance, expenses by category, maintenance by period, inactive vehicles, and branch performance with date/branch filters, printable views, and permission-protected CSV exports.
- **Dashboard F4:** debtor KPI is now an amount instead of a row count (with a separate debtor count in the payload); receipts/payments include posted records only; daily sales scale to the actual monthly maximum and render every returned month day; top sold products and sales by customer are now visible; API dashboard parity was completed.
- **Regression coverage:** added focused tests for ignored/non-persisted purchase line actual weight, tenant-scoped fleet foreign keys, immutable owner direct permissions, route kind enforcement for party profiles, and 403 behavior on inventory/fleet export routes without export permissions.

Verification:

- `vendor/bin/phpunit` — **71 tests, 919 assertions, 0 failures**.
- `vendor/bin/pint --test` — **125 files passed**.
- `composer validate --no-check-publish` — valid.
- `npm run build` — Vite 6.4.3 build passed; **802 modules transformed**.
- `npm audit --omit=optional` — **0 vulnerabilities**.
- `git diff --check` — clean.
- Fleet and inventory route listings include the new view/export endpoints.

External acceptance boundary:

- `F2` remains intentionally `DEFERRED`: the pure type+diameter schema cutover requires historical-data mapping and is not necessary for the current compatibility model.
- Per the binding backlog rule, items that require operator-visible MySQL/XAMPP/browser evidence remain `IN_PROGRESS` in `editandnew.md` until the operator runs the additive migrations and completes the supplied acceptance checklist. No `.env` was read and no destructive migration/reset command was run.
