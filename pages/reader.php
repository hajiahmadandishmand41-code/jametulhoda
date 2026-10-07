<?php
/**
 * reader.php — صفحهٔ اختصاصی مطالعهٔ PDF
 *
 * فایل‌های PDF در صفحات محتوا دیگر داخل همان صفحه رندر نمی‌شوند. هر مورد یک
 * مقصد مستقل برای مطالعه دارد تا صفحهٔ اصلی و صفحات محتوا سبک‌تر بمانند.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$kind = strtolower(trim((string)($_GET['type'] ?? '')));
$slug = trim((string)($_GET['slug'] ?? ''));
$mediaId = max(0, (int)($_GET['media'] ?? 0));

if (!in_array($kind, ['book', 'lesson', 'post'], true) || $slug === '') {
    http_response_code(404);
    $pageTitle = 'صفحه مطالعه یافت نشد';
    $pageDesc = 'نشانی صفحه مطالعه معتبر نیست.';
    require __DIR__ . '/../includes/header.php';
    echo '<section class="container py-5 text-center"><h1>صفحه مطالعه یافت نشد</h1><p class="text-muted">نشانی یا فایل موردنظر معتبر نیست.</p><a class="btn btn-primary mt-3" href="' . sanitize(url()) . '">بازگشت به صفحه اصلی</a></section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$db = jhd_db();
if ($db === null) {
    jhd_render_content_unavailable('فایل مطالعه', url(), 'صفحه اصلی');
}

$title = '';
$filePath = '';
$backUrl = url();
$backLabel = 'بازگشت';
$sourceType = $kind;

try {
    if ($kind === 'book') {
        $book = getBookBySlug($slug);
        if (!$book || ($book['status'] ?? 'published') !== 'published' || empty($book['pdf_file'])) {
            throw new RuntimeException('book not found');
        }
        $title = (string)$book['title'];
        $filePath = (string)$book['pdf_file'];
        $backUrl = bookUrl($book);
        $backLabel = 'بازگشت به کتاب';
    } elseif ($kind === 'lesson') {
        $lesson = getLessonBySlug($slug);
        if (!$lesson || ($lesson['status'] ?? 'published') !== 'published') {
            throw new RuntimeException('lesson not found');
        }
        $title = (string)$lesson['title'];
        if ($mediaId > 0) {
            $stmt = $db->prepare("SELECT file_path FROM media_files WHERE id=? AND ref_type='lesson' AND ref_id=? AND kind='document' LIMIT 1");
            $stmt->execute([$mediaId, (int)$lesson['id']]);
            $filePath = (string)$stmt->fetchColumn();
        }
        if ($filePath === '') $filePath = (string)($lesson['pdf_file'] ?? '');
        if ($filePath === '') throw new RuntimeException('lesson pdf missing');
        $backUrl = lessonUrl($lesson);
        $backLabel = 'بازگشت به درس';
    } else {
        $stmt = $db->prepare("SELECT p.*, COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name FROM posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.slug=? AND p.status='published' LIMIT 1");
        $stmt->execute([$slug]);
        $post = $stmt->fetch() ?: null;
        if (!$post) throw new RuntimeException('post not found');

        $title = (string)$post['title'];
        if ($mediaId > 0) {
            $m = $db->prepare("SELECT file_path FROM media_files WHERE id=? AND ref_type='post' AND ref_id=? AND kind='document' LIMIT 1");
            $m->execute([$mediaId, (int)$post['id']]);
            $filePath = (string)$m->fetchColumn();
        }
        if ($filePath === '') {
            $filePath = (string)($post['pdf_file'] ?? '');
        }
        if ($filePath === '') throw new RuntimeException('post pdf missing');
        $backUrl = postUrl($post);
        $backLabel = 'بازگشت به مطلب';
    }
} catch (Throwable) {
    http_response_code(404);
    $pageTitle = 'فایل مطالعه یافت نشد';
    $pageDesc = 'فایل PDF این محتوا در دسترس نیست.';
    require __DIR__ . '/../includes/header.php';
    echo '<section class="container py-5 text-center"><h1>فایل مطالعه یافت نشد</h1><p class="text-muted">ممکن است فایل حذف شده یا هنوز بارگذاری نشده باشد.</p><a class="btn btn-primary mt-3" href="' . sanitize($backUrl) . '">' . sanitize($backLabel) . '</a></section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if (!preg_match('~\.pdf$~i', $filePath)) {
    http_response_code(404);
    $pageTitle = 'فایل مطالعه معتبر نیست';
    $pageDesc = 'صفحهٔ مطالعه فقط برای PDF است.';
    require __DIR__ . '/../includes/header.php';
    echo '<section class="container py-5 text-center"><h1>فایل مطالعه معتبر نیست</h1><p class="text-muted">این فایل PDF نیست.</p><a class="btn btn-primary mt-3" href="' . sanitize($backUrl) . '">' . sanitize($backLabel) . '</a></section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$fileUrl = imgUrl($filePath);
if ($fileUrl === '') {
    http_response_code(404);
    $pageTitle = 'فایل مطالعه در دسترس نیست';
    $pageDesc = 'نشانی فایل PDF قابل ساخت نیست.';
    require __DIR__ . '/../includes/header.php';
    echo '<section class="container py-5 text-center"><h1>فایل مطالعه در دسترس نیست</h1><a class="btn btn-primary mt-3" href="' . sanitize($backUrl) . '">' . sanitize($backLabel) . '</a></section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = 'مطالعه: ' . $title;
$pageDesc = 'صفحه مطالعهٔ ' . $title . ' در مدرسه جامعه‌الهدی.';
$canonicalOverride = documentReaderUrl($sourceType, $slug, $mediaId);
$noindexSeo = true;
$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => SITE_URL ? rtrim(SITE_URL, '/') . '/' : url()],
    ['name' => $sourceType === 'book' ? 'کتابخانه' : ($sourceType === 'lesson' ? 'دروس' : 'مطالب'), 'url' => $backUrl],
    ['name' => 'مطالعه', 'url' => $canonicalOverride],
    ['name' => $title, 'url' => $canonicalOverride],
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-bar"><div class="container">
  <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
    <?php foreach ($breadcrumbs as $i => $bc): $last = $i === count($breadcrumbs) - 1; ?>
      <li class="breadcrumb-item <?= $last ? 'active' : '' ?>" <?= $last ? 'aria-current="page"' : '' ?>>
        <?php if (!$last): ?><a href="<?= sanitize($bc['url']) ?>"><?= sanitize($bc['name']) ?></a><?php else: ?><?= sanitize($bc['name']) ?><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol></nav>
</div></div>

<main class="jhd-section jhd-reader-page">
  <div class="container">
    <div class="jhd-reader-head">
      <div>
        <span class="jhd-eyebrow">مرکز مطالعه</span>
        <h1 class="jhd-reader-title"><i class="bi bi-book-half ms-2" aria-hidden="true"></i><?= sanitize($title) ?></h1>
        <p class="jhd-reader-lead">متن PDF در یک صفحهٔ اختصاصی و سبک برای مطالعهٔ موبایل و دسکتاپ نمایش داده می‌شود.</p>
      </div>
      <a class="btn btn-outline-primary" href="<?= sanitize($backUrl) ?>"><i class="bi bi-arrow-right ms-1"></i><?= sanitize($backLabel) ?></a>
    </div>

    <section class="jhd-reader-shell" aria-label="خواننده PDF">
      <?= jhd_pdf_reader($fileUrl, [
          'title' => $title,
          'download' => $fileUrl,
          'note' => 'برای مطالعه، از ابزارهای پایین/بالای خواننده استفاده کنید؛ این صفحه مخصوص مطالعهٔ همین سند است.',
      ]) ?>
    </section>
  </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
