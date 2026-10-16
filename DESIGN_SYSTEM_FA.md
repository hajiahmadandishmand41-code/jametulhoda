# سامانهٔ طراحی یکپارچهٔ جامعةالهدی

> این سند مرجع کوتاه «یک زبان بصری واحد» برای کل سایت است: کارت‌ها، رسانهٔ شاخص،
> خوانندهٔ PDF و قواعد کارایی. هر صفحهٔ تازه‌ای که اضافه می‌شود باید از همین
> توابع استفاده کند تا سایت یکدست بماند.

## ۱. کارت واحد

همهٔ فهرست‌ها (خانه، اخبار، مقالات، پژوهش، گزارش، اطلاعیه، رویداد، پرسش‌وپاسخ،
کتاب، درس، ویدیو، صوت، سخنرانی، موضوع، دسته و جستجو) از یک هستهٔ مشترک در
`includes/cards.php` رندر می‌شوند.

```php
echo jhd_grid_open();                         // <div class="row g-3 jhd-card-grid">
foreach ($items as $i => $item) {
    echo renderPostCard($item, ['type' => 'news', 'eager' => $i < 3]);
}
echo jhd_grid_close();
```

* ستون استاندارد: `JHD_CARD_COL` = `col-12 col-sm-6 col-lg-4`
  (موبایل یک‌ستونه، تبلت دوستونه، دسکتاپ سه‌ستونه).
* گونه‌ها: `default`، `featured` (کارت بزرگ صدر صفحه)، `compact` (ردیف افقی
  فشرده — بندانگشتی کوچک یک سو و عنوان سوی دیگر؛ پیش‌فرض بخش‌های صفحهٔ اصلی،
  ریل‌های انتهای مطلب و فهرست‌های کناری). حالت چیدمان هر بخش صفحهٔ اصلی
  (`auto`/`rows`/`grid`)، تراکم (`compact`/`cozy`) و اندازهٔ بندانگشتی
  (`sm`/`md`) از `/admin/settings` کنترل می‌شود.
* ساختار ثابت هر کارت:
  `.jhd-card` → `.jhd-card-media` → `.jhd-card-body` →
  `.jhd-card-meta` / `.jhd-card-title` / `.jhd-card-summary` / `.jhd-card-foot`.

### قاب تصویر
* نسبت ثابت ۱۶:۱۰ (کارت شاخص ۱۶:۹) با `object-fit: cover` — تصویر هیچ‌گاه کشیده
  یا بریده‌خراب نمی‌شود.
* جلد کتاب با گونهٔ `--contain` و پس‌زمینهٔ محو، کامل دیده می‌شود.
* نبود تصویر = «پلاک» برنددار (`--empty`)، نه قاب خالی یا شکسته.
* همهٔ تصویرها `width`/`height` و `loading="lazy"` دارند (سه کارت نخست `eager`).

### توابع سازگار
`renderPostCard`، `renderMiniItem`، `renderEditorialRow`، `renderEventRow`،
`renderBookCard`، `renderLessonCard`، `renderLessonRow`، `renderTopicCard`،
`renderMediaCard`، `renderAudioRow`، `renderDocumentCard` — همگی پوستهٔ نازکی روی
`jhd_card()` هستند، پس تغییر در یک نقطه کل سایت را هماهنگ به‌روز می‌کند.

## ۲. رسانهٔ شاخص چندگانه

| جدول | ستون‌ها | معنا |
|------|---------|------|
| `post_images` | `is_featured`, `featured_order` | تصویرهای شاخص مطلب |
| `media_files` | `is_featured`, `featured_order` | ویدیوهای شاخص مطلب |
| `posts` | `featured_image`, `featured_video` | آینهٔ «اولین» مورد شاخص (سازگاری) |

توابع مدل در `includes/post-gallery.php`:
`getPostFeaturedImages` / `getPostFeaturedVideos` / `setPostFeaturedImages` /
`setPostFeaturedVideos` / `togglePostFeaturedImage` / `togglePostFeaturedVideo` /
`addPostImageUnique` / `syncPostFeaturedPointers` / `getPostFeaturedMedia`.

رسانهٔ شاخص از رسانهٔ داخل متن جداست: گالری و مدیریت رسانه ردیف «آینهٔ اصلی»
(`sort_order = -1000000`) را نشان نمی‌دهند.

