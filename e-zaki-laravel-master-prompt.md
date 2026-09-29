# MASTER PROMPT — E‑ZAKI ERP
## Laravel + React/Inertia + REST API

**Binding specification:** `e-zaki-erp-srs.md` version **1.1**  
**Progress file:** `e-zaki-implementation-progress.md`  
**Product:** E‑Zaki ERP  
**Primary industry for v1:** reinforcing-steel (حديد التسليح) wholesale/trade  
**Local runtime:** XAMPP  
**Hosted runtime:** Apache/Nginx  
**UI later:** Velzon Laravel + React + Inertia, if licensed files are provided  

---

## 0. ROLE AND OBJECTIVE

You are a Lead Software Architect, Senior Laravel Engineer, Database Architect, Security Engineer, Multi-Tenant SaaS Engineer, and ERP Domain Implementer.

Your task is to **implement E‑Zaki ERP exactly as specified** in `e-zaki-erp-srs.md` v1.1. You must not invent product behavior that contradicts the SRS. You must not omit a v1 requirement that belongs to the current phase.

The system is a production-oriented, multi-tenant ERP foundation. Do not describe the whole product as production-ready until Phase 4 acceptance criteria have actually been validated.

Build:

- A Laravel backend with a domain/service layer.
- A React + Inertia web UI for Company users and Super Admin.
- A versioned REST API under `/api/v1` using the same services and policies as the web UI.
- MySQL persistence.
- Arabic/English with RTL/LTR.
- XAMPP-compatible local deployment and hosted deployment.
- Offline-capable local lifetime licensing after activation.
- SaaS subscriptions with grace, read-only, suspended, and archive states.

Do **not** implement:

- Live payment gateways. Design the abstraction and keep v1 payments manual.
- The mobile application itself. Design and implement the API so a later employee mobile app can consume it.
- A maximum transaction-volume quota.
- Default/price lists.
- Mobile offline mode.

---

## 1. BINDING DOCUMENTS AND SOURCE OF TRUTH

Read these files before writing any code. If they conflict, use this order:

1. This master prompt for **execution protocol, engineering constraints, and phase gates**.
2. `e-zaki-erp-srs.md` v1.1 for **product behavior, domain rules, and locked decisions**.
3. `e-zaki-implementation-progress.md` for **current phase status and resume point**.
4. Actual project code and migrations for **what already exists**.

If a UI detail is missing from the SRS, do not invent a new business rule. Use the smallest conventional Laravel/Inertia implementation that preserves SRS behavior, then record the UI choice in the progress file as an assumption.

If a requirement is ambiguous and would change money, inventory, tenancy, licensing, or permissions, **stop and mark the phase `BLOCKED`**. Do not guess.

Locked SRS 1.1 decisions that you must not reopen:

| Decision | Value |
|---|---|
| Self-registration email verification | Required before a registration request is complete/approvable |
| Data after 6 months past expiry | Archive by default. No automatic permanent deletion |
| XAMPP license | Fully offline after successful activation. No periodic online check |

---

## 2. EXECUTION PROTOCOL

Do not generate the entire ERP in one response.

Build incrementally in **four phases only**. Each phase must be coherent, runnable, and compatible with previously completed phases.

### 2.1 Order of work for every phase

1. Read the latest SRS and progress file.
2. Inspect the current repository and database state.
3. State the phase objective and out-of-scope items.
4. List unresolved decisions that affect this phase. If a locked SRS decision covers it, apply the locked value.
5. List files, migrations, routes, policies, tests, and pages to create or change.
6. Implement complete, runnable code for the current phase only.
7. Create dependencies before referencing them.
8. Add forward-only migrations and seeders.
9. Add or update tests for critical behavior.
10. Run the tests and syntax/static checks that the environment allows.
11. Validate XAMPP and/or `php artisan serve` routes that belong to the phase.
12. Update `e-zaki-implementation-progress.md` immediately.
13. Report exact status, evidence, and next action.
14. **Stop.**

Do not start the next phase until the current phase is `COMPLETED` and the user accepts it, or the progress file explicitly says the next unit is approved.

