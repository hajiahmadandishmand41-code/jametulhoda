<?php
/**
 * articles.php — بخش یکپارچهٔ «مقالات و پژوهش‌ها»
 *
 * مقاله‌های عمومی و پژوهش‌های علمی در یک فهرست، با فیلتر نوع:
 *   /articles               ⇒ همه (article + research)
 *   /articles?type=article  ⇒ فقط مقاله‌ها
 *   /articles?type=research ⇒ فقط پژوهش‌ها
 * جزئیات هر مطلب همچنان در نشانی نوع خودش است (/articles/<slug> یا /research/<slug>).
 */
$typeLabels = [
    'all'      => 'همه',
    'article'  => 'مقاله',
    'research' => 'پژوهش علمی',
];
$typeTypes = [
    'all'      => ['article', 'research'],
    'article'  => ['article'],
    'research' => ['research'],
];
$requestedType = trim((string)($_GET['type'] ?? 'all'));
$typeKey = isset($typeTypes[$requestedType]) ? $requestedType : 'all';
$types = $typeTypes[$typeKey];

$pageTitle = $typeKey === 'all' ? 'مقالات و پژوهش‌ها' : $typeLabels[$typeKey];
$metaTitleOverride = $typeKey === 'all'
    ? 'مقالات و پژوهش‌های علمی | فقه، قرآن، مهدویت و معارف اسلامی'
    : $pageTitle . ' | مقالات و پژوهش‌های علمی';
$pageDesc = 'مقالات و پژوهش‌های علمی درباره علوم اسلامی، مهدویت، فقه و اصول، قرآن، حدیث و معارف اسلامی از جامعه‌الهدی.';
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

$base = ['types' => $types];
if ($search) $base['search'] = $search;
if ($topicId) $base['topic'] = $topicId;

$opts = $base + ['limit' => $limit, 'offset' => $offset];
$posts = getPosts($opts);
$total = countPosts($base);
$pages = (int)ceil($total / $limit);

// Query fields that every link in this listing carries (search, topic, type).
$keep = static function (array $extra = []) use ($search, $topicSlug, $typeKey): array {
    $q = array_filter(['q' => $search, 'topic' => $topicSlug, 'type' => $typeKey !== 'all' ? $typeKey : ''], static fn($v) => $v !== '');
    return $q + $extra;
};

jhd_validate_pagination($page, $total, $limit, 'مقالات', url('articles', $keep()), 'مقالات');
$noindexSeo = ($total === 0);

// Filtered views share the listing's content: point their canonical at the
// unfiltered (or single-type) listing so search engines index one version.
$canonicalOverride = $typeKey === 'all' ? url('articles') : url('articles', ['type' => $typeKey]);

$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'مقالات و پژوهش‌ها', 'url' => url('articles')]
];
if ($typeKey !== 'all') {
    $breadcrumbs[] = ['name' => $typeLabels[$typeKey], 'url' => url('articles', ['type' => $typeKey])];
}
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

<div class="jhd-section jhd-articles-hub">
  <div class="container">
    <!-- Page Header -->
    <?= jhd_page_head([
        'eyebrow' => 'اندیشه و پژوهش‌های دینی',
        'icon' => 'bi-file-earmark-richtext',
        'title' => 'مقالات و پژوهش‌ها',
        'lead' => 'مقالات، یادداشت‌های علمی و پژوهش‌های اعضای مدرسه در معارف اسلامی؛ همه در یک آرشیو.',
        'actions' => $total > 0 ? '<span class="jhd-chip"><i class="bi bi-collection"></i>' . number_format($total) . ' مورد</span>' : '',
    ]) ?>

    <!-- فیلتر نوع: یک بخش، دو نما -->
    <nav class="jhd-type-tabs" aria-label="نوع مطلب">
      <?php foreach ($typeLabels as $key => $label):
        $tabQuery = array_filter(['q' => $search, 'topic' => $topicSlug, 'type' => $key !== 'all' ? $key : ''], static fn($v) => $v !== '');
      ?>
      <a class="jhd-type-tab<?= $key === $typeKey ? ' is-active' : '' ?>"
         href="<?= sanitize(url('articles', $tabQuery)) ?>"
         <?= $key === $typeKey ? 'aria-current="page"' : '' ?>><?= sanitize($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <!-- Search & Filter Form -->
    <form method="get" class="mb-4" role="search">
    <?php if ($typeKey !== 'all'): ?><input type="hidden" name="type" value="<?= sanitize($typeKey) ?>"><?php endif; ?>
      <div class="row g-2 align-items-center">
        <div class="col-md-6 col-lg-5">
          <div class="input-group">
            <input type="search" name="q" class="form-control" aria-label="جستجو در عنوان یا متن مقالات و پژوهش‌ها"
                   placeholder="جستجو در عنوان یا متن مقالات و پژوهش‌ها..."
                   value="<?= sanitize($search) ?>">
            <?php if ($topicSlug): ?>
            <input type="hidden" name="topic" value="<?= sanitize($topicSlug) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-search ms-1"></i>جستجو
            </button>
            <?php if ($search || $topicSlug): ?>
            <a href="<?= sanitize(url('articles', $keep(['q' => null, 'topic' => null]))) ?>" class="btn btn-outline-secondary" title="حذف فیلترها">
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
    <?= renderEmptyState('bi-file-text', $search ? 'مطلبی مطابق با جستجوی شما یافت نشد؛ عبارت دیگری را بیازمایید.' : 'هنوز مطلبی در این بخش ثبت نشده است.', url('articles'), 'مشاهده همه مقالات و پژوهش‌ها') ?>
    <?php else: ?>
    <?php jhd_preload_post_topics($posts); ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($posts as $k => $p):
        echo renderEditorialRow($p, ['index' => ($page - 1) * $limit + $k + 1, 'cta' => $p['post_type'] === 'research' ? 'مطالعه پژوهش' : 'مطالعه مقاله', 'excerpt' => 120]);
      endforeach; ?>
    <?= jhd_grid_close() ?>

    <!-- صفحه‌بندی -->
    <?php if ($pages > 1): ?>
    <div class="mt-5">
      <?= paginate($total, $limit, $page, url('articles', $keep(['page' => '%d']))) ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
