# نتائج تشخيص وإصلاح فشل رفع مركز نقل البيانات

**الحالة النهائية:** رُفع الملف الحقيقي وحُلّل بنجاح في قاعدة MariaDB مؤقتة بعد إصلاح المخطط. **قاعدة التطوير المحلية `ezaki_erp` فقدت بياناتها خلال اختبار PHPUnit غير المعزول، وهي حاليًا ذات مخطط فقط ولا أملك نسخة احتياطية موثوقة لاستعادتها.** لا تستخدم هذه القاعدة قبل استعادتها من نسخة معتمدة. **الاستخدام الإنتاجي ممنوع** أيضًا بسبب فشل `npm audit` وعدم فحص قاعدة الاستضافة.

> **تنبيه حادثة قاعدة البيانات:** أثناء تشغيل PHPUnit، اتصل الاختبار بقاعدة MariaDB `ezaki_erp` بدل SQLite بسبب إعدادات config/runtime غير معزولة، ونفّذ `RefreshDatabase` الذي أعاد بناء schema؛ بعد ذلك كانت أعداد `companies`, `users`, `data_migration_runs` صفرًا، وسجل الترحيلات 33 صفًا في batch 1. ظهر لاحقًا من اختبار trigger أن اختبار upload شُغّل فعلًا على MariaDB. أوقفت فور اكتشاف ذلك أي كتابة/اختبار إضافي. `log_bin=OFF`، ونسخة backup التي أُنشئت قبل الترحيل حُذفت أثناء cleanup، ولا يوجد backup manifest مؤكد. وُجد ملف SQLite باسم `ezaki-mig.sqlite` في TEMP بتاريخ 17 أكتوبر، لكنه قديم وغير متحقق ولم أفتحه أو أستعد منه. **لا تشغّل أي migration أو اختبار على هذه القاعدة، ولا تستعد `alawaadc_ezaki_erp.sql.gz` أو ملف SQLite المذكور دون أن يؤكد مسؤول النظام مصدر النسخة وتاريخها وسلامتها.** يلزم استعادة backup خارجي معتمد. هذا خطأ تنفيذ مني، وهو أهم قيد في النتيجة.

## خط الأساس والهوية

| البند | النتيجة |
|---|---|
| الفرع | `main` |
| HEAD قبل التعديل | `7f9a6ec1115eb6c9f969367efc5b08c7139b455b` |
| `origin/main` قبل التعديل | `7f9a6ec1115eb6c9f969367efc5b08c7139b455b` |
| عمل الشجرة قبل التعديل | نظيف |
| الملفات الجديدة المطلوبة للمركز | موجودة كلها، ومنها القارئ والمستورد والـworkflow والواجهة والترحيلان 19 و21 أكتوبر |
| مسار Composer | `SqlDumpReader` و`MigrationWorkflow` حُمّلا من `D:\xampp\htdocs\ezaki-erp-v1\app\Services\...` في الشجرة الحالية |
| ملف المصدر | موجود؛ 1,607,787 بايت؛ SHA-256 يبدأ `3F4DA5F9...`، لم يُقرأ محتواه نصيًا ولم يُعدّل أو يُتتبّع |

## السبب الجذري، مفصولًا حسب الواقعة

### حادثة اختبار قاعدة التطوير (مهمة)

على الرغم من أن `phpunit.xml` يطلب SQLite in-memory، كان config cache مفعّلًا وفيه إعداد MariaDB، ولم تُعزل بيئة PHPUnit من هذا cache. دورة `RefreshDatabase` أعادت بناء `ezaki_erp` على MariaDB ثم أعادت الترحيلات؛ القياس: 68 جدولًا، 0 شركة، 0 مستخدم، 0 تشغيل نقل، وسجل ترحيلات 33 صفًا في batch واحد. `log_bin` متوقف. لم أجد نسخة موثوقة؛ ملف SQLite الموجود في TEMP قديم وغير متحقق، لذلك لم أستخدمه. يجب اعتبار بيانات قاعدة التطوير مفقودة حتى استعادة backup معتمد. أُضيف حارس في `bootstrap/app.php` لإزالة config cache عند بدء `php artisan test`، وحُولت إعدادات DB في `phpunit.xml` إلى `<server force="true">` لمنع وراثة إعدادات MariaDB. لم أُعد الاختبارات بعدها على قاعدة البيانات المتضررة.

