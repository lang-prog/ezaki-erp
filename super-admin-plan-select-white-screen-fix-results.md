# نتائج إصلاح الشاشة البيضاء عند اختيار خطة (Super Admin → الاشتراكات)

- **التاريخ:** 2026-10-17
- **النطاق:** `/super-admin/subscriptions?state=lapsed` ← نافذة «تغيير الخطة»
- **الحالة النهائية:** الإصلاح مُنفَّذ ومُتحقَّق منه في متصفح حقيقي وباختبارات آلية. جميع بوابات التحقق نجحت.

## 1. خطوات إعادة الإنتاج الدقيقة

بيئة معزولة (لا تلمس قاعدة بيانات التطوير ولا الإنتاج): ملف SQLite مؤقت في `%TEMP%\ezaki-repro`، وخادم `php -S` مع متغيرات بيئة خاصة بالعملية فقط، ومسؤول منصة `owner` + 9 خطط + شركة اشتراكها منتهٍ منذ 10 أيام.

1. تسجيل الدخول كمدير منصة مخوَّل.
2. فتح `/super-admin/subscriptions?state=lapsed`.
3. الضغط على «تغيير الخطة» في صف الشركة المنتهية.
4. اختيار خطة مختلفة من القائمة **دون** الضغط على زر التأكيد.

**النتيجة قبل الإصلاح:** يفرغ جذر React بالكامل (`#app` بلا أي عناصر، `body.innerText.length = 0`) لحظة الاختيار.

## 2. أول خطأ في Console ونتيجة Network

- أول خطأ (من حدث `pageError` في المتصفح وحدث `window.error`):

```text
Uncaught TypeError: Cannot read properties of null (reading 'from')
    at R (…/build/assets/Subscriptions-DCkfw5n0.js:1:4054)   ← PlanDialog أثناء الـ render
```

(بناء الإنتاج لا يطبع component stack؛ الموقع هو دالة render لمكوّن `PlanDialog`.)

- **Network:** لم يُرسل أي طلب عند الاختيار. سجل خادم التطوير لا يحتوي أي `GET /super-admin/subscriptions/preview` ولا أي `POST`؛ وسجل الاعتراض داخل الصفحة (`XMLHttpRequest`/`fetch`) فارغ (`reqs: []`).
- **الحالة:** `rootChildren: 0`، أي أن React أزال الشجرة كلها لأن الاستثناء وقع أثناء الـ render ولا يوجد Error Boundary.

## 3. السبب الجذري المؤكد

الملف: `resources/js/Pages/SuperAdmin/Subscriptions.jsx` (قبل الإصلاح)

- `usePreview` (الأسطر 6–19): الحالة الابتدائية `{ loading: enabled, data: null }` وعند `enabled=false` تكون `loading=false`. تحدّث الحالة داخل `useEffect` **بعد** الـ render فقط.
- `PlanDialog` (الأسطر 52–64): عند `setPlanId('3')` يحدث render فورًا بحالة قديمة: `planId !== ''` + `preview.loading === false` + `preview.error === ''` + `preview.data === null`. فيدخل الفرع الأخير ويقيّم `info.from` (السطر 60) بينما `info === null` ← `TypeError` أثناء الـ render.
- لأن الاستثناء وقع قبل الـ commit، لم يُنفَّذ `useEffect` ولم يُرسل أي طلب، وبما أنه لا يوجد Error Boundary، أزال React التطبيق كله (الشاشة البيضاء). يختفي العَرَض بعد التحديث لأن الحالة المحلية تُفقد.

استُبعدت الأسباب الأخرى بالتتبع: ليست معالج `onChange` ولا مقارنة string/number للـ id ولا خلفية الـ API (لم يصل أي طلب) ولا الترجمة ولا CSS.

**هل أُرسل أي طلب قبل التأكيد؟** لا. لا طلب إطلاقًا عند الاختيار (لا GET ولا POST).

**إثبات آلي للسبب:** نسخة طبق الأصل من الكود القديم داخل بيئة jsdom أعطت نفس الاستثناء `Cannot read properties of null (reading 'from')` وجذرًا فارغًا وصفر طلبات. (ملفات الإثبات المؤقتة حُذفت.)

## 4. الإصلاح وفصل الاختيار عن التأكيد

| الحالة | أين تعيش |
| --- | --- |
| `selectedPlanId` (اختيار محلي فقط) | `useState` داخل `PlanChangeDialog` |
| `selectedPlan` (مشتق وآمن) | `findPlan()` يقارن الـ id كنص بنص، ويعيد `null` للمعرّف الغائب |
| المعاينة | `usePreview` — GET للقراءة فقط |
| قابلية التأكيد | `planChangeView().blocked` |
| الإرسال | فقط عند ضغط زر التأكيد داخل `ConfirmActionDialog` |
| الخطأ | إشعار محلي داخل النافذة |

