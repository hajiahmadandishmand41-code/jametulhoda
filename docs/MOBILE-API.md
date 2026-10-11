# API بومی موبایل — قرارداد `/api/mobile/*`

خانوادهٔ کامل JSON برای اپلیکیشن اندروید جامعه‌الهدی. همهٔ مسیرها در
`config/routes.php` allowlist شده‌اند و فقط از `api/mobile/` اجرا می‌شوند.
پاسخ موفق: `{ "ok": true, "data": … }` یا `{ "ok": true, "items": […], "meta": … }`؛
پاسخ خطا: `{ "ok": false, "error": "<code>", "message": "پیام فارسی" }` با کد HTTP درست.

## محتوای عمومی (GET/HEAD)

| مسیر | پارامترها | توضیح |
| --- | --- | --- |
| `/api/mobile/health` | — | سلامت سامانه و اتصال دیتابیس |
| `/api/mobile/home` | `limit` | خانه: سایت، بنرها، شاخص و بخش‌های تجمیعی |
| `/api/mobile/posts` | `type` (all/news/article/research/report/announcement/event/program/religious/qa/speech)، `page`، `per_page`، `search`، `category`، `topic` | فهرست مطالب با صفحه‌بندی. `type=event` همان ترکیب صفحهٔ `/events` وب است |
| `/api/mobile/post` | `id` یا `slug` | جزئیات مطلب: متن/HTML امن، گالری، پیوست‌ها، موضوع‌ها، وضعیت لایک |
| `/api/mobile/books` | `page`، `per_page`، `search`، `topic` | کتابخانه |
| `/api/mobile/book` | `id` یا `slug` | جزئیات کتاب + `pdf_url`/`word_url` برای دانلود |
| `/api/mobile/collections` | — | مجموعه‌های درسی + جلدها |
| `/api/mobile/lessons` | `collection`، `volume`، `search`، `page`، `per_page` | درس‌ها |
| `/api/mobile/lesson` | `id` یا `slug` | جزئیات درس + `audio_url`/`video_url`/`pdf_url` + پیوست‌ها |
| `/api/mobile/topics` | — | موضوع‌ها |
| `/api/mobile/topic` | `id` یا `slug` | موضوع + زیرموضوع‌ها + محتوای مرتبط |
| `/api/mobile/media` | `kind` (all/audio/video)، `page`، `per_page` | رسانه‌های منتشرشده |
| `/api/mobile/media-item` | `kind` (audio/video)، `id` | جزئیات رسانه با `media_url` جریان/دانلود |
| `/api/mobile/search` | `q`، `type`، `topic`، `sort`، `page`، `per_page` | جست‌وجوی کامل همهٔ انواع (همان `searchAll`) |
| `/api/mobile/categories` | — | دسته‌بندی‌ها |
| `/api/mobile/pages` | `page=about\|contact` | درباره ما / تماس |
| `/api/mobile/contact` | **POST** JSON | ارسال پیام تماس (نرخ‌محدود: ۳ پیام/۳۰ دقیقه/IP) |

## احراز هویت (Bearer)

توکن با `Authorization: Bearer jh1_…` می‌آید. خود توکن ۲۵۶ بیت تصادفی است و
**فقط hash (sha256) آن در `api_tokens` ذخیره می‌شود**؛ عمر پیش‌فرض ۶۰ روز، قابل
باطل‌سازی. تغییر رمز ⇒ افزایش `auth_version` و باطل‌شدن همهٔ توکن‌ها (وب و اپ با هم).

| مسیر | متد | بدنه (JSON) | توضیح |
| --- | --- | --- | --- |
| `/api/mobile/auth/register` | POST | `full_name, country, phone, email?, password, password_confirm, agreed_terms, device_label?` | ثبت‌نام عضو (همیشه role=user)؛ ۲۰۱ + توکن |
| `/api/mobile/auth/login` | POST | `identifier` (ایمیل/نام کاربری/تلفن)، `password, device_label?` | ورود؛ محدودیت تلاش مثل وب |
| `/api/mobile/auth/me` | GET | — | حساب جاری |
| `/api/mobile/auth/profile` | POST | `full_name?/email?/phone?/avatar?` | به‌روزرسانی پروفایل |
| `/api/mobile/auth/change-password` | POST | `current_password, new_password, new_password_confirm` | تغییر رمز؛ همهٔ توکن‌ها باطل می‌شوند |
| `/api/mobile/auth/logout` | POST | — | باطل‌کردن همین توکن (خروج امن) |

هیچ پاسخی hash رمز یا توکن دیگری را برنمی‌گرداند. نقش هرگز از ورودی خوانده نمی‌شود.

## لایک

| مسیر | متد | بدنه | توضیح |
| --- | --- | --- | --- |
| `/api/mobile/likes?ref_type=&ref_id=` | GET | — | `like_count` + `liked` (ناشناس: `liked=null`) |
| `/api/mobile/likes` | POST | `ref_type, ref_id, action=like\|unlike` | نیازمند ورود؛ **idempotent** |

- `ref_type ∈ {post, book, lesson, media}` و محتوا باید منتشرشده باشد (۴۰۴ در غیر این صورت).
- جدول `content_likes` قید یکتایی `(user_id, ref_type, ref_id)` دارد؛ ثبت تکراری
  (حتی هم‌زمان) یک ردیف می‌سازد.
- `like_count` فقط از `COUNT(*)` دیتابیس می‌آید؛ هیچ شمارنده‌ای از کلاینت پذیرفته نمی‌شود.

## ملاحظات امنیتی

- فقط محتوای `published`/فعال به اپ نشان داده می‌شود؛ پیش‌نویس‌ها هرگز نشت نمی‌کنند.
- `content_html` با همان sanitizer سایت (`safeRichText`) پاک می‌شود؛ `content_text` متن ساده است.
- عملیات عضو با Bearer است و cookie محیطی ندارد (CSRF ندارد)؛ عملیات ناشناس نرخ‌محدودند.
- هیچ رمز، کلید، یا دسترسی مدیریتی در این API یا در APK قرار نمی‌گیرد.
- جداول `api_tokens` و `content_likes` فقط افزودنی هستند؛ `php bin/migrate.php` آن‌ها را
  idempotent اضافه می‌کند و هیچ داده/فایل موجودی را حذف یا بازنشانی نمی‌کند.

## آزمون

```sh
# یک دیتابیس SQLite آزمایشی + سرور محلی (یا هر PHP 8.3):
php bin/dev-db.php
APP_ENV=development DB_DRIVER=sqlite SQLITE_PATH=/tmp/jhd.sqlite \
SESSION_DRIVER=files UPLOAD_STORAGE=local SITE_URL=http://127.0.0.1:8080 \
php -S 127.0.0.1:8080 router.php

# آزمون قرارداد (بدون Playwright):
TEST_BASE_URL=http://127.0.0.1:8080 node tests/mobile-api.mjs
```

گردش‌کار **Mobile API tests** در GitHub Actions همین آزمون‌ها را روی دیتابیس
ایزولهٔ SQLite اجرا می‌کند. فید قبلی `/api/mobile-feed` برای سازگاری دست‌نخورده مانده است.
