# نتائج معالجة ملاحظات Super Admin (E‑Zaki ERP)

- **الفرع:** `super-admin-complete-remediation`
- **الـ commit الأساسي:** `921a2e9` (التغييرات كلها غير مُرحَّلة في commit، ولم يتم أي push أو نشر أو إنشاء PR)
- **تاريخ التنفيذ:** 2026-10-14

## 1. الملخص التنفيذي وجاهزية الإصدار

تم تنفيذ إعادة بناء منطقة Super Admin بالكامل: صلاحيات منصة حسب الدور، تحقق بخطوتين (TOTP) مع step-up للعمليات الحساسة، مسار اشتراكات ومدفوعات يحفظ التاريخ ويمنع التكرار، سجل تدقيق للإضافة فقط مع إخفاء الأسرار، تقوية الدخول والـ API، وواجهة جديدة مقسمة لصفحات حقيقية (عربي/إنجليزي، RTL/LTR).

**الجاهزية:** جاهز للمراجعة والنشر على بيئة staging بعد نسخة احتياطية موثقة. جميع بوابات الجودة الآمنة نجحت (انظر القسم 15). لا يوجد فحص خارجي على MySQL الإنتاجي أو SMTP أو HTTPS الحقيقي (انظر القسم 16).

## 2. تأكيدات السلامة

- لم يتم قراءة أو طباعة أو تعديل ملف `.env`، ولا الاتصال ببيانات الإنتاج، ولا أي نشر.
- لم يُستخدم `migrate:fresh` أو `migrate:refresh` أو أوامر git مدمّرة. المحافظة على التعديلات غير المحفوظة السابقة (محاسبة/تسويات).
- شُغّلت الـ migrations الجديدة فقط على قاعدة البيانات المحلية للتطوير (`--pretend` أولًا ثم `--force`)، وهي إضافية فقط.

## 3. الملفات المتغيرة (حسب الأثر)

### 3.1 خاص بالمنصة فقط (Platform-only)
- **جديد:** `routes/platform.php`، `app/Http/Controllers/Platform/*` (Admin, Audit, Catalog, Company, Dashboard, License, Mfa, Platform, Registration, Request, Settings, Subscription)، `app/Http/Middleware/{PlatformCan,PlatformMfa,PlatformStepUp}.php`، `app/Services/{MfaService,PlatformAdminService,PlatformMetricsService,SubscriptionTransitionService}.php`، `app/Support/{PlatformPermissions,PlatformPassword,Totp}.php`، `app/Console/Commands/{PrunePlatformLogs,PruneStaleRegistrations,ResetPlatformMfa}.php`، `lang/ar/platform.php`، `lang/en/platform.php`.
- **واجهة جديدة:** `resources/js/Components/Platform/ui.jsx`، `resources/js/Pages/SuperAdmin/{Admins,Audit,Companies,Licenses,MfaChallenge,Plans,Registrations,Requests,Security,Settings,Subscriptions}.jsx`، `resources/js/i18n-platform.js`.
- **معدّل:** `Pages/SuperAdmin/Dashboard.jsx`، `resources/js/navigation.js`، `resources/css/navigation.css`، `app/Console/Commands/CreateSuperAdmin.php`.
- **محذوف:** `Web/SuperAdminPlatformController.php`، `Web/SuperAdminLicenseController.php` (استُبدلا بمتحكمات `Platform/*`).

### 3.2 بنية مشتركة (Shared infrastructure)
`bootstrap/app.php` (aliases + `AssignRequestId`)، `config/security.php`، `config/sanctum.php` (جديد)، `app/Providers/AppServiceProvider.php` (rate limiters)، `app/Http/Middleware/{AssignRequestId,EnsureSessionVersion,HandleInertiaRequests,TrustProxies,RequireCompanyApiAbility}.php`، `app/Services/{AuditRecorder,AuthenticationService}.php`، `app/Models/{AuditLog,User,Subscription,ManualPayment,LocalLicense}.php`، `app/Models/{SubscriptionEvent,SubscriptionRequest}.php`، `routes/{web,api,console}.php`، `resources/js/{bootstrap,i18n}.js`، `resources/js/Layouts/WorkspaceLayout.jsx`.

