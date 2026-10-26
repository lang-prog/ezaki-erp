# نتائج Phase 1: العملاء والموردون وأرصدة الأطراف الافتتاحية

## الحكم

أُضيف مسار Phase 1 مستقل، لكن **لا يُسمح بالتنفيذ الإنتاجي بعد**. سبب المنع الحالي:

- قاعدة التطوير المحلية `ezaki_erp` تعرضت سابقًا لحادثة PHPUnit غير معزول وأصبحت مخططًا بلا بيانات؛ يجب استعادتها من نسخة موثوقة قبل أي اختبار تكاملي.
- تفسير الموردين المطلوب في Phase 1 يختلف عن تفسير الكود القديم في 28 موردًا، ويحتاج قرارًا واعتمادًا لكل فرق/قاعدة.
- 76 حالة supplier-sale لا تتطابق قيمة `buy.eda3` مع قيمة `sales.total` وتبقى للمراجعة.
- لم يُنفَّذ أي posting Phase 1 على قاعدة حقيقية أو clone بعد إضافة الخدمة الجديدة.

## التنفيذ المضاف

- `PartyOpeningBalanceCalculator`: حساب نقي cutoff-aware، يحفظ مكونات العميل والمورد، التفسير القديم للمقارنة، null/invalid issues، supplier-sale links، وقرارات المراجعة.
- `PartyOpeningBalanceService`: preview، قرارات issue بسبب إلزامي، Phase1 master creation، posting عبر `AccountingService::postOpeningBalance`، reconciliation، وrollback party-only.
- `MappingGenerator::generatePhaseOneParties` و`MasterDataService::preflightPhaseOne/createPhaseOne`: لا تنشئ إلا customer/supplier masters.
- حالات `party_openings_preflight`, `party_openings_ready`, `party_openings_posted`, `phase1_completed` تمنع الانتقال إلى document execution.
- endpoints: `/phase1/preview`, `/phase1/preflight`, `/phase1/issues`, `/phase1/masters/create`, `/phase1/post`, `/phase1/reverse`, `/phase1/reconciliation`.
- الواجهة تعرض scope، cutoff/effective date، component totals، supplier-sale review، blockers، Phase1 reconciliation، وrollback.
- CSV جديد `reports/phase1`.

## ما تم فحصه

- النسخة الحالية: `main`، HEAD و`origin/main` عند `95bcaaa` وقت بدء المهمة.
- المصدر القديم: `D:\xampp\htdocs\alawaad`، خصوصًا `sales.php` و`buy.php` و`add_all.php`.
- dump الحقيقي: `alawaadc_alawaadv2.sql.gz`، 1,607,787 بايت، SHA-256 `3F4DA5F9B757646730394215506A53ACEAD1D9E8B5D9BEB9A9CE530F6209680D`.
- القراءة تمت بـ`SqlDumpReader` دون تنفيذ SQL ودون اتصال بقاعدة E‑Zaki.

## الجداول والأعمدة المؤكدة

| الغرض | الجدول/الأعمدة |
|---|---|
| العملاء | `customer.id_customer`, `name_customer`, `address`, `phon`, `start_balance`, `insert_date` |
| الموردون | `import.id_import`, `name` |
| مكوّن بداية المورد | `account_import.id_account_import`, `id_import`, `start_cash` |
| مبيعات العملاء | `sales.id_sales`, `insert_date`, `id_customer`, `id_account_import`, `total`, `cash` |
| شراء الموردين | `buy.id_buy`, `date`, `id_import`, `id_account_import`, `kg`, `price`, `eda3`, `id_sales` |
| تفاصيل الشراء | `details_buy.id_details_buy`, `id_buy`, `id_categories`, `kg`, `price` |
| تفاصيل البيع | `details_sales.id_details_sales`, `id_sales`, `id_categories`, `kg`, `price`, `total` |
| قاعدة تحويل المادة | `categories.id_categories`, `steel_or_other` |

التاريخ التجاري المؤكد للعميل هو `sales.insert_date`، وللمورد هو `buy.date`. تاريخ السريان في E‑Zaki هو اليوم التالي لتاريخ القطع، باستخدام timezone الشركة، وليس تاريخًا ثابتًا.

## معادلة العميل

طبّق الكود القديم في `sales.php` المعنى التالي:

```text
customer_result = start_balance + SUM(sales.cash) - SUM(sales.total)
```

