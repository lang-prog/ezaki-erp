import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { translate } from '../resources/js/i18n.js';

const statementSource = readFileSync(new URL('../resources/js/Pages/Company/AccountingReport.jsx', import.meta.url), 'utf8');

test('statement renders opening balance and metrics in summary KPI cards separately from table rows', () => {
    assert.match(statementSource, /className="statement-kpis"/);
    assert.match(statementSource, /\{t\('openingBalance'\)\}/);
    assert.match(statementSource, /\{t\('periodDebits'\)\}/);
    assert.match(statementSource, /\{t\('periodCredits'\)\}/);
    assert.match(statementSource, /\{t\('netMovement'\)\}/);
    assert.match(statementSource, /\{t\('closingBalance'\)\}/);

    // Ensure tbody only maps over transaction rows and has no synthetic opening balance row
    assert.doesNotMatch(statementSource, /<tbody>\s*<tr className="statement-opening-row"/);
});

test('particulars column displays localized description with party subtitle', () => {
    assert.match(statementSource, /<th>\{t\('particulars'\)\}<\/th>/);
    assert.match(statementSource, /row\.particulars_ar \? row\.particulars_ar : \(row\.particulars/);
    assert.match(statementSource, /document\?\.party && <small className="table-subline">\{document\.party\}<\/small>/);
});

test('document modal presents full audit details including settled, remaining, payment source, and reversal reason', () => {
    assert.match(statementSource, /\{t\('settledAmount'\)\}/);
    assert.match(statementSource, /\{t\('remainingAmount'\)\}/);
    assert.match(statementSource, /\{t\('paymentSource'\)\}/);
    assert.match(statementSource, /\{t\('reversalReason'\)\}/);
    assert.match(statementSource, /allocations\.length > 0/);
    assert.match(statementSource, /\{t\(isSales \|\| isPurchase \? 'allocatedPayments' : 'allocatedInvoices'\)\}/);
});

test('document modal action buttons have explicit type="button" and respect permissions', () => {
    assert.match(statementSource, /<button type="button" className="erp-button erp-button--secondary" onClick=\{onClose\}/);
    assert.match(statementSource, /document\.print_url && canPrint && <button type="button"/);
    assert.match(statementSource, /document\.view_url && canView && <button type="button"/);
});

test('filter bar preserves account_id, from_date, and to_date with apply button', () => {
    assert.match(statementSource, /<form method="get" className="no-print report-filter-bar">/);
    assert.match(statementSource, /name="account_id"/);
    assert.match(statementSource, /name="from_date"/);
    assert.match(statementSource, /name="to_date"/);
    assert.match(statementSource, /<button className="erp-button erp-button--primary">\{t\('apply'\)\}<\/button>/);
});

test('view mode switcher offers operational statement and detailed ledger options', () => {
    assert.match(statementSource, /className="statement-view-toggle no-print"/);
    assert.match(statementSource, /view=operational/);
    assert.match(statementSource, /view=detailed/);
    assert.match(statementSource, /\{t\('operationalStatement'\)\}/);
    assert.match(statementSource, /\{t\('detailedLedger'\)\}/);
});

test('revised bills render amended badge and revision history modal action', () => {
    assert.match(statementSource, /document-badge--revised/);
    assert.match(statementSource, /\{t\('amended'\)\}/);
    assert.match(statementSource, /table-action--revision/);
    assert.match(statementSource, /\{t\('revisionHistory'\)\}/);
    assert.match(statementSource, /function RevisionModal/);
    assert.match(statementSource, /\{t\('reversalJournal'\)\}/);
    assert.match(statementSource, /\{t\('replacementJournal'\)\}/);
});

test('all statement translation keys are localized in Arabic and English', () => {
    const keys = [
        'accountStatement',
        'openingBalance',
        'periodDebits',
        'periodCredits',
        'netMovement',
        'closingBalance',
        'allActivity',
        'settledAmount',
        'remainingAmount',
        'paymentSource',
        'allocations',
        'reversalReason',
        'periodTotals',
        'debitBalance',
        'creditBalanceTag',
        'zeroBalance',
        'particulars',
        'operationalStatement',
        'detailedLedger',
        'viewMode',
        'amended',
        'revisionHistory',
        'revisionChain',
        'revised',
        'originalAmount',
        'revisedAmount',
        'revisionDelta',
        'replacementJournal',
        'reversalJournal',
    ];

    for (const key of keys) {
        const en = translate('en', key);
        const ar = translate('ar', key);
        assert.notEqual(en, key, `Missing en translation for ${key}`);
        assert.notEqual(ar, key, `Missing ar translation for ${key}`);
        assert.notEqual(en, ar, `English and Arabic should not be identical for ${key}`);
    }
});

const css = readFileSync(new URL('../resources/css/navigation.css', import.meta.url), 'utf8');
const rule = (selector) => css.match(new RegExp(`${selector.replace(/[.[\]]/g, '\\$&')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

test('both preview modals share one shell with header, scrolling body and footer regions', () => {
    assert.match(statementSource, /function ModalShell/);
    assert.equal((statementSource.match(/<ModalShell/g) || []).length, 2);
    assert.match(statementSource, /className="statement-modal-body"/);
    assert.match(statementSource, /className="modal-footer statement-modal-footer"/);
    assert.match(statementSource, /aria-modal="true" aria-labelledby=\{titleId\}/);
    assert.match(statementSource, /aria-label=\{closeLabel\}/);
});

test('dialog shell is viewport constrained and only the body scrolls', () => {
    const shell = rule('.modal.statement-document-modal');
    assert.match(shell, /max-height:\s*calc\(100vh/);
    assert.match(shell, /display:\s*flex/);
    assert.match(shell, /flex-direction:\s*column/);
    assert.match(css, /@supports \(height: 100dvh\)/);
    const body = rule('.statement-modal-body');
    assert.match(body, /min-height:\s*0/);
    assert.match(body, /overflow-y:\s*auto/);
    assert.match(body, /overflow-x:\s*auto/);
    assert.match(rule('.statement-modal-header'), /flex:\s*none/);
    assert.match(rule('.statement-modal-footer'), /flex:\s*none/);
    assert.match(rule('.statement-modal-footer'), /flex-wrap:\s*wrap/);
});

test('wide invoice tables scroll horizontally and mobile footer buttons wrap', () => {
    assert.match(rule('.statement-document-modal .modal-table-wrap'), /overflow-x:\s*auto/);
    assert.match(rule('.statement-document-modal .modal-table-wrap .erp-table'), /min-width:\s*640px/);
    assert.match(css, /@media \(max-width: 760px\)[^@]*\.statement-modal-footer \.erp-button \{ flex: 1 1 140px/);
});

test('layout uses logical properties only so RTL and LTR do not reverse actions', () => {
    const block = css.slice(css.indexOf('Statement document / revision preview dialogs'));
    assert.doesNotMatch(block, /(margin|padding)-(left|right)|(?<![-\w])(left|right):/);
});

test('escape, focus restore and background scroll lock are handled by the shell', () => {
    assert.match(statementSource, /event\.key === 'Escape'/);
    assert.match(statementSource, /body\.style\.overflow = 'hidden'/);
    assert.match(statementSource, /trigger\.focus\(\)/);
    assert.match(statementSource, /event\.target === event\.currentTarget && onClose\(\)/);
    assert.match(statementSource, /onMouseDown=\{\(event\) => event\.stopPropagation\(\)\}/);
});

test('full view and print buttons use the document own routes and stay hidden without permission', () => {
    assert.match(statementSource, /window\.open\(document\.view_url/);
    assert.match(statementSource, /window\.open\(document\.print_url/);
    assert.match(statementSource, /document\.print_url && canPrint/);
    assert.match(statementSource, /document\.view_url && canView/);
});

test('modal chrome is removed from print output', () => {
    assert.match(css, /@media print \{ \.statement-modal-backdrop \{ display: none !important/);
});