## ۳. ترتیب درست ذخیره (رفع ریشه‌ای «شناسه رد شد»)

```
۱. ذخیرهٔ محتوا  → ۲. گرفتن شناسهٔ واقعی → ۳. پیوست همهٔ فایل‌ها با همان شناسه
→ ۴. تعیین رسانهٔ شاخص → ۵. انتشار کامل
```

اگر مدیر پیش از ذخیره فایلی آپلود کند، `includes/content-draft.php` یک
«پیش‌نویس خودکار» واقعی می‌سازد (`jhd_ensure_auto_draft`) و شناسهٔ آن در
`$_SESSION['jhd_auto_drafts']` می‌ماند؛ هنگام ثبت فرم همان ردیف به‌روزرسانی
می‌شود، نه اینکه ردیف تازه‌ای درج شود. `addPostImageUnique()` از ثبت مسیر تکراری
جلوگیری می‌کند و `requireMediaUploads()` در صورت خطا رکوردهای نیمه‌کاره را
برمی‌گرداند؛ نتیجه: بدون رکورد ناقص، یتیم یا تکراری.

نقاط پایانی JSON مدیریت: `admin/posts/gallery.php` و `admin/posts/media-manage.php`
با کنش‌های `ensure_draft`, `add`, `delete`, `order`, `feature`, `unfeature`,
`set_featured_images`, `set_featured_videos`, `set_primary`, `state`.

## ۴. خوانندهٔ PDF داخل سایت

```php
echo jhd_pdf_reader($pdfUrl, ['title' => 'متن کتاب', 'allow_download' => true]);
```

* موتور: `assets/vendor/pdfjs/` (نسخهٔ legacy، بدون وابستگی به CDN).
* `assets/js/pdf-reader.js` فقط وقتی بارگذاری می‌شود که صفحه واقعاً PDF داشته باشد
  (`$GLOBALS['JHD_NEEDS_PDF_READER']`).
* صفحه‌ها تنبل رندر می‌شوند (IntersectionObserver)، بزرگ‌نمایی، تمام‌صفحه،
  شمارهٔ صفحه با رقم فارسی و دکمهٔ دانلود در کنار نوار ابزار.

## ۵. سربرگ کوتاه و ردیف موضوعات

`.jhd-hero-compact` جای بنر بلند قدیمی را گرفته و `.jhd-topic-rail` ردیف افقی
موضوعات را در همان بخش بالا نشان می‌دهد؛ روی موبایل با اسکرول افقی و بدون
اشغال فضای عمودی.

## ۶. قواعد کارایی

* شاخص‌های پایگاه داده روی `slug`، فهرست‌های عمومی، `post_topics` و ستون‌های
  شاخص (هر سه فایل `database/*.sql` و `tests/fixtures/schema.sqlite.sql`).
* `jhd_preload_post_topics()` موضوعات یک فهرست را با یک پرس‌وجو می‌گیرد
  (به‌جای N پرس‌وجو).
* `jhd_topic_content_counts()` شمارش دسته‌ای موضوع‌ها، `getTopicScopeIds()` و
  `getCategories()` کش‌شده.
* تصویرها هنگام ذخیره بازکدگذاری و به حداکثر `IMAGE_MAX_EDGE` پیکسل (پیش‌فرض
  ۲۰۰۰) کوچک می‌شوند؛ WebP اگر در دسترس باشد.
* `Cache-Control` سی‌روزه برای فایل‌های ایستا در `.htaccess`.
* صفحه‌بندی روی همهٔ فهرست‌های بزرگ.

## ۷. افزودن یک فهرست جدید — سیاههٔ کوتاه

1. `jhd_preload_post_topics($items)` را صدا بزنید.
2. `jhd_grid_open()` … `renderPostCard()` … `jhd_grid_close()`.
3. برای سه کارت اول `eager => true` بدهید.
4. برای فهرست خالی از `renderEmptyState()` استفاده کنید.
5. صفحه‌بندی را با `paginate()` اضافه کنید.

## ۸. بخش یکپارچهٔ «مقالات و پژوهش‌ها» (۲۰۲۶)

* «مقالات» و «پژوهش» دیگر دو بخش جدا نیستند؛ یک بخش با دو نما است:
  `/articles` (همه)، `/articles?type=article` و `/articles?type=research`.