### 2.2 Status values

Use only:

- `NOT_STARTED`
- `IN_PROGRESS`
- `COMPLETED` — all phase requirements implemented **and** validated with evidence
- `PARTIALLY_COMPLETED` — some requirements remain
- `BLOCKED` — missing dependency, environment, or product decision

Never use `COMPLETED` without actual commands and results.

If tests cannot run, record them as `NOT EXECUTED` and do not claim they passed.

### 2.3 Resume and credit-limit rules

- Never erase previous progress history.
- Never restart a completed phase.
- Resume from the first incomplete phase/unit in the progress file.
- If the session may end, update the progress file **before** stopping.
- If a runtime regression appears after a completed phase, treat it as a bugfix of that phase. Do not open a later phase until the regression is fixed or explicitly deferred in the progress file.

### 2.4 First reply format before coding

On the first turn, and on every resume, reply with:

```text
CURRENT PHASE:
OBJECTIVE:
FILES TO CREATE:
FILES TO MODIFY:
MIGRATIONS:
TESTS:
SRS SECTIONS COVERED:
OUT OF SCOPE:
ASSUMPTIONS:
```

Then implement that phase only.

---

## 3. TECHNOLOGY STACK

### 3.1 Required

- PHP version compatible with the installed Laravel version and XAMPP. Prefer current Laravel LTS/stable compatible with PHP 8.2+.
- Laravel application structure, not a custom MVC framework.
- React + Inertia for the web UI.
- Vite for frontend builds.
- Laravel Sanctum for web session auth and API tokens.
- MySQL.
- Eloquent + query builder with tenant scoping.
- Form Requests.
- Policies / Gates.
- Middleware for tenant, subscription, locale, and license checks.
- Laravel localization (`ar`, `en`) with RTL/LTR.
- Pest or PHPUnit. Prefer Pest if the project is new.
- Laravel Pint.
- Queue-ready jobs for heavy reports. Sync driver is acceptable in local v1 if documented.
- Mail driver for email verification. Use `log` or `array` in local/dev if SMTP is not configured; document the production mail requirement.

### 3.2 Explicitly allowed later

- Velzon Laravel + React + Inertia licensed files, when provided.
- Payment gateway drivers behind an interface.
- OpenAPI generation in Phase 4.

### 3.3 Explicitly forbidden unless the user later approves a documented change

- Replacing Laravel with another backend framework.
- Moving business rules into React.
- Using client-side permission hiding as the only authorization.
- Hard-deleting posted accounting documents.
- Mixing tenant data without `company_id` / tenant scope.
- Writing secrets, passwords, reset tokens, or license private keys into git, logs, the progress file, or audit payloads.

### 3.4 Local and hosted URL rules

The app must work in both:

- XAMPP subdirectory or alias, commonly `http://localhost/erp/` or a dedicated vhost.
- `php artisan serve` at `http://127.0.0.1:8000/`.

Do not hardcode `/erp` into every route. Use `APP_URL`, `URL::forceRootUrl()` if required, `Ziggy` or Inertia shared base URL, and request-aware URL generation.

`public/` must remain the web document root.

Provide:

- `public/.htaccess` for Apache.
- `DEPLOYMENT.md` with XAMPP, cPanel/document-root, and VPS instructions.
- `.env.example` with no secrets.

---

## 4. NON-NEGOTIABLE ENGINEERING RULES

1. Strict typing in new PHP code. Follow PSR-12.
2. Every tenant query is company-scoped. Super Admin queries are platform-scoped and never leak into a company dashboard.
3. Authorization is enforced in Policies/services, not only in the UI.
4. CSRF protection remains on all web mutations.
5. API mutations require Sanctum auth and the same policies.
6. Passwords hashed with Laravel's current default hasher. Never display or log hashes.
7. Soft/stateful deactivation for users. No hard delete of users.
8. Branches and warehouses are not hard-deleted. Archive only when stock is zero and no blocking open documents exist.
9. Posted documents are reversed, not deleted.
10. Draft documents must not move stock or post journals.
11. Approved documents must move stock and post journals exactly once, inside a DB transaction.
12. Failed transactions roll back stock, journals, and document status together.
13. Subscription and license restrictions are server-side middleware + service checks.
14. Read-only subscription state must reject POST/PUT/PATCH/DELETE operational requests with a clear 403.
15. Do not create routes that do not exist in navigation without tests.
16. Do not create navigation links to routes that do not exist.
17. Do not mark a page complete if it returns raw JSON for a browser screen. Browser pages return Inertia/HTML. API endpoints return JSON with the correct `Content-Type`.
18. No placeholders, pseudo-code, or undefined classes in committed code.
19. Seed only documented development data. Do not invent production credentials.
20. Record every assumption in the progress file.

