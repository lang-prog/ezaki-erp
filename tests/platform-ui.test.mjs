import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { catalog } from '../resources/js/i18n.js';

const root = new URL('../resources/js/', import.meta.url);
const files = [
    ...readdirSync(new URL('Pages/SuperAdmin/', root)).map((name) => `Pages/SuperAdmin/${name}`),
    'Components/Platform/ui.jsx',
    'Components/Platform/PlanChangeDialog.jsx',
    'Pages/Auth/Login.jsx',
];

function literalKeys(source) {
    const keys = new Set();
    for (const match of source.matchAll(/\bt\(\s*'([A-Za-z0-9_]+)'/g)) keys.add(match[1]);
    for (const match of source.matchAll(/\b(?:label|title|eyebrow|description|hint|placeholder)(?:Key)?\s*[:=]\s*\{?'((?:pf_)[A-Za-z0-9_]+)'/g)) keys.add(match[1]);
    return keys;
}

function templatePrefixes(source) {
    const prefixes = new Set();
    for (const match of source.matchAll(/t\(\s*`(pf_[a-z_]*?)\$\{/g)) prefixes.add(match[1]);
    return prefixes;
}

test('every literal translation key used by platform pages exists in both locales', () => {
    const missing = [];
    for (const file of files) {
        const source = readFileSync(new URL(file, root), 'utf8');
        for (const key of literalKeys(source)) {
            for (const locale of ['en', 'ar']) if (!(key in catalog[locale])) missing.push(`${locale}:${key} (${file})`);
        }
    }
    assert.deepEqual(missing, []);
});

test('dynamic translation families are fully defined', () => {
    const families = {
        pf_status_: ['active', 'suspended', 'archived'],
        pf_state_: ['active', 'grace', 'read_only', 'suspended', 'archived', 'cancelled', 'none'],
        pf_action_: ['activate', 'suspend', 'archive', 'restore'],
        pf_impact_: ['suspend', 'activate', 'archive', 'restore', 'approve', 'reject'],
        pf_duration_: ['monthly', 'six_months', 'yearly', 'trial', 'lifetime'],
        pf_pay_: ['paid', 'pending', 'failed'],
        pf_role_: ['owner', 'billing', 'support', 'auditor', 'none'],
        pf_role_hint_: ['owner', 'billing', 'support', 'auditor'],
        pf_sev_: ['info', 'warning', 'critical'],
        pf_reg_: ['pending', 'rejected', 'approved'],
        pf_req_: ['open', 'handled'],
        pf_req_type_: ['contact', 'renew'],
        pf_lic_: ['enabled', 'disabled', 'revoked', 'activated', 'transferred'],
        pf_lic_action_: ['enable', 'disable', 'revoke', 'transfer'],
        pf_impact_lic_: ['enable', 'disable', 'revoke', 'transfer'],
        pf_filter_: ['all', 'expiring', 'lapsed', 'suspended'],
        pf_res_: ['users', 'branches', 'warehouses'],
        pf_extra_: ['users', 'branches', 'warehouses'],
        pf_alert_: ['lapsed', 'expiring', 'registrations', 'requests'],
        pf_err_: ['403', '409', '419', '422', '423', '429', '500', 'network'],
        pf_boundary_: ['title', 'copy', 'retry', 'reload'],
    };
    const missing = [];
    for (const [prefix, names] of Object.entries(families)) {
        for (const name of names) for (const locale of ['en', 'ar']) {
            if (!(`${prefix}${name}` in catalog[locale])) missing.push(`${locale}:${prefix}${name}`);
        }
    }
    assert.deepEqual(missing, []);
});

test('platform locales expose identical keys', () => {
    const en = Object.keys(catalog.en).filter((key) => key.startsWith('pf_')).sort();
    const ar = Object.keys(catalog.ar).filter((key) => key.startsWith('pf_')).sort();
    assert.deepEqual(ar, en);
});

test('platform pages only use known template families', () => {
    const known = new Set(['pf_status_', 'pf_state_', 'pf_action_', 'pf_impact_', 'pf_duration_', 'pf_pay_', 'pf_role_', 'pf_role_hint_', 'pf_sev_', 'pf_reg_', 'pf_req_', 'pf_req_type_', 'pf_lic_', 'pf_lic_action_', 'pf_impact_lic_', 'pf_filter_', 'pf_res_', 'pf_extra_', 'pf_alert_', 'pf_kpi_']);
    const unknown = [];
    for (const file of files) {
        for (const prefix of templatePrefixes(readFileSync(new URL(file, root), 'utf8'))) if (!known.has(prefix)) unknown.push(`${prefix} (${file})`);
    }
    assert.deepEqual(unknown, []);
});

test('platform pages never render secrets and never use physical direction CSS classes', () => {
    for (const file of files) {
        const source = readFileSync(new URL(file, root), 'utf8');
        assert.ok(!/\.(?:license_key|signature|password_hash|two_factor_secret)\b/.test(source), file);
        assert.ok(!/\b(?:ml|mr|pl|pr)-\d/.test(source), file);
    }
});

test('confirmation reasons match the backend minimum and phase-one step-up does not stack modals', () => {
    const ui = readFileSync(new URL('Components/Platform/ui.jsx', root), 'utf8');
    const migration = readFileSync(new URL('Pages/SuperAdmin/DataMigrationRun.jsx', root), 'utf8');
    assert.match(ui, /textarea required minLength=\{5\}/);
    assert.match(migration, /const phaseOneDialogAction = useCallback/);
    assert.match(migration, /phaseOneDialogAction\('\/phase1\/issues\/bulk'/);
    assert.match(migration, /setDialog\(null\);/);
});
