import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import { closeAll, createScene, readSource } from './helpers/dom-harness.mjs';
import { catalog, translate } from '../resources/js/i18n.js';
import { durationLabel, durationSummary } from '../resources/js/i18n-durations.js';

after(closeAll);

const ENTRY = 'tests/helpers/durations-entry.jsx';
const CAPS = { 'platform.plans.manage': true };

const monthly = { id: 1, key: 'monthly', name_ar: 'شهري', name_en: 'Monthly', description_ar: null, description_en: null, category: 'paid', unit: 'month', value: 1, sort_order: 10, is_active: true, is_system: true, is_lifetime: false, version: 1 };
const quarter = { id: 5, key: 'paid_3m', name_ar: 'ربع سنوي', name_en: 'Quarterly', description_ar: null, description_en: null, category: 'paid', unit: 'month', value: 3, sort_order: 20, is_active: true, is_system: false, is_lifetime: false, version: 2 };
const trial14 = { id: 7, key: 'trial_14d', name_ar: 'تجربة 14 يومًا', name_en: 'Trial 14 days', description_ar: null, description_en: null, category: 'trial', unit: 'day', value: 14, sort_order: 30, is_active: true, is_system: false, is_lifetime: false, version: 1 };
const lifetime = { id: 4, key: 'lifetime', name_ar: 'مدى الحياة', name_en: 'Lifetime', description_ar: null, description_en: null, category: 'paid', unit: 'lifetime', value: null, sort_order: 40, is_active: true, is_system: true, is_lifetime: true, version: 1 };
const archived = { ...quarter, id: 9, key: 'paid_9m', name_ar: 'تسعة أشهر', name_en: 'Nine months', value: 9, is_active: false };

const meta = { categories: ['trial', 'test', 'paid'], units: ['day', 'week', 'month', 'year', 'lifetime'], max_value: { day: 3650, week: 520, month: 120, year: 50 }, non_paid_units: ['day', 'week', 'month'] };
const rows = [
    { ...monthly, plans_count: 3, active_plans_count: 2, subscriptions_count: 9, active_plans: [{ id: 1, name: 'Basic' }, { id: 2, name: 'Pro' }], immutable: 'system' },
    { ...quarter, plans_count: 1, active_plans_count: 1, subscriptions_count: 0, active_plans: [{ id: 3, name: 'Quarter plan' }], immutable: 'referenced' },
    { ...trial14, plans_count: 0, active_plans_count: 0, subscriptions_count: 0, active_plans: [], immutable: null },
    { ...archived, plans_count: 0, active_plans_count: 0, subscriptions_count: 0, active_plans: [], immutable: null },
];

const plans = [
    { id: 1, code: 'basic', name: 'Basic', price: 100, is_active: true, duration_info: monthly, subscriptions_count: 4, limits: {} },
    { id: 2, code: 'free', name: 'Free', price: 0, is_active: true, duration_info: trial14, subscriptions_count: 0, limits: {} },
];

const fail = (status, data = {}) => Object.assign(new Error('Request failed'), { response: { status, data } });
const mutations = (scene) => scene.requests.filter((request) => request.method !== 'get');
const confirm = (scene) => scene.$('button[type="submit"][form="confirm-form"]');
const buttonByText = (scene, text) => scene.$$('button').find((button) => button.textContent.trim() === text);

async function open(page, data, { locale = 'en', handler = async () => ({ data: { ok: true } }) } = {}) {
    const scene = await createScene({ entry: ENTRY, locale, handler, pageProps: { capabilities: CAPS, navigationCapabilities: { 'platform.plans.manage': true }, auth: { user: { name: 'Root Admin' } } } });
    await scene.mount({ page, data });
    return scene;
}

const openDurations = (options = {}, locale = 'en') => open('durations', { durations: rows, filters: {}, meta, ...options }, { locale, handler: options.handler });
const openPlans = (options = {}, locale = 'en') => open('plans', { plans, coupons: [], durations: [monthly, quarter, trial14, lifetime], ...options }, { locale, handler: options.handler });