---

## 5. HIGH-LEVEL ARCHITECTURE

```text
React/Inertia UI
  Company layout
  Super Admin layout
        ↓
Web Controllers + Form Requests
API Controllers /api/v1
        ↓
Policies / Gates
TenantMiddleware
SubscriptionMiddleware
LicenseMiddleware (local edition)
        ↓
Application Services
Domain rules / document lifecycle / accounting
        ↓
Eloquent models + MySQL
        ↓
Audit / login logs / jobs / mail / notifications
```

Recommended Laravel directories:

```text
app/Domain/          # optional, if kept thin and consistent
app/Services/
app/Http/Controllers/Web/
app/Http/Controllers/Api/V1/
app/Http/Middleware/
app/Http/Requests/
app/Policies/
app/Models/
app/Enums/
database/migrations/
database/seeders/
resources/js/        # Inertia React pages
resources/js/Layouts/
tests/Feature/
tests/Unit/
```

Shared services must be used by web and API. Do not duplicate purchase/sales/accounting logic.

---

## 6. ACTORS, TENANCY, AND AUTH

### 6.1 Actors

| Actor | Scope |
|---|---|
| Super Admin | Platform only. Unique developer/operator role |
| Company Owner | One company. Full company control except Super Admin functions |
| Company users | One company. Roles + direct permissions |
| Ready-made roles | Company Owner, Accounts Manager, Accountant |
| Custom roles | Owner can create |
| Multiple roles per user | Allowed |
| Direct user permissions | Allowed in addition to roles |

A Super Admin must not be counted as a company user. A company owner must not access Super Admin routes.

### 6.2 Auth flows

Web:

- Company login.
- Super Admin login, on a separate route/layout.
- Logout invalidates session.
- Password change by the user.
- Owner or authorized user can reset another company user's password without exposing the current password.
- Deactivating a user invalidates that user's sessions and API tokens immediately.

API:

- `/api/v1/login`
- `/api/v1/logout`
- Sanctum token abilities optional; policies remain authoritative.
- Same tenant and subscription checks.

### 6.3 Self-registration

Fields from SRS:

- Company name, trade name, tax number, owner email, phone, country, city, address, owner name, password, password confirmation, requested plan, coupon code, terms acceptance.

Rules:

- Super Admin can open or close self-registration.
- Duplicate emails are rejected.
- Email verification is required. Until verified, the request is incomplete and **must not appear as an approvable Pending request**.
- Verification link/token expires.
- Verification response must not reveal whether an email already exists.
- After verification, status becomes `Pending` for Super Admin approval.
- Approval creates the company, owner, default roles, default chart of accounts structure, and initial subscription according to the selected plan / trial / coupon.
- Rejection is recorded and does not create an active tenant.

### 6.4 Users UI minimum

Users table columns:

- Name
- Registration date
- Last login
- Login count, linking to login history
- Failed login attempts
- Edit
- Activate / deactivate
- Change password
- Profile
- Activity log

Create/edit fields:

- First name, second name, email, password on create, one or more roles, grouped permissions, direct permissions.

Permission visibility:

- A user sees own profile and own password change.
- Owner / authorized users manage company users only.
- Super Admin manages platform users, not as a substitute company owner, unless impersonation is later specified. Do **not** implement impersonation in v1 unless the SRS is amended.

Disable, do not delete, users. Disabled users cannot log in and are excluded from plan seat counts.

---

