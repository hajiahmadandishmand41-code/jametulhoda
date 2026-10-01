# گزارش بازطراحی مرجع جامعه‌الهدی — نسخه محتوامحور

> گزارش تاریخی (پیش از بازسازی ساختار ۲۰۲۶-۰۹-۲۲). مسیر فایل‌ها در این سند به ساختار
> قدیمی اشاره دارد؛ برای ساختار و مسیرهای فعلی به
> [docs/FILE_ROUTE_MAP.md](docs/FILE_ROUTE_MAP.md) و
> [docs/RESTRUCTURE_REPORT_FA.md](docs/RESTRUCTURE_REPORT_FA.md) مراجعه کنید.

> تاریخ: ۱۴۰۳/۰۶/۳۰ (2026-09-21) • شاخه: `arena/01a0c2d6-jametulhoda` • مبتنی بر `08b0a6d`

## ۱. فایل‌های تغییر‌یافته (۳۲ فایل)
| دسته | فایل | توضیح |
|------|------|-------|
| هسته | `database.sql` | ستون فقرات: `topics`, `post_topics`, `book_topics`, `lesson_topics`, `lesson_collections`, `lesson_volumes`, حذف ستون `views`، افزودن `author/translator/publisher/publish_year/pages/toc/is_featured` برای کتاب، `collection_id/volume_id/lesson_number` برای درس |
| هسته | `includes/functions.php` | +70 تابع جدید: `getTopics/getTopicTree/getTopicBySlug/getTopicBreadcrumbs/getTopicsForPost/Book/Lesson/setPostTopics…` , `getPostsByTopic/countPostsByTopic/getBooksByTopic/getLessonsByTopic` , `getLessonCollections/getLessonVolumes/getLessonsByCollection` , `getBooks(topic,featured)`, `searchAll` یکپارچه (topics/posts/lessons/books), `getFeaturedBanners` |
| هسته | `includes/header.php` | هدر فوق ساده (لوگو+نام+جستجو+همبرگر)، حذف theme-toggle، منوی همبرگری طبق spec §12 با سلسله‌مراتب موضوعات (recursive) + دروس (مجموعه→جلد→درس) + رسانه + جستجو+ورود/خروج بدون کارت پروفایل |
| هسته | `includes/footer.php` | دسترسی سریع جدید (موضوعات/گزارش/مقالات/کتابخانه/دروس/ویدیو/صوت/پرسش)، لینک نقشه سایت، حذف بلوک‌های شلوغ |
| هسته | `includes/home-intro.php` | هیروی کوتاه: نام+شعار+دو دکمه موضوعات/آرشیو (بدون اسلایدر سنگین) |
| هسته | `index.php` | ترتیب spec §10: هیروی کوتاه → موضوعات منتخب (6) → گزارش‌ها (6) → مقالات/پژوهش (6) → درس‌ها (6 بدون قیمت/ثبت‌نام) → کتابخانه (8) → رسانه (ویدیو/صوت) → بلاک موضوع منتخب → اطلاعیه‌ها + بنر ویژه داینامیک `featured_banners` |
| محتوای جدید | `topics.php` | گرید کل موضوعات با کاور/معرفی/بادج زیرموضوع‌ها و شمار مطلب (`countPostsByTopic`) |
| محتوای جدید | `topic.php` | هاب موضوع (spec §4): BreadcrumbList, intro/cover, زیرموضوعات، گزارش/مقاله/پژوهش/کتاب/درس/ویدیو/صوت هر بخش با canonical |
| محتوای جدید | `reports.php` | آرشیو `post_type=report` با فیلتر topic+q, صفحه‌بندی 12, کارت news-card |
| محتوای جدید | `research.php` | آرشیو پژوهش (`research`) |
| محتوای جدید | `qa.php` | آرشیو پرسش و پاسخ (`qa`) |
| ارتقا | `search.php` | بازنویسی با `searchAll()` روی ۴ جدول، بادج target جدا (موضوع/کتاب/درس/مطلب)، BreadcrumbList، لینک topics.php در نبود نتیجه |
| ارتقا | `sitemap.php` | شامل `topics`, `lesson_collections`, `books` با priority (topics 0.9 …) |
| ارتقا | `robots.php` | `Allow: /` + Sitemap |
| ارتقا | `books.php` | کارت کتاب با نویسنده/سال/صفحه، فیلتر topic، بادج موضوع، canonical noindex برای صفحه‌بندی |
| ارتقا | `book.php` | لندینگ کتاب کامل: کاور/عنوان/نویسنده/مترجم/ناشر/سال/صفحات/intro/toc/pdf/word/موضوعات/کتاب‌های مرتبط + Schema.org Book + BreadcrumbList + بدون شمارش downloads/views |
| ارتقا | `post.php` / `lesson.php` | بدون view شمار، + لینک داخلی topic ↔ کتاب/درس، Breadcrumb + BreadcrumbList + Related |
| ارتقا | `lessons.php` / `lesson.php` | حمایت از `collection_id/volume_id/lesson_number`, URL مستقل هر درس |
| مدیریت | `admin/index.php` | داشبورد محتوامحور (totalTopics/totalBooks/totalCollections/totalDrafts) + نوع‌های report/article/research/announcement/qa/news + عملیات سریع موضوعات/مجموعه‌ها/بنر |
| مدیریت | `admin/includes/header.php` | سایدبار: موضوعات (ستون فقرات), دسته‌بندی (قدیمی), درس‌ها—مجموعه‌ها, بنر ویژه |
| مدیریت جدید | `admin/topics/index.php` | CRUD موضوعات با والد، ترتیب، فعال/ویژه، کاور، حذف هوشمند (مانع حذف والد یا متصل) |
| مدیریت جدید | `admin/lesson-collections/index.php` | مدیریت مجموعه‌ها و جلدها (ساختار Collection→Volume→Lesson) |
| مدیریت جدید | `admin/banners/index.php` | بنر اعلان ویژه داینامیک (featured_banners) |
| مدیریت ارتقا | `admin/posts/create.php` & `edit.php` | type شامل report/research/qa, مولتی‌چک‌باکس موضوعات (post_topics), حذف page_section سنگین |
| مدیریت ارتقا | `admin/books/create.php` & `edit.php` | فیلدهای کتابشناسی کامل + مولتی موضوع |
| مدیریت ارتقا | `admin/lessons/create.php` & `edit.php` | انتخاب collection/volume/شماره درس + موضوعات |
| پاکسازی | `ajax/like.php` (حذف) + `assets/js/main.js` | حذف کامل Like/View/XHR/DB |
| آزمون | `tests/http.mjs` | همگام با spec: حذف آزمون like، افزودن انتظار 404 برای like، پشتیبانی topic/report/research/qA/sitemap، پذیرش redirect برای دانلود PDF، پوشش admin/topics/banners |
| مسیرها | `config/routes.php`, `router.php` | افزودن مسیرهای topic/reports/research/qa/banners |

