import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import { closeAll, createScene, readSource } from './helpers/dom-harness.mjs';
import { catalog } from '../resources/js/i18n.js';
import { dayPhrase, pluralCategory, subscriptionNoticeMessage } from '../resources/js/i18n-subscription.js';

after(closeAll);

const ENTRY = 'tests/helpers/subscription-entry.jsx';
const notice = (over = {}) => ({ state: 'active', category: 'trial', access: 'warning', show: true, key: 'trial.ends', params: { days: 3 }, severity: 'warning', ends_at: '2026-10-20T07:00:00+00:00', ends_on: '2026-10-20', timezone: 'Africa/Cairo', days_remaining: 3, days_since_end: null, ...over });
const capabilities = { 'subscriptions.view': true };

async function banner(props, locale = 'en') {
    const scene = await createScene({ entry: ENTRY, locale, pageUrl: '/dashboard', pageProps: { navigationCapabilities: capabilities } });
    await scene.mount({ mode: 'banner', locale, ...props });
    return scene;
}

async function page({ locale = 'en', subscriptionNotice = notice(), caps = capabilities, subscription = { plan: { name: 'Starter', duration: 'trial' } } } = {}) {
    const scene = await createScene({ entry: ENTRY, locale, pageUrl: '/subscription', pageProps: { subscriptionNotice, navigationCapabilities: caps, capabilities: caps, auth: { user: { name: 'Owner', company: { name: 'Acme' } } } } });
    await scene.mount({ mode: 'page', subscription, status: subscriptionNotice?.state });
    return scene;
}

const text = (scene) => scene.$('.subscription-notice')?.textContent ?? '';

test('Arabic trial notice shows the real count with correct grammar', () => {
    assert.equal(subscriptionNoticeMessage('ar', notice({ params: { days: 3 } })), 'تنتهي الفترة التجريبية خلال 3 أيام.');
    assert.equal(subscriptionNoticeMessage('ar', notice({ params: { days: 1 } })), 'تنتهي الفترة التجريبية خلال يوم واحد.');
    assert.equal(subscriptionNoticeMessage('ar', notice({ params: { days: 10 } })), 'تنتهي الفترة التجريبية خلال 10 أيام.');
});

test('English trial notice shows the real count', () => {
    assert.equal(subscriptionNoticeMessage('en', notice({ params: { days: 3 } })), 'Your trial period ends in 3 days.');
    assert.equal(subscriptionNoticeMessage('en', notice({ params: { days: 1 } })), 'Your trial period ends in 1 day.');
});

test('Arabic and English plural forms cover every count class', () => {
    assert.deepEqual([1, 2, 3, 10, 11, 25, 99, 100].map((days) => dayPhrase('ar', days)), ['يوم واحد', 'يومين', '3 أيام', '10 أيام', '11 يومًا', '25 يومًا', '99 يومًا', '100 يوم']);
    assert.deepEqual([1, 2, 7, 30].map((days) => dayPhrase('en', days)), ['1 day', '2 days', '7 days', '30 days']);
    assert.equal(pluralCategory('ar', 2), 'two');
    assert.equal(pluralCategory('ar', 5), 'few');
    assert.equal(pluralCategory('ar', 12), 'many');
    assert.equal(pluralCategory('en', 1), 'one');
});

test('trial, test, paid, grace, read-only, expired and lifetime states use their own wording', () => {
    const cases = [
        [{ category: 'test', key: 'test.ends', params: { days: 5 } }, 'Your test period ends in 5 days.', 'تنتهي فترة الاختبار خلال 5 أيام.'],
        [{ category: 'paid', key: 'paid.ends', params: { days: 2 } }, 'Your subscription ends in 2 days.', 'ينتهي اشتراكك خلال يومين.'],
        [{ category: 'paid', key: 'paid.today', params: {} }, 'Your subscription ends in less than one day.', 'ينتهي اشتراكك خلال أقل من يوم واحد.'],
        [{ state: 'grace', category: 'paid', key: 'paid.grace', params: { days: 2 } }, 'Your subscription has expired and a grace period applies. Full access continues for 2 days.', 'انتهى الاشتراك، وتوجد فترة سماح يستمر خلالها الوصول الكامل لمدة يومين.'],
        [{ state: 'read_only', category: 'paid', key: 'paid.read_only', params: {} }, 'Your subscription has expired and the system is currently in read-only mode.', 'انتهى الاشتراك، والنظام يعمل حاليًا في وضع القراءة فقط.'],
        [{ state: 'suspended', category: 'paid', key: 'paid.expired', params: { days: 40 } }, 'Your subscription expired 40 days ago.', 'انتهى الاشتراك منذ 40 يومًا.'],
        [{ state: 'suspended', category: 'trial', key: 'trial.expired', params: { days: 11 } }, 'Your trial period ended 11 days ago.', 'انتهت الفترة التجريبية منذ 11 يومًا.'],
    ];
    for (const [over, en, ar] of cases) {
        assert.equal(subscriptionNoticeMessage('en', notice(over)), en);
        assert.equal(subscriptionNoticeMessage('ar', notice(over)), ar);
    }
    assert.equal(subscriptionNoticeMessage('en', notice({ category: 'lifetime', show: false, key: null })), null, 'lifetime has no expiry warning');
    assert.doesNotMatch(subscriptionNoticeMessage('en', notice({ category: 'trial', key: 'trial.ends' })), /subscription/i, 'a trial never says subscription');
});

