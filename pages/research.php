<?php
/**
 * research.php — پژوهش‌ها و دستاوردهای علمی مدرسه علمیه جامعه‌الهدی
 */
$pageTitle = 'پژوهش‌های علمی';
$pageDesc = 'پژوهش‌های علمی و دینی مدرسه جامعه‌الهدی — مطالب تحقیقی با منابع، چکیده و موضوعات مرتبط.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$opts = ['type' => 'research', 'limit' => $limit, 'offset' => $offset];
if ($search) $opts['search'] = $search;

$posts = getPosts($opts);
$total = countPosts(array_merge(['type' => 'research'], $search ? ['search' => $search] : []));
$pages = (int)ceil($total / $limit);
jhd_validate_pagination($page, $total, $limit, 'پژوهش‌ها', url('research'), 'پژوهش');
$noindexSeo = ($total === 0);


$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'پژوهش‌های علمی', 'url' => url('research')]
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <?php foreach ($breadcrumbs as $i => $bc): $isLast = ($i === count($breadcrumbs) - 1); ?>
        <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>>
          <?php if (!$isLast): ?><a href="<?= sanitize($bc['url']) ?>"><?= sanitize($bc['name']) ?></a><?php else: ?><?= sanitize($bc['name']) ?><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </nav>
  </div>
</div>

<div class="jhd-section">
  <div class="container">
    <!-- Header -->
    <?= jhd_page_head([
        'eyebrow' => 'تحقیقات تخصصی و مستند',
        'icon' => 'bi-journal-richtext',
        'title' => 'پژوهش‌های علمی',
        'lead' => 'پژوهش‌ها و بررسی‌های علمی در حوزه معارف اسلامی',
        'actions' => $total > 0 ? '<span class="jhd-chip"><i class="bi bi-journal-check"></i>' . number_format($total) . ' عنوان پژوهش</span>' : '',
    ]) ?>

    <!-- Search Form -->
    <form method="get" class="mb-4" role="search">
    <?= queryKeepFields() ?>
      <div class="input-group" style="max-width:480px">
        <input type="search" name="q" class="form-control" aria-label="جستجو در پژوهش‌ها"
               placeholder="جستجو در عنوان یا متن پژوهش‌ها..."
               value="<?= sanitize($search) ?>">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-search ms-1"></i>جستجو
        </button>
        <?php if ($search): ?>
        <a href="<?= url('research') ?>" class="btn btn-outline-secondary" title="حذف فیلتر">
          <i class="bi bi-x-lg"></i>
        </a>
        <?php endif; ?>
      </div>
    </form>

    <?php if (empty($posts)): ?>
    <?= renderEmptyState('bi-journal-richtext', $search ? 'پژوهشی مطابق با عبارت جستجو یافت نشد.' : 'هنوز پژوهشی در این بخش منتشر نشده است.', url('research'), 'مشاهده همه پژوهش‌ها') ?>
    <?php else: ?>
    <?= renderCategoryChips(['research'], url('research'), 'همه پژوهش‌ها') ?>
    <?php jhd_preload_post_topics($posts); ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($posts as $k => $p):
        echo renderEditorialRow($p, ['index' => ($page - 1) * $limit + $k + 1, 'cta' => 'مشاهده پژوهش', 'excerpt' => 120]);
      endforeach; ?>
    <?= jhd_grid_close() ?>

    <!-- صفحه‌بندی -->
    <?php if ($pages > 1): ?>
    <div class="mt-5">
      <?= paginate($total, $limit, $page, url('research', ['q' => $search, 'page' => '%d'])) ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
