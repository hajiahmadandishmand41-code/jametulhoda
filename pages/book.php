<?php
/**
 * book.php — صفحه کتاب (لندینگ معرفی + فهرست + دانلود + پیوند موضوعی)
 */
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/media.php';

$slug = $_GET['slug'] ?? '';
$slug = is_string($slug) ? trim($slug) : '';
$id   = (int)($_GET['id'] ?? 0);
if (!jhd_db_ready()) {
    jhd_render_content_unavailable('کتاب', url('books'), 'کتابخانه');
}
$book = null;
if($slug){
    $book=getBookBySlug($slug);
}
if(!$book && $id){
    $book=getBookById($id);
}
if(!$book || ($book['status']??'published')!=='published'){
    http_response_code(404);
    $pageTitle='کتاب یافت نشد';
    require __DIR__.'/../includes/header.php';
    echo '<section class="container py-5"><nav class="breadcrumb-bar mb-4"><a href="'.siteUrl('books').'">کتابخانه</a> / یافت نشد</nav><h1>کتاب مورد نظر یافت نشد.</h1><a class="btn btn-primary mt-3" href="'.siteUrl('books').'">کتابخانه</a></section>';
    require __DIR__.'/../includes/footer.php'; exit;
}

// download redirect (no counter)
if(isset($_GET['download'])){
    $type=$_GET['download'];
    $col = $type==='pdf' ? 'pdf_file' : ($type==='word' ? 'word_file' : null);
    if(!$col || empty($book[$col])){ http_response_code(404); exit('فایل مورد نظر موجود نیست.'); }
    $key=storageKey($book[$col] ?? '');
    if(!$key){ http_response_code(404); exit('فایل موجود نیست.'); }
    header('Location: '.storageUrl($key), true, 302); exit;
}

// One book = one URL. Books published with a slug are always addressed by that
// slug; the numeric /book/<id> form stays reachable and forwards here.
$bookCanonicalPath = trim((string)($book['slug'] ?? '')) !== ''
    ? jhd_route_path('book', ['slug' => (string)$book['slug']])
    : jhd_route_path('book', ['id' => (int)$book['id']]);
jhd_redirect_to_canonical(bookUrl($book), $bookCanonicalPath);

$pageTitle = $book['title'];
$canonicalOverride = bookUrl($book);
$rawBookDesc = excerpt($book['description'] ?? $book['toc'] ?? '', 160);
$pageDesc = $rawBookDesc !== '' ? $rawBookDesc : ('کتاب «' . $book['title'] . '» در کتابخانه دیجیتال مدرسه جامعه‌الهدی');

/*
 * NB: the local variable used to be called `$canonicalUrl`, which shadowed the
 * canonicalUrl() *function* — so the breadcrumb items below were emitted as
 * relative paths instead of absolute URLs. Renamed, and the JSON-LD now comes
 * from the shared helpers so it matches every other page.
 */
$bookCanonical = bookUrl($book);
$ogImage = !empty($book['cover_image']) ? jhd_absolute_url(imgUrl($book['cover_image'])) : canonicalUrl(SITE_LOGO_PATH);
$ogImageAlt = (string)$book['title'];

$breadcrumbs = [
  ['name' => 'صفحه اصلی', 'url' => SITE_URL ? rtrim(SITE_URL, '/') . '/' : siteUrl()],
  ['name' => 'کتابخانه', 'url' => siteUrl('books')],
  ['name' => $book['title'], 'url' => $bookCanonical],
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);
$bookJsonLd = bookJsonLd($book);

$topics = getTopicsForBook((int)$book['id']);
$attachments = getMediaFor('book', (int)$book['id'], 'document');
$related = getRelatedBooks((int)$book['id'], 6);
require __DIR__.'/../includes/header.php';
?>