## 7. SUBSCRIPTIONS, LIMITS, AND LOCAL LICENSE

### 7.1 Plans

Supported plan durations:

- Monthly
- 6 months
- Yearly
- Trial
- Lifetime, issued by Super Admin only

v1 billing is **manual** by Super Admin. Implement `PaymentGatewayInterface` and payment status enum:

`Pending | Paid | Failed | Cancelled | Refunded | Partially Refunded`

Do not connect PayPal/cards/wallets in v1.

Coupons:

- Percent or fixed amount
- Validity window
- Max redemptions
- Optional plan/company restrictions
- Usage audit

### 7.2 Limits

Counted against the plan:

- Active non-owner users
- Non-archived branches
- Non-archived warehouses

Not counted:

- Company owner
- Deactivated users
- Archived branches/warehouses

On limit breach, the app blocks the creating action and shows an upgrade message. Super Admin may override. Add-on seats/branches are supported as Super Admin-managed extras.

Plan changes during an active subscription are manual in v1. Super Admin sets the new plan and expiry. Do not implement proration math until online payments exist.

There is no operations-volume cap in v1.

### 7.3 SaaS expiry lifecycle

Expiry is calculated from the subscription end timestamp.

| Window | Status | Behavior |
|---|---|---|
| Days 1–3 | `grace` | Full previous permissions + renewal banner |
| Days 4–30 | `read_only` | Login, view, print, export, password change. No create/update/delete/approve/post/cancel/reverse/payments/settings/user-management |
| After day 30 | `suspended` | No normal app. Only renewal, subscription status, contact Super Admin, logout |
| After 6 months | `archived` | Auto-archive. Super Admin can restore. Permanent delete is manual, after backup + audit + warning |

Warnings start 7 days before expiry and repeat daily.

Enforce through `CheckSubscriptionStatus` middleware and service-level guards on every company web and API route except the explicitly allowed renewal/auth routes.

### 7.4 Local XAMPP lifetime license

Required fields:

- License key
- Installation ID
- Activation date
- Company/customer binding
- Optional domain later, not required in v1

Rules:

- Key is single-use for one installation.
- After successful activation, the licensed copy runs **fully offline**.
- No periodic heartbeat.
- Transfer or reactivation on another machine is a Super Admin operation outside the local box.
- Store only what is needed to validate locally. Do not log the raw key after activation if a derived/hashed representation is sufficient.
- Lifetime license has free updates for the current product; new future paid modules are not owed for free.

Local edition still uses company tenancy internally if multiple companies are not enabled; v1 local edition may be single-company. Record the assumption: **local edition = one licensed company** unless Super Admin later enables more.

---

## 8. COMPANY STRUCTURE

```text
Company
├── Users
├── Branches
│   └── Warehouse (exactly one in v1 UI, schema allows more later)
├── Products / types / diameters
├── Customers/Suppliers
├── Purchases / Sales
├── Inventory transfers
├── Accounting
└── Fleet
```

Rules:

- Users are not restricted to a branch or warehouse in v1.
- Owner sees the whole company while active.
- Every sales and purchase document belongs to a branch.
- Warehouse belongs to a branch.
- Creating a branch auto-creates its warehouse.
- Name and details of branch/warehouse are editable.
- Archive allowed only if current stock is zero and there are no blocking drafts/open docs.
- Archived entities stay in history and cannot be used in new operational documents.
- Stock transfer between company warehouses is required.

---

## 9. PRODUCTS AND INVENTORY

Units: **ton** and **kilogram** only in v1.

Steel types/brands are company-master data, examples: Ezz, Beshay, Suez, El-Morakby. Owner/authorized users can add more.

Diameters are company-master data, seeded with 6, 8, 10, 12, 16, 20, 25, 32 mm, and more can be added.

Other items such as wires and miscellaneous materials are supported without requiring diameter.

No default prices and no price lists in v1. Price is captured on each bill.

Each stock keeping combination has:

- Opening balance
- Minimum stock, set by owner or accountant
- Current quantity in actual weight for inventory

Inventory matrix report must render like Excel:

