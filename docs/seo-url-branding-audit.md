# ممیزی و اصلاح SEO، URL و Branding

سایت: <https://jametulhoda.vercel.app>
مخزن: `hajiahmadandishmand41-code/jametulhoda`
شاخهٔ مستقر: `main` · commit `128f077f9840a9dc519317e5ed12cc665defcf0b`

این سند فقط سیگنال‌های فنی را گزارش می‌کند. هیچ ادعایی دربارهٔ رتبه در Google
یا دربارهٔ نمایش قطعی لوگو در نتایج جستجو مطرح نمی‌شود؛ آنچه اینجا آمده،
پیش‌نیازهای فنی است که با شواهد Production تأیید شده‌اند.

---

## ۱) چه چیزهایی خراب بود

### URL و دسترسی مستقیم به محتوا

| # | مشکل | شاهد |
|---|---|---|
| ۱ | برای هر محتوا چند نشانی هم‌زمان با وضعیت ۲۰۰ وجود داشت (`/news/x` و `/post/x` و `/article/x`) و هیچ‌کدام به دیگری ریدایرکت نمی‌شد | در Production قدیم: `GET /post/<slug>` → **۲۰۰** بدون ریدایرکت |
| ۲ | اطلاعیه، برنامه و فعالیت مذهبی همگی در فضای مشترک `/event/<slug>` بودند؛ بنابراین نوع محتوا از روی URL قابل تشخیص نبود | مسیر `/event/` پیشوند مشترک سه نوع محتوا |
| ۳ | پیشوندهای مفرد/جمع هم‌زمان در دسترس بودند (`/report/x` در کنار `/reports/x`) | `GET /report/<slug>` → **۲۰۰** |
| ۴ | `pages/post.php` نوع‌های `announcement` و `program` را تشخیص نمی‌داد: `$isEventMatch` فقط `program` را بررسی می‌کرد و `announcement`/`religious` را از مسیر درست بیرون می‌انداخت | `$isEventMatch = in_array($normalizedExpected, ['program','event'], true) && …` |
| ۵ | sitemap دست‌نویس بود و فقط فهرست‌ها، موضوعات، یک خبر و دسته‌بندی‌ها را می‌گذراند؛ کتاب، درس، سخنرانی، رسانه، پژوهش، گزارش و… اصلاً تولید نمی‌شدند و پس از انتشار محتوای جدید نیاز به تغییر کد داشت | sitemap قدیمی Production: ۴۶ URL، بدون هیچ `/books/` یا `/lessons/` |
| ۶ | سازندهٔ URL در sitemap پورت را حذف می‌کرد (فقط `scheme://host`) و origins با پیش‌فرض `http` را با guard `^https://` رد می‌کرد، بنابراین روی هر origin غیرپیش‌فرض sitemap یا خالی بود یا همهٔ URLها به هاست اشتباه اشاره می‌کردند | دو commit جداگانه برای رفع |

### لوگو و هویت بصری

| # | مشکل | شاهد |
|---|---|---|
| ۷ | **علت اصلی «لوگو نمایش داده نمی‌شود»:** انتساب `$siteLogo` درون یک خط کامنت `//` گیر کرده بود که به‌جای newline واقعی شامل `\n` لفظی بود؛ بنابراین کل دستور کامنت محسوب می‌شد و هدر در صفحات دارای دیتابیس هیچ لوگویی نشان نمی‌داد | `// Public branding is fixed to the real repository logo for every public page.\n    $siteLogo = 'assets/img/logo.png';` |
| ۸ | `/favicon.ico` فایل لوگوی واقعی نبود | — |
| ۹ | دو فایل جایگزین (`favicon.svg`، `placeholder.svg`) در مخزن بودند و در خروجی عمومی ارجاع می‌شدند | هر دو حذف شدند |
| ۱۰ | آیکون‌ها، og:image و twitter:image نسبی بودند (بدون origin) | — |

### برند

| # | مشکل | شاهد (Production قدیم) |
|---|---|---|
| ۱۱ | نام برند در چهار جا چهار مقدار متفاوت داشت | `<title>`: `مدرسه علمیه جامعه‌الهدی در کابل \| جامعة‌الهدی`<br>`Organization.name`: `مدرسه علمیه جامعه‌الهدی`<br>`WebSite.name`: `جامعة‌الهدی`<br>`og:site_name`: `جامعة‌الهدی` |
| ۱۲ | `<title>` با یک عبارت توصیفی شروع می‌شد و نام برند در جایگاه دوم بود | `مدرسه علمیه جامعه‌الهدی در کابل \| جامعة‌الهدی` |