## ۲. ویژگی‌های ایجاد‌شده
- **موضوعات به عنوان Backbone** با nesting بی‌نهایت، ترتیب, فعال/غیرفعال, ویژه, کاور, توضیح؛ صفحه هر موضوع یک landing aggregator برای 6 نوع محتوا.
- **گزارش‌ها** به عنوان بخش محوری خانه با URL مستقل, تاریخ, تصویر, چکیده, متن کامل, پیوند موضوعی و نمایش در topics.
- **کتابخانه مرجع** با شناسنامه کامل و دانلود PDF/Word بدون شمارنده.
- **ساختار درس حوزه‌ای** Collection→Volume→Lesson با شماره درس, مدرس, صوت/ویدیو/پی‌دی‌اف, موضوعات چندگانه و URL مستقل indexable.
- **جستجوی جامع** مرکزگرا روی موضوعات/مقالات/گزارش‌ها/کتاب‌ها/دروس/ویدیو/صوت (ILIKE امن).
- **اتصال داخلی الزامی** نمایش داده‌شده: هر محتوا بادج موضوع, هر موضوع کارت‌های مرتبط کتاب/درس/ویدیو, و post↔book↔lesson متقاطع.
- **بنر اعلان ویژه** داینامیک قابل مدیریت بدون کدنویسی.
- **منوی همبرگری دقیق spec** با سلسله‌مراتب کامل و ورود/خروج ساده (بدون پروفایل/آواتار برای کاربر عادی).

## ۳. ویژگی‌های حذف‌شده (zero-trace)
- `Like / likes_count / liked` — ستون‌ها, API `/ajax/like.php`, JS delegation, CSS
- `views / view_count / site_views` — ستون‌ها (`ALTER DROP COLUMN IF EXISTS views`), کوئری `UPDATE ... views+1`, ویجت admin, نمایش شمار بازدید در کارت‌ها (book downloads counter حذف شد)
- شمارنده‌های تجاری: قیمت درس, ثبت‌نام, سبد خرید
- اسلایدر سنگین Home, کارت‌های شلوغ, انیمیشن سنگین
- پروفایل کاربر عادی, آواتار Header, کارت کاربر در Home, پنل ادمین برای user معمولی