test('durationSummary uses grammatical Arabic and English forms without exposing raw values', () => {
    assert.equal(durationSummary('en', { unit: 'month', value: 1 }), '1 month');
    assert.equal(durationSummary('en', { unit: 'month', value: 6 }), '6 months');
    assert.equal(durationSummary('ar', { unit: 'month', value: 1 }), 'شهر واحد');
    assert.equal(durationSummary('ar', { unit: 'month', value: 2 }), 'شهران');
    assert.equal(durationSummary('ar', { unit: 'month', value: 3 }), '3 أشهر');
    assert.equal(durationSummary('ar', { unit: 'month', value: 11 }), '11 شهرًا');
    assert.equal(durationSummary('ar', { unit: 'year', value: 2 }), 'سنتان');
    assert.equal(durationSummary('ar', { unit: 'lifetime', value: null }), 'مدى الحياة');
    assert.equal(durationSummary('en', { unit: 'day', value: null }), '—');
});

test('durationLabel falls back to the other language and then the stable key', () => {
    assert.equal(durationLabel('ar', quarter), 'ربع سنوي');
    assert.equal(durationLabel('en', quarter), 'Quarterly');
    assert.equal(durationLabel('en', { key: 'x', name_ar: 'س', name_en: '' }), 'س');
    assert.equal(durationLabel('en', { key: 'only_key' }), 'only_key');
    assert.equal(durationLabel('en', null), '—');
});

test('every dur_* key exists in both languages', () => {
    const en = Object.keys(catalog.en).filter((key) => key.startsWith('dur_'));
    const ar = Object.keys(catalog.ar).filter((key) => key.startsWith('dur_'));
    assert.ok(en.length > 40);
    assert.deepEqual(en.sort(), ar.sort());
    for (const key of en) assert.notEqual(catalog.ar[key], catalog.en[key], `${key} must be translated`);
});

test('the durations page renders catalog rows in English without raw ids, ISO dates or JSON', async () => {
    const scene = await openDurations();
    const text = scene.text();
    assert.match(text, /Subscription durations/);
    assert.match(text, /Monthly/);
    assert.match(text, /3 months/);
    assert.match(text, /14 days/);
    assert.match(text, /Lifetime|Archived/);
    assert.doesNotMatch(text, /\[object Object\]|undefined|null|\d{4}-\d{2}-\d{2}T/);
    assert.equal(scene.document.documentElement.dir, 'ltr');
    assert.deepEqual(scene.errors, []);
    scene.close();
});

test('the durations page renders in Arabic RTL with Arabic labels first', async () => {
    const scene = await openDurations({}, 'ar');
    const text = scene.text();
    assert.equal(scene.document.documentElement.dir, 'rtl');
    assert.match(text, /ربع سنوي/);
    assert.match(text, /3 أشهر/);
    assert.match(text, /شهر واحد/);
    assert.deepEqual(scene.errors, []);
    scene.close();
});

test('system and referenced durations lock the interval and explain why', async () => {
    const scene = await openDurations();
    const editButtons = scene.$$('tbody tr').map((row) => [...row.querySelectorAll('button')].find((button) => button.textContent.trim() === translate('en', 'dur_edit')));
    await scene.click(editButtons[0]);
    assert.ok(scene.$('[role="dialog"] select[name="category"]').disabled);
    assert.ok(scene.$('[role="dialog"] select[name="unit"]').disabled);
    assert.ok(scene.$('[role="dialog"] input[name="value"]').disabled);
    assert.match(scene.$('[role="dialog"]').textContent, new RegExp(translate('en', 'dur_immutable_system').slice(0, 20)));
    scene.close();

    const second = await openDurations();
    const buttons = second.$$('tbody tr').map((row) => [...row.querySelectorAll('button')].find((button) => button.textContent.trim() === translate('en', 'dur_edit')));
    await second.click(buttons[1]);
    assert.match(second.$('[role="dialog"]').textContent, new RegExp(translate('en', 'dur_immutable_referenced').slice(0, 20)));
    second.close();

    const third = await openDurations();
    const free = third.$$('tbody tr').map((row) => [...row.querySelectorAll('button')].find((button) => button.textContent.trim() === translate('en', 'dur_edit')));
    await third.click(free[2]);
    assert.equal(third.$('[role="dialog"] input[name="value"]').disabled, false);
    third.close();
});

