import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import { closeAll, createScene, readSource } from './helpers/dom-harness.mjs';
import { failureKey, findPlan, normalizePlanPreview, planChangeView, sameId } from '../resources/js/Components/Platform/planChange.js';

after(closeAll);

const ENTRY = 'tests/helpers/plan-dialog-entry.jsx';
const subscription = { id: 11, company_id: 7, company: 'Lapsed Co', plan: 'Plan 1' };
const plans = [{ id: 2, name: 'Plan 2', price: 20, duration: 'monthly' }, { id: 3, name: 'Plan 3', price: 30, duration: 'yearly' }, { id: 4, name: 'Plan 4', price: 40 }];

const preview = (over = {}) => ({ action: 'plan', same_plan: false, inactive: false, from: 'Plan 1', to: 'Plan 3', ends_at: '2026-10-07T00:00:00Z', usage: { users: { usage: 1, limit: null }, branches: { usage: 0, limit: null }, warehouses: { usage: 0, limit: null } }, over_limit: [], ...over });
const fail = (status, data = {}) => Object.assign(new Error('Request failed'), { response: { status, data } });

function handlerFor(overrides = {}) {
    return async (request) => {
        const key = `${request.method} ${request.url}`;
        if (overrides[key]) return overrides[key](request);
        if (request.method === 'get' && request.url === '/super-admin/subscriptions/preview') return { data: preview({ to: `Plan ${request.params.plan_id}` }) };
        if (request.method === 'post') return { data: { ok: true } };
        throw fail(404);
    };
}

async function open({ locale = 'en', overrides = {}, props = {} } = {}) {
    const scene = await createScene({ entry: ENTRY, locale, handler: handlerFor(overrides) });
    await scene.mount({ subscription, plans, ...props });
    return scene;
}

const dialog = (scene) => scene.$('[role="dialog"]');
const select = (scene) => scene.$('select[name="plan_id"]');
const confirm = (scene) => scene.$('button[type="submit"][form="confirm-form"]');
const mutations = (scene) => scene.requests.filter((request) => request.method !== 'get');

async function choose(scene, id) {
    await scene.setValue(select(scene), String(id));
}

async function submit(scene, reason = 'Customer upgrade') {
    await scene.setValue(scene.$('#confirm-form textarea'), reason, 'input');
    await scene.click(confirm(scene));
}

test('opening the modal keeps the page rendered and sends no request', async () => {
    const scene = await open();
    assert.ok(dialog(scene));
    assert.match(scene.text(), /PAGE-STILL-HERE/);
    assert.ok(select(scene));
    assert.equal(confirm(scene).disabled, true, 'confirm is disabled until a plan is chosen');
    assert.equal(scene.requests.length, 0);
    assert.deepEqual(scene.errors, []);
    scene.close();
});

test('selecting a plan does not blank the page, only previews via GET and never mutates', async () => {
    const scene = await open();
    await choose(scene, 3);
    assert.deepEqual(scene.errors, [], 'no render exception when the selection changes');
    assert.match(scene.text(), /PAGE-STILL-HERE/);
    assert.ok(dialog(scene), 'modal header, body and footer stay visible');
    assert.ok(scene.$('.modal-header') && scene.$('.modal-footer'));
    assert.deepEqual(scene.requests.map((request) => `${request.method} ${request.url}`), ['get /super-admin/subscriptions/preview']);
    assert.deepEqual(scene.requests[0].params, { company_id: 7, action: 'plan', plan_id: 3 });
    assert.equal(mutations(scene).length, 0);
    assert.equal(scene.inertia.length, 0, 'no Inertia navigation or reload during selection');
    assert.match(dialog(scene).textContent, /Plan 1 → Plan 3/);
    assert.equal(confirm(scene).disabled, false);
    scene.close();
});

test('the first render after a selection (before the preview effect runs) never dereferences missing data', async () => {
    const scene = await createScene({ entry: ENTRY, handler: () => new Promise(() => {}) });
    await scene.mount({ subscription, plans });
    await choose(scene, 2);
    assert.deepEqual(scene.errors, []);
    assert.match(dialog(scene).textContent, /Loading/);
    assert.equal(confirm(scene).disabled, true);
    scene.close();
});

