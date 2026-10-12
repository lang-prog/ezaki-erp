# إصلاح خطأ 419 Page Expired

## السبب المؤكد

في بيئة الاستضافة، قيمة Cookie المسماة `XSRF-TOKEN` تكون مشفّرة من Laravel، وتبدأ عادةً بالقيمة المشفّرة `eyJpdiI6`.

كان العميل الأمامي يقرأ Cookie بعد فك ترميزها ثم يرسلها في الترويسة:

```text
X-CSRF-TOKEN
```

وهذا غير صحيح؛ لأن `X-CSRF-TOKEN` يجب أن يحتوي الرمز الخام، وليس قيمة Cookie المشفّرة. النتيجة كانت رفض تسجيل الدخول وكل طلبات الحفظ برسالة:

```text
419 Page Expired
```

## الإصلاح

أصبح العميل يميّز بين الحالتين:

- إذا كانت Cookie مشفّرة، يرسل القيمة الأصلية المرمّزة في:

```text
X-XSRF-TOKEN
```

- إذا كانت Cookie غير مشفّرة، يستخدم `X-CSRF-TOKEN`.

وهذه هي الصيغة التي يتوقعها Laravel ويدعمها Axios.

## الاختبارات

تم اختبار الموقع المنشور مباشرةً بمقارنة الترويستين:

- إرسال Cookie المشفّرة في `X-CSRF-TOKEN`: النتيجة `419`.
- إرسال Cookie المرمّزة في `X-XSRF-TOKEN`: الطلب يمر إلى التحقق من بيانات الدخول بدل رفض CSRF.

كما نجحت اختبارات المشروع:

- PHPUnit: عدد 98 اختبارًا.
- Assertions: عدد 1282.
- اختبار JavaScript الخاص بصيغة Cookie المشفّرة.
- بناء Vite.
- اختبارات التنقل.
- Pint وComposer وOpenAPI.

## النشر

بعد تطبيق الـPatch على Windows أو GitHub، يجب تنفيذ الأوامر التالية على الاستضافة:

```bash
cd /home/alawaadc/erp.al-awaad.com
git pull --ff-only origin main
source /home/alawaadc/nodevenv/erp.al-awaad.com/20/bin/activate
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

ثم يجب حذف Cookies الخاصة بالدومين أو فتح الموقع في نافذة خاصة قبل تسجيل الدخول مرة أخرى.