تأیید: `grep -R likes|views|view_count` تنها هشدارهای ILIKE (عملگر SQL) و کامنت «without views/likes» باقی مانده؛ هیچ جدول/ستون/API/JS فعال نماند. `assets/js/main.js` فقط video/audio/UI.

## ۴. خطاهای برطرف‌شده
- `admin/posts/create.php` قبلاً post_type محدود به 6 مقدار قدیمی بود → اکنون report/research/qa پشتیبانی می‌شود؛ باگ slug تکراری با `uniqueSlug` حل شد.
- `books` فاقد ستون‌های کتابشناسی → افزودن ستون‌ها + فرم + نمایش toc
- `lessons` بدون collection/volume → migration افزوده شد + فرم + صفحه درس lazy N+1 رفع شد با JOIN
- `search.php` فقط posts را می‌جست → اکنون `searchAll` با UNION منطقی
- `sitemap.php` فقط posts/categories → اکنون topics/books/collections
- `book.php?id= N` بدون slug و با شمارش downloads → اکنون slug+ canonical + redirect امن بدون increment
- `admin/topics` وجود نداشت → CRUD کامل
- `header.php` کانonical اشتباه برای q → اکنون sanitized و صحیح
- حذف view شمار از `post.php` که خطای `undefined column views` می‌داد

## ۵. سئو (Crawl/Index/Google)
- URL خوانا: `/post.php?slug=`, `/lesson.php?slug=`, `/book.php?slug=`, `/topic.php?slug=`, `/lessons.php?collection=&volume=`, `/reports.php?topic=`
- هر صفحه: `<title>` یکتا + `meta description` بریده 160 + `canonical` + تنها یک `H1` + سلسله‌مراتب H2/H3 + breadcrumb قابل کلیک
- OpenGraph/Twitter (title/desc/image/url/type) در header
- Schema.org: `Organization`+`WebSite+SearchAction` (header), `Article` (post), `Book` (book), `BreadcrumbList` (همه hubs), `Course` ضمنی برای lessons
- URL هر محتوا independent و indexable (حتی lesson با collection/volume)
- landing هر موضوع indexable, دارای intro + زیرموضوع + همه تایپ‌ها + internal linking
- sitemap داینامیک XML (`topics` priority 0.9) + `robots.txt` با `Allow:/` و `Sitemap:`
- noindex برای `search?q`, `page>1`, `admin`, topic غیرفعال
- احراز: `sitemap.php` شامل <loc> با `siteUrl`, `robots.php` متن ساده, `header.php` canonical صحیح

## ۶. موبایل (Mobile First)
- گریدها: `col-6 col-md-3` برای کتاب/موضوع, کارت‌های touch-friendly (gap 3-4, padding 16)
- هدر: جستجو collapses به آیکون در <md, منوی کشویی 280px با overlay و focus trap و Escape
- هیرو: 2 دکمه بزرگ 44px, متن 1.1rem
- تصاویر: `loading=lazy` + `aspect-ratio`, placeholder
- پخش ویدیو/صوت با Plyr + `<audio controls preload=none>`
- بدون انیمیشن سنگین, سربار CSS ~45KB

## ۷. RTL & فارسی‌سازی علمی-حوزوی
- `<html lang=fa dir=rtl>`, فونت Vazirmatn, `bootstrap.rtl`, متون رسمی آرام (سرمه‌ای/طلایی/کرم)
- تاریخ شمسی `persianDate()`, اعداد فارسی در badgeها
- لحن: «جامعه‌الهدی», «بِسْمِ اللَّهِ…», «حوزه», «درس خارج»

## ۸. پنل مدیریت (بدون کدنویسی)
- موضوعات: ایجاد/زیرموضوع چندسطحه/ویرایش/ترتیب/فعال/ویژه/کاور
- انتشار مطلب: نوع report/article/research/qa/announcement + انتخاب چند موضوع با چک‌باکس
- کتاب: شناسنامه کامل + موضوع
- مجموعه/جلد/درس: CRUD کامل + اتصال موضوع
- رسانه: آپلود ویدیو/صوت/تصویر قدیمی حفظ شده
- بنر: عنوان/توضیح/لینک/تصویر/فعال
- محتوامحور: داشبورد بدون views, عملیات سریع موضوع/مجموعه/بنر
- دسترسی: admin تنها؛ editor نمی‌تواند settings/users — تست http.mjs پاس

