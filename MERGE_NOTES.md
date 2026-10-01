> گزارش تاریخی نسخه قبلی؛ برای وضعیت فعلی به [Audit](docs/AUDIT_FA.md) و [README](README.md) مراجعه کنید.

# پروژه جامعه‌الهدی — نسخه ادغام‌شده (Merged)

## منشاء
- **پایه (Original):** `DB_B1_DB_B1.zip` — پروژه اصلی کامل
- **تغییرات (Modified):** `project_fixed.zip` — ۹ فایل اصلاح‌شده + ۱ فایل جدید

## فایل‌های جایگزین‌شده از Modified روی Original

| فایل | نوع تغییر |
|---|---|
| `admin/lessons/create.php` | Migration ستون‌های lessons + بهبود هندلینگ آپلود صوت |
| `admin/lessons/edit.php` | Migration ستون‌های lessons + رفع مسیر حذف فایل قدیمی |
| `admin/messages/index.php` | سازگاری با ستون `is_read` (به‌جای `status`) |
| `assets/js/main.js` | Bootstrap Icons برای لایک + Plyr برای ویدیو + data-confirm |
| `contact.php` | Migration ایمن به `is_read` + `ip_address` |
| `database.sql` | نسخه ۲.۳ — اضافه شدن `featured_video`، `summary`، `page_section` |
| `includes/functions.php` | MIME type های بیشتر برای صوت + `ensureMediaTable` |
| `index.php` | v2 — Plyr player + placeholder تیره برای آیتم‌های بدون تصویر |
| `update.sql` | **جدید** — Migration امن برای ستون‌های `featured_video`، `summary`، `page_section`، `is_read`، `ip_address` + جداول `post_likes` و `media_files` |

## Conflict برطرف‌شده
تابع `ensureMediaTable()` هم در `functions.php` و هم در `media.php` وجود داشت.
هر دو نسخه با `function_exists()` guard محافظت شدند تا duplicate declaration رخ ندهد.

## نحوه نصب
1. محتوای این پوشه را در ریشه هاست (مثلا `public_html/`) آپلود کنید.
2. فایل `config/config.php` را با اطلاعات دیتابیس خود ویرایش کنید.
3. فایل `database.sql` را در phpMyAdmin ایمپورت کنید (ایمن — جداول موجود حذف نمی‌شوند).
4. در صورت ارتقا از نسخه قبلی، فایل `update.sql` را نیز اجرا کنید.
5. دسترسی پوشه `uploads/` را روی ۷۵۵ تنظیم کنید.

## ساختار پوشه‌ها
```
.
├── admin/            # پنل مدیریت
├── ajax/             # endpoint های AJAX
├── assets/
│   ├── css/          # استایل‌ها
│   ├── images/       # تصاویر
│   └── js/           # اسکریپت‌ها (main.js + media-player.js)
├── config/           # تنظیمات و دیتابیس
├── includes/         # توابع مشترک
├── pages/            # صفحات اضافی
├── uploads/          # فایل‌های آپلودی
│   ├── audio/        # فایل‌های صوتی
│   ├── video/        # ویدیوها
│   ├── lessons/      # تصاویر دروس
│   ├── posts/        # تصاویر پست‌ها
│   ├── thumbs/       # thumbnail
│   ├── media/        # فایل‌های رسانه‌ای
│   └── site/         # فایل‌های سایت
├── .htaccess         # امنیت
├── admin.htaccess    # محافظت پنل
├── uploads.htaccess  # محافظت آپلودها
├── index.php         # صفحه اصلی
├── contact.php       # فرم تماس
├── news.php          # اخبار
├── articles.php      # مقالات
├── lessons.php       # دروس
├── post.php          # جزئیات پست
├── lesson.php        # جزئیات درس
├── search.php        # جستجو
├── category.php      # دسته‌بندی
├── about.php         # درباره ما
├── install.php       # نصب‌کننده
├── database.sql      # schema اصلی
├── update.sql        # Migration امن
└── migrate-sections.php
```