* در ناوبری، فهرست موبایل، فوتر، صفحهٔ اصلی و صفحهٔ موضوع فقط یک مدخل «مقالات و پژوهش» وجود دارد.
* `/research` (فهرست قدیمی) با ۳۰۱ به `/articles?type=research` می‌رود؛ نشانی جزئیات
  `/research/<slug>` و `/articles/<slug>` بدون تغییر است.
* `countPosts()`، `getPosts()` و `getPostsByTopic()` کلید `types` (چند نوع همزمان) می‌پذیرند.

## ۹. تایپوگرافی (لایهٔ ۲۰۲۶ در `design-system.css`)

* فونت: Vazirmatn (متن‌باز، OFL) با وزن‌های ۴۰۰ و ۸۰۰–۹۰۰. فونت‌های اختصاصی BBC
  لایسنس عمومی ندارند و استفاده نشده‌اند.
* تیتر پرِوزن (۹۰۰)، سرخط بخش با میلهٔ ضخیم بالا، کارت‌ها با تیتر ۸۰۰.
* مقیاس ثابت و کوچک‌تر از نسخهٔ پیشین: ریشهٔ `html` روی `--jhd-root-size: 15px` است و
  همهٔ اندازه‌ها از متغیرهای `--jhd-text-*` می‌آیند (`xs` 11.25، `sm` 12.2، `base` 14،
  `md` 15، `lg` 16.9، `xl` 19.5، `2xl` 24، `3xl` 29 px). اندازهٔ خام در قالب ممنوع است.
* `--jhd-text-2xs` (10.3px) فقط برای برچسب عددی ریز است (شمار، badge، chip)؛ هیچ متن
  خوانایی — از جمله متادیتای کارت و فهرست اخبار — نباید کوچک‌تر از `--jhd-text-xs` باشد.
* متن مقاله: `1rem` روی همان ریشهٔ ۱۵px (یعنی ۱۵px)، فاصلهٔ خط ۱.۹۵ و عرض سطر
  `74ch`؛ پاراگراف اول به‌عنوان لید ۱.۰۷rem.
* ریتم فاصله‌ها مضرب ۴px است: `--jhd-space-1…8` برای فاصله‌های درون‌قطعه‌ای و
  `--jhd-space-section` / `--jhd-space-section-tight` (کلمپ‌شده با `clamp`) برای
  فاصلهٔ بین بخش‌ها. `--jhd-gap-card` فاصلهٔ استاندارد شبکهٔ کارت‌هاست.

## ۱۰. تمرکز بخش‌ها (۲۰۲۶)

بخش‌های اصلی پایگاه: **اخبار، مقالات و پژوهش، کتابخانه، دروس، رسانه، موضوعات** (به‌همراه درباره ما و تماس).
* «دسترسی سریع» از صفحهٔ اصلی حذف شد.
* گزارش‌ها، رویدادها، سخنرانی‌ها و پرسش و پاسخ از منو، منوی موبایل، فوتر، صفحهٔ اصلی و نقشهٔ سایت حذف شده‌اند؛
  محتوا و نشانی‌های آن‌ها در پایگاه داده و مسیرهای مستقیم همچنان باقی است.

## ۱۱. اسکلت صفحهٔ اصلی — هفت بلوک، پنج هاب (۲۰۲۶)

صفحهٔ اصلی یک «درگاه» است، نه آرشیو. ترتیب بلوک‌ها در `index.php` ثابت است و هر
بلوک یا از هاب‌سازهای `includes/cards.php` ساخته می‌شود یا هیچ خروجی‌ای ندارد:

1. `.jhd-hero` — کیکر، تیتر + حدیث با `.jhd-hero-display`، شعار، سه دکمهٔ دعوت
   (`lessons`، `books`، `about`) و در کنارش `jhd_picture('img/hero/hero-hawza')` داخل
   `figure.jhd-hero-media` با برچسب مکان و `figcaption`. زیر شبکه، ریل پنج هاب:
   `.jhd-hub-rail.jhd-hub-rail--grid` با `jhd_hub_tile()` برای دروس، کتابخانه،
   نوشتارها و رسانه + همیشگی «اخبار مدرسه». شبکه `auto-fit` است، پس اگر مدیر
   بخشی را خاموش کند، تایلِ جای‌خالی ساخته نمی‌شود.