### فشل الترحيل الحالي

القاعدة التي أعادت `php artisan migrate` هي MariaDB المحلية `ezaki_erp` على `127.0.0.1:3306`، وليست قاعدة الاختبار المؤقتة. أظهر `migrate:status` أن `2026_10_19_000001_create_data_migration_tables` مطبق وأن `2026_10_21_000001_remediate_data_migration_center` Pending. أظهر سجل Laravel الفشل الأول عند إضافة القيد الفريد `dm_runs_company_source_unique`، ورسالة duplicate entry لمجموعة واحدة من الهوية `(company_id, source_system, source_hash)`. عند إعادة المحاولة انتقل أول خطأ إلى `state_version` الموجود بالفعل لأن MariaDB أنجزت أعمدة مبكرة قبل توقف DDL. لذا كانت الحالة **ترحيلًا جزئيًا**، لا مشكلة اتصال ولا اختلاف branch.

مصفوفة المخطط قبل الاستعادة:

| الكائن | مطلوب بالكود | قبل: موجود؟ | في ترحيل 21 أكتوبر؟ | مسجل كمطبق؟ | الحالة | الإجراء الآمن |
|---|---|---:|---:|---:|---|---|
| `data_migration_runs.state_version` | نعم | نعم | نعم | لا | متوافق، جزئي | تخطّي العمود الموجود وإكمال المفقود |
| `lock_token`, `lock_expires_at`, `lock_action` | نعم | نعم | نعم | لا | متوافقة | التحقق ثم الإكمال |
| `integrity`, `preflight`, `policy` | نعم | نعم | نعم | لا | متوافقة | التحقق ثم الإكمال |
| `source_bytes`, retention, gap, sign-off, archive columns | نعم | نعم | نعم | لا | متوافقة | التحقق ثم الإكمال |
| `dm_runs_company_source_unique` | لا يمكن فرضه على القديم | لا | نعم | لا | متعارض مع مجموعة تاريخية مكررة واحدة | عدم حذف التشغيلات؛ استخدام index غير فريد + claim فريد مستقل |
| أعمدة مراجعة `data_migration_mappings` | نعم | لا | نعم | لا | ناقصة | إضافتها مرة واحدة |
| أعمدة مراجعة `data_migration_records` | نعم | لا | نعم | لا | ناقصة | إضافتها مرة واحدة |
| `data_migration_source_rows` | نعم | لا | نعم | لا | غائب | إنشاؤه مع الفهارس المطلوبة |
| `data_migration_events` | نعم | لا | نعم | لا | غائب | إنشاؤه مع الفهرس المطلوب |
| `data_migration_links` | نعم | لا | نعم | لا | غائب | إنشاؤه بقيود المصدر والهدف |
| `products.material_category`, `quantity_basis` | نعم | لا | نعم | لا | غائبان | إضافتهما؛ التحقق من duplicate non-null قبل unique index |
| `data_migration_identity_claims` | نعم بعد الإصلاح | لا | ترحيل 000002 | لا | غائب | إنشاء فريد مع ربط أقدم run دون حذف القديم |
| `migrations` row لـ 21 أكتوبر | نعم | لا | — | لا | الترحيل Pending | يسجله Laravel فقط بعد نجاح كامل |

لم يُدخل أي صف migrations يدويًا. ولم يُستخدم `migrate:fresh` أو rollback أو حذف بيانات/فهارس.

### فشل الرفع الذي ظهر في السجل

سجل Laravel المحلي يثبت طلبًا سابقًا حقيقيًا إلى `POST /super-admin/data-migrations` وصل إلى `DataMigrationController::store`، ثم فشل عند `INSERT data_migration_runs` بسبب `Unknown column 'source_bytes'`. وقتها لم يُنشأ صف run. سبب هذه الواقعة هو **الكود الأحدث مع مخطط أقدم/جزئي**، لا gzip ولا CSRF. بعد الترحيل، رفع HTTP الحقيقي للملف انتهى بـ200 وأنشأ run في قاعدة الاختبار. لا يوجد دليل على stale route/config cache بوصفه السبب؛ `about` أظهر أن cache config/routes لم يكن مفعّلًا قبل الإصلاح، وبعدها اختُبرت أوامر cache.

