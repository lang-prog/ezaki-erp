# إصلاح خطأ CSRF token mismatch

## السبب

صفحات الشركة كانت تستخدم Cookie الجلسة `ezaki-company-session`، بينما طلبات الحفظ إلى `/api/v1` كانت تبدأ جلسة API باستخدام Cookie مختلف. رمز CSRF الموجود في صفحة الشركة كان مرتبطًا بجلسة الشركة، لذلك يرفض Laravel طلبات POST وPUT برسالة `CSRF token mismatch`.

## الإصلاح

تم تسجيل `ScopeSessionCookie` ضمن مجموعة `api` قبل بدء جلسة Sanctum. وبذلك تستخدم طلبات الواجهة وطلبات API نفس Cookie جلسة الشركة، ويصبح رمز CSRF صالحًا في طلبات الإضافة والتعديل والحفظ.

## التطبيق على Windows/XAMPP

```powershell
git checkout main
git pull --ff-only origin main
git am --3way .\e-zaki-csrf-session-hotfix.patch
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
npm run build
git push origin main
```

## التطبيق على الاستضافة

بعد وصول التحديث من GitHub:

```bash
git checkout main
git pull --ff-only origin main
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

إذا كان `public/build` غير محدث، نفّذ `npm ci --include=optional && npm run build` قبل إعادة إنشاء كاش Laravel.

## تنظيف جلسة المتصفح

بعد تحديث الموقع، نفّذ تسجيل خروج ثم دخول مرة أخرى. وإذا استمر الخطأ بسبب Cookie قديم، افتح الموقع في نافذة Private/Incognito أو احذف Cookies الخاصة بالدومين ثم سجّل الدخول من جديد.

لا تقم بتعطيل CSRF أو إزالة حماية المسارات؛ الإصلاح يحافظ على الحماية ويعيد استخدام جلسة الشركة الصحيحة فقط.