### 3.3 يمس مستخدمي الشركات (Company-facing)
`Web/CompanySubscriptionRequestController.php` (زرا التواصل/التجديد يخزنان طلبًا فعليًا)، `Web/AuthenticationController.php`، `Web/PasswordResetController.php`، `Web/RegistrationController.php`، `Api/V1/AuthenticationController.php` (توكن بـ ability وانتهاء)، `CheckSubscriptionStatus`، `Web/CompanyAccessController.php`، `Pages/Auth/Login.jsx`، `app/Notifications/SubscriptionExpiryWarning.php` (queued).

### 3.4 محاسبة / بيانات / اشتراكات (Accounting-data)
`SubscriptionLifecycle`، `CompanyLimitService`، `CompanyProvisioningService` (الافتراضي `factory_weight`)، `CompanyStructureService`، `LocalLicenseService`، `ArchiveExpiredCompanies`، `SendSubscriptionExpiryWarnings`، و migrations القسم 8. لا تُعدَّل قيود يومية أو حركات مخزون أو أرصدة.

### 3.5 أمني / تشغيلي
`TrustProxies`، `AuthenticationService`، `MfaService`، `Totp`، `AuditRecorder`/`AuditLog`، أوامر الصيانة، `DEPLOYMENT.md`.

### 3.6 اختبارات ووثائق
`tests/Feature/SuperAdminRemediationTest.php` (جديد، 19 اختبارًا)، `tests/platform-ui.test.mjs` (جديد)، تعديل `PhaseOneFoundationTest`، `PhaseTwoCoreTest`، `PhaseThreeOperationsTest`، `SecurityPlatformHardeningTest`، `tests/navigation.test.mjs`، `package.json` (سكربت الاختبار)، `DEPLOYMENT.md`.

## 4. خريطة النتائج → الإصلاح (SA-* / R-*)

| الموضوع | الإصلاح |
| --- | --- |
| صلاحيات مدير المنصة غير مفصلة | أدوار `owner/billing/support/auditor` عبر `PlatformPermissions` + middleware `platform.can`، وصلاحيات تُمرَّر للواجهة |
| لا MFA / لا تأكيد للعمليات الحساسة | TOTP (RFC 6238) + رموز استرداد مُجزّأة + step-up + سبب إلزامي |
| دفعات يدوية قابلة للتكرار/الجزئية | `idempotency_key` إلزامي، دفع كامل فقط، رفض حالات الاسترداد، تسجيل الزيادة |
| تغيير الخطة يفقد التاريخ | صف اشتراك جديد + `superseded_at` + `subscription_events` |
| دفعة تُعيد تفعيل شركة موقوفة | منع: الإيقاف/الأرشفة تتغلب دائمًا (بما فيها النسخة المحلية) |
| أزرار التجديد/التواصل لا تفعل شيئًا | تُخزَّن في `subscription_requests` وتُعالج من صفحة الطلبات |
| سجل تدقيق ضعيف/قابل للتعديل | إضافة فقط، إخفاء الحقول الحساسة، actor/severity/reason/before/after/request_id |
| قفل دخول يمكن استغلاله لحجب المالك | قفل مرتبط بالمصدر (بريد+IP) وقفل شامل للحساب بعتبة أعلى |
| كشف الحسابات عند استعادة كلمة المرور | رد عام موحد |
| TrustProxies مفتوح | الافتراضي loopback فقط، وإعداد صريح عبر `TRUSTED_PROXIES` |
| توكنات API بلا نطاق/انتهاء | ability `company-api` + انتهاء (التوكنات القديمة تبقى تعمل) |
| `plans.features` غير مطبقة | موقوفة (deprecated)، وتحكم الحدود فقط |
| طلبات تسجيل قديمة غير مُنظَّفة | `registrations:prune-stale` مجدول |
| مؤشرات لوحة التحكم غير دقيقة | `PlatformMetricsService` يعتمد الاشتراك الحالي (`Subscription::current()`) |