test('invalid server params never produce a made-up count', () => {
    for (const params of [{}, { days: 0 }, { days: -2 }, { days: 1.5 }, { days: '3' }]) {
        assert.equal(subscriptionNoticeMessage('en', notice({ params })), 'The subscription status is unavailable right now. Try again later.');
    }
    assert.equal(subscriptionNoticeMessage('en', notice({ key: 'unknown.key' })), 'The subscription status is unavailable right now. Try again later.');
    assert.equal(subscriptionNoticeMessage('en', null), null);
    assert.equal(subscriptionNoticeMessage('en', notice({ show: false })), null);
});

test('the banner renders the server notice with a text badge and no raw timestamps', async () => {
    const scene = await banner({ notice: notice(), canView: true });
    assert.match(text(scene), /Your trial period ends in 3 days\./);
    assert.match(scene.$('.subscription-notice__badge').textContent, /Trial period/);
    assert.doesNotMatch(scene.text(), /2026-10-20T|\+00:00|T07:00/);
    assert.equal(scene.$('.subscription-notice').getAttribute('role'), 'status');
    scene.close();
});

test('the banner is silent for hidden notices, lifetime plans and missing data', async () => {
    for (const value of [null, undefined, notice({ show: false }), notice({ category: 'lifetime', show: false, key: null })]) {
        const scene = await banner({ notice: value });
        assert.equal(scene.$('.subscription-notice'), null);
        scene.close();
    }
});

test('the loading state shows no count and the failed state shows no false number', async () => {
    const loading = await banner({ notice: notice(), loading: true });
    assert.match(text(loading), /Loading subscription status/);
    assert.doesNotMatch(text(loading), /\d/);
    loading.close();

    const failed = await banner({ notice: notice(), failed: true });
    assert.match(text(failed), /unavailable right now/);
    assert.doesNotMatch(text(failed), /\d/);
    failed.close();

    const arabic = await banner({ notice: notice(), failed: true }, 'ar');
    assert.match(text(arabic), /غير متاحة/);
    assert.doesNotMatch(text(arabic), /[0-9٠-٩]/);
    arabic.close();
});

test('the view-subscription action follows permission and page', async () => {
    const allowed = await banner({ notice: notice(), canView: true });
    assert.equal(allowed.$('.subscription-notice a').getAttribute('href'), '/subscription');
    allowed.close();
    const denied = await banner({ notice: notice(), canView: false });
    assert.equal(denied.$('.subscription-notice a'), null);
    denied.close();
    const here = await banner({ notice: notice(), canView: true, onSubscriptionPage: true });
    assert.equal(here.$('.subscription-notice a'), null);
    here.close();
});

test('blocked and read-only notices use alert semantics and are not colour-only', async () => {
    const scene = await banner({ notice: notice({ state: 'suspended', access: 'blocked', severity: 'danger', key: 'paid.expired', category: 'paid', params: { days: 40 } }) });
    assert.equal(scene.$('.subscription-notice').getAttribute('role'), 'alert');
    assert.match(scene.$('.subscription-notice__badge').textContent, /✕.*Suspended/);
    scene.close();
});

test('the shared layout shows the server notice on ordinary pages and hides it for non-company contexts', async () => {
    const scene = await createScene({ entry: ENTRY, locale: 'en', pageUrl: '/dashboard', pageProps: { subscriptionNotice: notice({ params: { days: 3 } }), navigationCapabilities: capabilities, capabilities, auth: { user: { name: 'Owner' } } } });
    await scene.mount({ mode: 'layout' });
    assert.match(scene.$('#page-content').textContent, /PAGE-CONTENT/);
    assert.match(text(scene), /Your trial period ends in 3 days\./);
    assert.equal(scene.$('.subscription-notice a').getAttribute('href'), '/subscription');
    assert.doesNotMatch(scene.text(), /seven/i);
    scene.close();

    const quiet = await createScene({ entry: ENTRY, locale: 'en', pageUrl: '/dashboard', pageProps: { subscriptionNotice: null, navigationCapabilities: capabilities, capabilities, auth: { user: { name: 'Owner' } } } });
    await quiet.mount({ mode: 'layout' });
    assert.equal(quiet.$('.subscription-notice'), null, 'a company without a notice (for example a local edition) shows nothing');
    quiet.close();
});