test('string and numeric plan ids are normalised without altering the submitted value', async () => {
    assert.equal(sameId(3, '3'), true);
    assert.equal(sameId('3', 3), true);
    assert.equal(sameId(null, ''), false);
    assert.equal(findPlan(plans, '3').name, 'Plan 3');

    const numeric = await open();
    await choose(numeric, 3);
    await submit(numeric);
    assert.strictEqual(mutations(numeric)[0].data.plan_id, 3);
    numeric.close();

    const stringIds = await open({ props: { plans: plans.map((plan) => ({ ...plan, id: String(plan.id) })) } });
    await choose(stringIds, 3);
    assert.match(dialog(stringIds).textContent, /Plan 1 → Plan 3/);
    await submit(stringIds);
    assert.strictEqual(mutations(stringIds)[0].data.plan_id, '3');
    stringIds.close();
});

test('a plan that disappears after selection renders a safe message and blocks confirmation', async () => {
    const scene = await open();
    await choose(scene, 3);
    await scene.update({ subscription, plans: plans.filter((plan) => plan.id !== 3) });
    assert.deepEqual(scene.errors, []);
    assert.match(dialog(scene).textContent, /no longer available/);
    assert.equal(confirm(scene).disabled, true);
    scene.close();
});

test('an empty plan list renders an empty state and disables the control', async () => {
    const scene = await open({ props: { plans: [] } });
    assert.deepEqual(scene.errors, []);
    assert.match(dialog(scene).textContent, /no active plans/i);
    assert.equal(select(scene).disabled, true);
    assert.equal(confirm(scene).disabled, true);
    scene.close();
});

test('plans with missing optional fields still render', async () => {
    const scene = await open({ props: { plans: [{ id: 9, name: 'Bare' }] } });
    await choose(scene, 9);
    assert.deepEqual(scene.errors, []);
    assert.match(dialog(scene).textContent, /Bare/);
    scene.close();
});

test('confirm stays disabled for same-plan, over-limit and failed previews', async () => {
    const sameScene = await open({ overrides: { 'get /super-admin/subscriptions/preview': async () => ({ data: preview({ same_plan: true }) }) } });
    await choose(sameScene, 2);
    assert.equal(confirm(sameScene).disabled, true);
    assert.match(dialog(sameScene).textContent, /already on this plan/);
    sameScene.close();

    const over = await open({ overrides: { 'get /super-admin/subscriptions/preview': async () => ({ data: preview({ over_limit: ['users'], usage: { users: { usage: 5, limit: 2 } } }) }) } });
    await choose(over, 2);
    assert.equal(confirm(over).disabled, true);
    assert.match(dialog(over).textContent, /Current usage exceeds/);
    over.close();

    const broken = await open({ overrides: { 'get /super-admin/subscriptions/preview': async () => { throw fail(500); } } });
    await choose(broken, 2);
    assert.deepEqual(broken.errors, []);
    assert.equal(confirm(broken).disabled, true);
    assert.match(broken.text(), /PAGE-STILL-HERE/);
    assert.match(dialog(broken).textContent, /impact preview could not be loaded/);
    broken.close();
});

test('a malformed preview payload is treated as a recoverable error', async () => {
    const scene = await open({ overrides: { 'get /super-admin/subscriptions/preview': async () => ({ data: 'not-json' }) } });
    await choose(scene, 2);
    assert.deepEqual(scene.errors, []);
    assert.equal(confirm(scene).disabled, true);
    scene.close();
});

test('cancel closes the modal without any mutation', async () => {
    const scene = await open();
    await choose(scene, 3);
    await scene.click(scene.$$('.modal-footer button').find((button) => button.textContent.trim() === 'Cancel'));
    assert.equal(dialog(scene), null);
    assert.match(scene.text(), /PAGE-STILL-HERE/);
    assert.equal(mutations(scene).length, 0);
    scene.close();
});

