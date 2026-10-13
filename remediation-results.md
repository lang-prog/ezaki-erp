# Remediation Results

Date: 2026-10-13
Scope: code-level remediation based on `full-audit-report.md`; no production configuration or database access.

## Delivered

- Party reporting and credit checks now use company-scoped customer/supplier control-account balances. New receipts/vouchers support FIFO or invoice-specific allocation, preserve overpayment as unapplied party credit, return the unapplied amount to the API/UI, and reverse allocations with their source payment. Historical records were not rewritten; aging virtually FIFO-applies legacy unapplied payments.
- Balance-sheet reporting includes a virtual cumulative-earnings equity line and a visible difference check. Posting and closing recheck fiscal-period state while holding locks inside transactions.
- Inventory transfers move weighted-average quantity and value, retain line unit cost/value, support idempotent request retries and source-aware reversal, and refuse reversal after destination stock has been consumed.
- Receipt/voucher reversal is source-aware; generic journal reversal cannot bypass the source lifecycle. Reversal metadata and audit events are recorded.
- Fleet expenses and maintenance require an explicit cashbox, bank, or postable liability account at approval. Branch-performance costs are assigned once to the vehicle branch; trip costs/revenue remain associated with the trip branch.
- License validation binds signed claims to configured installation/customer identity and company, then checks status and expiry. Stateful API requests enforce session-version revocation; bearer-token requests remain independent.
- Company settings upload changes are staged and only replace the prior file after persistence/audit succeeds. Shared API/Inertia errors use dismissible UI notices. CSP is configured Report-Only, not enforced.
- OpenAPI now includes the new payment allocation and receipt/voucher/transfer reversal operations.
- Purchase policy is explicit: purchase value follows factory weight; stock quantity follows the saved company policy; newly provisioned companies default to actual weight; legacy missing settings retain factory weight. No historical conversion is performed.

## Validation

| Check | Result |
|---|---|
| `php vendor/bin/phpunit --do-not-cache-result --cache-directory "$env:TEMP\ezaki-remediation-final-2"` | Passed: 114 tests, 1,376 assertions, 0 failures, 2 skipped |
| `npm test` | Passed: 10 tests |
| `npm run build` | Passed: Vite production build, 820 modules transformed |
| `php vendor/bin/pint --test` | Passed |
| `composer validate --no-check-publish` | Valid |
| `php scripts/lint-openapi.php` | Passed: 17 paths, 19 operations |
| `npm audit --omit=dev --audit-level=high` | 0 vulnerabilities |
| `git diff --check` | Passed; no whitespace errors |

The two skipped PHPUnit tests depend on ephemeral OpenSSL key generation, which is unavailable in the configured PHP CLI. License identity mismatch and copied-database rejection are covered by a separate passing test that does not require key generation.

## Database and Deployment Boundary

- The additive migration was exercised only via PHPUnit's isolated in-memory SQLite database. It has not been run against MySQL or an operator database.
- No MySQL concurrency instance was available; concurrent fiscal close/post and transfer contention still need staging verification on MySQL.
- No `.env` contents were read or changed. No production database, secrets, or external deployment were accessed. No destructive database command, push, or deployment was performed.
- Existing historical journals, bill payments, and stock movements were not rewritten. Legacy payment aging uses a virtual FIFO calculation; no historical allocation backfill is included.
- The migration adds signed-license payload storage. Existing offline license records created before these fields existed need configured installation/customer identity and a newly signed activation before protected use after upgrade. Coordinate reactivation with the offline issuer. Copying both database and all application secrets still cannot be distinguished from the original by an offline-only application.
- `party_payment_allocations` contains operational allocation history. After it has data, do not roll the migration back because its `down()` drops that table; prefer a forward fix or code-only rollback and a verified database backup.
- Browser walkthrough, Apache/XAMPP integration, production HTTPS/mail, external OpenAPI validation, and real deployment smoke tests remain operator/staging acceptance items.

## Commit

One local remediation commit is created after final diff/status checks; nothing was pushed or deployed. `full-audit-report.md` is preserved as an untracked audit artifact and is intentionally excluded from the remediation commit.
