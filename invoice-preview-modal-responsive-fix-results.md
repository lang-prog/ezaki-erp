# Invoice Preview Modal — Responsive Fix Results

## Root cause
The statement preview dialogs (`DocumentModal`, `RevisionModal` in `resources/js/Pages/Company/AccountingReport.jsx`) reused the shared `.modal` class from `resources/css/app.css`, which is `width:min(480px,100%); overflow:hidden` with no `max-height`. The dialog's header, summary, tables and buttons were direct children of that shell, so nothing owned scrolling: content taller or wider than the viewport was clipped and the footer buttons were unreachable.

Investigation findings:
- Only one modal system exists (`.modal-backdrop` + `.modal`, `z-index:50`); it was reused, not replaced. The shared `.modal` rule was left untouched because other dialogs depend on it; the fix is scoped to `.statement-document-modal`.
- No ancestor (`.ez-shell`, `.ez-page`, `.ez-main`) applies `overflow:hidden`, a fixed height, `transform` or `backdrop-filter`, so `position:fixed` is not clipped or re-anchored.
- No wrapping form: the dialog is rendered outside the filter `<form>`.
- Preview data is already server-provided with the statement rows, so there is no async request: no loading/error state applies and no blank-modal failure mode exists.

## Changes
| File | Change |
|---|---|
| `resources/js/Pages/Company/AccountingReport.jsx` | New `ModalShell` (header / scrolling body / footer). Both dialogs now use it. Escape closes, Tab is trapped, `body` scroll is locked and restored, focus returns to the triggering button, backdrop click closes but clicks inside do not. Close button has an accessible label; `aria-labelledby` points at the title. |
| `resources/css/navigation.css` | Scoped `.statement-*` rules (see below). |
| `resources/js/i18n.js` | Added missing `revisions` key (en/ar) used by the revision dialog subtitle. |
| `tests/statement.test.mjs` | 8 markup/CSS regression tests. |
| `tests/Feature/AccountingStatementRebuildTest.php` | Tenant-isolation test for preview/full-view/print routes. |

No invoice fields were removed; accounting, permissions (`canView`/`canPrint`), routes and tenant scoping are unchanged.

## Responsive decisions
- Shell: `width:min(1100px,100%)`, `max-height: calc(100vh - 2*clamp(8px,2vw,24px))`, overridden with `100dvh` under `@supports` for mobile browser chrome; `display:flex; flex-direction:column`.
- Header and footer are `flex:none` (always visible); body is `flex:1 1 auto; min-height:0; overflow:auto; overscroll-behavior:contain` with 28px bottom padding.
- Tables sit in `.modal-table-wrap` (`overflow-x:auto`, table `min-width:640px`) so wide content scrolls inside the dialog and never widens the page. Text uses `overflow-wrap:anywhere`.
- Summary uses `auto-fit minmax(190px,1fr)`; revision cards collapse to one column at `<=760px`.
- Footer wraps; on `<=760px` buttons are `flex:1 1 140px` (stack/fill, 40px min height). Close button is 40x40.
- Only logical/flow properties are used (no `left`/`right`), so RTL and LTR share one layout.
- Print: `@media print { .statement-modal-backdrop { display:none !important } }`; the dialog never affects the print routes, which are separate pages.

## Tests and results
- `php vendor/bin/phpunit` (equivalent to `php artisan test`): **132 tests, 1585 assertions, 0 failures, 2 skipped** (the 2 skips pre-date this work).
- `npm test`: **25 passed, 0 failed.**
- `npm run build`: passed (vite 6.4.3, 823 modules).
- `vendor/bin/pint --test`: passed.
- `composer validate --no-check-publish`: valid.
- `php scripts/lint-openapi.php`: OK (17 paths, 19 operations).
- `npm audit --omit=optional`: 0 vulnerabilities.
- `git diff --check`: no whitespace errors (only CRLF-to-LF advisory warnings for `navigation.css` and `statement.test.mjs`).

## Limitations / manual checks still required
The repository has no browser test runner, so layout is verified by deterministic markup/CSS tests only; real rendering has not been checked in a browser. Please verify manually, in Arabic RTL and English LTR at desktop, tablet and ~375px widths:
1. Customer and supplier statement: open an invoice preview with many lines and a wide table; scroll vertically and horizontally inside the dialog to the totals and footer.
2. Footer buttons stay visible; Full view and Print open the correct invoice route.
3. Close button, Escape and backdrop click close it; background page does not scroll; focus returns to the row button.
4. Print the statement page with the dialog open: the overlay must not appear.
