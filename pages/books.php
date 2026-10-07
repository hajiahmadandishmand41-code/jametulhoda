<?php
/**
 * books.php — کتابخانه دیجیتال (مرجع کتب حوزوی و پژوهشی)
 */
$pageTitle = 'کتابخانه دیجیتال';
$pageDesc = 'کتابخانه دیجیتال مدرسه جامعه‌الهدی — کتب علمی، حوزوی و پژوهشی با دسترسی آزاد، معرفی و دانلود فایل‌های PDF و Word.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');
$topicSlug = trim($_GET['topic'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$topic = null;
$topicId = null;
if ($topicSlug) {
    $topic = getTopicBySlug($topicSlug);
    if ($topic) $topicId = (int)$topic['id'];
}

$opts = ['limit' => $limit, 'offset' => $offset];
if ($search) $opts['search'] = $search;
if ($topicId) $opts['topic'] = $topicId;

$books = getBooks($opts);
$total = countBooks(['search' => $search, 'topic' => $topicId]);
$pages = (int)ceil($total / $limit);
jhd_validate_pagination($page, $total, $limit, 'کتابخانه', url('books'), 'کتابخانه');
$noindexSeo = ($total === 0);


$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'کتابخانه', 'url' => url('books')],
];
if ($topic) {
    $breadcrumbs[] = ['name' => $topic['name'], 'url' => url('books', ['topic' => $topic['slug']])];
}
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <?php foreach ($breadcrumbs as $i => $cr): $isLast = ($i === count($breadcrumbs) - 1); ?>
        <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>>
          <?php if (!$isLast): ?><a href="<?= sanitize($cr['url']) ?>"><?= sanitize($cr['name']) ?></a><?php else: ?><?= sanitize($cr['name']) ?><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </nav>
  </div>
</div>

<div class="jhd-section">
  <div class="container">
    <?= jhd_page_head([
        'eyebrow' => 'مرجع اسناد و نشر آثار اسلامی',
        'icon' => 'bi-book',
        'title' => 'کتابخانه دیجیتال',
        'lead' => 'کتب درسی حوزوی، تألیفات اساتید، آثار پژوهشی و متون کهن با قابلیت معرفی و دریافت.',
    ]) ?>

    <?= jhd_listing_toolbar([
        'route' => 'books',
        'search_action' => formUrl('books'),
        'placeholder' => 'جستجو در عنوان، نویسنده یا توضیحات کتاب...',
        'value' => $search,
        'chips' => ($topicSlug ? '<a class="jhd-chip jhd-chip--all" href="' . sanitize(url('books')) . '">حذف فیلتر موضوع</a>' : ''),
        'extra' => $total > 0 ? '<span class="text-muted small ms-auto">' . number_format($total) . ' عنوان کتاب</span>' : '',
    ]) ?>

    <?php if ($topic): ?>
    <div class="jhd-topic-hero d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
      <div>
        <span class="text-muted small">فیلتر موضوعی:</span>
        <strong class="ms-1"><?= sanitize($topic['name']) ?></strong>
        <?php if ($topic['description']): ?><span class="text-muted small d-none d-md-inline ms-2">— <?= sanitize(excerpt($topic['description'], 120)) ?></span><?php endif; ?>
      </div>
      <a href="<?= topicUrl($topic) ?>" class="btn btn-sm btn-outline-primary">هاب جامع موضوع <i class="bi bi-arrow-left ms-1"></i></a>
    </div>
    <?php endif; ?>

    <?php if (empty($books)): ?>
    <?= renderEmptyState('bi-book', $search || $topicSlug ? 'کتابی با این مشخصات یافت نشد.' : 'هنوز کتابی در این بخش ثبت نشده است.', url('books'), 'مشاهده همه کتاب‌ها') ?>
    <?php else: ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($books as $b): ?><?= renderBookCard($b) ?><?php endforeach; ?>
    <?= jhd_grid_close() ?>
    <?php if ($pages > 1): ?>
    <nav class="mt-4" aria-label="صفحه‌بندی کتابخانه">
      <?= paginate($total, $limit, $page, url('books', ['q' => $search, 'topic' => $topicSlug, 'page' => '%d'])) ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