الإصلاح الجذري في `usePreview` (`Components/Platform/ui.jsx`): صارت `loading` و`data` **مشتقتين متزامنًا** من مفتاح الاستعلام الذي ينتمي إليه الناتج (`settled = enabled && state.query === query`)، فلا يمكن لأي render أن يرى بيانات فارغة أو بيانات استعلام آخر.

مكوّن جديد `PlanChangeDialog.jsx` يعرض حالات: `empty | choose | stale | loading | error | ready`، ويتعامل بأمان مع حقول الخطة الاختيارية والـ id النصي/الرقمي (القيمة المُرسلة هي `selected.id` الأصلية دون تحويل). لا يستدعي `router` ولا `window.location`. القائمة `<select name="plan_id">` تُحدِّث الحالة المحلية فقط.

## 5. معالجة الاستجابات (بدون شاشة بيضاء)

كل الاستجابات تُعالج في `ConfirmActionDialog` عبر `failureMessage()` + `failureKey()` ولا تُعرض أبدًا كصفحة:

| الحالة | السلوك |
| --- | --- |
| 200/201/302 | تُغلق النافذة، `router.reload({ preserveScroll: true })` (يحافظ على `?state=lapsed`)، وتظهر رسالة نجاح «تم الحفظ.» |
| 409 | رسالة محلية + نص الخادم عند وجوده، النافذة تبقى مفتوحة |
| 422 | أخطاء الحقول من `errors` تُعرض داخل النافذة |
| 423 `step_up_required` | حوار تأكيد الهوية (كلمة المرور + TOTP إن لزم) ثم **إعادة محاولة واحدة**؛ الإلغاء يترك نافذة التأكيد مفتوحة بلا تعديل |
| 423 `mfa_required` وغيره | `router.visit` إلى صفحة التحقق/الأمان (بدلًا من `window.location.assign`) |
| 403 | رسالة صلاحية محلية |
| 419 | رسالة انتهاء الجلسة + زر «إعادة تحميل البيانات» (`router.reload`) |
| 500+ | رسالة عامة محلية؛ لا تُعرض تفاصيل الخادم (اختبار يتحقق أن `SQLSTATE` لا يظهر) |
| انقطاع الشبكة | رسالة محلية قابلة للاسترجاع |

طبقات حماية إضافية: منع الإرسال المزدوج (`inFlight` ref + تعطيل الزر)، Error Boundary داخل جسم النافذة (يبقي العنوان والتذييل)، وError Boundary نهائي في `app.jsx` يعرض بطاقة محلية مع «حاول مرة أخرى/إعادة تحميل» ويُعاد ضبطه عند كل تنقل — وهو شبكة أمان بعد إصلاح السبب الجذري وليس بديلًا عنه.

## 6. الأنماط المشابهة التي بُحث عنها وأُصلحت

| الموضع | النتيجة |
| --- | --- |
| `PaymentDialog` / `LimitsDialog` (نفس `usePreview`) | كانتا معرّضتين لنفس الاعتماد على `info.*`؛ أُصلحتا بالـ hook الجديد وبقيم افتراضية `?? {}` |
| `Companies.jsx` → `CreateCompany` | **عيب مؤكد:** `{api.stepUpDialog}` كان داخل `<form>` فيصعد حدث submit لنموذج التأكيد المتداخل إلى نموذج إنشاء الشركة (احتمال إرسال مكرر). نُقل خارج النموذج + `stopPropagation` في الحوارات |
| `app.jsx` → `enhanceControls` | يحقن حقل بحث في DOM يملكه React لكل `<select>` بأكثر من 7 خيارات (ظهر فعليًا في قائمة الخطط). مخاطرة كامنة عند إعادة المطابقة؛ استُثنيت عناصر `[role="dialog"]` |
| أزرار بلا `type` داخل نماذج | أزرار `Companies` و`Subscriptions` صارت `type="submit"`؛ بقية صفحات المنصة كانت مضبوطة. اختبار مصدر يمنع التراجع |
| `window.location` / `router.*` قبل التأكيد | لا يوجد في صفحات المنصة؛ استُبدل `window.location.assign` في `usePlatformApi` بـ `router.visit` |
| `Dashboard.jsx` `window.location.replace` | توجيه للروابط القديمة بالـ hash عند التحميل فقط — آمن |
| `Company/Access.jsx` `window.location.reload()` | بعد طلب مؤكد صراحةً في صفحة شركة — لا علاقة بالاختيار؛ لم يُعدَّل |
| `.find()` في `MoneyForm`/`SidebarNav`/`HelpCenter` | مستخدم بـ `?.` أو بفحص `if` — آمن |
| `<select onChange>` يرسل فورًا | لا يوجد في التطبيق (بحث نمطي) |

