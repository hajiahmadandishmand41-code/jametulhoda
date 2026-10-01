# راهنمای نصب

## نصب روی InfinityFree (PHP + MySQL) — مسیر پیشنهادی

> **نکته معماری:** دیتابیس‌های InfinityFree فقط از داخل شبکه خود InfinityFree در دسترس‌اند
> (hostnameهای `sqlXXX.infinityfree.com` رکورد DNS عمومی ندارند). پس اگر دیتابیس روی
> InfinityFree است، خودِ برنامه هم باید روی همان هاست اجرا شود؛ Vercel نمی‌تواند به این
> دیتابیس وصل شود.

1. در کنترل‌پنل InfinityFree (**MySQL Databases**) یک دیتابیس بسازید و مقادیر
   **MySQL Host / Database Name / Username / Password** را دقیقاً یادداشت کنید.
   (اگر دیتابیس قبلی دارید و رمزش را نمی‌دانید، رمز کاربر را در همان صفحه تغییر دهید.)
2. در **SSL/TLS Certificates** گواهی رایگان Let's Encrypt را برای دامنه فعال کنید و تا
   آماده شدن آن صبر کنید. سایت را با **https** باز کنید.
3. تمام فایل‌های پروژه را در پوشهٔ document root دامنه (`htdocs`) آپلود کنید (FTP با FileZilla یا Online File Manager). فایل مخفی ریشهٔ **`.htaccess`** را هم حتماً منتقل کنید؛ در FileZilla نمایش فایل‌های مخفی را فعال کنید و مطمئن شوید `.htaccess` کنار `router.php` و `index.php` قرار گرفته است. Apache باید `mod_rewrite` فعال داشته باشد. فایل `config/local.php` و `config/install.lock` نباید در آپلود باشند؛ نصب‌کننده خودش می‌سازد.
4. آدرس `https://your-domain/php/install` را در مرورگر باز کنید
   (نشانی کوتاه `https://your-domain/install` هم به همان نصاب می‌رسد؛ مسیر قدیمی
   `/install.php` عمداً ۴۰۴ می‌ماند تا نصاب ریشه قابل اجرا نباشد).
5. مشخصات MySQL و اطلاعات حساب مدیر را وارد کنید و «شروع نصب» را بزنید.
   - **Site URL** را با `https://` و دامنه نهایی وارد کنید. اگر خالی بگذارید، نصب‌کننده
     `https://` + دامنه‌ای که در مرورگر باز است را ذخیره می‌کند. مقدار `http://` باعث
     می‌شود `sitemap.xml` خطا بدهد و `robots.txt` روی `Disallow: /` بماند.
   - رمز مدیر اجباری و باید دست‌کم ۱۴ نویسه و منحصربه‌فرد باشد؛ هیچ رمز پیش‌فرض یا
     رمز عمومی‌ای در نصاب وجود ندارد. رمز را در محیط امن خود نگه دارید.
   - اگر حساب مدیر از قبل در دیتابیس وجود داشته باشد، نصب‌کننده رمز آن را **بازنشانی**
     می‌کند (با `password_hash()`) و با افزایش `auth_version` همه نشست‌های فعال را
     باطل می‌کند؛ دسترسی آن حساب `superadmin` و فعال می‌شود.
6. نصب‌کننده `config/local.php` و `config/install.lock` را می‌سازد و طرحواره
   `database/database.mysql.sql` را اعمال می‌کند (۲۲ جدول + داده‌های اولیه موضوع‌ها/دسته‌ها/تنظیمات).
   هر دو فایل با `.htaccess` و allowlist مسیرها از دسترسی وب مسدودند (۴۰۴).
7. از `/admin` وارد پنل شوید و رمز را از `/admin/change-password` تغییر دهید.

بازبینی پس از نصب:

- `/` , `/topics` , `/articles` , `/news` , `/books` , `/lessons` , `/qa` , `/contact` → 200؛ به‌ویژه کلیک «اخبار» باید به `/news` برسد، نه فایل داخلی `pages/news.php`.
- اگر `/news` هنوز 404 است: بررسی کنید `.htaccess` دقیقاً در document root کنار `router.php` وجود داشته باشد، فایل مخفی FTP آپلود شده باشد، Apache `mod_rewrite` را فعال کرده و پروژه در پوشه‌ای غیر از document root آپلود نشده باشد. در ریشهٔ دامنه، `BASE_PATH` را خالی بگذارید؛ در زیرپوشه مقدار آن باید همان پیشوند URL باشد.
- `/robots.txt` → `Allow: /` و خط `Sitemap:`
- `/sitemap.xml` → XML با دامنه خودتان
- ساخت یک مطلب آزمایشی با تصویر → فایل باید در `uploads/posts/` ذخیره و در فرانت دیده شود

نکات و محدودیت‌های InfinityFree (پلن رایگان):

