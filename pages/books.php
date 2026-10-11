<?php
/**
 * books.php — کتابخانه دیجیتال (مرجع کتب حوزوی و پژوهشی)
 */
$pageTitle = 'کتابخانه دیجیتال';
$metaTitleOverride = 'کتابخانه علوم اسلامی | کتاب‌های حوزوی و پژوهشی';
$pageDesc = 'کتابخانه دیجیتال مدرسه جامعه‌الهدی — کتب علمی، حوزوی و پژوهشی با دسترسی آزاد، معرفی و دانلود فایل‌های PDF و Word.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$opts = ['limit' => $limit, 'offset' => $offset];
if ($search) $opts['search'] = $search;

$books = getBooks($opts);
$total = countBooks(['search' => $search]);
$pages = (int)ceil($total / $limit);
jhd_validate_pagination($page, $total, $limit, 'کتابخانه', url('books'), 'کتابخانه');
$noindexSeo = ($total === 0);


$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'کتابخانه', 'url' => url('books')],
];
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
        'extra' => $total > 0 ? '<span class="text-muted small ms-auto">' . number_format($total) . ' عنوان کتاب</span>' : '',
    ]) ?>



    <?php if (empty($books)): ?>
    <?= renderEmptyState('bi-book', $search ? 'کتابی با این مشخصات یافت نشد.' : 'هنوز کتابی در این بخش ثبت نشده است.', url('books'), 'مشاهده همه کتاب‌ها') ?>
    <?php else: ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($books as $b): ?><?= renderBookCard($b) ?><?php endforeach; ?>
    <?= jhd_grid_close() ?>
    <?php if ($pages > 1): ?>
    <nav class="mt-4" aria-label="صفحه‌بندی کتابخانه">
      <?= paginate($total, $limit, $page, url('books', ['q' => $search, 'page' => '%d'])) ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