### ساختاریافته (JSON-LD)

| # | مشکل | شاهد |
|---|---|---|
| ۱۳ | صفحات داخلی اصلاً `Organization` منتشر نمی‌کردند | topic/news/category قدیم: فقط `WebSite, BreadcrumbList` |
| ۱۴ | خبر به‌جای `NewsArticle` به‌عنوان `Article` منتشر می‌شد | `news: JSON-LD — WebSite, BreadcrumbList, Article` |
| ۱۵ | `pages/lesson.php` متغیر `$lessonJsonLd` را هرگز محاسبه نمی‌کرد، در حالی که `includes/header.php` فقط در صورت تنظیم‌بودن آن را چاپ می‌کند؛ بنابراین اسکیمای `Course` هیچ‌وقت منتشر نمی‌شد | خط حذف‌شده در diff |
| ۱۶ | `pages/book.php` متغیر محلی `$canonicalUrl` داشت که **تابع** `canonicalUrl()` را shadow می‌کرد؛ در نتیجه آیتم‌های breadcrumb به‌جای URL مطلق، مسیر نسبی منتشر می‌کردند | — |
| ۱۷ | `pages/book.php` اسکیمای `BreadcrumbList` را دوبار منتشر می‌کرد (یک‌بار inline، یک‌بار از طریق هدر مشترک) | — |
| ۱۸ | `pages/speech.php` و `pages/category.php` مسیر راهنمای دیداری داشتند اما هیچ `BreadcrumbList`ای | probe: `category: BreadcrumbList present — WebSite` |

---

## ۲) چه چیزهایی اصلاح شد

### URL

نشانی‌ها از یک **registry مسیر واحد** (`includes/functions.php`) تولید می‌شوند؛ همان توابعی که sitemap، breadcrumb، کارت‌ها و لینک‌های داخلی استفاده می‌کنند. در نتیجه نشانی و ورودی sitemap برای محتوای تازه **خودکار** است و بین این خروجی‌ها هیچ انحرافی ممکن نیست.

| نوع محتوا | نشانی canonical |
|---|---|
| خبر | `/news/<slug>` |
| مقاله | `/articles/<slug>` |
| پژوهش | `/research/<slug>` |
| گزارش | `/reports/<slug>` |
| اطلاعیه | `/announcements/<slug>` |
| برنامه | `/programs/<slug>` |
| فعالیت مذهبی | `/religious-activities/<slug>` |
| سخنرانی | `/speech/<slug>` |
| پرسش و پاسخ | `/qa/<slug>` |
| درس | `/lessons/<slug>` |
| کتاب | `/books/<slug>` |
| موضوع | `/topics/<parent>/<child>` |
| دسته‌بندی | `/category/<slug>` |
| ویدیو / صوت | `/video/<id>` · `/audio/<id>` |

نشانی‌های قدیمی و Query (`/post/x`، `/article/x`، `/report/x`، `/topic/x`، `?p=…`) همچنان باز می‌شوند اما با **۳۰۱** به نشانی canonical هدایت می‌شوند؛ بنابراین هرگز به‌عنوان نشانی اصلی در HTML جدید، sitemap یا canonical منتشر نمی‌شوند.

### لوگو

- یک ثابت واحد: `SITE_LOGO_PATH = 'assets/img/logo.png'` در `config/config.php`.
- `/favicon.ico` همان فایل `logo.png` را برمی‌گرداند.
- `favicon.svg` و `placeholder.svg` حذف شدند.
- favicon، shortcut icon، apple-touch-icon، هدر، og:image، twitter:image، `Organization.logo` و `publisher.logo` همگی به `/assets/img/logo.png` **مطلق** اشاره می‌کنند.

### برند

نام برند یک ثابت واحد است و در همهٔ سطوح یکسان منتشر می‌شود:
`Organization.name` = `WebSite.name` = `og:site_name` = شروع‌کنندهٔ `<title>` = **«مدرسه جامعه‌الهدی»**.

ویژگی‌های Organization فقط شامل داده‌های واقعی است: `url` برابر ریشهٔ سایت، `logo` برابر نشانی مطلق لوگو، `address` برابر کابل/افغانستان، `founder` برابر «آیت‌الله محمدحسین حلیمی» (تأییدشده توسط کارفرما)، `sameAs` فقط صفحهٔ رسمی پیکربندی‌شده، و `alternateName` فقط نام‌های واقعاً به‌کاررفته. هیچ دادهٔ ساختگی و هیچ تکرار کلیدواژه‌ای افزوده نشده است.

