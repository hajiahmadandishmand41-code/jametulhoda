# جامعة‌الهدی — پایگاه علمی، آموزشی و پژوهشی

[![Vercel Production](https://img.shields.io/website?url=https%3A%2F%2Fjametulhoda.vercel.app%2F&label=Vercel%20Production&logo=vercel)](https://jametulhoda.vercel.app/)  [![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fhajiahmadandishmand41-code%2Fjametulhoda)

**Production:** https://jametulhoda.vercel.app/

> پیش از بهره‌برداری واقعی، الزامات دیتابیس، session و فضای ذخیره‌سازی را در [فهرست آماده‌سازی تولید](PRODUCTION_CHECKLIST_FA.md) تکمیل کنید. در بررسی ۲۸ سپتامبر ۲۰۲۶، سایت عمومی پیام پیکربندی‌نشدن دیتابیس را نمایش می‌داد؛ کد این مخزن نمی‌تواند اسرار و تنظیمات محیط Vercel را به‌جای مالک پروژه تنظیم کند.

وب‌سایت فارسی و RTL با PHP، MySQL/PostgreSQL، کتابخانه، دروس، اخبار، مقالات، صوت و ویدیو و پنل مدیریت. صفحات PHP موجود حفظ شده‌اند؛ یک front controller قابل‌اعتماد، ذخیره‌سازی مرکزی و هویت بصری مشترک به آن‌ها افزوده شده است و ساختار پوشه‌ها در بازسازی ۲۰۲۶-۰۹ مرتب شده است (بدون حذف قابلیت).

## معماری

- **PHP 8.3+**؛ production با Apache داخل `Dockerfile.vercel`، بدون استفاده از PHP built-in server در production.
- **MySQL/MariaDB (InfinityFree) یا PostgreSQL (Neon)**؛ PDO با prepared statements بومی، `DB_*` برای MySQL و `DATABASE_URL` با TLS تأییدشده برای PostgreSQL. قطعه‌های SQL مخصوص PostgreSQL به‌صورت شفاف برای MySQL نرمال‌سازی می‌شوند (`config/database.php`).
- **Session پایدار در دیتابیس فعال** (PostgreSQL یا MySQL)؛ session فایل فقط برای آزمون/development.
- **Storage:** local در development، S3-compatible در production. آدرس عمومی با مسیر فیزیکی جداست.
- **رابط:** فونت Vazirmatn و Bootstrap RTL محلی، پوسته روشن/تاریک، منوی موبایل، بدون ابزار JS سنگین.
- **Routing:** `.htaccess` همه درخواست‌ها را به `router.php` می‌فرستد و `router.php` فقط allowlist `config/routes.php` را می‌خواند. هر نشانی اصلی به‌صورت خودکار سه شکل `/x`، `/x/` و `/x.php` را پاسخ می‌دهد (aliasها فقط با همان نوشتار و اسلش پایانی، تا مسیرهای مسدود مثل `/install.php` خودکار باز نشوند)؛ مسیرهای پویا (`/post/{slug}`، `/book/{id}`، `/lessons/{collection}`، `/search/{q}`) هم ثبت شده‌اند. هیچ فایل PHP دیگری از وب قابل اجرا نیست.

## اجرای محلی

نیازمندی‌ها: PHP 8.3+ با `pdo_pgsql`, `mbstring`, `fileinfo`, `gd` (JPEG/WebP), `dom`, `curl`, `zip`؛ Composer 2؛ PostgreSQL 15+. `composer.lock` و `package-lock.json` نسخه وابستگی‌ها را قفل می‌کنند.

```sh
composer install --no-dev
# مقادیر واقعی را در محیط خود تنظیم کنید؛ فایل .env خودکار خوانده نمی‌شود.
export APP_ENV=development
export DATABASE_URL='postgresql://YOUR_USER:YOUR_PASSWORD@127.0.0.1:5432/YOUR_DATABASE?sslmode=disable'
export UPLOAD_STORAGE=local
export SESSION_DRIVER=database
php bin/migrate.php
php -S 0.0.0.0:8080 router.php
```

برای اجرای زیرمسیر، مثلاً `/school`، `BASE_PATH=/school` قرار دهید و تمام درخواست‌ها را به router بفرستید. دامنه در لینک‌های داخلی hard-code نمی‌شود. برای canonical و sitemap، `SITE_URL` را برابر URL کامل عمومی (شامل زیرمسیر در صورت وجود) قرار دهید.

### اجرای محلی بدون سرور دیتابیس (SQLite)

برای کار روی UI/لینک‌ها و آزمون‌های HTTP می‌توانید به‌جای PostgreSQL/MySQL از درایور
محلی SQLite استفاده کنید (فقط توسعه/آزمون؛ در `APP_ENV=production` رد می‌شود).
طرحواره و داده‌های نمونه در `tests/fixtures/schema.sqlite.sql` و
`tests/fixtures/seed.sqlite.sql` هستند و با `bin/dev-db.php` ساخته می‌شوند:

```sh
export APP_ENV=development DB_DRIVER=sqlite SESSION_DRIVER=files \
       SQLITE_PATH=/tmp/jametulhoda-dev.sqlite UPLOAD_STORAGE=local
php bin/dev-db.php            # دیتابیس را از نو می‌سازد (schema + seed)
php -S 0.0.0.0:8080 router.php
# مدیر نمونهٔ seed: admin / TestAdmin123!@#
TEST_ADMIN_USERNAME=admin TEST_ADMIN_PASSWORD='TestAdmin123!@#' npm run test:http
node tests/links.mjs
```

CI مسیرها، امنیت، مرورگر و جریان‌های HTTP را با fixture ایزولهٔ SQLite اجرا می‌کند؛ آزمون production دیتابیس واقعی، مهاجرت داده و bucket باید جداگانه در محیط staging انجام شود.

### حساب مدیر

نام کاربری مدیر در نصب تازه می‌تواند `admin` باشد، اما هیچ رمز پیش‌فرض یا رمز عمومی‌ای وجود ندارد. برای ساخت/بازنشانی حساب، رمز یکتا و دست‌کم ۱۴ نویسه را فقط از محیط محرمانه میزبان یا محیط CLI وارد کنید. رمز به‌صورت `password_hash()` ذخیره می‌شود و بازنشانی حساب، نشست‌های قبلی را باطل می‌کند.

```sh
ADMIN_USERNAME=me ADMIN_PASSWORD='یک-رمز-طولانی-و-یکتا-با-حداقل-۱۴-نویسه' php bin/create-admin.php
unset ADMIN_PASSWORD
```

در نصاب وب، رمز مدیر اجباری است؛ آن را پس از نصب در محل امن نگه دارید و در صورت افشا فوراً از `/admin/change-password` تغییر دهید.

ورود اعضا و کارکنان از مسیرهای عمومی `/login` و `/register` انجام می‌شود؛ مدیران وارد پنل `/admin/` می‌شوند. مدیر ارشد در `/admin/users` حساب کارکنان، نقش و فعال‌بودن را مدیریت می‌کند. ویراستار به محتوا دسترسی دارد؛ تنظیمات، کاربران و تشخیص سامانه فقط در اختیار مدیر ارشد است.

## گالری چندتصویری و رسانهٔ مطلب

هر مطلب می‌تواند چند تصویر داشته باشد؛ ترتیب، تصویر اصلی، متن جانشین (alt)،
جایگزینی و حذف همه از `/admin/content` و با ذخیرهٔ واقعی در DB و دیسک انجام
می‌شود. ویدیو/صوت/سند مطلب هم از همان صفحه مدیریت می‌شوند (تغییر نام، ترتیب،
حذف، ویدیوی شاخص).

- ستون ترتیب: `post_images.sort_order` (+ ایندکس `post_images_idx_order`)؛ مهاجرت idempotent در `database/`.
- endpointها: `/admin/content/gallery` و `/admin/content/media` (JSON، محافظت‌شده با auth + CSRF).
- صفحهٔ عمومی: `jhd_gallery()` در `includes/cards.php` گرید ریسپانسیو و لایت‌باکس کامل
  (کیبورد، سوایپ، بندانگشتی، دانلود) می‌سازد؛ اسکریپت بدون وابستگی در `assets/js/gallery.js`.
- قبل از انتشار رسانه، محدودیت‌ها و سیاست فضای ذخیره‌سازی را در [فهرست آماده‌سازی تولید](PRODUCTION_CHECKLIST_FA.md) مرور کنید.

## ذخیره‌سازی رسانه روی Vercel

فایل‌سیستم Vercel فقط‌خواندنی است و تنها `/tmp` writable است که آن هم با هر
اجرای تابع از بین می‌رود. بنابراین **هیچ فایلی روی خود Vercel ذخیره نمی‌شود**؛
`includes/storage.php` در این حالت آپلود را رد می‌کند تا نشانی مرده در دیتابیس
ثبت نشود. برای فعال‌شدن آپلود تصویر/ویدیو/صوت/PDF یکی از این دو لازم است:

| حالت | متغیرهای لازم |
| --- | --- |
| `UPLOAD_STORAGE=vercel-blob` (پیشنهادی) | `BLOB_READ_WRITE_TOKEN` (با ساخت Blob Store در داشبورد Vercel خودکار تزریق می‌شود) + `UPLOAD_BASE_URL=https://<store>.public.blob.vercel-storage.com` |
| `UPLOAD_STORAGE=s3` | `S3_ENDPOINT`، `S3_REGION`، `S3_BUCKET`، `S3_ACCESS_KEY_ID`، `S3_SECRET_ACCESS_KEY` + `UPLOAD_BASE_URL` برابر نشانی https عمومی bucket |

وضعیت واقعی این پیکربندی در `/admin/diagnostics` (کارت «فضای ذخیره‌سازی رسانه»)
نمایش داده می‌شود؛ در صورت ناقص‌بودن، نام متغیرهای پیکربندی‌نشده نوشته می‌شود و
پیام خطای آپلود هم همان دلیل را به مدیر نشان می‌دهد. هیچ مقدار محرمانه‌ای نمایش
داده نمی‌شود.

**سقف حجم:** پلتفرم Vercel بدنهٔ هر درخواست به تابع را به **۴.۵ مگابایت** محدود
می‌کند (غیرقابل تغییر) و `router.php` هم بالای ۴ مگابایت را با ۴۱۳ پاسخ می‌دهد.
پس آپلود مستقیم از طریق فرم، عملاً به فایل‌های کوچک‌تر از ۴ مگابایت محدود است؛
مقادیر ۲۰/۲۰۰ مگابایت در `config/config.php` فقط روی میزبان‌های Apache/MySQL
قابل رسیدن‌اند. برای فایل‌های بزرگ باید آپلود مستقیم مرورگر به Blob/S3
(presigned URL) پیاده‌سازی شود.

## دیتابیس و داده‌های قبلی

`database/database.postgres.sql` schema مرجع PostgreSQL و `database/database.mysql.sql` schema مرجع MySQL/MariaDB است (هر دو idempotent). `php bin/migrate.php` بر اساس درایور فعال، فایل درست را از `database/` انتخاب می‌کند؛ غیرمخرب و قابل اجرای مجدد است. ایجاد/تغییر جدول در درخواست وب انجام نمی‌شود. `bin/install-cli.php`، `bin/migrate-sections.php` و `bin/db-test.php` فقط از CLI اجرا می‌شوند.

**این schema، داده‌های MySQL موجود را خودکار منتقل نمی‌کند.** پیش از انتقال داده، نسخه پشتیبان بگیرید، داده‌ها را در یک دیتابیس آزمایشی PostgreSQL import کنید، enum/زمان/encoding/کلیدها را تطبیق دهید، sequenceها را پس از حفظ IDها تنظیم کنید و شمار رکوردها و تمام فایل‌های قدیمی را مقایسه کنید. هیچ اتصال به دیتابیس واقعی قدیمی یا Neon در این بررسی انجام نشده است.

## متغیرهای محیطی

فهرست بدون secret در [`.env.example`](.env.example) است. شرایط متغیرها، TLS پایگاه داده، دسترسی bucket، آزمون پس از استقرار و محدودیت محیط زنده در [فهرست آماده‌سازی تولید](PRODUCTION_CHECKLIST_FA.md) آمده است. `.env` را commit نکنید؛ secrets را فقط در dashboard میزبان یا secret manager نگهداری کنید.

## آزمون‌ها

آزمون‌های HTTP در دیتابیس **آزمایشی و قابل حذف** محتوا ایجاد می‌کنند؛ روی سایت واقعی اجرا نکنید.

```sh
find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l
php tests/security.php
# فقط با DATABASE_URL آزمایشی، APP_ENV=development و UPLOAD_STORAGE=local:
ALLOW_DESTRUCTIVE_TESTS=1 php tests/storage-recovery.php
npm ci
# TEST_BASE_URL، TEST_ADMIN_USERNAME، TEST_ADMIN_PASSWORD را برای محیط آزمایش تنظیم کنید.
npm run test:http
npx playwright install chromium
npm run test:browser
node tests/links.mjs
```

نتایج آزمون‌ها در `test-results/` تولید می‌شوند و وارد Git نمی‌شوند. تنها workflow موجود در مخزن `.github/workflows/production-e2e.yml` است که با `workflow_dispatch` و به‌صورت دستی، آزمون سرتاسری محیط زنده را اجرا می‌کند؛ بقیهٔ آزمون‌ها (`npm run test:http`، `tests/route-smoke.mjs`، `php tests/security.php`، `php tests/storage-drivers.php`) باید محلی یا در CI دلخواه اجرا شوند. وجود workflow به‌تنهایی به معنی موفق‌بودن اجرا نیست؛ وضعیت واقعی باید در Checks مربوط به commit/PR تأیید شود.

## ساختار پوشه‌ها

```
index.php  router.php  robots.php  sitemap.php  .htaccess   ← ریشه وب
config/     config.php  database.php  routes.php  local.php*  local.example.php  production.ini
includes/   auth.php  functions.php  header.php  footer.php  session.php  storage.php  media.php …
admin/      index.php  login.php  logout.php  change-password.php  settings.php
            users/ articles/ books/ lessons/ media/ categories/ topics/ news/ posts/ speeches/
            banners/ messages/ lesson-collections/ includes/
pages/      about  articles  books  book  lessons  lesson  topics  topic  search  contact …
content/    home-intro.php
assets/     css/  js/  img/  fonts/  vendor/
uploads/    images/  books/  videos/  audios/  documents/  (+ audio/ video/ posts/ lessons/ …)
database/   database.mysql.sql  database.postgres.sql  migrations/
php/        install.php
bin/        migrate.php  create-admin.php  storage-gc.php  db-test.php  install-cli.php …
storage/    logs/  cache/
```

\* `config/local.php` و `config/install.lock` توسط نصاب ساخته می‌شوند، در `.gitignore` هستند و
با `.htaccess` و allowlist مسیرها از وب ۴۰۴ می‌دهند. الگوی دستی: `config/local.example.php`.

## مستندات

- [راهنمای نصب (InfinityFree + MySQL)](INSTALL_GUIDE_FA.md)
- [فهرست آماده‌سازی برای محیط تولید](PRODUCTION_CHECKLIST_FA.md)
- [نمونه متغیرهای محیطی بدون اسرار](.env.example)
- [آزمون سرتاسری محیط زنده](.github/workflows/production-e2e.yml)
- [آزمون رانندهٔ فضای ذخیره‌سازی](tests/storage-drivers.php)

فونت و کتابخانه‌های frontend با مجوز اصلی در `assets/fonts/` و `assets/vendor/` نگهداری شده‌اند. اسرار را فقط از secret manager میزبان تنظیم کنید و اگر credential قدیمی پیش‌تر در Git یا log منتشر شده، آن را rotate کنید.