2. `.jhd-banner-strip` — بنر فعالِ مدیر (`getActiveBanner()`)؛ نشانی بنر فقط وقتی پیوند
   می‌شود که مسیرِ هم‌دامنه باشد (نه `javascript:` و نه دامنهٔ بیرونی).
3. حالت خالی — اگر هیچ محتوایی نباشد `renderEmptyState()` (با متن متفاوت برای «دیتابیس وصل نیست»).
4. «شاخصِ سردبیر»: `renderPostCard($featurePost, ['featured' => true])` در `col-lg-8` و
   `aside .jhd-side-card` در `col-lg-4` با `renderMiniItem()`ها و `jhd_news_list()`.
5. هاب دروس (`jhd_home_lesson_collections()` در `.jhd-hub-grid--one`)، هاب کتابخانه
   (`renderBookCard`)، هاب نوشتارها (`renderEditorialRow` در `jhd-home-writing-list`).
6. هاب رسانه (`jhd_home_media()` برای ویدیو و صوت) و اطلس موضوعات
   (`.jhd-home-topic-links`)، و در پایان `jhd_cta_band()`.

هر بلوک با شرط «داشته بودن محتوا» چاپ می‌شود؛ برای همین بخشِ خالی در خانه باقی نمی‌ماند.

قواعدی که یکدستی را نگه می‌دارند:

* **کلیدهای خانه در یک جا**: `jhd_homepage_settings()` هفت کلید
  `hero / feature / lessons / books / writing / media / topics` را برمی‌گرداند.
  `jhd_homepage_legacy_keys()` نگاشت کلیدهای قدیمی (مثلاً `videos` → `media`) است؛
  یعنی تنظیمات ذخیره‌شدهٔ نصب‌های پیشین بی‌آنکه مهاجرت دیتابیس لازم باشد کار می‌کند.
* **بدون تکرار مطلب**: همهٔ فهرست‌ها از `jhd_homepage_unique_posts()` عبور می‌کنند و
  شناسه‌های پذیرفته‌شده در `$homeUsedPosts` می‌نشینند؛ یک مطلب حداکثر یک‌بار در خانه می‌آید.
* **دیتابیس اختیاری است**: هر بلوک با `$jhdPublicDbReady` می‌سازد؛ بدون دیتابیس، خانه
  اسکلتِ خالی نشان می‌دهد نه خطای ۵۰۰. (همین الگو در ناوبری با
  `jhd_static_section_children()` تکرار شده است.)
* `jhd_preload_post_topics()` پیش از `require header.php` صدا زده می‌شود تا هر
  درخواستِ تاپیکِ درون کارت‌ها یک کوئری گروهی باشد، نه N تا.

## ۱۲. ناوبری — یک رجیستری، سه مصرف‌کننده

* `jhd_nav_sections()` در `includes/cards.php` تنها منبع حقیقت منو است:
  `primary` (پنج بخش هابی در نوار دسکتاپ) و `more` (بقیهٔ بخش‌ها در دراپ‌داون «بیشتر»،
  منوی کشویی موبایل و فوتر).
* هر مدکل می‌تواند `extra_active` داشته باشد (آرایهٔ کلیدهای `?p=`) تا مثلاً
  `/topic/<slug>` هم «موضوعات» را در منو روشن نگه دارد؛
  `jhd_render_desktop_nav_item()` و `jhd_render_drawer_nav_item()` همان را می‌خوانند.
* صفحهٔ «بیشتر» به `/topics` اشاره می‌کند و درخت موضوعات (`jhd_render_topic_tree_nav()`)
  را در همان دراپ‌داون می‌گذارد؛ `$navMoreActive` از `isActiveNav('topic')` هم seeded می‌شود.
* مسیرها حذف نشده‌اند، فقط از منو بیرون رفته‌اند: `news`, `qa`, `programs`,
  `announcements`, `religious-activities`, `events`, `speeches`, `reports`,
  `research` همچنان در `config/routes.php` فعال‌اند (برای لینک‌های قدیمی، نقشهٔ سایت و اپ اندروید).