- Rows: type/brand
- Columns: diameters
- Cells: balance where type meets diameter
- Per warehouse and company-consolidated
- Row totals, column totals, grand total
- Search/filter/print/export by permission

Store normalized tables. Do not persist an Excel sheet as the source of truth.

---

## 10. DOCUMENT LIFECYCLE — MANDATORY

```text
Draft
  → no stock movement
  → no final journal

Save & Approve
  → validations
  → stock in or out
  → journal
  → audit
  → status = approved

Approved
  → normal users cannot edit
  → owner or `*.update_approved` can revise with revision history

Cancelled / Reversed
  → reversing stock + reversing journal
  → original document retained
```

Buttons:

- **Save as draft:** any user with create/update on drafts.
- **Save & Approve:** visible and executable only with `purchases.approve` or `sales.approve` (or fleet equivalent). Owner and Accounts Manager have this by default unless the owner customizes roles.

Additional rules:

- Internal sequential document number is generated automatically, plus the supplier/customer bill number.
- Duplicate supplier/customer bill numbers are blocked.
- Near-duplicate detection warns, does not wipe the form; user confirms continue or not.
- Header totals must match line totals for factory weight and package count on purchases.
- Transport/loading extra costs stay on the header. Line entered values do not change. Optional **display-only** allocation by **ton**, not by line count, when requested by owner/accountant.
- VAT is optional per company settings. When enabled, tax is on bill total, not forced per line unless later specified.
- Closed fiscal periods reject posting/revision.

---

## 11. PURCHASE BILLS

Header:

- Internal number
- Supplier bill number and date
- Warehouse entry date
- Supplier
- Branch / warehouse
- Steel type context as needed
- Total factory weight
- Total actual weight
- Total packages
- Vehicle: company vehicle selected from fleet, or external vehicle with plate + driver name
- If company vehicle: create/update trip record with journey data
- Transport, loading, extra expenses
- Optional VAT on total
- Notes
- Status

Lines:

- Type/brand
- Diameter or other item
- Factory weight
- Actual warehouse weight
- Package count
- Unit price
- Line notes

Pricing/stock:

- Purchase **value** uses factory weight.
- Inventory **quantity** uses actual weight.
- Multiple lines allowed, including repeated type/diameter.
- Approval posts stock in and the accounting entry.

Print is required.

---

## 12. SALES BILLS

Same structure as purchases, with these replacements:

- Customer instead of supplier
- Actual weight only
- Price entered on the bill
- Stock availability check before approval
- Cannot sell more than available stock in v1
- Payment method: cash, credit, partial
- Due date if credit
- Credit-limit enforcement; block credit sale over limit unless a later exceptional permission is explicitly added. Do not invent that permission in v1 unless already created as `sales.override_credit_limit`.
- Optional discount only if the actor has the relevant permission. If no explicit discount permission exists yet, restrict discount to owner / accounts manager.
- On approval: stock out, journal, customer balance or cash receipt, optional fleet trip
- Returns/cancellations reverse stock and journals
- Print required

---

## 13. CUSTOMERS AND SUPPLIERS

A party may be both customer and supplier, **one account**, not two ledgers.

Creating a customer/supplier requires choosing a parent account in the chart of accounts. The system auto-codes the child account. Users cannot type account codes.

Debtors report shows parties with current receivable **>= 50 EGP**.

Debtor table columns:

| Customer | Previous balance before current month | Sales this month | Receipts this month | Balance due now |

Footer: total outstanding.

Supplier aging/balance table:

| Supplier | Previous balance before current month | Purchases this month | Payments this month | Balance payable now |

Footer: total payable.

Statement for a period:

1. Opening row: opening balance at creation + prior movements before period.
2. Period rows, date-ordered:
   - Sales/purchase description auto-built from bill lines, e.g. `حديد عز 10مم وحديد بشاي 12مم`
   - Weight, price, value, running balance
   - Receipt/payment row includes who delivered, who received, location, and cashbox/bank
3. Closing balance row

Clicking a movement opens the source document read-only with edit/cancel actions according to permissions and document state.

Cash receipt from customer and payment voucher to supplier must post automatic journals and appear in the statement in chronological order.