## 5. MFA و step-up
- TOTP ذاتي التنفيذ (`Totp`) مُتحقق منه بمتجهات RFC 6238، ومنع إعادة استخدام نفس الخطوة.
- 8 رموز استرداد تُخزَّن HMAC ولا تُعرض إلا مرة واحدة، وكل رمز يُستخدم مرة واحدة.
- الإلزام عبر `PLATFORM_MFA_REQUIRED` أو `PLATFORM_MFA_ENFORCE_FROM` (فترة سماح). المسؤول المسجل تُطلب منه شاشة التحقق في كل جلسة.
- step-up: كلمة مرور (+ رمز TOTP إن كان مفعلًا) صالحة 10 دقائق؛ ردّ JSON `423 step_up_required`. Remember-me معطل للمنصة.
- استرداد قفل المسؤول: `php artisan platform:mfa-reset <email>` من الخادم فقط.

## 6. الأدوار والصلاحيات
- `owner`: كل الصلاحيات. `billing`: الاشتراكات/المدفوعات/الخطط/الكوبونات. `support`: الشركات ودورة الحياة والتسجيل والطلبات. `auditor`: قراءة فقط مع سجل التدقيق.
- يُرحَّل كل `super_admin` موجود إلى `owner` تلقائيًا في الـ migration كي لا يُقفل أحد.
- حماية آخر Owner نشط: لا يمكن تعطيله أو خفض دوره. تغييرات الدور/الحالة تُسجَّل وتُبطل الجلسات.

## 7. الاشتراكات والمدفوعات والخطط ودورة الحياة
- كل الكتابة تمر عبر `SubscriptionTransitionService` داخل transactions مع `lockForUpdate`.
- الدفع: `paid|pending|failed` فقط؛ الجزئي مرفوض؛ الزيادة تُسجَّل؛ التمديد من تاريخ الانتهاء إن كان مستقبليًا وإلا من اليوم؛ دفعة `pending` تُحسم مرة واحدة فقط.
- الأرشفة تتطلب استعادة قبل أي دفعة أو تغيير خطة (409).
- الاستعادة بعد أكثر من 6 أشهر من الانتهاء تُبقي الاشتراك موقوفًا حتى التجديد.
- **قرار الـ entitlement/feature-gate:** `plans.features` غير مطبقة وممنوعة عند الإنشاء؛ التحكم بالحدود (مستخدمون/فروع/مخازن) فقط. الإضافات تمدد الحد المحدود ولا تغيّر "غير محدود". الشركات الجديدة تبدأ بـ `factory_weight`.
- خفض الخطة أسفل الاستخدام الحالي محجوب مع رسالة توضح المورد.

## 8. الـ Migrations
1. `2026_10_15_000001_add_platform_security_fields`: أعمدة `users.platform_role` و MFA، وحقول `audit_logs` الجديدة، ثم ترحيل `super_admin` إلى `owner`.
2. `2026_10_15_000002_add_subscription_history_and_requests`: `superseded_at`/`previous_subscription_id`، جدول `subscription_events`، أعمدة المدفوعات (idempotency، audit)، جدول `subscription_requests`.

كلاهما إضافي ومتوافق مع MySQL (تم `--pretend` ثم التطبيق محليًا بنجاح). لا يُعاد كتابة أي بيانات محاسبية.

## 9. الترخيص / الـ API / الإشعارات
- تراخيص النسخة المحلية: لا تُعرض المفاتيح أو التواقيع؛ الإبطال والنقل بـ step-up وسبب.
- API: توكنات جديدة بـ `company-api` وانتهاء 30 يومًا (`API_TOKEN_TTL_MINUTES`)، و middleware `company.api` يقبل جلسات SPA والتوكنات القديمة ويرفض التوكنات ذات نطاق آخر (403).
- تذكيرات الانتهاء تُرسل عبر queue مع منع التكرار اليومي.