test('editing sends the version and the reason, and never the key', async () => {
    const scene = await openDurations();
    const edit = scene.$$('tbody tr')[2].querySelector('button');
    await scene.click(edit);
    await scene.setValue(scene.$('[role="dialog"] input[name="value"]'), '21', 'input');
    await scene.setValue(scene.$('#confirm-form textarea'), 'Longer trial', 'input');
    await scene.click(confirm(scene));
    const [request] = mutations(scene);
    assert.equal(request.method, 'put');
    assert.equal(request.url, '/super-admin/durations/7');
    assert.equal(request.data.version, 1);
    assert.equal(request.data.value, 21);
    assert.equal(request.data.reason, 'Longer trial');
    assert.ok(!('key' in request.data));
    assert.ok(!('is_system' in request.data));
    scene.close();
});

test('creating a duration shows a live preview, posts the typed values and surfaces validation text', async () => {
    const handler = async (request) => { if (request.method === 'post') throw fail(422, { errors: { name_en: ['This name is already used by an active duration.'] } }); return { data: {} }; };
    const scene = await openDurations({ handler });
    const form = scene.$('form[aria-labelledby="create-duration-title"]');
    await scene.setValue(form.querySelector('input[lang="ar"]'), 'ربع سنوي جديد', 'input');
    await scene.setValue(form.querySelector('input[lang="en"]'), 'Quarterly', 'input');
    await scene.setValue(form.querySelector('select[name="unit"]'), 'month');
    await scene.setValue(form.querySelector('input[name="value"]'), '3', 'input');
    assert.match(form.textContent, /Expiry = start date \+ 3 months/);
    await scene.click(form.querySelector('button[type="submit"]'));
    const [request] = mutations(scene);
    assert.equal(request.url, '/super-admin/durations');
    assert.equal(request.data.unit, 'month');
    assert.equal(request.data.value, 3);
    assert.equal(request.data.category, 'paid');
    assert.match(scene.text(), /already used/);
    scene.close();
});

test('a trial or test category only offers day, week and month units', async () => {
    const scene = await openDurations();
    const form = scene.$('form[aria-labelledby="create-duration-title"]');
    await scene.setValue(form.querySelector('select[name="category"]'), 'trial');
    const units = [...form.querySelectorAll('select[name="unit"] option')].map((option) => option.value);
    assert.deepEqual(units, ['day', 'week', 'month']);
    scene.close();
});

test('archiving a duration used by active plans lists them and requires explicit confirmation', async () => {
    const scene = await openDurations();
    const archive = scene.$$('tbody tr')[0].querySelectorAll('button')[1];
    await scene.click(archive);
    const dialog = scene.$('[role="dialog"]');
    assert.match(dialog.textContent, /Basic/);
    assert.match(dialog.textContent, /Pro/);
    assert.equal(confirm(scene).disabled, true);
    await scene.click(dialog.querySelector('input[type="checkbox"]'));
    assert.equal(confirm(scene).disabled, false);
    await scene.setValue(scene.$('#confirm-form textarea'), 'Retire', 'input');
    await scene.click(confirm(scene));
    const [request] = mutations(scene);
    assert.equal(request.url, '/super-admin/durations/1/deactivate');
    assert.equal(request.data.confirm_affected, true);
    scene.close();
});

test('403, 409 and 500 failures show localized text, never raw server output', async () => {
    for (const [status, key] of [[403, 'pf_err_403'], [409, 'pf_err_409'], [500, 'pf_err_500']]) {
        const handler = async (request) => { if (request.method !== 'get') throw fail(status, status === 500 ? { message: 'SQLSTATE[HY000] secret' } : {}); return { data: {} }; };
        const scene = await openDurations({ handler });
        await scene.click(scene.$$('tbody tr')[2].querySelectorAll('button')[1]);
        await scene.setValue(scene.$('#confirm-form textarea'), 'Retire', 'input');
        await scene.click(confirm(scene));
        assert.match(scene.$('[role="dialog"]').textContent, new RegExp(translate('en', key).slice(0, 15)));
        assert.doesNotMatch(scene.text(), /SQLSTATE|secret/);
        scene.close();
    }
});