## 7. مراجعة الخلفية (Phase 5)

مسار `POST /super-admin/companies/{company}/plan` لم يتغير سلوكه: صلاحية `subscriptions.plan_change`، تأكيد الهوية `platform.stepup`، MFA، CSRF/الجلسة، المعاملة وقفل الصف، صف اشتراك جديد مع الحفاظ على القديم (`superseded_at`)، منع الخفض فوق الاستخدام، قواعد المؤرشفة (409) والموقوفة، والتدقيق بالسبب وقبل/بعد. تغييران طفيفان فقط في المعاينة (للقراءة): `plan_id` صار `required_if:action,plan` (422 واضح بدل 404)، ورسالة 409 «The company has no subscription.».

**التكرار/Idempotency:** تغيير الخطة لا يحمل مفتاح idempotency؛ الحماية هنا بالحالة: تكرار نفس الطلب يرجع 422 «already on this plan» دون صف جديد (مُختبر). الدفعات تحتفظ بمفتاح idempotency.

اختيار الخطة وحده لا ينشئ صف اشتراك ولا حدثًا ولا دفعة ولا سجل تدقيق ولا يغيّر حالة (مُختبر بلقطة قبل/بعد).

## 8. الملفات المتغيرة

**جديدة**
- `resources/js/Components/Platform/PlanChangeDialog.jsx`
- `resources/js/Components/Platform/planChange.js`
- `resources/js/Components/ErrorBoundary.jsx`
- `tests/plan-change.test.mjs`
- `tests/helpers/dom-harness.mjs`
- `tests/helpers/plan-dialog-entry.jsx`
- `tests/Feature/PlanChangeFlowTest.php`

**معدّلة**
- `resources/js/Components/Platform/ui.jsx` (`usePreview` مشتق، `failureMessage`، إشعار نجاح، `inFlight`، Error Boundary داخل النافذة، `router.visit`)
- `resources/js/Pages/SuperAdmin/Subscriptions.jsx` (حذف `usePreview`/`PlanDialog` المعيبين، استخدام `PlanChangeDialog`)
- `resources/js/Pages/SuperAdmin/Companies.jsx` (نقل step-up خارج النموذج، `type="submit"`)
- `resources/js/app.jsx` (Error Boundary نهائي، استثناء حوارات `enhanceControls`)
- `resources/js/i18n-platform.js` (مفاتيح عربي/إنجليزي جديدة)
- `resources/css/navigation.css` (`.platform-toast` بخصائص منطقية)
- `app/Http/Controllers/Platform/SubscriptionController.php` (تحقق المعاينة)
- `tests/platform-ui.test.mjs`, `package.json` (سكربت الاختبار + `jsdom` كـ devDependency), `package-lock.json`

**التصنيف:** إصلاح واجهة **خاص بالمنصة (Platform-only)** بالأساس. بنية مشتركة: `app.jsx` (Error Boundary نهائي + استثناء `enhanceControls`، ويؤثر على كل الصفحات دون تغيير سلوكها الظاهر)، وأدوات الاختبار. لا تأثير على واجهة الشركات، ولا على بيانات محاسبية أو اشتراكات، ولا migrations. تحسين أمني-تشغيلي: منع إرسال مزدوج وعدم عرض تفاصيل الخادم.

## 9. الاختبارات المضافة

**واجهة (jsdom + React حقيقي + esbuild، `tests/plan-change.test.mjs` — 25 اختبارًا):** فتح النافذة بلا شاشة بيضاء؛ الاختيار لا يُسقط الصفحة ويرسل GET واحدًا فقط وصفر طلبات تعديل ولا تنقل Inertia؛ أول render بعد الاختيار بلا استثناء؛ id رقمي/نصي؛ خطة مفقودة (stale)؛ قائمة فارغة؛ حقول اختيارية ناقصة؛ تعطيل التأكيد (نفس الخطة/تجاوز الحد/فشل المعاينة/معاينة تالفة)؛ الإلغاء بلا تعديل؛ التأكيد = POST واحد + `reload` + بقاء `?state=lapsed` + رسالة نجاح؛ ضغطتان على التأكيد = طلب واحد؛ 423 مع إعادة المحاولة؛ إلغاء step-up؛ 403/409/419/422/500 وانقطاع الشبكة؛ احتواء خطأ طفل داخل النافذة؛ عربي RTL وإنجليزي LTR؛ عرض ضيق؛ اختبارات صرفة لـ `planChangeView`/`failureKey`؛ حراسة المصدر (لا تنقل قسري، `type` صريح، step-up خارج النموذج).

