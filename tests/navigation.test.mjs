import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { companyNavigation, platformNavigation, isActiveNavigation, visibleNavigation } from '../resources/js/navigation.js';
import { translate } from '../resources/js/i18n.js';

const allCompany = Object.fromEntries(companyNavigation.flatMap((group) => group.items.flatMap((item) => [item.permission, ...(item.any || [])].filter(Boolean))).map((permission) => [permission, true]));
const visible = (grps, perms, query = '', lang = 'ar') => visibleNavigation(grps, perms, 42, query, (key) => translate(lang, key));

test('all company and platform destinations have unique IDs, labels and reachable routes', () => {
    const routes = readFileSync(new URL('../routes/web.php', import.meta.url), 'utf8');
    const dashboard = readFileSync(new URL('../resources/js/Pages/SuperAdmin/Dashboard.jsx', import.meta.url), 'utf8');
    for (const groups of [companyNavigation, platformNavigation]) {
        const items = groups.flatMap((group) => group.items);
        assert.equal(new Set(items.map((item) => item.id)).size, items.length);
        for (const item of items) {
            assert.notEqual(translate('ar', item.label), item.label, item.id);
            assert.notEqual(translate('en', item.label), item.label, item.id);
            assert.ok(item.href.startsWith('/'), item.id);
            if (item.href.startsWith('/super-admin#')) assert.ok(dashboard.includes(`id="${item.href.split('#')[1]}"`), item.id);
        }
    }
    for (const report of companyNavigation.flatMap((group) => group.items).filter((item) => item.href.startsWith('/reports/'))) {
        assert.ok(routes.includes(`'${report.href.split('/').at(-1)}'`), report.id);
    }
    for (const report of companyNavigation.flatMap((group) => group.items).filter((item) => item.href.startsWith('/fleet/reports/'))) {
        assert.ok(routes.includes(`'${report.href.split('/').at(-1)}'`), report.id);
    }
});

test('owner receives all permitted destinations and correct owner-only settings', () => {
    const items = visible(companyNavigation, { ...allCompany, 'company.owner': true }).flatMap((group) => group.items);
    assert.ok(items.length >= 33, items.length);
    assert.ok(items.some((item) => item.href === '/settings/accounting'));
    assert.ok(items.some((item) => item.href === '/reports/account-statement'));
    assert.ok(items.some((item) => item.href === '/settings/users/42/activity'));
    assert.ok(items.some((item) => item.href === '/fleet/reports/branch-performance'));
});

test('staff sees only allowed destinations without empty groups or owner settings', () => {
    const groups = visible(companyNavigation, { 'sales.view': true, 'fleet.view': true });
    assert.deepEqual(groups.map((group) => group.id), ['overview', 'sales', 'fleet', 'fleet-reports']);
    const hrefs = groups.flatMap((group) => group.items.map((item) => item.href));
    assert.ok(hrefs.includes('/operations#sales'));
    assert.ok(!hrefs.includes('/settings/users/42/activity'));
    assert.ok(!hrefs.includes('/operations/sales/create'));
    assert.ok(!hrefs.includes('/settings/accounting'));
    assert.ok(!hrefs.includes('/reports/journal'));
});

test('search filters by Arabic and English labels without exposing denied links', () => {
    assert.deepEqual(visible(companyNavigation, { 'purchases.view': true }, 'المشتريات').flatMap((group) => group.items.map((item) => item.id)), ['purchases-list']);
    assert.deepEqual(visible(companyNavigation, { 'reports.view': true }, 'ledger', 'en').flatMap((group) => group.items.map((item) => item.id)), ['report-ledger']);
    assert.equal(visible(companyNavigation, {}, 'الفواتير').length, 0);
});

test('active matching distinguishes hashes, document creation and reporting routes', () => {
    const items = visible(companyNavigation, { ...allCompany, 'company.owner': true }).flatMap((group) => group.items);
    const byId = (id) => items.find((item) => item.id === id);
    assert.ok(isActiveNavigation(byId('purchases-list'), '/operations#purchases'));
    assert.ok(!isActiveNavigation(byId('sales-list'), '/operations#purchases'));
    assert.ok(isActiveNavigation(byId('sales-list'), '/operations/sales/51/edit'));
    assert.ok(isActiveNavigation(byId('sales-new'), '/operations/sales/create'));
    assert.ok(isActiveNavigation(byId('report-debtors'), '/reports/debtors?from=2026-01-01'));
    assert.ok(!isActiveNavigation(byId('operations'), '/operations#sales'));
});