test('the durations page is usable at mobile width without errors', async () => {
    const scene = await openDurations();
    scene.window.innerWidth = 360;
    scene.window.dispatchEvent(new scene.window.Event('resize'));
    await scene.flush();
    assert.ok(scene.$('.erp-table-wrap'), 'tables stay inside a scroll wrapper');
    assert.deepEqual(scene.errors, []);
    scene.close();
});

test('the plan form offers exactly the active catalog durations supplied by the server', async () => {
    const scene = await openPlans();
    const select = scene.$('form select[name="subscription_duration_id"]');
    const options = [...select.querySelectorAll('option')].map((option) => option.value).filter(Boolean);
    assert.deepEqual(options, ['1', '5', '7', '4']);
    assert.ok(!options.includes('9'), 'archived durations are not offered to new plans');
    assert.match(select.textContent, /Quarterly — 3 months/);
    assert.equal(scene.$('input[name="duration_days"]'), null);
    assert.deepEqual(scene.errors, []);
    scene.close();
});

test('choosing a duration only previews it and sends no request', async () => {
    const scene = await openPlans();
    await scene.setValue(scene.$('form select[name="subscription_duration_id"]'), '7');
    assert.equal(scene.requests.length, 0);
    assert.equal(scene.inertia.length, 0);
    const text = scene.text();
    assert.match(text, /Expiry = start date \+ 14 days/);
    assert.match(text, /trial period ends in/i);
    scene.close();
});

test('creating a plan posts the catalog id and never a free duration or day count', async () => {
    const scene = await openPlans();
    const form = scene.$('form select[name="subscription_duration_id"]').closest('form');
    const inputs = form.querySelectorAll('input');
    await scene.setValue(inputs[0], 'quarter', 'input');
    await scene.setValue(inputs[1], 'Quarter plan', 'input');
    await scene.setValue(form.querySelector('select[name="subscription_duration_id"]'), '5');
    await scene.click(form.querySelector('button[type="submit"]'));
    const [request] = mutations(scene);
    assert.equal(request.method, 'post');
    assert.equal(request.url, '/super-admin/plans');
    assert.equal(request.data.subscription_duration_id, '5');
    assert.ok(!('duration' in request.data));
    assert.ok(!('duration_days' in request.data));
    scene.close();
});

test('a plan with subscriptions locks its duration while one without can change it', async () => {
    const scene = await openPlans();
    await scene.click(scene.$$('tbody tr')[0].querySelector('button'));
    const select = scene.$('[role="dialog"] select[name="subscription_duration_id"]');
    assert.equal(select.disabled, true);
    assert.match(scene.$('[role="dialog"]').textContent, new RegExp(translate('en', 'dur_plan_locked').slice(0, 25)));
    await scene.setValue(scene.$('#confirm-form textarea'), 'Rename', 'input');
    await scene.click(confirm(scene));
    const [request] = mutations(scene);
    assert.equal(request.method, 'put');
    assert.equal(request.url, '/super-admin/plans/1');
    assert.ok(!('subscription_duration_id' in request.data), 'locked duration is not sent');
    scene.close();

    const free = await openPlans();
    await free.click(free.$$('tbody tr')[1].querySelector('button'));
    assert.equal(free.$('[role="dialog"] select[name="subscription_duration_id"]').disabled, false);
    free.close();
});

test('the plans table shows catalog labels in Arabic', async () => {
    const scene = await openPlans({}, 'ar');
    const text = scene.text();
    assert.match(text, /شهري/);
    assert.match(text, /شهر واحد/);
    assert.match(text, /تجربة 14 يومًا/);
    assert.doesNotMatch(text, /undefined|\[object Object\]/);
    scene.close();
});

test('no page keeps the old fixed duration list', () => {
    for (const file of ['resources/js/Pages/SuperAdmin/Plans.jsx', 'resources/js/Pages/SuperAdmin/Companies.jsx', 'resources/js/Pages/Auth/Register.jsx', 'resources/js/Components/Platform/PlanChangeDialog.jsx']) {
        const source = readSource(file);
        assert.doesNotMatch(source, /const DURATIONS\s*=/, `${file} must not hard-code durations`);
        assert.doesNotMatch(source, /pf_duration_\$\{/, `${file} must use catalog labels`);
    }
});