### فشل محاولة فهرس التفرّد السابقة

في سجل 06:18 كان هناك duplicate source identity تاريخي، لذلك كان فرض قيد unique مباشرة على `data_migration_runs` غير صالح. لم تُعرض قيم البصمة أو سجلات الأعمال في التقرير. بقيت السجلّات القديمة كما هي، وأضيف جدول claim يختار `MIN(id)` لكل هوية قديمة.

## إصلاح الترحيل والتعافي

- جعل ترحيل `2026_10_21_000001` قابلًا للاستئناف: يفحص كل عمود/فهرس بالاسم، يتخطى الموجود، يضيف الناقص، ويرفض صراحةً كائنًا موجودًا لكن غير متوافق. لا يفرض unique على جدول runs القديم؛ يضيف index غير فريد للبحث.
- فحص الخامات قبل إنشاء unique index على `products`؛ إذا وُجدت هويات منتجات غير null مكررة يتوقف برسالة محددة دون تغيير الصفوف.
- إضافة ترحيل forward-only `2026_10_21_000002_create_data_migration_identity_claims`: ينشئ قيد هوية فريدًا ويملأ claim واحدًا لكل مجموعة إلى أقدم run. لا يحذف أو يغيّر run تاريخيًا.
- أخذت نسخة احتياطية من `ezaki_erp` خارج المستودع قبل التطبيق، استُعيدت في قاعدة مؤقتة `ezaki_upload_clone`، واختُبر التعافي على نسخة جزئية تحتوي نمط الأعمدة الموجودة والمجموعة المكررة. نجح الاستكمال، حُفظت كل التشغيلات القديمة، ثم حُذفت قاعدة clone والنسخة الاحتياطية المؤقتة.
- طُبق الترحيلان forward-only بعد نجاح clone، وإعادة `php artisan migrate` قالت `Nothing to migrate` قبل حادثة PHPUnit. بعد الحادثة أعاد `RefreshDatabase` بناء schema؛ الحالة الراهنة: 68 جدولًا، 0 شركات، 0 مستخدمين، 0 runs. لا يوجد binlog أو backup مؤكد عُثر عليه. لم يُطبق شيء على production.

## مسار الرفع والتغييرات

- يتحقق `DataMigrationController::store` الآن من كل الجداول والأعمدة المطلوبة، بما فيها المنتجات والجداول المرحلية وجدول identity claims، ومن وجود migration rows للترحيلين 000001/000002 **قبل التخزين**. لا يشغّل ترحيلات من HTTP؛ يرد HTTP 503 JSON بكود `migration_schema_pending` أو يعيد خطأ Inertia على حقل المصدر برسالة تشغيل واضحة.
- يُحسب hash ثم يخزّن المصدر على قرص `local` الخاص. run وclaim وحدث التدقيق تُنشأ في transaction واحدة. التكرار يربط بأقدم run موجود ويزيل الملف المؤقت الجديد. فشل التخزين أو DB يحذف الملف المخزن؛ لا يبقى orphan.
- التكرار المتزامن محمي بقيد جدول claims الفريد وlock في المعاملة. لم تُحذف تشغيلات قديمة متكررة.
- أضيفت رسائل مترجمة عربي/إنجليزي للـ403/409/413/419/422/500/502/503/timeout/network؛ أخطاء validation تبقى بجوار الحقل، والصفحة لا تختفي. النموذج يستخدم Inertia `forceFormData`، ولا يضبط Content-Type يدويًا.
- بقي middleware `auth`, `account.type:super_admin`, `platform.can:data_migration.manage`, `throttle:platform.write`, CSRF، وstep-up في مسارات القرار والتنفيذ كما هي. اختبارات الرفع أثبتت رفض مستخدم support والشركة المؤرشفة؛ كما بقيت اختبارات العزل وstep-up السابقة ناجحة.
- ملفات PHP/JS المعدلة، اختبارات upload والواجهة، رسائل اللغة، migrations، و`DEPLOYMENT.md` هي الملفات المتغيرة؛ لا ملف dump أو fixture حقيقي داخل Git.

## إعادة إنتاج HTTP الحقيقي