## 10. التدقيق والاحتفاظ
- `AuditLog` للإضافة فقط (يرمي `LogicException` عند update/delete).
- الحقول الحساسة (`password`, `token`, `secret`, `signature`, `license_key`, …) تُستبدل بـ `[redacted]`.
- `platform:prune-logs` يتطلب `--force` (و`--dry-run` للمعاينة) وغير مجدول إطلاقًا.

## 11. تجربة الاستخدام والتنقل
صفحات منفصلة بدل المراسي (`#`): النظرة العامة، الشركات، التسجيل، الطلبات، الاشتراكات، الخطط والكوبونات، التراخيص، المديرون، التدقيق، الإعدادات، الأمان. تظهر عناصر القائمة بحسب الصلاحيات، وكل نافذة تأكيد تعرض الأثر. CSS بخصائص منطقية فقط (دعم RTL). تغطية مفاتيح الترجمة بالعربية والإنجليزية بالاختبار.

## 12. التوافق العكسي والتراجع
- الروابط القديمة تعمل (`/super-admin` وصفحات الشركات)؛ التوكنات القديمة تعمل؛ لا تغيير في واجهة API الخاصة بالشركات.
- **التراجع:** الأفضل تراجع الكود فقط (الكود القديم يتجاهل الأعمدة الجديدة). `migrate:rollback` يحذف أعمدة MFA ومفاتيح idempotency وسجل الطلبات والأحداث فيُفقد ما التقط بعد النشر.
- الصفوف الاشتراكية التي صارت `superseded` لا تُحذف ويمكن قراءتها تاريخيًا.

## 13. جدول تغيّر السلوك

| Change | Scope | Affects company users? | Affects existing data? | Requires migration? | Security impact | Accounting/subscription impact | Rollback difficulty |
| --- | --- | --- | --- | --- | --- | --- | --- |
| أدوار المنصة والصلاحيات | Platform | لا | يضيف `platform_role` (owner لكل الحاليين) | نعم | عالٍ إيجابي | لا | منخفضة |
| MFA وstep-up وسبب إلزامي | Platform | لا | يضيف أعمدة | نعم | عالٍ إيجابي | لا | متوسطة (فقدان التسجيل) |
| دفعات كاملة + idempotency | Platform | لا | يضيف أعمدة | نعم | متوسط | يمنع تمديدًا مكررًا/جزئيًا | متوسطة |
| تغيير الخطة بصف جديد | Platform | غير مباشر (تاريخ الاشتراك) | يضيف صفوفًا ولا يحذف | نعم | منخفض | يحفظ تاريخ الاشتراك | متوسطة |
| الإيقاف/الأرشفة يتغلبان دائمًا | مشترك | نعم (النسخة المحلية أيضًا) | لا | لا | متوسط | يمنع تجاوز الإيقاف | منخفضة |
| طلبات التجديد/التواصل تُخزَّن | شركات | نعم (رسالة تأكيد حقيقية) | يضيف جدولًا | نعم | منخفض | لا | منخفضة |
| قفل دخول مرتبط بالمصدر | مشترك | نعم (أقل تعرضًا للحجب) | لا | لا | عالٍ إيجابي | لا | منخفضة |
| رد موحد لاستعادة كلمة المرور | مشترك | نعم | لا | لا | متوسط إيجابي | لا | منخفضة |
| TrustProxies loopback افتراضيًا | مشترك | نعم إن لم يُضبط `TRUSTED_PROXIES` | لا | لا | عالٍ | لا | منخفضة (متغير بيئة) |
| توكنات API بنطاق وانتهاء | شركات (API) | نعم للتوكنات الجديدة فقط | لا (القديمة تعمل) | لا | متوسط | لا | منخفضة |
| سجل تدقيق للإضافة فقط | مشترك | لا | يضيف أعمدة | نعم | عالٍ إيجابي | لا | متوسطة |
| إيقاف `plans.features` | منصة/شركات | لا (لم تكن مطبقة) | لا | لا | منخفض | التحكم بالحدود فقط | منخفضة |
| الافتراضي `factory_weight` للشركات الجديدة | شركات جديدة | شركات جديدة فقط | لا | لا | لا | يؤثر على أساس تقييم المخزون للشركات الجديدة | منخفضة |