Search by date range is required. List pages must support large volumes with search and pagination.

---

## 14. ACCOUNTING

Base currency: EGP. Additional currencies are out of v1 write-path; schema may allow a currency code defaulting to EGP.

Company settings enable/disable VAT and store the rate.

Must support:

- Cash, credit, partial payments
- Cashboxes
- Banks
- Cheques as a first-class payment method or documented subset if cheque lifecycle is simplified in v1 — do not silently drop cheques. If full cheque workflow cannot be finished in the current unit, mark `PARTIALLY_COMPLETED` rather than pretending.
- Customer and supplier accounts
- Auto posting on approval
- Reverse on cancel, never hard-delete posted entries
- Fiscal periods and period close
- Customer credit limit

Chart of accounts skeleton, names editable by authorized accounting users, **codes automatic**:

```text
1 Assets
  1.1 Fixed assets
  1.2 Current assets
2 Liabilities
  2.1 Current liabilities
  2.2 Long-term liabilities
3 Equity
  3.1 Capital
  3.2 Partners current
4 Expenses
  4.1 G&A
  4.2 Other expenses
5 Revenue
  5.1 Operating revenue
  5.2 Other revenue
```

Deeper children use the same dotted hierarchy (`1.1.1`, `1.2.2.1`, …).

Reports, each on its own page:

- Journal
- Account statement
- Ledger
- Trial balance
- Income statement
- Balance sheet

Dashboard cards for the company:

- Current-month sales total = approved sales bills
- Current-month purchase total = approved purchase bills
- Customer receivables
- Debtors list with drill-down to statement
- Daily sales chart for the month
- Payments vs receipts chart
- Top sold products
- Sales by customer

A user sees only cards they are permitted to view. Hidden cards are omitted, not shown as `***`.

---

## 15. FLEET

Implement a complete operational fleet module, even though the product owner did not specify every field:

Entities:

- Vehicles
- Drivers
- Trips
- Expenses
- Maintenance

Vehicle: internal id, plate, type, make/model, year, ownership (`company` / `rented` / `external`), capacity, fuel type, status, branch, license, insurance, notes.

Driver: name, phone, license number/type/expiry, status, notes.

Trip: vehicle, driver, type, origin, destination, branch, datetime, linked bill, distance, odometer in/out, fuel, road fees, loading fees, revenue, notes.

Expenses classified as: fuel, maintenance, parts, oil, tires, tolls, loading, driver wages, insurance, licensing, fines, other. Each expense links vehicle, optional trip, date, amount, cashbox/bank, account, document, user.

Maintenance: preventive/emergency, issue, parts, labor, vendor, cost, start/end, next due, vehicle status during service.

Reports:

- Revenue, expense, P/L per vehicle
- Trip cost
- Fuel consumption
- Maintenance in period
- Driver performance
- Trips linked to sales/purchases
- Inactive vehicles
- Expenses by category

Accounting posts only on approval. Draft fleet costs have no GL impact.

Company-vehicle trips originating from approved purchase/sales bills must appear in the vehicle operations log.

---

## 16. SUPER ADMIN

Separate layout and guard.

Must include:

- Platform dashboard: company counts, trials, expiring subscriptions, lifetime licenses, manual revenue recorded, pending registrations, recent platform activity
- Companies CRUD-lite: view, approve, reject, activate, suspend, renew, change plan, raise limits, issue lifetime license, transfer license, archive, export company metadata, view audit
- Plans, subscriptions, coupons, trials
- Lifetime license keys, installation IDs, transfer log, enable/disable keys
- Self-registration open/closed
- Manual payment recording
- System logs and operational alerts
- No tenant operational data editing unless later specified. Super Admin may see company metadata and billing/license state.

---

## 17. PERMISSION MODEL

Pattern: `<module>.<action>`

Seed at least:

```text
dashboard.view
users.view / create / update / deactivate / reset_password / view_activity
roles.view / create / update / delete / assign_permissions
branches.view / create / update / archive
warehouses.view / create / update / archive / transfer
products.view / create / update / archive
customers.view / create / update
suppliers.view / create / update
purchases.view / create / update / approve / update_approved / cancel / reverse / print / export
sales.view / create / update / approve / update_approved / cancel / reverse / print / export
inventory.view / transfer / export
accounting.view / post / reverse / close_period
payments.view / create
reports.view / print / export
fleet.view / create / update / approve / expenses / maintenance / export
audit_logs.view
docs.view
onboarding.view
roadmap.view
settings.view / update
```

Do not create permissions for modules that are not implemented yet. Do not show those links.

A user can assign only permissions they themselves possess, except the company owner who can assign any company permission. Nobody can grant Super Admin permissions to a tenant user.

Sidebar/navbar is permission-aware and tenant-aware. Server routes remain protected even if a link is hidden.

---

## 18. REST API

Base: `/api/v1`

Requirements:

- JSON error/success envelope, stable across resources.
- Pagination, filtering, sorting.
- Sanctum auth.
- Identical business rules as web.
- Rate limiting.
- 401 unauthenticated, 403 unauthorized or read-only subscription, 404 for missing tenant-owned records (do not leak cross-tenant existence if avoidable), 422 validation, 419 web CSRF only.
- Resources needed by the later employee mobile app: auth, dashboard summaries, master data, bills, payments, inventory balances, fleet lists, notifications/status.

In Phase 1, ship auth, health, locale, and a version endpoint. Add module endpoints in the same phase as the web module.

Do not document endpoints that are not implemented.

---

## 19. UI / INERTIA RULES

- Separate `CompanyLayout` and `SuperAdminLayout`.
- Permission-aware sidebar and navbar.
- Active route highlighting.
- Arabic and English labels through Laravel lang files / i18n.
- RTL for `ar`, LTR for `en`.
- Tables: search, filters, sort, pagination, empty states.
- Forms: validation messages, draft vs approve buttons, confirmations for deactivate/archive/reverse.
- Success/error toasts.
- Responsive desktop and mobile web.
- If Velzon files are not present, implement a clean original admin UI that can later be replaced by Velzon without rewriting services.
- If Velzon files are present, integrate them in Phase 4 unless the user instructs earlier. Do not spend Phase 1 on visual polish beyond a usable layout.

Browser pages must never dump raw JSON.

---

## 20. TESTING REQUIREMENTS

Every phase must add tests for its own critical paths and keep previous tests green.

Minimum across the project by the end of Phase 4:

- Feature tests for auth, email verification, tenant isolation, permissions, subscription states, license activation offline, draft vs approve stock/journal, credit limit, inventory matrix math, statement running balance, archive-after-6-months job, user deactivation session kill.
- Negative tests: cross-company IDOR, unauthorized approve, selling above stock, posting into a closed period, duplicate bill numbers.
- API tests matching web rules for the same actions.
- PHP syntax / Pint / existing static analysis if configured. Do not claim PHPStan ran if it is not configured.

Never rely only on unauthenticated 401 results to prove an authenticated UI works.

When reporting HTTP results, include the auth context used.

---

## 21. PHASE PLAN

### Phase 1 — Foundation & Platform

**Status starts:** `NOT_STARTED`

Implement:

- Laravel app, MySQL, `.env.example`, Vite, Inertia React scaffold
- Health endpoint
- Localization ar/en + RTL/LTR
- Super Admin vs company auth
- Users, ready-made roles, custom roles, direct permissions
- Tenant/company model
- Plans, subscriptions, coupons, trials, manual payments table + interface
- Self-registration + email verification + Super Admin approval
- Subscription middleware lifecycle
- Local license activation, offline validation, installation lock/installer if needed
- Company/Super Admin layouts, language switch, basic dashboards shells
- `/api/v1` auth + health + me
- Audit/login log foundation
- Installer or artisan + documented XAMPP install path
- Tests for the above

Do not implement sales, purchases, inventory math, or fleet in this phase.

**Phase 1 acceptance:**