test('the subscription page renders Arabic RTL and English LTR with one notice', async () => {
    const ar = await page({ locale: 'ar' });
    assert.equal(ar.$('.ez-shell').getAttribute('dir'), 'rtl');
    assert.equal(ar.$$('.subscription-notice').length, 1, 'the layout banner is not duplicated on the subscription page');
    assert.match(text(ar), /تنتهي الفترة التجريبية خلال 3 أيام\./);
    assert.match(ar.text(), /٢٠|20/);
    assert.deepEqual(ar.errors, []);
    ar.close();

    const en = await page({ locale: 'en' });
    assert.equal(en.$('.ez-shell').getAttribute('dir'), 'ltr');
    assert.match(text(en), /Your trial period ends in 3 days\./);
    assert.match(en.text(), /Africa\/Cairo/);
    en.close();
});

test('mobile width keeps the same readable markup', async () => {
    const scene = await createScene({ entry: ENTRY, locale: 'ar', pageUrl: '/subscription', pageProps: { subscriptionNotice: notice(), navigationCapabilities: capabilities, capabilities, auth: { user: { name: 'Owner' } } } });
    scene.window.innerWidth = 360;
    await scene.mount({ mode: 'page', subscription: { plan: { name: 'Starter' } }, status: 'active' });
    assert.match(text(scene), /خلال 3 أيام/);
    assert.deepEqual(scene.errors, []);
    scene.close();
    const css = readSource('resources/css/navigation.css');
    const block = css.slice(css.indexOf('.subscription-notice {'));
    assert.match(block, /flex-wrap:\s*wrap/);
    assert.match(block, /min-inline-size:\s*0/);
    assert.doesNotMatch(block.split('/*')[0], /(?:margin|padding)-(?:left|right)|\bleft:|\bright:/, 'only logical properties so RTL mirrors correctly');
});

test('renewal and contact actions follow lifecycle state', async () => {
    const paid = await page({ subscriptionNotice: notice({ category: 'paid', key: 'paid.ends' }) });
    const labels = paid.$$('.erp-toolbar button').map((button) => button.textContent.trim());
    assert.deepEqual(labels, ['Request renewal', 'Contact support']);
    await paid.click(paid.$$('.erp-toolbar button')[0]);
    assert.deepEqual(paid.inertia.filter(([kind]) => kind === 'post').map(([, url]) => url), ['/subscription/renew']);
    paid.close();

    const readOnly = await page({ subscriptionNotice: notice({ state: 'read_only', access: 'read_only', category: 'paid', key: 'paid.read_only', params: {}, severity: 'danger' }) });
    assert.ok(readOnly.$$('.erp-toolbar button').some((button) => /renewal/i.test(button.textContent)), 'the renew request route is allowed while read-only');
    readOnly.close();

    const lifetime = await page({ subscriptionNotice: notice({ category: 'lifetime', show: false, key: null, ends_on: null, ends_at: null, days_remaining: null }) });
    assert.equal(lifetime.$('.subscription-notice'), null);
    assert.match(lifetime.text(), /lifetime subscription with no end date/);
    assert.equal(lifetime.$$('.erp-toolbar button').length, 0);
    lifetime.close();

    const admin = await page({ subscriptionNotice: notice({ state: 'suspended', access: 'blocked', severity: 'danger', key: 'suspended_admin', params: {} }) });
    assert.deepEqual(admin.$$('.erp-toolbar button').map((button) => button.textContent.trim()), ['Contact support']);
    admin.close();
});

test('no component or catalog hard-codes a seven-day subscription sentence', () => {
    const pattern = /(?:seven|سبعة|\b7)\s*(?:-\s*)?(?:days?|أيام)|within\s+(?:seven|7)|expires within|renewalWarning|subscriptionWarning/i;
    for (const file of ['resources/js/Components/SubscriptionNotice.jsx', 'resources/js/i18n-subscription.js', 'resources/js/Pages/Company/Subscription.jsx', 'resources/js/Layouts/WorkspaceLayout.jsx', 'resources/js/i18n.js', 'app/Http/Middleware/HandleInertiaRequests.php', 'app/Http/Middleware/CheckSubscriptionStatus.php']) {
        assert.doesNotMatch(readSource(file), pattern, file);
    }
});

test('Arabic and English subscription label keys stay in sync', () => {
    const keys = (locale) => Object.keys(catalog[locale]).filter((key) => key.startsWith('sub_')).sort();
    assert.deepEqual(keys('ar'), keys('en'));
    assert.ok(keys('en').length > 20);
});