1. شُغّل خادم PHP المحلي على `127.0.0.1:8099` مربوطًا بقاعدة MariaDB المؤقتة فقط.
2. أُخذت جلسة من `/super-admin/login` مع CSRF ثم سُجل الدخول بحساب مالك اختبار على clone.
3. أُرسل `curl -F` إلى `POST /super-admin/data-migrations` بحقول الشركة والتواريخ والسياسة و`source`، وبـ`Content-Type: multipart/form-data; boundary=...` وCookie/CSRF صالحين. حمل الطلب ملف المصدر الحقيقي مباشرة ولم يُنسخ إلى مكان عام.
4. النتيجة: HTTP 200، request body 1,608,703 بايت، response `application/json`، `duplicate=false`، run id مؤقت 6. كان الملف في `storage/app/private/data-migrations` على clone فقط.
5. أُعيد `POST` بنفس الملف والبصمة: HTTP 200 و`duplicate=true` وعاد run نفسه؛ لا run مكرر.
6. endpoint التحليل الفعلي أنهى الطلب، والحالة المحفوظة أصبحت `review`، و0 integrity errors، و62,141 صفًا في جدول staging. هذه كلها أعداد فقط.
7. حُذفت clone ونسخة backup والـcookies والـheaders والملفات الاصطناعية المؤقتة وخادم الاختبار. قاعدة `ezaki_erp` لا تحتوي أي run من اختبار HTTP (عدد تشغيلات الشركة الاختبارية في القاعدة الأصلية = 0).

طلب real HTTP على clone أثبت وجود boundary؛ اختبار Laravel Feature يمر عبر middleware والroute لكنه helper framework وليس إثبات boundary بحد ذاته. CSRF رفض محاولة curl الأولى ذات الجلسة غير الصالحة بـ419؛ المحاولة المصادق عليها والرمز المطابق نجحت.

## الإصدار القديم

النسخة الأقدم التي أمكن تثبيتها هي `5bfb733e590348f9d7c88aab07668927f246b071` بتاريخ 2026-10-20 (`migration-execution-fix`)، وهي قبل الدمج الحالي `7f9a6ec1115eb6c9f969367efc5b08c7139b455b`. كانت صفحة واحدة تستخدم endpoints `analyze`, `prepare-masters`, `prepare-openings`, `execute-invoices` ونسخة الخدمات القديمة؛ ليست state machine الحالية. لا يوجد في سجل هذه المهمة طلب/trace/لقطة قاعدة من ذلك الإصدار تثبت السبب المحدد لخطأ التنفيذ القديم، لذلك لا أنسبه إلى سبب واحد. generic frontend في النسخة القديمة لا يغني عن سجل Laravel فيها. لم تُخلط أخطاء تنفيذ النسخة القديمة مع خطأ upload المثبت على main.

## التخزين والإعدادات

- قرص `local` مضبوط إلى `storage/app/private`؛ لا تُخدم الملفات عبر `public/storage`.
- الرفع الافتراضي محدود بـ40 MiB؛ يجب أن تكون `upload_max_filesize` و`post_max_size` وحد خادم الويب مساوية أو أكبر. الحد المفكوك 256 MiB، والحد لكل statement 32 MiB. PHP CLI استخدم `memory_limit=512M`؛ إعداد PHP/FastCGI الفعلي في الاستضافة لم يُفحص من هذه البيئة.
- عند وجود migration pending تعرض الواجهة رسالة تشغيل؛ لا تنفذ schema change في upload request.

## الاختبارات والبوابات

