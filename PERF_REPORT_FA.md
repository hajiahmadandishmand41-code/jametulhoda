# گزارش عملکرد — چرا Homepage چند دقیقه طول می‌کشید

همهٔ اعداد این گزارش اندازه‌گیری‌شده‌اند، نه حدس. ابزارها:

* `tests/perf-measure.mjs` — اندازه‌گیری واقعی HTTP (وضعیت، TTFB، زمان کل، بایت، هدرها) روی یک استقرار زنده.
* `includes/profiler.php` — هدر استاندارد `Server-Timing` با تفکیک PHP / اتصال DB / اجرای Query / تعداد Query / Region.
* `tests/perf-baseline-restore.sh` — کد قبل از بهینه‌سازی را برمی‌گرداند و با **همان ابزار** دوباره اندازه می‌گیرد.
* `.github/workflows/perf-audit.yml` — اجرای همهٔ موارد بالا از یک Runner با شبکهٔ واقعی.

---

## ۱. وضعیت اولیه (Production، کد قبلی)

| مسیر | تعداد Query | TTFB واقعی Production |
|---|---|---|
| `/this-route-does-not-exist` | 0 | **73 ms** |
| `/robots.txt` | 0 | **121 ms** |
| `/admin` (302) | 6 | **6.35 s** |
| `/sitemap.xml` | 8 | **11.34 s** |
| `/` | **130** | **88.58 s** (۸ نمونه: 83.80s – 89.87s) |
| `/programs`, `/admin/login` | زیاد | Timeout در ۴۰ ثانیه |
| CSS/JS/Font/تصویر | — | 65–90 ms، `x-vercel-cache: HIT` |

`x-vercel-cache: MISS` و `cache-control: no-cache` روی `/` در هر بار.

**نتیجهٔ کلیدی:** زمان پاسخ تقریباً خطی با «تعداد Query» بود:
`T ≈ 0.1s + ~0.7s × تعداد Query`.
مسیری که به دیتابیس دست نمی‌زد در ۷۳ تا ۱۲۱ میلی‌ثانیه جواب می‌داد — یعنی PHP و Router کند نبودند.

---

## ۲. علت‌های دقیق (با Evidence)

### الف) N+1 روی جدول `topics` — بزرگ‌ترین عامل

خروجی واقعی Profiler:

```
BEFORE  JHD_PROFILE /      q=130 dup=98 conn=2
BEFORE  JHD_PROFILE /about q=92  dup=80 conn=2
                           | 85x SELECT * FROM topics WHERE id=?
```

`topicUrl()` → `jhd_topic_slug_path()` → `getTopicBreadcrumbs()` زنجیرهٔ والد هر موضوع را
با یک Query جداگانه و بدون Cache بالا می‌رفت — برای **هر لینک موضوع**، و منوی موضوعات
در هر صفحه **دو بار** رندر می‌شود (منوی دسکتاپ + کشوی موبایل).
۸۵ Query یکسان در `/` و ۷۲ تا در هر صفحهٔ دیگر.

### ب) ساخت Session برای هر بازدیدکنندهٔ ناشناس

تگ `<meta name="csrf-token">` در `header.php` تابع `generateCsrfToken()` را صدا می‌زد و آن
`startSecureSession()` را. هر Session دیتابیسی:

* یک اتصال **دوم** به PostgreSQL باز می‌کند (`conn=2` در خروجی بالا)،
* دو دستور `CREATE TABLE IF NOT EXISTS` اجرا می‌کند،
* و `BEGIN; INSERT … ON CONFLICT DO NOTHING; SELECT … FOR UPDATE` می‌زند و **قفل ردیف را
  تا پایان Request نگه می‌دارد**.

هیچ کدی در پروژه `meta[name=csrf-token]` را نمی‌خواند (grep روی `assets/js`, `admin`,
`includes`, `pages` → صفر مورد). علاوه بر آن، همهٔ کنترلرهای `pages/*.php` هم
`startSecureSession()` را بدون شرط صدا می‌زدند.

