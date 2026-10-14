<?php
/**
 * reports.php — آرشیو گزارش‌های تصویری، میدانی و فعالیت‌های حوزه علمیه جامعه‌الهدی
 */
$pageTitle = 'گزارش‌ها و رخدادها';
$pageDesc = 'گزارش فعالیت‌های علمی، فرهنگی، جلسات، محافل، مراسم و برنامه‌های مذهبی مدرسه جامعه‌الهدی.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$opts = ['type' => 'report', 'limit' => $limit, 'offset' => $offset];
if ($search) $opts['search'] = $search;

$posts = getPosts($opts);
$total = countPosts(['type' => 'report'] + ($search ? ['search' => $search] : []));

$pages = (int)ceil($total / $limit);
jhd_validate_pagination($page, $total, $limit, 'گزارش‌ها', url('reports'), 'گزارش‌ها');
$noindexSeo = ($total === 0);


$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'گزارش‌ها', 'url' => url('reports')]
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
    <!-- Page Header -->
    <?= jhd_page_head([
        'eyebrow' => 'پوشش میدانی و رخدادهای حوزه',
        'icon' => 'bi-card-text',
        'title' => 'گزارش‌ها',
        'lead' => 'گزارش‌های میدانی، برنامه‌ها و رخدادهای مدرسه',
        'actions' => $total > 0 ? '<span class="jhd-chip"><i class="bi bi-images"></i>' . number_format($total) . ' گزارش</span>' : '',
    ]) ?>

    <!-- Search Form -->
    <form method="get" class="mb-4" role="search">
    <?= queryKeepFields() ?>
      <div class="row g-2 align-items-center">
        <div class="col-md-6 col-lg-5">
          <div class="input-group">
            <input type="search" name="q" class="form-control" aria-label="جستجو در گزارش‌ها"
                   placeholder="جستجو در گزارش‌ها..."
                   value="<?= sanitize($search) ?>">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-search ms-1"></i>جستجو
            </button>
            <?php if ($search): ?>
            <a href="<?= url('reports') ?>" class="btn btn-outline-secondary" title="حذف فیلترها">
              <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($search): ?>
      <div class="mt-2 text-muted small">
        فیلتر فعال:
        «<strong><?= sanitize($search) ?></strong>»
        — <?= number_format($total) ?> مورد
      </div>
      <?php endif; ?>
    </form>

    <?php if (empty($posts)): ?>
    <div class="jhd-empty-state">
      <h4>
        <?= $search ? 'گزارشی با این مشخصات یافت نشد' : 'هنوز گزارشی در این بخش درج نشده است' ?>
      </h4>
      <a href="<?= url('reports') ?>" class="btn btn-outline-primary btn-sm mt-2">
        <i class="bi bi-arrow-right ms-1"></i>مشاهده همه گزارش‌ها
      </a>
    </div>
    <?php else: ?>
    <?= renderCategoryChips(['report'], url('reports'), 'همه گزارش‌ها') ?>
    <?php jhd_preload_post_topics($posts); ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($posts as $k => $p):
        echo renderPostCard($p, ['featured' => $k === 0 && empty($search), 'cta' => 'مشاهده گزارش', 'excerpt' => 110]);
      endforeach; ?>
    </div>

    <!-- صفحه‌بندی -->
    <?php if ($pages > 1): ?>
    <div class="mt-5">
      <?= paginate($total, $limit, $page, url('reports', ['q' => $search, 'page' => '%d'])) ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