<div class="breadcrumb-bar"><div class="container">
<nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
<?php foreach($breadcrumbs as $i=>$cr): if($i===count($breadcrumbs)-1): ?><li class="breadcrumb-item active"><?= sanitize($cr['name']) ?></li><?php else: ?><li class="breadcrumb-item"><a href="<?= sanitize($cr['url']) ?>"><?= sanitize($cr['name']) ?></a></li><?php endif; endforeach; ?>
</ol></nav>
</div></div>

<article class="container py-4 py-md-5 jhd-book-detail">
<?php
$bookDetailUrl = bookUrl($book);
$bookDownloadPdfUrl = $bookDetailUrl . (str_contains($bookDetailUrl, '?') ? '&' : '?') . 'download=pdf';
$bookDownloadWordUrl = $bookDetailUrl . (str_contains($bookDetailUrl, '?') ? '&' : '?') . 'download=word';
?>
<div class="row g-4 g-xl-5 align-items-start">
<aside class="col-lg-4 jhd-book-sidebar" aria-label="جلد و مشخصات کتاب">
  <div class="jhd-book-cover-panel">
    <div class="jhd-book-cover-stage">
      <?php if (!empty($book['cover_image'])): ?>
      <img src="<?= sanitize(imgUrl((string)$book['cover_image'])) ?>" alt="جلد <?= sanitize($book['title']) ?>" class="jhd-book-detail-cover" loading="eager" width="400" height="530">
      <?php else: ?>
      <div class="jhd-book-cover-empty">
        <i class="bi bi-book" aria-hidden="true"></i>
        <span>جلد کتاب ثبت نشده است</span>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($book['pdf_file']) || !empty($book['word_file'])): ?>
    <div class="jhd-book-actions" aria-label="دسترسی به فایل کتاب">
      <?php if (!empty($book['pdf_file'])): ?>
      <a href="<?= sanitize(documentReaderUrl('book', (string)$book['slug'])) ?>" class="btn btn-primary jhd-book-study"><i class="bi bi-book ms-1" aria-hidden="true"></i>مطالعهٔ آنلاین</a>
      <a href="<?= sanitize($bookDownloadPdfUrl) ?>" class="btn btn-outline-primary jhd-book-download"><i class="bi bi-file-pdf ms-1" aria-hidden="true"></i>دریافت PDF</a>
      <?php endif; ?>
      <?php if (!empty($book['word_file'])): ?>
      <a href="<?= sanitize($bookDownloadWordUrl) ?>" class="btn <?= !empty($book['pdf_file']) ? 'btn-outline-secondary' : 'btn-primary' ?> jhd-book-download"><i class="bi bi-file-word ms-1" aria-hidden="true"></i>دریافت Word</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($book['author']) || !empty($book['translator']) || !empty($book['publisher']) || !empty($book['publish_year']) || !empty($book['pages'])): ?>
  <section class="jhd-book-metadata" aria-label="مشخصات کتاب">
    <h2 class="jhd-book-metadata__title">مشخصات کتاب</h2>
    <?php if (!empty($book['author'])): ?><div class="jhd-book-meta-row"><span>نویسنده</span><strong><?= sanitize($book['author']) ?></strong></div><?php endif; ?>
    <?php if (!empty($book['translator'])): ?><div class="jhd-book-meta-row"><span>مترجم</span><strong><?= sanitize($book['translator']) ?></strong></div><?php endif; ?>
    <?php if (!empty($book['publisher'])): ?><div class="jhd-book-meta-row"><span>ناشر</span><strong><?= sanitize($book['publisher']) ?></strong></div><?php endif; ?>
    <?php if (!empty($book['publish_year'])): ?><div class="jhd-book-meta-row"><span>سال نشر</span><strong><?= sanitize((string)$book['publish_year']) ?></strong></div><?php endif; ?>
    <?php if (!empty($book['pages'])): ?><div class="jhd-book-meta-row"><span>تعداد صفحات</span><strong><?= number_format((int)$book['pages']) ?></strong></div><?php endif; ?>
  </section>
  <?php endif; ?>