- اجرای هر اسکریپت PHP حداکثر **۱۰ ثانیه** — آپلود ویدیوی بزرگ ممکن است timeout شود.
- سقف هر دیتابیس **۵۰ مگابایت** و سقف کل **۳۰٬۰۰۰ فایل (inode)**.
- **SSH و Cron وجود ندارد**؛ اجرای زمان‌بندی‌شده ممکن نیست. پاک‌سازی صف حذف فایل‌ها در مسیر
  درخواست‌ها انجام می‌شود (هنگام آپلود/حذف). `bin/storage-gc.php` برای اجرای دستی روی
  هاست‌های دارای CLI است.
- **PHP `mail()` بسته است**؛ فرم تماس هیچ ایمیلی نمی‌فرستد و پیام‌ها فقط در
  `/admin/messages` ذخیره می‌شوند (کد پروژه هم از `mail()` استفاده نمی‌کند).
- آپلودها روی همین هاست و در پوشه `uploads/` ذخیره می‌شوند؛ نیازی به S3 و Composer نیست.
- اگر دیتابیس را دستی (phpMyAdmin) ایمپورت می‌کنید، همان ترتیب نصب‌کننده را رعایت کنید و
  کلیدهای رشته‌ای ایندکس‌دار را بیش از ۷۰۰ کاراکتر نگیرید (سقف ایندکس InnoDB با utf8mb4 برابر
  ۷۶۸ کاراکتر / ۳۰۷۲ بایت است؛ خطای ۱۰۷۱ «Specified key was too long»).

### خطاهای رایج نصب

| نشانه | علت / راه‌حل |
|---|---|
| `SQLSTATE[42000] 1071 Specified key was too long` | طرحواره قدیمی؛ از نسخه اصلاح‌شده `database/database.mysql.sql` استفاده کنید (کلیدهای ایندکس‌دار ≤ ۷۰۰ کاراکتر) |
| صفحهٔ «سایت هنوز نصب نشده است» | یعنی `config/local.php` ساخته نشده؛ آدرس `/php/install` را باز کنید و نصب را کامل کنید |
| صفحهٔ «اتصال به دیتابیس برقرار نشد» | مشخصات MySQL در `config/local.php` یا وضعیت دیتابیس در کنترل‌پنل را بررسی کنید |
| «نصب قبلاً انجام شده و مسیر نصب قفل شده است» | برای نصب مجدد، `config/install.lock` را حذف کنید (داده‌ها حفظ می‌شوند؛ نصب idempotent است) |
| صفحه ۵۰۳ با «شناسه پیگیری» | `config/local.php` ساخته نشده یا مشخصات MySQL غلط است؛ پنل InfinityFree → Error Logs |
| حلقه ریدایرکت در ورود | سایت را با https باز کنید و SSL را فعال کنید |
| `sitemap.xml` → 503 | مقدار `SITE_URL` در `config/local.php` باید آدرس کامل `https://...` باشد |
| `robots.txt` → `Disallow: /` | همان علت بالا |

## نصب و اجرای محلی / Vercel + Neon (PostgreSQL)

- نصب و اجرای محلی: [README](README.md)
- تنظیم متغیرهای تولید، Neon، Vercel و S3: [فهرست آماده‌سازی تولید](PRODUCTION_CHECKLIST_FA.md)
- وضعیت پیکربندی زنده: تا زمان تنظیم `DATABASE_URL` و اجرای schema، سایت Vercel محتوای پایگاه داده را نمایش نمی‌دهد.

از دستورهای نصب قدیمی یا رمزهای نمونه استفاده نکنید. اسکریپت‌های `bin/install-cli.php`، `bin/migrate-sections.php` و `bin/db-test.php` فقط wrapper ابزار CLI هستند (در ریشه وب نیستند و از وب ۴۰۴ می‌دهند)؛ نصب وب واقعی همان `/php/install` است. اعتبارنامه‌های موجود در تاریخچه باید rotate شوند؛ حذف از نسخه فعلی تاریخچهٔ Git را پاک نمی‌کند.

### ساختار پوشه‌ها پس از بازسازی

```
index.php  router.php  robots.php  sitemap.php  .htaccess   ← تنها فایل‌های ریشه وب
config/     config.php  database.php  routes.php  local.php  local.example.php  production.ini
includes/   auth.php  functions.php  header.php  footer.php  session.php  storage.php  media.php …
admin/      index.php  login.php  logout.php  change-password.php  settings.php
            users/ articles/ books/ lessons/ media/ categories/ topics/ news/ posts/ speeches/
            banners/ messages/ lesson-collections/
pages/      about  articles  books  book  lessons  lesson  topics  topic  search  contact …
content/    home-intro.php (قطعه‌های نمایشی)
assets/     css/  js/  img/  fonts/  vendor/
uploads/    images/  books/  videos/  audios/  documents/  (+ audio/ video/ posts/ … قدیمی)
database/   database.mysql.sql  database.postgres.sql  migrations/
php/        install.php (نصاب مرورگری)
bin/        migrate.php  create-admin.php  storage-gc.php  db-test.php …
storage/    logs/  cache/
```

مرجع مسیرها و کنترلرها در [`config/routes.php`](config/routes.php) نگهداری می‌شود؛ آزمون ایستای آن را با `python3 tests/verify_routes.py` اجرا کنید.
