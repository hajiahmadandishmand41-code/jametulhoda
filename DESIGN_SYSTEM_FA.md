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
* گونه‌ها: `default`، `featured` (کارت بزرگ صدر صفحه)، `compact` (فهرست کناری).
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