| البوابة | النتيجة |
|---|---|
| `php artisan test` | خرج 249 passed، 2 skipped (3091 assertions)، **لكن الاتصال كان MariaDB لا SQLite؛ دورة RefreshDatabase صفّرت قاعدة التطوير. هذه النتيجة غير صالحة كدليل اختبار معزول ولا تُعاد قبل استعادة DB والتحقق من عزل SQLite** |
| `DataMigrationUploadFailureTest` | 9 passed (51 assertions) قبل اكتشاف اتصال MariaDB؛ غير معزول، وتسبب ضمن التشغيل في إعادة بناء القاعدة. لا تُعامل كنجاح آمن رغم assertions |
| `npm test` | 115 passed، 0 failed (بعدها أضيفت رسالة upload 502/503/timeout؛ اختبار UI ذي الصلة 9 passed قبل الحادثة) |
| `npm run build` | نجح، Vite built in 13.51s |
| `vendor/bin/pint --test` | passed |
| `composer validate --no-check-publish` | composer.json valid |
| `php scripts/lint-openapi.php` | OK، 19 paths / 21 operations |
| `git diff --check` | exit 0، تحذير line-ending فقط على ملفي JS |
| `php artisan route:cache --no-interaction` | نجح |
| `php artisan config:cache --no-interaction` | نجح |
| `php artisan migrate:status` | ترحيلا المركز `[Ran]`؛ بعد الحادثة سجل الترحيلات 33 صفًا في batch واحد نتيجة إعادة بناء المخطط |
| `php artisan migrate --pretend` | exit 0؛ لا تغييرات DDL معلقة على المخطط الفارغ الحالي |
| `php artisan migrate` + الإعادة | نجحا قبل حادثة PHPUnit؛ لا تعِد تشغيلهما قبل استعادة البيانات المعتمدة |
| `php artisan optimize:clear` | نجح قبل الحادثة |
| `npm audit --omit=optional` | **فشل**: ثغرتان high في `follow-redirects` وVite (<=6.4.3). لم أجرِ `npm audit fix` لتجنب تغييرات اعتمادية غير مرتبطة دون مراجعة |
| MariaDB | 10.4.28؛ clone من نسخة القاعدة تعافى، migration clean/pending/partial/fully-applied وإعادة migrate اجتازت. ليست MySQL 8 |

اختبارات إضافية: `DataMigrationUploadFailureTest` (9، 51 assertions)، `DataMigrationRealSourceTest` على المصدر الحقيقي (1، 51 assertions)، اختبار UI جديد لرفض upload و500، واختبارات parser/state-machine السابقة ضمن المجموعة الكاملة.

## السبب المتعلق بالنسخة المنشورة وخطوات Windows

في بيئة Windows المحلية، بعد التأكد من النسخة الاحتياطية، شُغّل:

```powershell
cd D:\xampp\htdocs\ezaki-erp-v1
git status --short
git rev-parse HEAD
php artisan optimize:clear
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
php artisan about
php artisan test --filter=DataMigrationUploadFailureTest
Remove-Item Env:DB_CONNECTION,Env:DB_DATABASE -ErrorAction SilentlyContinue
```

تحقق من أن `php artisan about` يعرض SQLite قبل الاختبار. لا تشغّل اختبارًا كاملًا أو أي `migrate` على `ezaki_erp` قبل استعادة نسخة معتمدة والتحقق من أعداد الشركات/المستخدمين. بعد الاستعادة من مسؤول النظام، أوامر الترحيل المعتادة:

```powershell
php artisan optimize:clear
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate --force
php artisan migrate --force
php artisan optimize:clear
composer dump-autoload
npm ci
npm run build
```

لا تنفذ الترحيل على استضافة بعينها حتى تؤخذ نسخة احتياطية منها وتُفحص `migrate:status`/schema الخاصة بها. أوامر الاستضافة بعد النسخة الاحتياطية:

```sh
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate --force
php artisan migrate --force
php artisan optimize:clear
composer dump-autoload --no-dev --optimize
npm ci
npm run build
```

لا تُنفذ `migrate:fresh`, `migrate:refresh`، ولا تُدخل migration row يدويًا. عند فشل migration مجددًا: أوقف التطبيق، احتفظ بالنسخة الاحتياطية، افحص المخطط/السجل، ثم صحح للأمام.

## القرار

**لا تسمح بتشغيل نقل إنتاجي أو استخدام قاعدة التطوير الحالية.** رفع الملف وتحليله نجحا على clone، لكن `ezaki_erp` الحالية فقدت بياناتها ولا توجد نسخة backup مؤكدة. لا تستعد أي ملف SQL/SQLite بديل إلا بعد تأكيد مصدره وتاريخه وسلامته من مسؤول البيانات. قاعدة الاستضافة لم تُفحص/تُرحّل، و`npm audit` يفشل باثنتين high. الأولوية: أوقف التطبيق المحلي عن الكتابة، استعد backup معتمد أو اطلبه من مسؤول قاعدة البيانات، تحقق من الأعداد الأساسية، ثم اختبر PHPUnit في بيئة معزولة فعلًا (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` مع config cache ممسوح)، عالج الثغرتين، وأعد البوابات على قاعدة اختبار منفصلة قبل staging/production.