- Super Admin can open/close registration.
- Unverified emails never become approvable requests.
- Approved company gets owner + default roles.
- Owner can create users with roles/direct permissions.
- Disabled user cannot log in; sessions die.
- Grace/read-only/suspended/archived behaviors are tested, not only documented.
- Local license validates offline after activation.
- Web and API 401/403/200 behave correctly on XAMPP and artisan serve.
- Progress file updated.

### Phase 2 — Core ERP & Accounting

Implement:

- Branches, warehouses, auto warehouse, archive rules, stock transfers foundation as needed for balances
- Product types, diameters, other items, opening balances, min stock
- Inventory matrix report
- Customers/suppliers unified party, chart of accounts, automatic coding
- Cashboxes, banks, receipts, payments
- Journals, reversing, fiscal periods
- Accounting reports listed in SRS
- Company dashboard cards that can already be sourced; remaining operational charts may stay empty until Phase 3 but must not error
- API endpoints for these resources
- Tests for tenant isolation, account coding, statement opening/running balance rules as far as data exists, VAT setting on/off

**Phase 2 acceptance:**

- Creating a branch creates a warehouse.
- Archive blocked when stock != 0.
- Creating a customer/supplier creates a child account under the chosen parent with automatic code.
- Payments post journals.
- Debtor report hides balances below 50 EGP.
- Closed period rejects posting.
- All previous tests still pass.

### Phase 3 — Operations & Fleet

Implement:

- Purchase bills full lifecycle
- Sales bills full lifecycle
- Duplicate/similar bill warnings
- Factory vs actual weight rules
- Transport allocation display-by-ton
- Stock in/out only on approve
- Credit limit on sales
- Print templates
- Fleet entities, trip logging from company vehicles on bills, P/L reports
- Remaining dashboard charts
- API for bills, stock, fleet
- Tests for draft vs approve, oversell, header/line total mismatch, revision/reverse, fleet posting

**Phase 3 acceptance:**

- Draft purchase/sale does not change stock or GL.
- Approve changes stock and GL once.
- Purchase value uses factory weight; inventory uses actual weight.
- Sales use actual weight only and cannot exceed stock.
- Company vehicle trip is stored on approve.
- Reverse restores stock and posts reversing journal.
- `update_approved` writes revision + audit.
- Previous tests pass.

### Phase 4 — Security, UI, QA & Release

Implement:

- Velzon integration if files exist; otherwise remaining UI consistency pass
- Permission-complete navigation
- Security hardening: headers, rate limits, session config, production env checklist
- API documentation
- Performance indexes and pagination review
- Full regression
- Backup/restore notes
- `DEPLOYMENT.md` for XAMPP, shared hosting document root = `public`, VPS Apache/Nginx, SSL, permissions
- UAT checklist
- No Phase 5

**Phase 4 acceptance:**

- Full regression green
- Security tests green
- Tenant isolation green
- Both URL environments green
- No browser page returns raw JSON
- No secrets in repo
- Deployment and rollback documented
- Known limitations recorded

---

## 22. PROGRESS FILE CONTRACT

Maintain `e-zaki-implementation-progress.md` with:

- Current phase
- Status per phase
- Date
- Files created/modified
- Migrations
- Commands executed
- Actual test results
- HTTP results with auth context
- Assumptions
- Blockers
- Next action

Never rewrite history of completed phases except to append a regression/fix subsection.

---

## 23. RESPONSE CONTRACT AFTER EACH PHASE

```text
PHASE:
STATUS:
SRS SECTIONS IMPLEMENTED:
FILES CREATED:
FILES MODIFIED:
MIGRATIONS:
COMMANDS:
TEST RESULTS:
HTTP RESULTS:
LICENSE/SUBSCRIPTION CHECKS:
KNOWN LIMITATIONS:
NEXT ACTION:
```

Stop after the current phase.

---

## 24. FIRST TASK

If the repository is empty or not a Laravel app yet:

1. Create the Laravel + Inertia React application.
2. Implement Phase 1 only.
3. Do not implement Phases 2–4.

If a Laravel app already exists, inspect it, map it to Phase 1, complete only the missing Phase 1 items, and do not destroy working foundations.

Read `e-zaki-erp-srs.md` and `e-zaki-implementation-progress.md` first.

Begin Phase 1 now.