## 14. تعليمات النشر
راجع قسم "Super Admin remediation runbook" في `DEPLOYMENT.md`: نسخة احتياطية، `migrate --force` بالترتيب، ضبط `TRUSTED_PROXIES`، طرح MFA تدريجيًا (اختياري ثم تاريخ إلزام ثم إلزامي)، `optimize:clear` ثم caches، `queue:restart`، الإبقاء على `schedule:run`، ثم smoke.

## 15. نتائج الفحوصات (أرقام فعلية)

| الفحص | النتيجة |
| --- | --- |
| `php artisan test` | **165 passed، 2 skipped، 0 failed** (2049 assertions) |
| اختبارات Node (`npm test`) | **47 passed، 0 failed، 0 skipped** |
| `npm run build` | نجح (Vite، ~13s؛ تحذير حجم chunk لـ app.js بحجم 529 kB كما كان) |
| `vendor/bin/pint --test` | passed |
| `composer validate --no-check-publish` | valid |
| `php scripts/lint-openapi.php` | OK: 19 paths، 21 operations |
| `npm audit --omit=optional` | 0 vulnerabilities |
| `git diff --check` | exit 0 (تحذير CRLF→LF لملف `navigation.css` فقط) |
| `migrate:status` / `--pretend` / `--force` (محلي) | الـ migrations 14 تعمل؛ pretend ناجح؛ تطبيق محلي ناجح |

**المتخطّى:** اختباران (ترخيص النسخة المحلية) يُتخطيان لأن PHP CLI الحالي لا يستطيع توليد مفتاح OpenSSL مؤقت؛ لا علاقة لهما بالتعديلات الحالية.

**مشاكل وُجدت وأُصلحت أثناء الفحص:** خطأ تركيب في `User.php` كسر الاختبارات، وتعديل الاختبارات القديمة لتوافق العقد الجديد (دور `owner`، ability `company-api`، `reason`/`idempotency_key`/step-up، قفل مرتبط بالمصدر) دون حذف أو إضعاف أي اختبار، وإضافة اختبار للقفل الموزع.

## 16. فحوصات لم تُجرَ / يدوية مطلوبة
- MySQL الإنتاجي (migration/استعادة/EXPLAIN)، SMTP، Redis، worker دائم، HTTPS حقيقي وخلف proxy، اختبار حمل.
- التحقق اليدوي بالمتصفح من الصفحات العربية/الإنجليزية (RTL/LTR) وتجربة تطبيق مصادقة حقيقي (Google/Microsoft Authenticator).
- التأكد من `TRUSTED_PROXIES` على الخادم الحقيقي.
- توليد مفاتيح OpenSSL لاختبارين متخطيين على بيئة بها OpenSSL كامل.

## 17. بنود لم تُنفَّذ عمدًا
- مسارات الإلغاء/الاسترداد (refund) من النظام؛ الاستردادات تُعالج خارجه.
- تقارير وتصدير بيانات المنصة، ورسم رمز QR داخل الواجهة (يُعرض المفتاح والرابط).
- جدولة تلقائية لحذف سجلات التدقيق (الحذف يدوي وصريح فقط).
- فرض انتهاء عام على التوكنات القديمة (حفاظًا على التوافق).
- قفل/إشعار تلقائي للشركة عند رفض طلب تسجيل أو معالجة طلب.