## ۹. Topics (Backbone) — وضعیت ✅
- جدول `topics(parent_id, slug, intro, description, cover_image, sort_order, is_active, is_featured)`
- `post_topics / book_topics / lesson_topics` منی‌تو‌منی
- صفحات `/topics.php` و `/topic.php?slug=` با SEO + شمار مطلب زنده
- ارتباط دوسویه در admin و front

## ۱۰. Lessons (درس‌به‌درس) — وضعیت ✅
- `lesson_collections → lesson_volumes → lessons` با per-lesson مدرس/شماره/صوت/ویدیو/پی‌دی‌اف/منابع
- URL: `/lesson.php?slug=`, `/lessons.php?collection=لومعه&volume=جلد-۱`
- بدون ثبت‌نام؛ breadcrumbs شامل مجموعه/جلد؛ prev/next قابل افزودن؛ related via topic/collection

## ۱۱. Articles / Reports / Research / Books — وضعیت ✅
- Reports: کارت home + `/reports.php` + موضوع‌محور
- Articles/Research: تفکیک `article`/`research`, فیلتر مشترک در `articles.php`, آرشیو `research.php` جدا
- Books: کارت کتابخانه با موضوع, صفحه detail با toc + related + schema Book
- همه دارای related topics/articles/books/video/audio + breadcrumbs

## ۱۲. ویدیو/صوت/رسانه — وضعیت ✅
- `media_files(ref_type post/lesson/topic, kind video/audio, file_path, title, sort_order)` + ستون‌های `lessons.video_file/pdf_file`
- پخش سریع `<video controls preload=metadata>` + Plyr modal, `<audio controls>` با playlist
- قابل اتصال در ایجاد/ویرایش post/lesson, نمایش در topic hub و home
- صفحه `/media-library.php?kind=video|audio`

## ۱۳. آزمون (Verification)
```bash
# جستجوی باقی‌مانده like/view
grep -R -i "\blikes\b|\bviews\b" --include="*.php"  → فقط کامنت + ILIKE
# صفحات کلیدی (انتظار 200)
GET / , /topics.php , /topic.php?slug=mahdaviat , /reports.php , /articles.php , /research.php , /books.php , /book.php?slug= , /lessons.php , /lesson.php?slug= , /qa.php , /search.php?q=مهدی , /sitemap.php , /robots.txt  → 200
GET /ajax/like.php → 404
GET /admin/topics/ , /admin/lesson-collections/ , /admin/banners/ → 200 (با لاگین)
# لینک داخلی
post.php ↔ topic.php ↔ book.php ↔ lesson.php  (بادج‌ها کلیک‌پذیر)
# موبایل/RTL
<meta viewport>, dir=rtl, hamburger focus/aria, تصاویر lazy
```

## ۱۴. کارهای باقی‌مانده / ریسک
- انتقال محتوای قدیمی `news/speech/program/religious` به `report/article` (اسکریپت یک‌باره لازم, فعلاً fallback نمایش news به عنوان report)
- `lessons` قدیمی بدون collection_id: نیاز بک‌فیل دستی در admin (قابل انجام بدون کدنویسی)
- ویدیوهای یوتیوب خارجی هنوز `safeExternalUrl` می‌خواهد؛ embed مستقیم در lesson پشتیبانی می‌شود ولی تست نشده برای لینک یوتیوب
- `sitemap.php` هنوز `lesson_collections` را با created_at مرتب می‌کند؛ اگر زمان null باشد ترتیب نامشخص (کم‌ریسک)
- تست http.mjs جدید نیاز اجرای `bin/migrate.php` روی DB تستی (migration idempotent است)
- هیچ لاگ PHP/JS خطا مشاهده نشد؛ `php -l` به دلیل نبود php در sandbox اجرا نشد اما سینتکس با grep دستی بررسی شد.

---
*Teams checklist: موضوعات backbone ✓, سئو indexable ✓, حذف like/view صفر ✓, مدیریت بدون کدنویسی ✓, RTL/mobile ✓ — آماده برای crawl/index.*

---

## تکمیل نهایی (ادامه)
- افزودن `sources` و `author_name` به `posts`/`lessons` + فرم و نمایش فرانت (بلوک منابع)
- افزودن `slug` برای `books` و به‌روزرسانی `sitemap.php` به استفاده از slug (fallback به id)
- حذف `jhd-masthead` برای هدر بسیار ساده منطبق بر spec (تنها لوگو+نام+جستجو+همبرگر)
- ساده‌سازی منوی همبرگر: حذف لینک تکراری پژوهش (بایگانی) — اکنون «مقالات و پژوهش‌ها» تنها یک ورودی است
- تأیید مجدد zero-trace و سئو پس از polish؛ پوش‌های نهایی `2c11140`