test('confirm sends exactly one mutation, reloads data in place and keeps the lapsed filter', async () => {
    const scene = await open();
    await choose(scene, 3);
    await submit(scene);
    assert.deepEqual(mutations(scene).map((request) => [request.url, request.data]), [['/super-admin/companies/7/plan', { plan_id: 3, reason: 'Customer upgrade' }]]);
    assert.equal(dialog(scene), null);
    assert.deepEqual(scene.inertia.map(([kind]) => kind), ['reload'], 'only an in-place Inertia reload, no navigation');
    assert.equal(scene.inertia[0][1].preserveScroll, true);
    assert.equal(scene.window.location.search, '?state=lapsed');
    assert.match(scene.text(), /Saved\./);
    scene.close();
});

test('double clicking confirm does not duplicate the request', async () => {
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const scene = await open({ overrides: { 'post /super-admin/companies/7/plan': async () => { await gate; return { data: { ok: true } }; } } });
    await choose(scene, 3);
    await scene.setValue(scene.$('#confirm-form textarea'), 'Customer upgrade', 'input');
    await scene.click(confirm(scene), 2);
    assert.equal(mutations(scene).length, 1);
    release();
    await scene.flush();
    assert.equal(mutations(scene).length, 1);
    scene.close();
});

test('423 opens the step-up dialog, then retries once after verification', async () => {
    let attempts = 0;
    const scene = await open({ overrides: {
        'post /super-admin/companies/7/plan': async () => {
            attempts += 1;
            if (attempts === 1) throw fail(423, { code: 'step_up_required', requires_mfa: false });
            return { data: { ok: true } };
        },
    } });
    await choose(scene, 3);
    await submit(scene);
    assert.match(scene.text(), /PAGE-STILL-HERE/);
    assert.match(scene.text(), /Confirm your identity/);
    assert.deepEqual(scene.errors, []);
    const password = scene.$('#stepup-form input[type="password"]');
    await scene.setValue(password, 'Correct-horse-1', 'input');
    await scene.click(scene.$('button[type="submit"][form="stepup-form"]'));
    assert.deepEqual(scene.requests.filter((request) => request.method === 'post').map((request) => request.url), ['/super-admin/companies/7/plan', '/super-admin/step-up', '/super-admin/companies/7/plan']);
    assert.equal(dialog(scene), null);
    scene.close();
});

test('cancelling the step-up dialog leaves the confirmation open and mutation-free', async () => {
    const scene = await open({ overrides: { 'post /super-admin/companies/7/plan': async () => { throw fail(423, { code: 'step_up_required' }); } } });
    await choose(scene, 3);
    await submit(scene);
    const cancel = scene.$$('.modal-footer button').filter((button) => button.textContent.trim() === 'Cancel').pop();
    await scene.click(cancel);
    assert.ok(scene.$('#confirm-form'), 'the confirmation dialog is still open');
    assert.equal(scene.requests.filter((request) => request.url.endsWith('/plan')).length, 1);
    assert.doesNotMatch(scene.text(), /cancelled/i);
    scene.close();
});

for (const [status, data, pattern] of [
    [403, {}, /do not have permission/],
    [409, { message: 'Restore the company before changing its plan.' }, /conflicts with the current state.*Restore the company/],
    [419, {}, /session expired/],
    [422, { errors: { plan_id: ['The company is already on this plan.'] } }, /already on this plan/],
    [500, { message: 'SQLSTATE secret detail' }, /could not complete the request/],
]) {
    test(`a ${status} response is shown inside the dialog without blanking the page`, async () => {
        const scene = await open({ overrides: { 'post /super-admin/companies/7/plan': async () => { throw fail(status, data); } } });
        await choose(scene, 3);
        await submit(scene);
        assert.deepEqual(scene.errors, []);
        assert.match(scene.text(), /PAGE-STILL-HERE/);
        assert.ok(dialog(scene), 'the dialog stays open so the administrator can recover');
        assert.match(scene.$('#confirm-form [role="alert"]').textContent, pattern);
        assert.doesNotMatch(scene.text(), /SQLSTATE/, 'server internals are never rendered');
        assert.equal(confirm(scene).disabled, false, 'loading state is reset after the failure');
        if (status === 419) {
            await scene.click(scene.$$('#confirm-form button').find((button) => /Reload data/.test(button.textContent)));
            assert.deepEqual(scene.inertia.map(([kind]) => kind), ['reload']);
        }
        scene.close();
    });
}