* فوتر همان رجیستری را مصرف می‌کند (`$footerSections` = پنج بخش اصلی + چهار بخش اول `more`)؛
  افزودن یک بخش تازه فقط یک خط در همین رجیستری می‌خواهد.

## ۱۳. تصویر، گالری و لایت‌باکس

* `jhd_picture($base, $widths, $alt, $opts)` خروجی `<picture>` با `srcset` وب‌پی‌جی/جی‌پِگ،
  `width`/`height` (برای جلوگیری از جهش چیدمان)، `loading` و `fetchpriority` می‌دهد.
  فایل‌های `assets/img/hero/*` با `npm run build:hero` (اسکریپت `tools/build-hero.mjs`)
  از منبع ۱۲۸۰px ساخته می‌شوند؛ نسخه‌های ۶۴۰ و ۹۶۰ دستی نسازید.
* **قاعدهٔ لایت‌باکس**: ریشهٔ `.jhd-lightbox` باید در `<body>` باشد (پورتال)، نه داخل
  کارت. دلیلش این است که `.jhd-card-media` سرریز پنهان و `aspect-ratio` دارد و
  `.jhd-card:hover` با `transform` «containing block» می‌سازد؛ در نتیجهٔ آن
  `position: fixed` نسبت به کارت می‌شد و تصویر در پنجره نصفه‌نیمه یا نامرئی نمایش داده
  می‌شد. `assets/js/gallery.js` همان ریشه را به `body` منتقل می‌کند،
  `body.jhd-lightbox-open` اسکرول را قفل می‌کند و `z-index: 2050` روی همهٔ سربرگ‌ها
  و مودال‌های بوت‌استرپ قرار می‌گیرد.
* قرارداد DOM: ریشهٔ `[data-jhd-gallery="<uid>"]`، دیتای `[data-jhd-lightbox-data="<uid>"]`
  (نام قدیمی `data-jhd-gallery-data` همچنان پذیرفته می‌شود)، المان‌های
  `[data-jhd-lightbox-image|-caption|-counter|-download|-thumbs|-prev|-next|-close]` و
  تریگرهای `a.jhd-gallery__cell[data-index]`. ارتفاع تصویر با `vh` محدود می‌شود، نه
  با `flex: 1`، واین که `figcaption` زیر تصویر بماند.
* `jhd_card_gallery()` ترتیب تصاویر را از `post_images` با `sort_order ASC, id ASC`
  می‌خواند و تصویر شاخص را به ابتدا می‌چرخاند؛ اگر جدول تصویر وجود نداشت، `catch`
  همان کارت تک‌تصویری را می‌سازد.

## ۱۴. پنل مدیریت موبایل‌محور

* پوستهٔ پنل در `assets/css/admin.css` است (پیش‌تر `<style>` درون‌خطی در
  `admin/includes/header.php` بود؛ حالا یک `<link>`). روتر، `/assets/*.css` را مستقیم
  سرو می‌کند، پس کشیدن مرورگر بین ۲۰+ صفحهٔ پنل هم مشترک است.
* زیر `767.98px`:
  * `.admin-tabbar` پایین صفحه: داشبورد، محتوا، «افزودن» (دکمهٔ برجستهٔ وسط)، رسانه و
    «منو» که همان کشوی `#adminSidebar` را باز می‌کند (`data-admin-drawer`). تب‌ها بر پایهٔ
    `jhd_can()` ساخته می‌شوند؛ تبِ بی‌دسترسی نمایش داده نمی‌شود.
  * جدول `table.admin-table` کارت می‌شود: هر `tr` یک کارت و هر `td` یک ردیف
    «برچسب: مقدار». برچسب را اسکرپیت انتهای `admin/includes/footer.php` از روی
    `<th>` به `data-label` می‌نویسد، پس هیچ فهرستی برای موبایل باید ویرایش نشود.
  * ورودی‌ها `1rem` و `min-height: 46px`، دکمه‌ها `≥ 42px` (هدف لمسی)، و ردیف
    `class="admin-form-bar"` (ذخیره/انصراف) چسبان به پایین صفحه می‌چسبد.
* `npm run lint:php` (`tools/lint-php.mjs`) همهٔ فایل‌های PHP را بدون باینری PHP
  پارس می‌کند؛ در محیط‌هایی که `php -l` نصب نیست، تنها ترمزِ خطای نگارشی همین است.