### هر صفحه

title و description یکتا، canonical دقیق، `index, follow`، Open Graph کامل، و JSON-LD متناسب با نوع محتوا:
`NewsArticle`، `Article`، `ScholarlyArticle`، `Report`، `QAPage`، `Book`، `Course`، `VideoObject`، `AudioObject`، همگی در کنار `EducationalOrganization`، `WebSite` و `BreadcrumbList`.

### Sitemap و robots

sitemap با پیمایش همان registry تولید می‌شود و یک guard صریح هر نشانی private، legacy یا تکراری را رد می‌کند. `robots.txt` بخش‌های admin، ورود/ثبت‌نام/حساب، جستجو، نصاب و `?p=` را مسدود می‌کند و نشانی sitemap را اعلام می‌کند.

### محدودهٔ تغییرات

۵۶ فایل، ۱۱۹۶ درج و ۲۹۶ حذف. **هیچ تغییری** در Supabase، PostgreSQL، Storage، احراز هویت، آپلود، جداول دیتابیس، migrationها یا منطق پنل مدیریت ایجاد نشده است (بررسی‌شده با `git diff --name-only` از commit پایه).

---

## ۳) commit و استقرار تولیدشده

| PR | commit روی `main` | موضوع |
|---|---|---|
| #11 | `dba73e4` | اصلاح اصلی: نشانی یکتا برای هر محتوا، یکپارچه‌سازی برند و لوگوی واقعی |
| #12 | `5683434` | اجرای خودکار probe روی Production هنگام موفقیت استقرار Vercel |
| #13 | `7d93814` | بررسی صفحات فهرست همهٔ انواع محتوا |
| #14 | `7064324` → merge `128f077` | بررسی تک‌تک URLهای sitemap |

**سرشاخهٔ مستقر `main`: `128f077f9840a9dc519317e5ed12cc665defcf0b`**

هر چهار workflow روی `main` سبز هستند:
`PHP syntax check` ✅ · `Production checks` ✅ · `SEO / URL / branding audit` ✅ · `Responsive & storage audit` ✅

گزارش probe به‌صورت commit comment روی هر commit منتشر می‌شود
(`gh api repos/.../commits/<sha>/comments`).

---

## ۴) کدام URLها واقعاً در Production تست شدند

همهٔ موارد زیر روی <https://jametulhoda.vercel.app> و با commit `128f077` اجرا شده‌اند.
نتیجه: **۲۱۶/۲۱۶ بررسی موفق، بدون هیچ موردِ ناموفق.**

### پایه

| URL | وضعیت | نتیجه |
|---|---|---|
| `/` | ۲۰۰ `text/html` | title با نام برند شروع می‌شود |
| `/robots.txt` | ۲۰۰ | شامل `Sitemap: https://jametulhoda.vercel.app/sitemap.xml` |
| `/sitemap.xml` | ۲۰۰ `application/xml` | ۴۷ URL، ۴۷ یکتا، بدون مورد private/legacy |
| `/favicon.ico` | ۲۰۰ `image/png` | **بایت‌به‌بایت یکسان با logo.png** |
| `/assets/img/logo.png` | ۲۰۰ `image/png` | ۱۶۷۸۹۱ بایت، ۷۰۲×۷۲۳ |

### صفحات فهرست هر نوع محتوا (۱۳ مورد، هرکدام ۴ بررسی)

`/news` · `/articles` · `/research` · `/reports` · `/announcements` · `/programs`
· `/religious-activities` · `/speeches` · `/qa` · `/lessons` · `/books`
· `/topics` · `/media` — همگی ۲۰۰، title یکتا، canonical برابر خود URL، `index, follow`.

### URLهای واقعیِ محتوا (نمونهٔ هر نوع موجود در Production)

| نوع | URL | title | canonical | JSON-LD |
|---|---|---|---|---|
| موضوع | `/topics/mahdaviat` | مهدویت \| مدرسه جامعه‌الهدی | = URL | EducationalOrganization, WebSite, BreadcrumbList |
| خبر | `/news/فعالیت-رسمی-مدرسه-علمیه-جامعه‌الهدی` | … \| مدرسه جامعه‌الهدی | = URL | … BreadcrumbList, **NewsArticle** |
| دسته‌بندی | `/category/fiqh-osul` | فقه و اصول \| مدرسه جامعه‌الهدی | = URL | … BreadcrumbList |