**خلفية (`PlanChangeFlowTest` — 9 اختبارات):** المعاينة للقراءة فقط (لقطة قبل/بعد)؛ تحقق المدخلات و409؛ الصلاحيات (غير مصادق/مستخدم شركة/دور بلا صلاحية/support/auditor)؛ تغيير شركة منتهية مع حفظ التاريخ والتواريخ والتدقيق؛ step-up وMFA؛ قواعد الموقوفة والمؤرشفة؛ رفض الخفض فوق الاستخدام برسالة واضحة؛ منع التكرار؛ عزل الشركات.

**تحديث:** `platform-ui.test.mjs` (عائلات المفاتيح `pf_err_*` و`pf_boundary_*` وملف المكوّن الجديد). لم يُحذف أو يُضعَف أي اختبار.

## 10. نتائج بوابات التحقق (أرقام فعلية)

| الفحص | النتيجة |
| --- | --- |
| `php artisan test` | **174 ناجح، 2 متخطّى، 0 فاشل** (2135 assertion) |
| `npm test` | **72 ناجح، 0 فاشل، 0 متخطّى** (47 سابقة + 25 جديدة) |
| `npm run build` | نجح (exit 0، تحذير حجم chunk المعتاد) |
| `vendor/bin/pint --test` | passed (بعد تطبيق Pint على ملف الاختبار الجديد: line_ending فقط) |
| `composer validate --no-check-publish` | valid |
| `php scripts/lint-openapi.php` | OK — 19 مسارًا، 21 عملية |
| `npm audit --omit=optional` | 0 vulnerabilities |
| `git diff --check` | exit 0 (تحذيرات CRLF→LF فقط، لا أخطاء مسافات) |

**المتخطّى:** اختباران للترخيص المحلي (بيئة PHP لا تولّد مفتاح OpenSSL مؤقتًا) — لا علاقة لهما بهذا الإصلاح.

**التحقق في متصفح حقيقي (المتصفح المدمج/Playwright):** بعد الإصلاح وإعادة البناء — الاختيار يُبقي الصفحة والنافذة، يظهر ملخص الأثر (من/إلى/السعر/المدة/الاستخدام)، طلب واحد `GET …/preview`، لا أخطاء Console؛ ثم التأكيد → `POST /plan` → 423 ← نافذة تأكيد الهوية → `POST /step-up` → إعادة `POST /plan` → إغلاق النافذة، رسالة «تم الحفظ.»، `GET` لإعادة تحميل البيانات، والعنوان بقي `?state=lapsed`، وتحوّلت الخطة إلى Plan 3. فحص سطح مكتب 1280px وعرض ضيق (الحد الأدنى الذي سمح به المتصفح ≈ 487px): التذييل ظاهر ولا تمرير أفقي.

## 11. تحقق يدوي ما زال مطلوبًا

- تجربة 403/409/419/422/500 الحية في المتصفح (مُغطّاة حاليًا باختبارات jsdom فقط).
- فحص الواجهة الإنجليزية LTR وعرض هاتف حقيقي (≤ 390px) على جهاز فعلي.
- حساب مسؤول مفعّل عليه TOTP فعليًا بتطبيق مصادقة (المسار مختبر برمجيًا لا بتطبيق حقيقي).
- مراجعة بصرية لشكل صندوق الأثر داخل الوضع الليلي.

## 12. ملاحظات صدق وأثر جانبي

- محاولتان فاشلتان لتسجيل الدخول (`repro-admin@example.test`) وصلتا خطأً إلى قاعدة بيانات **التطوير المحلية** لأن `artisan serve` لا يمرّر متغيرات البيئة؛ سُجّل صفان في `login_logs` وعدّاد محاولات فاشلة لبريد غير موجود. اكتُشف السبب فورًا وانتقلت إعادة الإنتاج إلى `php -S` مع SQLite معزول. لا بيانات إنتاج.
- أُضيفت تبعية تطوير `jsdom@^25` (39 حزمة) لاختبارات المكوّنات؛ `esbuild` يُستخدم من اعتماد Vite المتعدّي.
- إيقاف العمليات `node` المعلّقة أثناء بناء بيئة الاختبار (إغلاق نوافذ jsdom ومحاكاة `MessageChannel` لازمان لخروج العملية).

## 13. تأكيدات

- لم يُلمس `.env` ولا أي إعداد نشر، ولم تُستخدم بيانات إنتاج.
- لم يتم أي `push` ولا نشر ولا PR.
- لم تُشغَّل migrations جديدة ولا أوامر مدمّرة؛ قاعدة SQLite المؤقتة معزولة وقابلة للحذف.
- أُبقيت كل حمايات التأكيد وMFA/step-up والصلاحيات وCSRF وسجل الاشتراكات وقواعد الحدود دون إضعاف.