> این همان مکانیزمی است که می‌توانست «چند دقیقه» تولید کند: چون روی اتصال‌ها هیچ
> `lock_timeout` و هیچ `statement_timeout` تنظیم نشده بود، اگر یک Invocation در میانهٔ کار
> کشته می‌شد و قفل ردیف Session را رها نمی‌کرد، Requestهای بعدی **بدون محدودیت زمانی**
> پشت همان قفل می‌ماندند.

### ج) پنج Query مرده در Homepage

`$homeCounts` / `$homeContentTotal` با پنج `COUNT(*)` پر می‌شدند و **هیچ‌جا در صفحه رندر
نمی‌شدند**.

### د) هشت Query به `information_schema` در هر Request

`jhd_db()` برای هر جدول هسته یک Query جدا می‌زد (۸ رفت‌وبرگشت قبل از خواندن اولین محتوا).

### ه) Queryهای تکراری/موازی

`getPosts(limit 6)` + `getPosts(limit 12)`، سه Query جدا برای انواع رویداد، و یک Query
جدا برای موضوع مطلب شاخص.

### و) نبودِ کامل Timeout روی PostgreSQL

نه `statement_timeout`، نه `lock_timeout`، نه `idle_in_transaction_session_timeout`.

### ز) `Cache-Control: no-cache` روی همهٔ صفحات

Edge هرگز نمی‌توانست بازدید تکراری را جذب کند → `x-vercel-cache: MISS` در هر بار.

### ح) Bottleneck خارج از کد: فاصلهٔ Region (اثبات‌شده)

`Server-Timing` از Preview:

```
/ total=16707ms php=4841ms dbexec=10327ms dbconnect=1540ms q=24
  region=iad1   dbregion=ap-southeast-1
```

Function در **iad1 (واشینگتن)** اجرا می‌شد و Supabase Pooler در
**ap-southeast-1 (سنگاپور)** است: حدود **۲۱۵ms در هر رفت‌وبرگشت شبکه**، یعنی
**۴۳۰ms برای هر Query** و **۱۵۴۰ms برای هر اتصال**.
چون مسیر بدون دیتابیس در ۷۳–۱۲۱ms جواب می‌داد، این بخش **قطعاً شبکه/توپولوژی است، نه کد**.

---

## ۳. اصلاحات انجام‌شده

| # | تغییر | فایل |
|---|---|---|
| 1 | Cache درخواستی برای ردیف‌های `topics` + memoize کردن Breadcrumbها (حذف N+1) | `includes/functions.php` |
| 2 | Session فقط وقتی Cookie هست یا Request غیر-GET است (`startPublicSession`) | `includes/auth.php`, `index.php`, `pages/*.php` |
| 3 | تگ CSRF فقط وقتی Session از قبل وجود دارد | `includes/header.php` |
| 4 | حذف پنج `COUNT(*)` مرده | `index.php` |
| 5 | یک Query به‌جای هشت Query روی `information_schema` | `includes/functions.php` |
| 6 | ادغام Queryهای تکراری فهرست‌ها و سه Query رویدادها در یکی | `index.php`, `includes/functions.php` |
| 7 | memoize کردن منوها، `getTopics()` و `getLessonCollections()` | `includes/cards.php`, `includes/functions.php` |
| 8 | `statement_timeout` / `lock_timeout` / `idle_in_transaction_session_timeout` + `connect_timeout=5` (همه در یک رفت‌وبرگشت با `SET TIME ZONE`) | `config/database.php` |
| 9 | Cache لبه برای صفحات عمومی ناشناس با `Vercel-CDN-Cache-Control` | `includes/header.php` |
| 10 | سرو مستقیم `/assets/*` از CDN با Cache طولانی (بدون اجرای PHP) | `vercel.json`, `router.php` |
| 11 | هم‌مکان‌کردن Function با دیتابیس: `regions: ["sin1"]` | `vercel.json` |
| 12 | تله‌متری دائمی `Server-Timing` | `includes/profiler.php`, `config/*` |

