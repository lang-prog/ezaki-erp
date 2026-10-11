# حزمة الجولة الثالثة: كشف الحساب والمستندات المالية

## ما تم تنفيذه

- إعادة بناء جدول **كشف حساب العميل/المورد** ليعرض:
  - التاريخ والبيان.
  - الوزن وسعر الوحدة.
  - قيمة المستند.
  - الدفعات أو المقبوضات.
  - الرصيد الجاري ورصيد البداية والإجماليات.
  - زر معاينة المستند داخل الصفحة.
- تصحيح روابط الإيصالات وسندات الصرف داخل كشف الحساب باستخدام أسماء المسارات الرسمية.
- فصل مسارات عرض وطباعة إيصالات القبض وسندات الصرف إلى دوال واضحة، لمنع أخطاء `not found`.
- إتاحة زر **تحصيل من عميل** أو **دفعة لمورد** داخل صفحة ملف الجهة عند امتلاك صلاحية `accounting.post` أو عند كون المستخدم مالك الشركة، مع بقاء الإدخال داخل Modal.
- إرفاق الوزن والسعر والقيمة داخل بيانات المستند المستخدمة في جدول كشف الحساب والمعاينة.
- إضافة اختبار تكاملي لمسارات العرض والطباعة وظهور زر الترحيل.

## فحوص القبول المنفذة

- `vendor/bin/phpunit` — **96 اختبارًا، 1277 assertion، ناجح**.
- `npm run build` — **ناجح**.
- `npm audit --omit=optional` — **0 vulnerabilities**.
- `vendor/bin/pint --test` — **ناجح**.
- `composer validate --no-check-publish` — **ناجح**.
- `php scripts/lint-openapi.php` — **ناجح**.
- `git diff --check` — **ناجح**.

## التطبيق على نسخة Windows/XAMPP

بعد التأكد من أن `git push origin main` أرسل النسخة إلى GitHub:

```powershell
git checkout main
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
npm ci --include=optional
npm run build
php artisan optimize:clear
php artisan migrate
```

لا تحتاج إلى تشغيل `composer install` أو `npm ci` مع كل تعديل صغير إذا لم تتغير `composer.lock` أو `package-lock.json`. لكن يجب تشغيل `npm run build` بعد أي تعديل في `resources/js` أو `resources/css` وقبل تجربة نسخة الإنتاج.
