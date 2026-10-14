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