</aside>

<div class="col-lg-8 jhd-book-main" id="book-main">
  <header class="jhd-book-heading">
    <span class="jhd-eyebrow">کتابخانهٔ دیجیتال</span>
    <h1 class="jhd-detail-title"><?= sanitize($book['title']) ?></h1>
    <?php if (!empty($book['author'])): ?>
    <p class="jhd-book-author"><i class="bi bi-person" aria-hidden="true"></i><span><?= sanitize($book['author']) ?><?= !empty($book['translator']) ? ' — ترجمهٔ ' . sanitize($book['translator']) : '' ?></span></p>
    <?php elseif (!empty($book['translator'])): ?>
    <p class="jhd-book-author"><i class="bi bi-translate" aria-hidden="true"></i><span>مترجم: <?= sanitize($book['translator']) ?></span></p>
    <?php endif; ?>
  </header>

  <?php if (!empty($book['description'])): ?>
  <section class="jhd-book-section jhd-book-introduction" aria-labelledby="book-introduction-title">
    <h2 id="book-introduction-title" class="jhd-book-section-title"><i class="bi bi-info-circle" aria-hidden="true"></i>معرفی کتاب</h2>
    <div class="jhd-prose jhd-book-copy"><?= safeRichText($book['description']) ?></div>
  </section>
  <?php endif; ?>

  <?php if (!empty($book['toc'])): ?>
  <details class="jhd-book-toc jhd-book-section">
    <summary>
      <span class="jhd-book-section-title"><i class="bi bi-list-ol" aria-hidden="true"></i>فهرست مطالب</span>
      <i class="bi bi-chevron-down jhd-book-toc-chevron" aria-hidden="true"></i>
    </summary>
    <div class="jhd-book-toc-content"><?= sanitize($book['toc']) ?></div>
  </details>
  <?php endif; ?>

  <?php if ($attachments): ?>
  <section class="jhd-attachments jhd-book-attachments jhd-book-section" aria-label="فایل‌های تکمیلی کتاب">
    <div class="jhd-attachments__head">
      <h2 class="jhd-attachments__title"><i class="bi bi-paperclip" aria-hidden="true"></i>فایل‌های تکمیلی</h2>
      <p class="jhd-attachments__lead">ضمیمه‌ها و نسخه‌های دیگر این کتاب</p>
    </div>
    <div class="jhd-files">
      <?php foreach ($attachments as $file):
        $fileTitle = (string)($file['title'] ?: basename((string)$file['file_path']));
        $isPdf = (bool)preg_match('~\.pdf$~i', (string)$file['file_path']);
        $fileHref = imgUrl((string)$file['file_path']);
        $readerHref = $isPdf ? documentReaderUrl('book', (string)$book['slug'], (int)($file['id'] ?? 0)) : '';
        $fileSize = (string)($file['file_size'] ?? ($file['size'] ?? ''));
      ?>
      <?= jhd_file_row($fileTitle, $fileHref, $fileSize, 'bi-file-earmark', $isPdf ? ['reader_target' => $readerHref] : []) ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <nav class="jhd-book-navigation" aria-label="پیوندهای کتاب">
    <a href="<?= sanitize(siteUrl('books')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>بازگشت به کتابخانه</a>
  </nav>
</div>
</div>

<?php if ($related): ?>
<section class="jhd-book-related jhd-book-section" aria-labelledby="book-related-title">
  <h2 id="book-related-title" class="jhd-book-section-title"><i class="bi bi-collection" aria-hidden="true"></i>کتاب‌های مرتبط</h2>
  <?= jhd_grid_open() ?>
  <?php foreach ($related as $rb) echo renderBookCard($rb); ?>
  <?= jhd_grid_close() ?>
</section>
<?php endif; ?>
</article>

<?php require __DIR__.'/../includes/footer.php'; ?>
