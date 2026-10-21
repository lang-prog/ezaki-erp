import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import { closeAll, createScene } from './helpers/dom-harness.mjs';
import { catalog, translate } from '../resources/js/i18n.js';
import { MIGRATION_CODES, MIGRATION_DECISIONS, MIGRATION_ENTITIES, MIGRATION_NEXT, MIGRATION_RECORD_STATUSES, MIGRATION_RECORD_TYPES, MIGRATION_REPORTS, MIGRATION_STAGES, MIGRATION_STATUSES, MIGRATION_UNITS } from '../resources/js/i18n-migration.js';

after(closeAll);

const ENTRY = 'tests/helpers/migration-entry.jsx';
const CAPS = { 'platform.data_migration.view': true, 'platform.data_migration.manage': true, 'platform.data_migration.execute': true };
const STAGES = ['upload', 'parse', 'dates', 'mapping', 'masters', 'openings', 'staging', 'review', 'execution', 'reconciliation', 'archive'];

const stages = (currentIndex, next) => STAGES.map((id, index) => ({ id, state: index < currentIndex ? 'done' : (index === currentIndex ? 'current' : 'todo'), next_action: index === currentIndex ? next : null, irreversible: ['openings', 'execution'].includes(id), stepup: ['masters', 'openings', 'execution'].includes(id), retry: ['parse', 'masters', 'execution'].includes(id) }));
const makeRun = (overrides = {}) => ({
    id: 7, company: { id: 3, name: 'Target Co' }, created_by: { id: 1, name: 'Owner' }, source_name: 'legacy.sql.gz', source_hash: 'a'.repeat(64), source_bytes: 2048,
    documents_from: '2026-10-01', documents_to: '2026-10-18', opening_balance_as_of: '2026-09-30', status: 'review', error_message: null, created_at: '2026-10-19T10:00:00Z', locked: false,
    summary: null, integrity: { material_clean: true, statements: 4, error_count: 0, uncompressed_bytes: 4096, tables: { customer: { rows: 2, rejected: 0 } }, staged_rows: { customer: 2 } },
    policy: null, gap_reason: null, source_retention_until: '2027-01-17', source_removed: false,
    counts: { mappings: { customer: { matched: 1, needs_review: 1 }, product: { needs_review: 1 } }, records: {} },
    stages: stages(3, 'review_mappings'), allowed_next: ['masters_preflight', 'parsing'], ...overrides,
});
const mappingRows = [
    { id: 11, entity: 'customer', source_key: '5', name: 'Customer Five', decision: 'needs_review', note: 'similar_name', target_id: null, target_type: null, reviewed: false, locked: false, reason: null, unit_basis: null },
    { id: 12, entity: 'product', source_key: 'x:y', name: 'Tie wire', decision: 'needs_review', note: 'unit_required', target_id: null, target_type: null, reviewed: false, locked: false, reason: null, unit_basis: null, steel: false },
];
const options = { customer: [{ id: 91, label: 'Existing Customer' }], product: [], cashbox: [{ id: 1, label: 'Main cashbox' }], bank: [] };

const fail = (status, data = {}) => Object.assign(new Error('Request failed'), { response: { status, data } });
const mutations = (scene) => scene.requests.filter((request) => request.method !== 'get');
const confirm = (scene) => scene.$('button[type="submit"][form="confirm-form"]');
const buttonByText = (scene, text) => scene.$$('button').find((button) => button.textContent.trim().includes(text));

function defaultHandler(run, extra = {}) {
    return async (request) => {
        if (request.method === 'get' && request.url.endsWith('/mappings')) return { data: { data: mappingRows, meta: { page: request.params?.page ?? 1, last_page: 3, total: 120 }, counts: {} } };
        if (request.method === 'get' && request.url.endsWith('/records')) return { data: { data: [], meta: { page: 1, last_page: 1, total: 0 }, counts: {} } };
        if (request.method === 'get' && request.url.endsWith('/state')) return { data: { run, options } };
        if (request.method === 'get' && request.url.endsWith('/execution/preflight')) return { data: { ok: true, ready: 3, blockers: [] } };
        if (extra[`${request.method} ${request.url.replace(/^.*data-migrations\/7/, '')}`]) return extra[`${request.method} ${request.url.replace(/^.*data-migrations\/7/, '')}`](request);
        return { data: { ok: true, run } };
    };
}

async function openRun(run, { locale = 'en', extra = {}, handler = null } = {}) {
    const scene = await createScene({ entry: ENTRY, locale, handler: handler ?? defaultHandler(run, extra), pageProps: { capabilities: CAPS, navigationCapabilities: CAPS, auth: { user: { name: 'Root' } } } });
    await scene.mount({ page: 'run', data: { run, options } });
    return scene;
}