Production در حال حاضر فقط یک مطلب منتشرشده دارد؛ بقیهٔ انواع محتوا هنوز ردیف
منتشرشده‌ای در دیتابیس ندارند. به‌همین دلیل **تمام انواع، روی یک نمونهٔ seeded
شامل همهٔ انواع محتوا نیز تست شده‌اند** (در ادامه).

### تک‌تک URLهای sitemap (۴۷ مورد × ۲ بررسی)

هر URL در sitemap فراخوانی شد و تأیید شد که **زنده (۲۰۰)** و **self-canonical**
است و `index, follow` دارد. این قوی‌ترین شاهد برای این است که sitemap هیچ
نشانی تکراری، legacy یا private ندارد.

### ریدایرکت‌های legacy

| نشانی قدیمی | نتیجه |
|---|---|
| `/post/<slug>` | **۳۰۱** → `/news/<slug>` |
| `/topic/mahdaviat` | **۳۰۱** → `/topics/mahdaviat` |
| `/library` | ۲۰۰ با canonical `/books` |
| `/index.php?p=news` | ۲۰۰ با canonical `/news` |

### صفحات بدون ایندکس

`/search` · `/login` · `/register` — همگی `noindex, follow`.

### پوشش انواع محتوای فاقد ردیف منتشرشده

روی یک نمونهٔ seeded محلی (SQLite) با همهٔ انواع محتوا، **۴۸۷/۴۸۷** بررسی موفق
بود و sitemap **۱۲۶ URL یکتا** برای ۱۴ نوع محتوا تولید کرد:
`announcement, article, audio, book, category, lesson, news, program,
religious activity, report, research, speech, topic, video`.

برای هرکدام تأیید شد: ۲۰۰، title و description یکتا، canonical برابر URL درخواستی،
`index, follow`، اسکیمای متناسب با نوع، breadcrumb مطلق، og:image مطلق، و حضور در sitemap.

---

## ۵) آیا favicon و logo.png دقیقاً یک فایل هستند؟

**بله.** probe هر دو را از Production دریافت و SHA-256 آن‌ها را مقایسه کرد:

```
PASS  /favicon.ico is byte-identical to logo.png
      — favicon 4e6fe8a52692bd89… vs logo 4e6fe8a52692bd89… (167891 bytes)
```

و این هش با هش فایل داخل مخزن یکسان است:

```
$ sha256sum assets/img/logo.png
4e6fe8a52692bd89b722319b2daaa878096ba675de2c2dab03004a83106937af
```

یعنی Production دقیقاً همان فایل `assets/img/logo.png` موجود در مخزن را سرو می‌کند،
و `/favicon.ico` همان فایل است (۱۶۷۸۹۱ بایت، PNG، ۷۰۲×۷۲۳).

---

## ۶) آیا هر محتوای منتشرشده نشانی مستقل و ورودی خودکار sitemap دارد؟

**بله، از نظر فنی.** سه سطح شاهد:

1. **منبع واحد حقیقت.** نشانی‌ها توسط یک registry (`jhd_post_route()` / `postUrl()` /
   `bookUrl()` / `lessonUrl()` / `topicUrl()` / `mediaUrl()`) تولید می‌شوند و sitemap،
   breadcrumb، canonical، کارت‌ها و JSON-LD همگی همان توابع را صدا می‌زنند. انحراف بین
   این خروجی‌ها structurally غیرممکن است.

2. **تست خودکار روی نمونهٔ seeded.** با انتشار محتوای تازه در هر یک از ۱۴ نوع،
   sitemap به‌طور خودکار از ۴۶ به ۱۲۶ URL رسید و هیچ فایل کدی تغییر نکرد. این
   همان رفتاری است که در Production پس از انتشار هر مطلب رخ می‌دهد.

3. **سweep کامل sitemap در Production.** هر ۴۷ نشانیِ فعلی sitemap زنده و
   self-canonical است؛ بنابراین هر نشانی‌ای که sitemap اعلام می‌کند، یک نشانی
   عمومی واقعی و ایندکس‌پذیر است.

نکتهٔ صادقانه: آنچه اینجا تأیید شده **پیش‌نیاز فنی** است (نشانی یکتا و باثبات،
canonical درست، sitemap خودکار، structured data معتبر). اینکه این نشانی‌ها چه
زمانی و با چه رتبه‌ای در موتورهای جستجو ظاهر شوند، خارج از کنترل این تغییرات
است و دربارهٔ آن ادعایی مطرح نمی‌شود.
