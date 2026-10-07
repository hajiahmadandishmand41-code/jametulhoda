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
$pageDesc  = excerpt($book['description'] ?? $book['toc'] ?? '', 160);

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

<article class="container py-4 py-md-5">
<div class="row g-4">
<div class="col-lg-4">
<div class="jhd-book-cover-panel">
<?php if(!empty($book['cover_image'])): ?>
<img src="<?= imgUrl($book['cover_image']) ?>" alt="جلد <?= sanitize($book['title']) ?>" class="jhd-book-detail-cover" loading="eager" width="400" height="530">
<?php else: ?>
<div class="p-4 border rounded-4 text-muted small">برای این کتاب تصویر جلد ثبت نشده است.</div>
<?php endif; ?>
<?php $bookDetailUrl = bookUrl($book); ?>
<div class="mt-4 d-grid gap-2">
<?php if(!empty($book['pdf_file'])): ?>
<a href="<?= sanitize(documentReaderUrl('book', (string)$book['slug'])) ?>" class="btn btn-primary"><i class="bi bi-book ms-2"></i>مطالعه کتاب</a>
<?php elseif(!empty($book['word_file'])): ?>
<a href="<?= sanitize($bookDetailUrl . (str_contains($bookDetailUrl, '?') ? '&amp;' : '?') . 'download=word') ?>" class="btn btn-primary"><i class="bi bi-file-word ms-2"></i>دریافت فایل کتاب</a>
<?php endif; ?>
<?php if($attachments): ?><div class="pt-2"><h2 class="h6 fw-bold mb-2">فایل‌های تکمیلی</h2><?php foreach($attachments as $file):
  $fileTitle = (string)($file['title'] ?: basename((string)$file['file_path']));
  $isPdf = (bool)preg_match('~\.pdf$~i', (string)$file['file_path']);
  $fileHref = $isPdf ? documentReaderUrl('book', (string)$book['slug'], (int)($file['id'] ?? 0)) : imgUrl((string)$file['file_path']);
?><a href="<?= sanitize($fileHref) ?>"<?= $isPdf ? '' : ' target="_blank" rel="noopener"' ?> class="btn btn-sm btn-outline-secondary w-100 mb-1"><i class="bi <?= $isPdf ? 'bi-book' : 'bi-paperclip' ?> ms-1"></i><?= sanitize($fileTitle) ?></a><?php endforeach; ?></div><?php endif; ?>
</div>
<?php if($topics): ?>
<div class="text-start mt-4">
<div class="small fw-bold mb-2" style="color:var(--jhd-green)"><i class="bi bi-tags ms-1"></i> موضوعات</div>
<div class="d-flex flex-wrap gap-2">
<?php foreach($topics as $tp): ?><a href="<?= topicUrl($tp) ?>" class="badge rounded-pill jhd-book-topic"><?= sanitize($tp['name']) ?></a><?php endforeach; ?>
</div>
</div>
<?php endif; ?>
</div>

<?php if(!empty($book['author']) || !empty($book['publisher'])): ?>
<div class="mt-4 p-3 rounded-4 jhd-book-metadata">
<?php if(!empty($book['author'])): ?><div class="d-flex justify-content-between small py-1"><span class="text-muted">نویسنده</span><strong><?= sanitize($book['author']) ?></strong></div><?php endif; ?>
<?php if(!empty($book['translator'])): ?><div class="d-flex justify-content-between small py-1"><span class="text-muted">مترجم</span><strong><?= sanitize($book['translator']) ?></strong></div><?php endif; ?>
<?php if(!empty($book['publisher'])): ?><div class="d-flex justify-content-between small py-1"><span class="text-muted">ناشر</span><strong><?= sanitize($book['publisher']) ?></strong></div><?php endif; ?>
<?php if(!empty($book['publish_year'])): ?><div class="d-flex justify-content-between small py-1"><span class="text-muted">سال نشر</span><strong><?= sanitize($book['publish_year']) ?></strong></div><?php endif; ?>
<?php if(!empty($book['pages'])): ?><div class="d-flex justify-content-between small py-1"><span class="text-muted">تعداد صفحات</span><strong><?= (int)$book['pages'] ?></strong></div><?php endif; ?>
</div>
<?php endif; ?>
</div>

<div class="col-lg-8">
<span class="jhd-eyebrow">کتابخانه دیجیتال</span>
<h1 class="jhd-detail-title"><?= sanitize($book['title']) ?></h1>
<?php if(!empty($book['author'])): ?><p class="text-muted mb-3"><i class="bi bi-person ms-1"></i> <?= sanitize($book['author']) ?><?= !empty($book['translator']) ? ' — ترجمهٔ '.sanitize($book['translator']) : '' ?></p><?php endif; ?>

<?php if(!empty($book['pdf_file'])): ?>
<section class="mb-4 jhd-reader-callout">
  <div><span class="jhd-eyebrow">مرکز مطالعه</span><h2 class="h5 mb-1">مطالعهٔ کتاب</h2><p class="text-muted mb-0">خواندن کتاب در صفحهٔ اختصاصی مطالعه انجام می‌شود.</p></div>
  <a class="btn btn-primary" href="<?= sanitize(documentReaderUrl('book', (string)$book['slug'])) ?>"><i class="bi bi-book ms-2"></i>مطالعه کتاب</a>
</section>
<?php endif; ?>

<?php if(!empty($book['description'])): ?>
<section class="mb-4">
<h2 class="h6 fw-bold" style="color:var(--jhd-green)"><i class="bi bi-info-circle ms-2"></i> معرفی کتاب</h2>
<div class="post-content jhd-book-copy"><?= nl2br(sanitize($book['description'])) ?></div>
</section>
<?php endif; ?>

<?php if(!empty($book['toc'])): ?>
<section class="mb-4 p-3 p-md-4 rounded-4 jhd-book-toc">
<h2 class="h6 fw-bold mb-3" style="color:var(--jhd-green)"><i class="bi bi-list-ol ms-2"></i> فهرست مطالب</h2>
<div class="jhd-book-toc-content"><?= sanitize($book['toc']) ?></div>
</section>
<?php endif; ?>

<?php if($topics): ?>
<section class="mb-4">
<h2 class="h6 fw-bold" style="color:var(--jhd-green)"><i class="bi bi-diagram-3 ms-2"></i> پیوندهای داخلی</h2>
<div class="d-flex flex-wrap gap-2">
<?php foreach($topics as $tp): ?><a href="<?= topicUrl($tp) ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><?= sanitize($tp['name']) ?></a><?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mt-4">
<a href="<?= siteUrl('books') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-right ms-1"></i> بازگشت به کتابخانه</a>
<?php if($topics): $firstTopic=$topics[0]; ?><a href="<?= topicUrl($firstTopic) ?>" class="btn btn-outline-primary">مشاهده در موضوع <?= sanitize($firstTopic['name']) ?></a><?php endif; ?>
</div>
</div>
</div>

<?php if($related): ?>
<section class="mt-5">
<h2 class="h5 fw-bold mb-3" style="color:var(--jhd-green)"><i class="bi bi-collection ms-2"></i> کتاب‌های مرتبط</h2>
<?= jhd_grid_open() ?>
<?php foreach($related as $rb) echo renderBookCard($rb); ?>
</div>
</section>
<?php endif; ?>
</article>

<?php require __DIR__.'/../includes/footer.php'; ?>