test('every migration code used by the wizard exists in both languages', () => {
    const families = {
        mg_status_: MIGRATION_STATUSES, mg_stage_: MIGRATION_STAGES, mg_stage_desc_: MIGRATION_STAGES, mg_next_: MIGRATION_NEXT, mg_code_: MIGRATION_CODES, mg_entity_: MIGRATION_ENTITIES,
        mg_unit_: MIGRATION_UNITS, mg_type_: MIGRATION_RECORD_TYPES, mg_record_status_: MIGRATION_RECORD_STATUSES, mg_decision_: MIGRATION_DECISIONS, mg_report_: MIGRATION_REPORTS,
        mg_state_: ['done', 'current', 'todo', 'blocked'], mg_err_: ['network', '403', '409', '419', '422', '500', 'illegal_state', 'migration_busy', 'mapping_locked', 'execution_blocked', 'illegal_transition', 'has_financial_effects', 'retention_not_elapsed'],
        mg_ack_hint_: ['negative_stock', 'missing_cost', 'negative_cash', 'undatable_rows', 'invoice_total_mismatch', 'unassigned_treasury'], mg_sign_: ['debit_positive', 'debit_negative', 'credit_positive', 'credit_negative'],
    };
    const missing = [];
    for (const [prefix, keys] of Object.entries(families)) {
        for (const key of keys) for (const locale of ['en', 'ar']) if (!(`${prefix}${key}` in catalog[locale])) missing.push(`${locale}:${prefix}${key}`);
    }
    assert.deepEqual(missing, []);
    for (const key of Object.keys(catalog.en).filter((name) => name.startsWith('mg_'))) assert.ok(catalog.ar[key], `ar:${key}`);
    for (const key of Object.keys(catalog.ar).filter((name) => name.startsWith('mg_'))) assert.ok(catalog.en[key], `en:${key}`);
});

test('the wizard shows all eleven stages, the current step and the next action, in Arabic too', async () => {
    for (const locale of ['en', 'ar']) {
        const scene = await openRun(makeRun(), { locale });
        assert.equal(scene.$$('ol.stage-rail > li').length, 11);
        assert.equal(scene.$$('ol.stage-rail > li[aria-current="step"]').length, 1);
        assert.ok(scene.text().includes(translate(locale, 'mg_next_review_mappings')));
        assert.ok(scene.text().includes(translate(locale, 'mg_status_review')));
        assert.equal(scene.document.documentElement.dir, locale === 'ar' ? 'rtl' : 'ltr');
        assert.ok(!/\bmg_[a-z_]+/.test(scene.text()), `no raw translation key is shown (${locale})`);
        assert.deepEqual(scene.errors, []);
        scene.close();
    }
});

test('mapping table loads rows, filters, pages, and sends exclusion with a reason', async () => {
    const scene = await openRun(makeRun());
    const first = scene.requests.find((request) => request.url.endsWith('/mappings'));
    assert.ok(first, 'the mappings are fetched');
    assert.ok(scene.text().includes('Customer Five'));
    const entity = scene.$$('select').find((select) => [...select.options].some((option) => option.value === 'supplier'));
    await scene.setValue(entity, 'customer');
    await scene.click(buttonByText(scene, 'Apply'));
    assert.equal(scene.requests.filter((request) => request.url.endsWith('/mappings')).pop().params.entity, 'customer');
    await scene.click(buttonByText(scene, 'Next'));
    assert.equal(scene.requests.filter((request) => request.url.endsWith('/mappings')).pop().params.page, 2);
    await scene.click(scene.$$('button').find((button) => button.textContent.trim() === 'Exclude'));
    assert.ok(confirm(scene), 'a confirmation dialog opens');
    assert.equal(scene.$('textarea').required, true, 'a reason is mandatory');
    assert.equal(mutations(scene).length, 0, 'nothing is sent before confirmation');
    await scene.setValue(scene.$('textarea'), 'Duplicate of another party');
    await scene.click(confirm(scene));
    const sent = mutations(scene);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].method, 'put');
    assert.deepEqual(sent[0].data, { decision: 'excluded', reason: 'Duplicate of another party' });
    scene.close();
});

test('a non-weight unit is stored verbatim and the unit warning is shown', async () => {
    const scene = await openRun(makeRun());
    const unit = scene.$$('select').find((select) => [...select.options].some((option) => option.value === 'bag'));
    await scene.setValue(unit, 'bag');
    assert.ok(scene.text().includes(translate('en', 'mg_unit_warning')));
    await scene.click(scene.$$('button').find((button) => button.textContent.trim() === 'Save'));
    const sent = mutations(scene)[0];
    assert.equal(sent.method, 'put');
    assert.equal(sent.data.unit_basis, 'bag');
    assert.equal(sent.data.decision, 'create_required');
    assert.ok(!('opening_cost_per_ton' in sent.data), 'no cost is invented');
    scene.close();
});