مع فلترة `insert_date <= cutoff`. لا تُستخدم تفاصيل البيع بدل `sales.total`. إذا كانت النتيجة سالبة فالعميل مدين للشركة، وتحوّل إلى debit في E‑Zaki؛ إذا كانت موجبة فالشركة دائنة للعميل وتحوّل إلى credit.

## معادلة المورد والاختلاف الموثق

Phase 1 يحسب تكلفة الشراء من تفاصيل الشراء:

```text
line_raw = details_buy.kg * details_buy.price
if categories.steel_or_other == 2:
    line_value = line_raw
else:
    line_value = line_raw / 1000
supplier_result = start_cash + SUM(buy.eda3) - SUM(line_value)
```

كل مكوّن يفلتر `buy.date <= cutoff`. NULL `buy.eda3` في صفوف شراء لها تفاصيل يُعامل صفرًا كمكوّن غير منطبق؛ NULL `account_import.start_cash` يبقى review. الفرق مع الكود القديم محفوظ في كل supplier: الكود القديم استخدم رأس `buy.kg * buy.price / 1000`، بينما Phase 1 يستخدم `details_buy` و`steel_or_other`. أي فرق غير صفري blocker حتى يوافق المستخدم على `approve_details` أو يستبعد الطرف بسبب.

## supplier-sale الخاص

المصدر القديم ينشئ بيعًا للمورد عبر:

- `buy.id_sales` يرتبط بـ`sales.id_sales`.
- `sales.id_customer` فارغ/صفر.
- تفاصيل `details_sales` موجودة.
- `buy.id_account_import` يطابق `sales.id_account_import`.
- قيمة المورد المتوقعة في `buy.eda3`.

نتائج القراءة الحقيقية:

| البند | العدد |
|---|---:|
| روابط `buy.id_sales` | 509 |
| الروابط التي وجدت sales | 509 |
| بدون عميل | 509 |
| لها details_sales | 509 |
| تطابق account_import | 509 |
| أكثر من buy لنفس sales | 0 |
| صُنفت `sale_to_supplier` بلا تعارض | 432 |
| تحتاج مراجعة | 76 |

الـ432 تدخل `buy.eda3` مرة واحدة ولا تدخل تكلفة الشراء. الـ76 لا تدخل تلقائيًا؛ قرارها يكون `include_eda3` أو `exclude` مع سبب. كل حالة تحمل source IDs والقيمة الخام والقيمة الداخلة/المستبعدة في preview وCSV.

## نتائج المصدر المجهولة

- parser: 368 statement، 0 أخطاء، 11,108,631 بايت بعد فك الضغط.
- 366 عميلًا و70 موردًا.
- عند cutoff الاختبار `2026-09-30`: 12 نتيجة عميل موجبة، 66 سالبة، 288 صفرية.
- 18 نتيجة مورد موجبة، 33 سالبة، 19 صفرية.
- 5 `customer.start_balance` و3 `account_import.start_cash` غير صالحة وتحتاج قرارًا.
- 28 موردًا يختلف تفسيرهم عن حساب الكود القديم per-ton؛ لا يُخفى الفرق.
- قيم NULL البنيوية: `sales.total` في 5,315 سجل قبض بلا تفاصيل، `sales.cash` في 4,602 فاتورة ذات تفاصيل، و`buy.eda3` في 1,366 شراء ذي تفاصيل. هذه لا تُعد أخطاء تلقائيًا؛ تُفسر حسب بنية السجل.

## Target accounting

- إنشاء/مطابقة الطرف يتم تحت `company_id` المحدد فقط.
- العملاء يستخدمون control account `1.2`، والموردون `2.1`، عبر `AccountingService::createParty`.
- القيد يستخدم `AccountingService::postOpeningBalance` وحساب `opening_balance_equity`.
- تاريخ القيد = cutoff + يوم في timezone الشركة.
- `DataMigrationLink.document_type = party_opening_balance_phase1` يحفظ الشركة، run، source system/table/id، المبلغ، الاتجاه، cutoff، والمكونات.
- القيد متوازن، retry idempotent، والتعارض مع opening balance أو activity موجودة يمنع التنفيذ.
- rollback Phase1 يعكس روابط الأطراف فقط؛ لا يلمس inventory، stock movements، invoices، receipts، vouchers، cash أو banks.

