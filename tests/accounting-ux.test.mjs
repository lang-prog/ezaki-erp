import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { accountLabel, accountName, counterpartyLabel, formatDate, formatDateTime, formatMoney } from '../resources/js/format.js';
import { catalog } from '../resources/js/i18n.js';
import { companyNavigation } from '../resources/js/navigation.js';

const read = (path) => readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

const NEW_SOURCES = [
    'Pages/Company/Accounting.jsx', 'Pages/Company/ChartOfAccounts.jsx', 'Pages/Company/FiscalPeriods.jsx', 'Pages/Company/Cashboxes.jsx', 'Pages/Company/Banks.jsx',
    'Components/MoneyDocumentsList.jsx', 'Components/MoneyForm.jsx', 'Pages/Company/MoneyDocumentView.jsx', 'Pages/Company/InvoiceSettlementReport.jsx',
    'Pages/Company/AccountingSettings.jsx', 'Pages/Company/BillForm.jsx', 'Components/PartyMoneyModal.jsx',
];
const DYNAMIC = [
    ...['asset', 'liability', 'equity', 'revenue', 'expense'].map((type) => `accountType_${type}`),
    ...['all', 'system', 'custom', 'inactive'].map((key) => `chartFilter_${key}`),
    ...['all', 'unpaid', 'partial', 'paid', 'overdue', 'reversed'].map((key) => `state_${key}`),
    'policyFactoryTitle', 'policyFactoryEffect', 'policyActualTitle', 'policyActualEffect',
];

test('formatDate never shows ISO strings or times for date-only values', () => {
    for (const locale of ['ar', 'en']) {
        for (const raw of ['2026-10-14', '2026-10-14T00:00:00.000000Z', '2026-10-14 08:30:00']) {
            const formatted = formatDate(raw, locale);
            assert.doesNotMatch(formatted, /T\d|Z$|\d{4}-\d{2}-\d{2}/);
            assert.doesNotMatch(formatted, /:/);
        }
    }
    assert.equal(formatDate('2026-10-14', 'en'), '14/10/2026');
    assert.match(formatDate('2026-10-14', 'ar').replace(/\u200f/g, ''), /^[\u0660-\u0669]{2}\/[\u0660-\u0669]{2}\/[\u0660-\u0669]{4}$/);
});

test('formatDate is stable around midnight because it ignores the local timezone', () => {
    assert.equal(formatDate('2026-01-01T00:00:00Z', 'en'), '01/01/2026');
    assert.equal(formatDate('2026-12-31', 'en'), '31/12/2026');
});

test('empty or invalid dates render a dash instead of an error', () => {
    assert.equal(formatDate(null, 'en'), '—');
    assert.equal(formatDate('', 'ar'), '—');
    assert.equal(formatDate('not a date', 'en'), '—');
    assert.equal(formatDateTime(null, 'en'), '—');
});

test('money uses locale digits and fixed decimals', () => {
    assert.equal(formatMoney(1234.5, 'en'), '1,234.50');
    assert.match(formatMoney(1234.5, 'ar'), /[\u0660-\u0669]/);
});

test('account names follow the locale with a fallback chain', () => {
    const account = { code: '1.2', name: 'Current assets', name_ar: 'الأصول المتداولة', name_en: 'Current assets' };
    assert.equal(accountName(account, 'ar'), 'الأصول المتداولة');
    assert.equal(accountName(account, 'en'), 'Current assets');
    assert.equal(accountName({ name: 'Legacy', name_ar: null, name_en: null }, 'ar'), 'Legacy');
    assert.equal(accountName({ name_en: 'Only English' }, 'ar'), 'Only English');
    assert.equal(accountLabel(account, 'en'), '1.2 · Current assets');
});

test('counterparty shows the party name or the other account and never an id', () => {
    assert.equal(counterpartyLabel({ party: { name: 'Delta Steel' }, party_id: 7 }, 'en'), 'Delta Steel');
    const other = { other_account: { code: '4.1', name: 'G&A', name_ar: 'المصروفات العمومية والإدارية', name_en: 'G&A' }, other_account_id: 9 };
    assert.equal(counterpartyLabel(other, 'ar'), '4.1 · المصروفات العمومية والإدارية');
    assert.equal(counterpartyLabel(other, 'en'), '4.1 · G&A');
    assert.equal(counterpartyLabel({ party_id: 7 }, 'en'), '—');
});

test('every translation key used by the new pages exists in Arabic and English', () => {
    const used = new Set(DYNAMIC);
    for (const file of NEW_SOURCES) {
        for (const match of read(file).matchAll(/\bt\('([A-Za-z0-9_]+)'\)/g)) used.add(match[1]);
        for (const match of read(file).matchAll(/'((?:hub|stat|policy|chart)[A-Z][A-Za-z0-9_]*)'/g)) used.add(match[1]);
    }
    const missing = [...used].filter((key) => !(key in catalog.en) || !(key in catalog.ar));
    assert.deepEqual(missing, []);
});

test('Arabic strings for the new keys are really Arabic', () => {
    for (const key of ['chartIntro', 'settlementsIntro', 'headerTotalsHint', 'policyFactoryEffect', 'counterpartyAccount', 'state_partial', 'agingCurrent']) {
        assert.match(catalog.ar[key], /[\u0600-\u06FF]/, key);
        assert.doesNotMatch(catalog.en[key], /[\u0600-\u06FF]/, key);
    }
});