test('a network failure is localized and recoverable', async () => {
    const scene = await open({ overrides: { 'post /super-admin/companies/7/plan': async () => { throw Object.assign(new Error('Network Error'), {}); } } });
    await choose(scene, 3);
    await submit(scene);
    assert.match(scene.$('#confirm-form [role="alert"]').textContent, /could not be reached/);
    scene.close();
});

test('a child that throws is contained inside the dialog', async () => {
    const scene = await createScene({ entry: ENTRY, handler: handlerFor() });
    await scene.mount({ subscription, plans, mode: 'throw' });
    assert.match(scene.text(), /PAGE-STILL-HERE/);
    assert.ok(scene.$('.modal-header') && scene.$('.modal-footer'));
    assert.match(dialog(scene).textContent, /could not be displayed/);
    scene.close();
});

test('Arabic (RTL) and English (LTR) both render the localized flow', async () => {
    const ar = await open({ locale: 'ar' });
    assert.equal(ar.document.documentElement.dir, 'rtl');
    assert.match(dialog(ar).textContent, /تغيير الخطة/);
    await choose(ar, 3);
    assert.deepEqual(ar.errors, []);
    assert.match(dialog(ar).textContent, /الاستخدام الحالي/);
    ar.close();

    const en = await open({ locale: 'en' });
    assert.equal(en.document.documentElement.dir, 'ltr');
    assert.match(dialog(en).textContent, /Change plan/);
    en.close();
});

test('the narrow viewport keeps the same markup (no viewport-specific branches)', async () => {
    const scene = await open();
    scene.window.innerWidth = 360;
    await choose(scene, 3);
    assert.ok(dialog(scene));
    assert.equal(confirm(scene).disabled, false);
    scene.close();
});

test('planChangeView and failureKey cover every state and status', () => {
    assert.equal(planChangeView({ planId: '', plans, preview: { loading: false } }).kind, 'choose');
    assert.equal(planChangeView({ planId: '2', plans: [], preview: null }).kind, 'empty');
    assert.equal(planChangeView({ planId: '99', plans, preview: null }).kind, 'stale');
    assert.equal(planChangeView({ planId: '2', plans, preview: { loading: true } }).kind, 'loading');
    assert.equal(planChangeView({ planId: '2', plans, preview: { loading: false, error: 'failed', status: 403 } }).kind, 'error');
    assert.equal(planChangeView({ planId: '2', plans, preview: { loading: false, data: null, error: '' } }).kind, 'error');
    assert.equal(planChangeView({ planId: '2', plans, preview: { loading: false, data: preview() } }).blocked, false);
    assert.equal(normalizePlanPreview(null), null);
    assert.deepEqual(normalizePlanPreview({}).over_limit, []);
    assert.deepEqual([403, 409, 419, 422, 423, 429, 500, 503, undefined].map(failureKey), ['pf_err_403', 'pf_err_409', 'pf_err_419', 'pf_err_422', 'pf_err_423', 'pf_err_429', 'pf_err_500', 'pf_err_500', 'pf_err_network']);
});

test('source guards: no unguarded preview dereference, no hard navigation on selection, explicit button types', () => {
    const dialogSource = readSource('resources/js/Components/Platform/PlanChangeDialog.jsx');
    assert.doesNotMatch(dialogSource, /window\.location|router\.(visit|post|put|reload|get)/);
    assert.match(dialogSource, /onChange=\{\(event\) => setPlanId\(event\.target\.value\)\}/);
    const hook = readSource('resources/js/Components/Platform/ui.jsx');
    assert.match(hook, /settled = enabled && state\.query === query/);
    for (const file of ['Admins', 'Audit', 'Companies', 'MfaChallenge', 'Plans', 'Security', 'Subscriptions']) {
        const source = readSource(`resources/js/Pages/SuperAdmin/${file}.jsx`);
        assert.doesNotMatch(source, /<button(?![^>]*\btype=)/, `${file} has a button without an explicit type`);
    }
    const companies = readSource('resources/js/Pages/SuperAdmin/Companies.jsx');
    assert.match(companies, /<\/form>\s*\{api\.stepUpDialog\}/, 'the step-up dialog must not be nested inside the create-company form');
});
