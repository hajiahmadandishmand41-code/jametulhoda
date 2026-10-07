<?php
/**
 * articles.php — فهرست و آرشیو مقالات علمی و یادداشت‌های پژوهشی
 */
$pageTitle = 'مقالات علمی';
$metaTitleOverride = 'مقالات اسلامی و علمی | فقه، قرآن، مهدویت و معارف';
$pageDesc = 'مقالات و یادداشت‌های علمی درباره علوم اسلامی، مهدویت، فقه و اصول، قرآن، حدیث و معارف اسلامی از جامعه‌الهدی.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');
$topicSlug = trim($_GET['topic'] ?? '');
$topicId = null;
if ($topicSlug) {
    $t = getTopicBySlug($topicSlug);
    if ($t) $topicId = (int)$t['id'];
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$opts = ['type' => 'article', 'limit' => $limit, 'offset' => $offset];
if ($search) $opts['search'] = $search;
if ($topicId) $opts['topic'] = $topicId;

$posts = getPosts($opts);
$total = countPosts(['type' => 'article'] + ($search ? ['search' => $search] : []) + ($topicId ? ['topic' => $topicId] : []));
$pages = (int)ceil($total / $limit);
jhd_validate_pagination($page, $total, $limit, 'مقالات', url('articles'), 'مقالات');
$noindexSeo = ($total === 0);


$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'مقالات علمی', 'url' => url('articles')]
];
if ($topicId && !empty($t)) {
    $breadcrumbs[] = ['name' => $t['name'], 'url' => url('articles', ['topic' => $t['slug']])];
}
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
        'eyebrow' => 'اندیشه و پژوهش‌های دینی',
        'icon' => 'bi-file-earmark-richtext',
        'title' => 'مقالات علمی',
        'lead' => 'مقالات، یادداشت‌های علمی و پژوهش‌های اعضای مدرسه در معارف اسلامی',
        'actions' => $total > 0 ? '<span class="jhd-chip"><i class="bi bi-collection"></i>' . number_format($total) . ' مقاله</span>' : '',
    ]) ?>

    <!-- Search & Filter Form -->
    <form method="get" class="mb-4" role="search">
    <?= queryKeepFields() ?>
      <div class="row g-2 align-items-center">
        <div class="col-md-6 col-lg-5">
          <div class="input-group">
            <input type="search" name="q" class="form-control" aria-label="جستجو در عنوان یا متن مقالات"
                   placeholder="جستجو در عنوان یا متن مقالات..."
                   value="<?= sanitize($search) ?>">
            <?php if ($topicSlug): ?>
            <input type="hidden" name="topic" value="<?= sanitize($topicSlug) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-search ms-1"></i>جستجو
            </button>
            <?php if ($search || $topicSlug): ?>
            <a href="<?= url('articles') ?>" class="btn btn-outline-secondary" title="حذف فیلترها">
              <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($search || $topicSlug): ?>
      <div class="mt-2 text-muted small">
        فیلتر فعال:
        <?php if ($search): ?>«<strong><?= sanitize($search) ?></strong>»<?php endif; ?>
        <?php if ($topicSlug && !empty($t)): ?>در موضوع «<strong><?= sanitize($t['name']) ?></strong>»<?php endif; ?>
        — <?= number_format($total) ?> مورد یافت شد
      </div>
      <?php endif; ?>
    </form>

    <?php if (empty($posts)): ?>
    <?= renderEmptyState('bi-file-text', $search ? 'مقاله‌ای مطابق با جستجوی شما یافت نشد؛ عبارت دیگری را بیازمایید.' : 'هنوز مقاله‌ای در این بخش ثبت نشده است.', url('articles'), 'مشاهده همه مقالات') ?>
    <?php else: ?>
    <?= renderCategoryChips(['article'], url('articles'), 'همه مقالات') ?>
    <?php jhd_preload_post_topics($posts); ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($posts as $k => $p):
        echo renderEditorialRow($p, ['index' => ($page - 1) * $limit + $k + 1, 'cta' => 'مطالعه مقاله', 'excerpt' => 120]);
      endforeach; ?>
    <?= jhd_grid_close() ?>

    <!-- صفحه‌بندی -->
    <?php if ($pages > 1): ?>
    <div class="mt-5">
      <?= paginate($total, $limit, $page, url('articles', ['q' => $search, 'topic' => $topicSlug, 'page' => '%d'])) ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