test('accounting navigation exposes each page once and no longer lists the duplicate creditors report', () => {
    const ids = companyNavigation.flatMap((group) => group.items.map((item) => item.id));
    assert.equal(new Set(ids).size, ids.length);
    const hrefs = companyNavigation.flatMap((group) => group.items.map((item) => item.href));
    for (const href of ['/accounting', '/accounting/chart', '/accounting/periods', '/accounting/cashboxes', '/accounting/banks', '/reports/payables', '/reports/invoice-settlements']) assert.ok(hrefs.includes(href), href);
    assert.ok(!hrefs.includes('/reports/creditors'));
    assert.equal(catalog.en.payablesReport, 'Payables / Creditors');
    assert.equal(catalog.ar.payablesReport, 'الذمم الدائنة / الموردون');
});

test('hub is a launcher and no longer embeds the chart, forms or posting logic', () => {
    const hub = read('Pages/Company/Accounting.jsx');
    assert.match(hub, /href: '\/accounting\/chart'/);
    assert.match(hub, /href: '\/accounting\/periods'/);
    assert.doesNotMatch(hub, /AccountTree|MoneyForm|useCoreApi/);
});

test('chart page offers search, filters, expand controls, bilingual names and protection badges', () => {
    const chart = read('Pages/Company/ChartOfAccounts.jsx');
    assert.match(chart, /type="search"/);
    assert.match(chart, /chartFilter_/);
    assert.match(chart, /expandAll/);
    assert.match(chart, /collapseAll/);
    assert.match(chart, /t\('arabicName'\)/);
    assert.match(chart, /t\('englishName'\)/);
    assert.match(chart, /t\('systemAccount'\)/);
    assert.match(chart, /aria-expanded/);
    assert.match(chart, /if \(!editing\.is_system\) payload\.parent_id/);
});

test('receipt and voucher lists show the counterparty column, payer, receiver, export and filters', () => {
    const list = read('Components/MoneyDocumentsList.jsx');
    assert.match(list, /t\('counterpartyAccount'\)/);
    assert.match(list, /counterpartyLabel\(row, locale\)/);
    assert.match(list, /formatDate\(date\(row\), locale\)/);
    assert.match(list, /\/export/);
    assert.match(list, /type="search"/);
    assert.match(list, /t\('receivedBy'\)/);
    assert.doesNotMatch(list, /\{row\.(receipt_date|voucher_date)\}/);
    assert.match(read('Pages/Company/ReceiptsList.jsx'), /kind="receipt"/);
    assert.match(read('Pages/Company/PaymentVouchersList.jsx'), /kind="voucher"/);
});

test('posting form keeps payer and receiver optional and sends no blank optional fields', () => {
    const form = read('Components/MoneyForm.jsx');
    assert.match(form, /delivered_by|paid_by/);
    assert.match(form, /received_by/);
    assert.match(form, /t\('optional'\)/);
    assert.match(form, /delete payload\[key\]/);
    assert.doesNotMatch(form, /<input required[^>]*received_by/);
});

test('document view formats the date, shows the counterparty and allows detail edits', () => {
    const view = read('Pages/Company/MoneyDocumentView.jsx');
    assert.match(view, /formatDate\(date, locale\)/);
    assert.match(view, /counterpartyLabel\(document, locale\)/);
    assert.match(view, /\/details/);
    assert.match(view, /allocations\.length > 0/);
});

test('settlement page has tabs, aging, the required columns and a gated payment-history modal', () => {
    const page = read('Pages/Company/InvoiceSettlementReport.jsx');
    for (const key of ['invoiceNumber', 'invoiceDate', 'dueDate', 'invoiceValue', 'settledAmount', 'outstanding', 'daysOverdue', 'settlementState', 'paymentHistory', 'partyAging', 'unallocatedCredit']) assert.match(page, new RegExp(`t\\('${key}'\\)`), key);
    for (const bucket of ['agingCurrent', 'aging1_30', 'aging31_60', 'aging61_90', 'aging90Plus']) assert.match(page, new RegExp(bucket), bucket);
    assert.match(page, /receivablesTab/);
    assert.match(page, /payablesTab/);
    assert.match(page, /<ModalShell/);
    assert.match(page, /selected\.view_url && canView/);
    assert.match(page, /selected\.print_url && canPrint/);
    assert.doesNotMatch(page, /JSON\.stringify/);
    assert.doesNotMatch(page, /\{row\.party_id\}|\{selected\.party_id\}/);
});

test('invoice form blocks mismatched header totals and compares integers at three decimals', () => {
    const form = read('Pages/Company/BillForm.jsx');
    assert.match(form, /Math\.round\(number\(value\) \* 1000\)/);
    assert.match(form, /headerMismatch/);
    assert.match(form, /disabled=\{processing \|\| headerMismatch\}/);
    assert.match(form, /total_factory_weight: purchase \? number\(form\.total_factory_weight\)/);
    assert.match(form, /t\('calculatedFromLines'\)/);
});

test('policy page marks factory weight as the recommended default and explains the lock', () => {
    const page = read('Pages/Company/AccountingSettings.jsx');
    assert.match(page, /value: 'factory_weight'[^}]*recommended: true/);
    assert.match(page, /value: 'actual_weight'[^}]*recommended: false/);
    assert.match(page, /policyLockedNotice/);
    assert.match(page, /fieldset disabled=\{locked\}/);
});
