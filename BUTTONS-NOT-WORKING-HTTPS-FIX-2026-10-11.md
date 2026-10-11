# إصلاح توقف أزرار الحفظ والتعديل والإضافة

## سبب المشكلة

كانت الاستضافة تنهي اتصال HTTPS عند OpenResty/Passenger ثم تمرر الطلب إلى Laravel داخليًا عبر HTTP. لذلك كان Laravel يولّد روابط ملفات Vite مثل:

```text
http://erp.example.com/build/assets/app-xxxx.js
```

بينما الصفحة نفسها تعمل على HTTPS. المتصفح يحجب ملفات JavaScript بسبب Mixed Content، فتظهر الصفحة أحيانًا لكن لا تعمل أزرار الحفظ والتعديل والإضافة.

## الإصلاح المطبق

أضيف Middleware باسم `TrustProxies`، وتم تسجيله قبل بدء جلسة الويب. عند وصول `X-Forwarded-Proto: https` من البروكسي، يتعرف Laravel على أن الطلب HTTPS ويولّد روابط CSS وJavaScript بصيغة HTTPS.

كما أضيف اختبار يمنع عودة المشكلة في الإصدارات القادمة.

## تطبيق الإصلاح على Windows/XAMPP

بعد وصول التحديث إلى GitHub:

```powershell
git checkout main
git pull --ff-only origin main
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
npm run build
php artisan optimize
```

## تطبيق الإصلاح على الاستضافة

من مجلد المشروع:

```bash
git checkout main
git pull --ff-only origin main
```

تأكد أن ملف `.env` يحتوي على القيم التالية، مع استبدال الرابط برابط الموقع الحقيقي:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.example.com
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
```

ثم نفّذ:

```bash
composer install --no-dev --optimize-autoloader
npm ci --include=optional
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

إذا لم يكن Node.js متاحًا أو كان إصدار Node قديمًا، نفّذ `npm ci --include=optional` و`npm run build` محليًا، ثم ارفع مجلد `public/build` الناتج مع ملفات المشروع.

## التحقق من نجاح الإصلاح

نفّذ الأمر التالي من Terminal الاستضافة:

```bash
curl -sk https://erp.example.com/login | grep -oE 'https://[^" ]+/build/assets/[^" ]+' | head
```

يجب أن تبدأ روابط الملفات الناتجة بـ `https://` وليس `http://`.

بعد ذلك افتح الموقع في المتصفح باستخدام تحديث قوي:

- Windows: `Ctrl + F5`
- أو افتح نافذة Private/Incognito

ثم جرّب زرًا بسيطًا مثل فتح قائمة، تعديل حساب، أو حفظ سجل. إذا لم يعمل الزر، افتح Developer Tools ثم Console وتأكد من عدم وجود رسالة `Mixed Content` أو `Failed to load module script`.