نکتهٔ مهم دربارهٔ Cache لبه: شش GET پشت‌سرهم روی `/` همیشه `x-vercel-cache: MISS` می‌داد،
چون **Vercel هیچ پاسخی را که `Vary` آن شامل `Cookie` باشد Cache نمی‌کند** (مستند رسمی:
«Vary key denied»). بنابراین سیاست Cache مشترک با `Vercel-CDN-Cache-Control` اعمال می‌شود
(مرورگر آن را نمی‌بیند) و فقط برای Requestی که **هیچ Cookieای نداشته، هیچ Sessionی نساخته
و هیچ Cookieای ست نکرده** است. مرورگرها همچنان `max-age=0, must-revalidate` می‌گیرند و
صفحات Admin دست‌نخورده `no-store` می‌مانند.

---

## ۴. نتیجه (اعداد واقعی)

### تعداد Query — روی یک PostgreSQL یکسان و با یک ابزار یکسان

| مسیر | قبل | بعد |
|---|---|---|
| `/` | **130 Query**، ۹۸ تکراری، ۲ اتصال | **24 Query**، ۲ تکراری، **۱ اتصال** |
| `/about` | **92 Query**، ۸۰ تکراری، ۲ اتصال | **7 Query**، ۰ تکراری، ۱ اتصال |
| `/articles` | 86 Query، ۷۲ تکراری | 9 Query، ۰ تکراری |
| `/admin` | 6 Query، ۲ اتصال | **0 Query، ۰ اتصال** |

### زمان پاسخ Homepage

```
Before: 88.58 s      (Production، میانهٔ ۸ نمونه، 83.80s – 89.87s، x-vercel-cache: MISS)
After:  0.16 s       (Cold — بدون Cache لبه)
After:  0.05–0.07 s  (Warm — x-vercel-cache: HIT، ۶ از ۶)

DB:           97.6 ms   (dbexec)  + 18.3 ms (dbconnect)   ← قبلاً 10 327 ms + 1 540 ms
PHP:          43.8 ms                                      ← قبلاً 4 841 ms
Query count:  24                                           ← قبلاً 130
اتصال‌ها:      1                                            ← قبلاً 2
Region:       sin1  ==  dbregion ap-southeast-1  (هم‌مکان)
```

### سایر مسیرها (Cold، بعد از اصلاح)

| مسیر | قبل | بعد |
|---|---|---|
| `/news` | — | **37 ms** |
| `/topics` | — | **35 ms** |
| `/books` | — | **27 ms** |
| `/login` | Timeout در ۴۰ ثانیه | **1.03 s** |

---

## ۵. تفکیک Bottleneck کد از Bottleneck زیرساخت

* **کد:** ۱۳۰ → ۲۴ Query و ۲ → ۱ اتصال. با همان شبکهٔ کند قبلی، همین تغییر
  ۸۸٫۵۸ ثانیه را به ۱۷٫۱۹ ثانیه رساند (**−۸۰٪**) — اندازه‌گیری‌شده روی Preview.
* **زیرساخت:** فاصلهٔ `iad1` تا `ap-southeast-1`. با هم‌مکان‌کردن Function در `sin1`
  هر Query از ۴۳۰ms به **۴٫۱ms** و هر اتصال از ۱۵۴۰ms به **۱۸ms** رسید؛
  ۱۷٫۱۹ ثانیه → **۰٫۱۶ ثانیه**.
* **Cache لبه:** بازدید تکراری اصلاً به PHP و PostgreSQL نمی‌رسد → **۵۰–۷۲ms**.

---

## ۶. چیزهایی که عمداً دست‌نخورده ماندند

Supabase، رشتهٔ اتصال PostgreSQL، Credentialها، Storage، Authentication و مسیر Upload.
هیچ Secretی در Git نیست. تست‌های `Storage upload audit`، `php-lint`،
`Unauthenticated production probe`، `End-to-end audit` و `Responsive audit` سبز هستند.

برای خاموش‌کردن تله‌متری: `JHD_PROFILE=0`.
برای تغییر مدت Cache عمومی: `JHD_PUBLIC_CACHE_SECONDS` (پیش‌فرض ۶۰، مقدار ۰ یعنی خاموش).
