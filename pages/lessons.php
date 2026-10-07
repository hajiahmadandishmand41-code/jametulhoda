<?php
/**
 * lessons.php — فهرست دروس با ساختار مجموعه → جلد → درس
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$collectionSlug = trim($_GET['collection'] ?? '');
$volumeSlug = trim($_GET['volume'] ?? '');
$search = trim($_GET['q'] ?? '');
$level = trim($_GET['level'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$activeCollection = $collectionSlug ? getLessonCollectionBySlug($collectionSlug) : null;
$activeVolume = null;
if ($activeCollection && $volumeSlug) {
    foreach (getLessonVolumes((int)$activeCollection['id']) as $v) {
        if ($v['slug'] === $volumeSlug) { $activeVolume = $v; break; }
    }
}

if (($collectionSlug !== '' && !$activeCollection) || ($activeCollection && $volumeSlug !== '' && !$activeVolume)) {
    http_response_code(404);
    $pageTitle = 'مجموعه یافت نشد';
    $pageDesc = 'مجموعه یا بخش درسی مورد نظر یافت نشد';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-5 text-center"><h1 class="h3 fw-bold">مجموعه یا بخش درسی یافت نشد</h1><p class="text-muted">ممکن است حذف شده یا نشانی نادرست باشد.</p><a href="' . url('lessons') . '" class="btn btn-primary mt-3">مشاهده همه دروس</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$lessonWhere = [];
$lessonParams = [];
if ($activeCollection) {
    $lessonWhere = ["l.status = 'published'", "l.collection_id = ?"];
    $lessonParams = [(int)$activeCollection['id']];
    if ($activeVolume) {
        $lessonWhere[] = "l.volume_id = ?";
        $lessonParams[] = (int)$activeVolume['id'];
    }
    if ($search) {
        $lessonWhere[] = "(l.title ILIKE ? OR l.summary ILIKE ?)";
        $s = "%$search%";
        $lessonParams[] = $s;
        $lessonParams[] = $s;
    }
    $whereStr = implode(' AND ', $lessonWhere);
    try {
        $cntStmt = getDB()->prepare("SELECT COUNT(*) FROM lessons l WHERE $whereStr");
        $cntStmt->execute($lessonParams);
        $total = (int)$cntStmt->fetchColumn();
    } catch (Throwable $e) {
        $total = 0;
    }
    $pages = max(1, (int)ceil($total / $limit));
    jhd_validate_pagination($page, $total, $limit, 'دروس حوزوی', collectionUrl($activeCollection, $activeVolume ?: null), 'بازگشت به این مجموعه');
    $noindexSeo = ($total === 0);
}

$pageTitle = $activeCollection ? $activeCollection['title'] : 'دروس حوزوی';
if (!$activeCollection) {
    $metaTitleOverride = 'دروس حوزوی | آموزش علوم اسلامی';
}
$pageDesc = $activeCollection && $activeCollection['description'] ? excerpt($activeCollection['description'], 160) : 'مجموعه دروس حوزوی به‌صورت درس‌به‌درس با جلسات صوتی و موضوعات مرتبط.';
if ($activeCollection) {
    $canonicalOverride = collectionUrl($activeCollection, $activeVolume ?: null);
}

// Breadcrumbs
$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'دروس حوزوی', 'url' => url('lessons')],
];
if ($activeCollection) {
    $breadcrumbs[] = ['name' => $activeCollection['title'], 'url' => collectionUrl($activeCollection)];
    if ($activeVolume) {
        $breadcrumbs[] = ['name' => $activeVolume['title'], 'url' => collectionUrl($activeCollection, $activeVolume)];
    }
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
    <!-- Header -->
    <?php
    if ($activeVolume) {
        $lessonsHeadTitle = $activeVolume['title'] . ' — ' . $activeCollection['title'];
    } elseif ($activeCollection) {
        $lessonsHeadTitle = $activeCollection['title'];
    } else {
        $lessonsHeadTitle = 'دروس و دوره‌های حوزوی';
    }
    $lessonsHeadLead = ($activeCollection && !empty($activeCollection['description']))
        ? $activeCollection['description']
        : 'دروس سطح مقدمات، سطوح عالی و خارج در رشته‌های فقه، اصول، کلام، منطق و عقاید';
    ?>
    <?= jhd_page_head([
        'eyebrow' => 'مدرسه علمیه و آموزش مجازی معارف',
        'icon' => 'bi-mortarboard-fill',
        'title' => $lessonsHeadTitle,
        'lead' => $lessonsHeadLead,
    ]) ?>

    <?php if (!$activeCollection): ?>
    <!-- ۱. فهرست مجموعه‌های درسی -->
    <?php $collections = getLessonCollections(['active' => 1]); if (!empty($collections)): ?>
    <div class="mb-5">
      <h2 class="h5 fw-bold mb-3"><i class="bi bi-journals ms-1 text-gold"></i> دوره‌ها و مجموعه‌های درسی</h2>
      <div class="row g-3">
        <?php foreach ($collections as $col):
            $vols = getLessonVolumes((int)$col['id']);
            $cnt = count(getLessonsByCollection((int)$col['id']));
            $colUrl = collectionUrl($col);
        ?>
        <div class="col-md-6 col-lg-4">
          <article class="jhd-card">
            <?php if (!empty($col['cover_image'])): ?>
            <a class="jhd-card-media" href="<?= $colUrl ?>" tabindex="-1" aria-label="<?= sanitize($col['title']) ?>"><img src="<?= imgUrl($col['cover_image']) ?>" alt="" loading="lazy"></a>
            <?php endif; ?>
            <div class="jhd-card-body">
              <h3 class="jhd-card-title"><a href="<?= $colUrl ?>"><?= sanitize($col['title']) ?></a></h3>
              <?php if (!empty($col['description'])): ?><p class="jhd-card-summary"><?= sanitize(excerpt($col['description'], 110)) ?></p><?php endif; ?>
              <?php if (!empty($vols)): ?>
              <div class="jhd-card-meta jhd-cat-strip mb-0">
                <?php foreach ($vols as $v): ?><a href="<?= collectionUrl($col, $v) ?>" class="jhd-chip"><?= sanitize($v['title']) ?></a><?php endforeach; ?>
              </div>
              <?php endif; ?>
              <div class="jhd-card-foot">
                <span class="jhd-card-author"><i class="bi bi-collection-play"></i><?= number_format($cnt) ?> درس</span>
                <a class="btn-read-more" href="<?= $colUrl ?>">مشاهده دوره <i class="bi bi-arrow-left"></i></a>
              </div>
            </div>
          </article>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (empty($lessons)): ?>
    <?= renderEmptyState('bi-mortarboard', 'درسی مطابق با مشخصات واردشده یافت نشد.', url('lessons'), 'همه دروس') ?>
    <?php else: ?>
    <?php $lessonGroups = []; foreach ($lessons as $ls) { $lessonGroups[(string)($ls['collection_title'] ?? 'دروس متفرقه')][] = $ls; } ?>
    <?php foreach ($lessonGroups as $groupName => $groupLessons): ?>
    <div class="jhd-lesson-group">
      <div class="jhd-lesson-group-head"><i class="bi bi-collection"></i> <?= sanitize($groupName) ?></div>
      <div class="jhd-lesson-group-body">
        <?= jhd_grid_open() ?><?php foreach ($groupLessons as $ls): ?><?= renderLessonRow($ls) ?><?php endforeach; ?><?= jhd_grid_close() ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
    <div class="mt-5">
      <?= paginate($total, $limit, $page, url('lessons', ['q' => $search, 'level' => $level, 'page' => '%d'])) ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php else: ?>
    <!-- ۳. نمایش یک دوره یا مجموعه خاص -->
    <?php
    $volumes = getLessonVolumes((int)$activeCollection['id']);
    if (!empty($volumes) && !$activeVolume):
    ?>
    <div class="mb-5">
      <h2 class="h5 fw-bold mb-3">بخش‌ها و جلدهای این دوره</h2>
      <div class="row g-3">
        <?php foreach ($volumes as $vol):
            $vcount = count(getLessonsByCollection((int)$activeCollection['id'], (int)$vol['id']));
        ?>
        <div class="col-md-6 col-lg-4">
          <article class="jhd-card">
            <div class="jhd-card-body">
              <h3 class="jhd-card-title"><a href="<?= collectionUrl($activeCollection, $vol) ?>"><?= sanitize($vol['title']) ?></a></h3>
              <?php if (!empty($vol['description'])): ?><p class="jhd-card-summary"><?= sanitize(excerpt($vol['description'], 90)) ?></p><?php endif; ?>
              <div class="jhd-card-foot">
                <span class="jhd-card-author"><i class="bi bi-hash"></i><?= number_format($vcount) ?> جلسه</span>
                <a class="btn-read-more" href="<?= collectionUrl($activeCollection, $vol) ?>">مشاهده جلسات <i class="bi bi-arrow-left"></i></a>
              </div>
            </div>
          </article>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
    $whereStr = implode(' AND ', $lessonWhere);
    try {
        $stmt = getDB()->prepare("SELECT l.* FROM lessons l WHERE $whereStr ORDER BY COALESCE(l.lesson_number, 9999) ASC, l.sort_order ASC, l.id ASC LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($lessonParams, [$limit, $offset]));
        $lessons = $stmt->fetchAll();
    } catch (Throwable $e) {
        $lessons = [];
    }
    ?>

    <?php if (empty($lessons)): ?>
    <?php echo renderEmptyState('bi-mortarboard', 'درسی در این بخش یافت نشد.'); ?>
    <?php else: ?>
    <?php $lessonGroups = []; foreach ($lessons as $ls) { $lessonGroups[(string)($ls['collection_title'] ?? 'دروس متفرقه')][] = $ls; } ?>
    <?php foreach ($lessonGroups as $groupName => $groupLessons): ?>
    <div class="jhd-lesson-group">
      <div class="jhd-lesson-group-head"><i class="bi bi-collection"></i> <?= sanitize($groupName) ?></div>
      <div class="jhd-lesson-group-body">
        <?= jhd_grid_open() ?><?php foreach ($groupLessons as $ls): ?><?= renderLessonRow($ls) ?><?php endforeach; ?><?= jhd_grid_close() ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
    <div class="mt-4">
      <?= paginate($total, $limit, $page, collectionUrl($activeCollection, $activeVolume ?: null) . '&page=%d') ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="mt-4">
      <a href="<?= url('lessons') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-right ms-1"></i> بازگشت به همه مجموعه‌ها
      </a>
    </div>

    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
