<?php
/**
 * events.php — رویدادها، برنامه‌های آموزشی و فعالیت‌های مذهبی جامعه‌الهدی
 */
$pageTitle = 'رویدادها و برنامه‌ها';
$pageDesc = 'رویدادها، مناسبت‌های مذهبی، نشست‌های علمی و دوره‌های آموزشی مدرسه جامعه‌الهدی';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');
$type   = trim($_GET['type'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 12;
$offset = ($page - 1) * $limit;

$db = jhd_db();
$where = ["p.status = 'published'"];
$params = [];

if ($type && in_array($type, ['program', 'religious', 'announcement'], true)) {
    $where[] = "p.post_type = ?";
    $params[] = $type;
} else {
    $where[] = "p.post_type IN ('program', 'religious', 'announcement')";
}

if ($search) {
    $where[] = "(p.title ILIKE ? OR p.summary ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = implode(' AND ', $where);

$total = 0;
$events = [];
if ($db !== null) {
    try {
        // Count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM posts p WHERE $whereClause");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch
        $queryStmt = $db->prepare("SELECT p.*, c.name as category_name FROM posts p LEFT JOIN categories c ON c.id = p.category_id WHERE $whereClause ORDER BY p.published_at DESC LIMIT ? OFFSET ?");
        $params[] = $limit;
        $params[] = $offset;
        $queryStmt->execute($params);
        $events = $queryStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('events listing failed: ' . $e->getMessage());
        $total = 0; $events = [];
    }
}

$pages = (int)ceil($total / $limit);
jhd_validate_pagination($page, $total, $limit, 'رویدادها', url('events'), 'رویدادها');
$noindexSeo = ($total === 0);

$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'رویدادها و برنامه‌ها', 'url' => url('events')]
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= url() ?>">صفحه اصلی</a></li>
      <li class="breadcrumb-item active" aria-current="page">رویدادها و برنامه‌ها</li>
    </ol></nav>
  </div>
</div>

<div class="jhd-section">
  <div class="container">
    <?= jhd_page_head([
        'eyebrow' => 'تقویم حوزه و مناسبت‌ها',
        'icon' => 'bi-calendar-event',
        'title' => 'رویدادها و برنامه‌ها',
        'lead' => 'نشست‌های علمی، دوره‌های آموزشی، مناسبت‌های مذهبی و اطلاعیه‌های جاری مدرسه.',
    ]) ?>

    <?= jhd_listing_toolbar([
        'route' => 'events',
        'search_action' => formUrl('events'),
        'placeholder' => 'جستجو در رویدادها...',
        'value' => $search,
        'chips' => '<a class="jhd-chip ' . ($type === '' ? 'jhd-chip--all' : '') . '" href="' . sanitize(url('events')) . '">همه</a>'
            . '<a class="jhd-chip ' . ($type === 'program' ? 'jhd-chip--all' : '') . '" href="' . sanitize(url('programs')) . '">برنامه‌ها</a>'
            . '<a class="jhd-chip ' . ($type === 'religious' ? 'jhd-chip--all' : '') . '" href="' . sanitize(url('religious-activities')) . '">فعالیت‌های مذهبی</a>'
            . '<a class="jhd-chip ' . ($type === 'announcement' ? 'jhd-chip--all' : '') . '" href="' . sanitize(url('announcements')) . '">اطلاعیه‌ها</a>',
    ]) ?>

    <?php if (empty($events)): ?>
    <?= renderEmptyState('bi-calendar-event', 'رویدادی برای نمایش ثبت نشده است.', url(), 'بازگشت به صفحه اصلی') ?>
    <?php else: ?>
    <?php
      $nowTs = time();
      $upcoming = array_values(array_filter($events, static fn(array $e): bool => strtotime((string)($e['published_at'] ?? $e['created_at'] ?? '')) >= $nowTs));
      $archive  = array_values(array_filter($events, static fn(array $e): bool => strtotime((string)($e['published_at'] ?? $e['created_at'] ?? '')) < $nowTs));
    ?>
    <?php if ($upcoming): ?>
    <h2 class="h6 mt-2"><i class="bi bi-stars ms-1 text-gold"></i> رویدادهای پیشِ رو</h2>
    <?php jhd_preload_post_topics($events); ?>
    <?= jhd_grid_open() ?><?php foreach ($upcoming as $ev): ?><?= renderEventRow($ev) ?><?php endforeach; ?><?= jhd_grid_close() ?>
    <?php endif; ?>
    <?php if ($archive): ?>
    <h2 class="h6 mt-4"><i class="bi bi-archive ms-1 text-gold"></i> آرشیو رویدادها</h2>
    <?= jhd_grid_open() ?><?php foreach ($archive as $ev): ?><?= renderEventRow($ev) ?><?php endforeach; ?><?= jhd_grid_close() ?>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
    <nav class="mt-4" aria-label="صفحه‌بندی رویدادها">
      <?= paginate($total, $limit, $page, url('events', ['q' => $search, 'type' => $type, 'page' => '%d'])) ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