## حدود النطاق

Phase 1 لا ينشئ أو يغير:

- inventory أو stock balances/movements؛
- products أو product costs/quantities؛
- purchases/sales invoices؛
- receipts/payment vouchers؛
- old journal history؛
- cash/bank openings؛
- full transaction history.

## الاختبارات

- `PartyOpeningBalanceCalculatorTest`: 5 passed، 33 assertions، ويغطي customer formula، supplier formula، cutoff boundary، category conversion، supplier-sale once، ambiguity، invalid dates/amounts/categories.
- `DataMigrationRemediationTest`: 12 passed، 579 assertions، بSQLite صريح، مع `migration_scope=full` صريح للحفاظ على regression الخاص بالمسار القديم.
- `DataMigrationUploadFailureTest`: 9 passed، 51 assertions، بSQLite صريح.
- `DataMigrationRealSourceTest`: 1 passed، 51 assertions، بSQLite صريح؛ الـfull workflow القديم يستمر فقط عندما يرسل الاختبار `migration_scope=full`.
- `npm test`: 115 passed، 0 failed.
- `npm run build`: نجح (مع تحذير حجم chunk موجود في Vite).
- `vendor/bin/pint --test`: passed، و`php -l`/diagnostics للملفات الجديدة بلا أخطاء.
- التحليل الحقيقي للـdump تم بنجاح؛ لم يُنفذ posting بسبب حالة قاعدة التطوير ومتطلبات المراجعة.
- لا يوجد حتى الآن test يثبت posting Phase1 كاملًا على target database؛ يلزم clone جديد بعد استعادة backup. اختبارات الحساب/الرفع/full workflow لا تثبت posting Phase1.

## بوابات هذه المرحلة

| البوابة | النتيجة |
|---|---|
| `PartyOpeningBalanceCalculatorTest` | 5 passed، 33 assertions، SQLite صريح |
| `npm test` | 115 passed، 0 failed |
| `npm run build` | نجح؛ تحذير Vite chunk size فقط |
| `vendor/bin/pint --test` | passed |
| `php -l` وdiagnostics | بلا أخطاء للملفات المعدلة |
| `php artisan test` الكامل | **لم يُعتمد**: تشغيل سابق اتصل بMariaDB ودمّر بيانات التطوير؛ يجب إعادة تشغيله بعد restore وعزل SQLite |
| MySQL/MariaDB Phase1 posting | **لم يُنفذ**؛ لا توجد قاعدة clone صالحة حاليًا بعد cleanup |
| real-source Phase1 posting | **لم يُنفذ**؛ تم profile/read-only فقط |

## التقارير والبيانات الحساسة

الـdump الأصلي لم يُعدّل ولم يُنسخ إلى public ولم يدخل Git. `tmp_phase1_profile.php` أداة مؤقتة حُذفت بعد استخراج counts مجهولة. لا يحتوي هذا التقرير أسماء أو هواتف أو عناوين أو قائمة مبالغ فردية.

## القرارات المطلوبة قبل التنفيذ

1. اعتماد قاعدة `details_buy`/`steel_or_other` بدل تفسير الكود القديم per-ton للموردين المختلفين.
2. قرار لكل 76 supplier-sale متعارضة.
3. قرار للـ5 customer start balances والـ3 supplier start balances غير الصالحة.
4. قرار الأطراف الموجودة مسبقًا ذات activity أو opening balance.
5. تأكيد إشارة customer/supplier وفق control accounts.
6. استعادة قاعدة التطوير من backup موثوق، ثم تشغيل كل اختبارات Phase1 مع SQLite/clone معزول.

## الإجراء والتراجع

1. استعادة نسخة معتمدة والتحقق من counts الأساسية.
2. `php artisan optimize:clear`.
3. تثبيت SQLite للاختبارات: `$env:DB_CONNECTION='sqlite'; $env:DB_DATABASE=':memory:'` ثم تشغيل الاختبار.
4. في staging: backup، `migrate --pretend`، migrations، ثم upload/analyze/approve/preflight.
5. لا post قبل إزالة كل unresolved blockers.
6. rollback قبل الوثائق عبر `/phase1/reverse` فقط؛ بعد أي مرحلة أخرى يلزم قرار/backup.

Inventory opening balances تبقى مرحلة لاحقة مستقلة ولا يجوز تنفيذها من Phase 1.