test('master blockers link back to the mapping and a failed action is reported without raw errors', async () => {
    const run = makeRun({ stages: stages(4, 'create_masters'), status: 'masters_ready' });
    const blockers = [{ code: 'unit_required', entity: 'product', source_key: 'x:y', mapping_id: 12, name: 'Tie wire', params: {} }];
    const scene = await openRun(run, { extra: { 'post /masters/preflight': async () => ({ data: { preflight: { ok: false, blockers, counts: { create_required: 2 } }, run } }) } });
    await scene.click(buttonByText(scene, 'Check masters'));
    assert.ok(scene.text().includes('Unit basis required'));
    await scene.click(buttonByText(scene, 'Open mapping'));
    const last = scene.requests.filter((request) => request.url.endsWith('/mappings')).pop();
    assert.equal(last.params.q, 'x:y');
    assert.equal(last.params.entity, 'product');
    scene.close();

    const broken = await openRun(run, { extra: { 'post /masters/preflight': async () => { throw fail(500, { message: 'SQLSTATE[HY000] secret detail' }); } } });
    await broken.click(buttonByText(broken, 'Check masters'));
    assert.ok(broken.text().includes(translate('en', 'mg_err_500')));
    assert.ok(!broken.text().includes('SQLSTATE'));
    broken.close();
});

test('execution asks for confirmation, sends once for a double click, and maps refusal codes to messages', async () => {
    const run = makeRun({ status: 'documents_ready', stages: stages(8, 'execute'), counts: { mappings: {}, records: { ready: 3 } }, allowed_next: ['executing'] });
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const scene = await openRun(run, { extra: { 'post /execute': async () => { await gate; return { data: { result: { created: 3, already_done: 0, failed: 0, remaining: 0 }, run } }; } } });
    await scene.click(buttonByText(scene, 'Execute documents'));
    assert.equal(mutations(scene).length, 0);
    await scene.click(confirm(scene), 2);
    assert.equal(mutations(scene).filter((request) => request.url.endsWith('/execute')).length, 1, 'a double click posts once');
    release();
    await scene.flush();
    scene.close();

    const refused = await openRun(run, { extra: { 'post /execute': async () => { throw fail(409, { code: 'migration_busy', message: 'busy' }); } } });
    await refused.click(buttonByText(refused, 'Execute documents'));
    await refused.click(confirm(refused));
    assert.ok(refused.text().includes(translate('en', 'mg_err_migration_busy')));
    refused.close();

    const network = await openRun(run, { locale: 'ar', handler: async (request) => { if (request.method === 'post') throw new Error('Network Error'); return defaultHandler(run)(request); } });
    await network.click(buttonByText(network, translate('ar', 'mg_execute')));
    await network.click(confirm(network));
    assert.ok(network.text().length > 0);
    network.close();
});

test('report downloads, step-up hints and irreversible warnings are visible', async () => {
    const run = makeRun({ status: 'masters_created', stages: stages(5, 'preview_openings') });
    const scene = await openRun(run);
    const links = scene.$$('a[download]').map((link) => link.getAttribute('href'));
    assert.deepEqual(links, MIGRATION_REPORTS.map((type) => `/super-admin/data-migrations/7/reports/${type}`));
    assert.ok(scene.text().includes(translate('en', 'mg_irreversible')));
    assert.ok(scene.text().includes(translate('en', 'mg_stepup')));
    scene.close();
});

test('the upload form asks for a gap reason only when documents do not start the day after the cutoff', async () => {
    const scene = await createScene({ entry: ENTRY, locale: 'en', pageProps: { capabilities: CAPS, navigationCapabilities: CAPS, auth: { user: { name: 'Root' } } } });
    await scene.mount({ page: 'index', data: { companies: [{ id: 3, name: 'Target Co' }], runs: { data: [], links: [] }, limits: { max_upload_kb: 40960, retention_days: 90 } } });
    assert.equal(scene.$('input[name="gap_reason"]'), null);
    await scene.setValue(scene.$('input[name="documents_from"]'), '2026-10-05');
    assert.ok(scene.$('input[name="gap_reason"]'), 'a gap needs a reason');
    assert.ok(scene.$('input[name="source"]').accept.includes('.sql'));
    assert.deepEqual(scene.errors, []);
    scene.close();
});
