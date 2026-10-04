<?php
/**
 * media-library.php — نگارخانه و کتابخانه چندرسانه‌ای (ویدیوها و صوت‌ها)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$kind = ($_GET['kind'] ?? 'video') === 'audio' ? 'audio' : 'video';
$pageTitle = $kind === 'audio' ? 'کتابخانه صوتی و سخنرانی‌ها' : 'نگارخانه ویدیویی';
$pageDesc = $kind === 'audio' ? 'سخنرانی‌ها، صوت جلسات علمی، ادعیه و زیارات مدرسه علمیه جامعه‌الهدی' : 'ویدیوها، نشست‌های تخصصی و کلیپ‌های تصویری مدرسه علمیه جامعه‌الهدی';
$mediaPath = current_path();
if (in_array($mediaPath, ['/audio', '/audios'], true) || ($mediaPath === '/media' && isset($_GET['kind']) && $kind === 'audio')) {
    $canonicalOverride = url('audios');
} elseif (in_array($mediaPath, ['/video', '/videos'], true) || ($mediaPath === '/media' && isset($_GET['kind']) && $kind === 'video')) {
    $canonicalOverride = url('videos');
} else {
    $canonicalOverride = url('media');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;
$column = $kind === 'audio' ? 'audio_file' : 'video_file';

$sql = "SELECT m.id AS media_id, m.file_path AS path, p.title, p.slug, p.post_type, p.featured_image, p.published_at AS date, 'post' AS target
 FROM media_files m JOIN posts p ON p.id=m.ref_id AND m.ref_type='post' WHERE m.kind=? AND p.status='published'
 UNION SELECT m.id, m.file_path, l.title, l.slug, 'lesson', l.featured_image, l.created_at, 'lesson'
 FROM media_files m JOIN lessons l ON l.id=m.ref_id AND m.ref_type='lesson' WHERE m.kind=? AND l.status='published'
 UNION SELECT (SELECT pm.id FROM media_files pm WHERE pm.ref_type='lesson' AND pm.ref_id=l.id AND pm.kind='$kind' AND pm.file_path=l.$column ORDER BY pm.id DESC LIMIT 1), l.$column, l.title, l.slug, 'lesson', l.featured_image, l.created_at, 'lesson' FROM lessons l WHERE l.status='published' AND l.$column IS NOT NULL AND l.$column<>''";

if ($kind === 'video') {
    $sql .= " UNION SELECT (SELECT pm.id FROM media_files pm WHERE pm.ref_type='post' AND pm.ref_id=p.id AND pm.kind='video' AND pm.file_path=p.featured_video ORDER BY pm.id DESC LIMIT 1), p.featured_video, p.title, p.slug, p.post_type, p.featured_image, p.published_at, 'post' FROM posts p WHERE p.status='published' AND p.featured_video IS NOT NULL AND p.featured_video<>''";
}

$db = jhd_db();
$total = 0;
$items = [];
if ($db !== null) {
    try {
        $countStmt = $db->prepare('SELECT COUNT(*) FROM (' . $sql . ') media');
        $countStmt->execute([$kind, $kind]);
        $total = (int)$countStmt->fetchColumn();

        $queryStmt = $db->prepare('SELECT * FROM (' . $sql . ') media ORDER BY date DESC LIMIT ? OFFSET ?');
        $queryStmt->execute([$kind, $kind, $limit, $offset]);
        $items = $queryStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('media library listing failed: ' . $e->getMessage());
        $total = 0; $items = [];
    }
}
$pages = (int)ceil($total / $limit);

$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'چندرسانه‌ای', 'url' => url('media')],
    ['name' => $kind === 'audio' ? 'صوت‌ها' : 'ویدیوها', 'url' => url('media', ['kind' => $kind])]
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <?php foreach ($breadcrumbs as $i => $bc): $isLast = ($i === count($breadcrumbs) - 1); ?>
      <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>>
        <?php if (!$isLast): ?><a href="<?= sanitize($bc['url']) ?>"><?= sanitize($bc['name']) ?></a><?php else: ?><?= sanitize($bc['name']) ?><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol></nav>
  </div>
</div>

<div class="jhd-section">
  <div class="container">
    <?= jhd_page_head([
        'eyebrow' => 'نگارخانه صوتی و تصویری',
        'icon' => $kind === 'audio' ? 'bi-headphones' : 'bi-play-circle',
        'title' => $kind === 'audio' ? 'کتابخانه صوتی و سخنرانی‌ها' : 'نگارخانه ویدیویی',
        'lead' => $kind === 'audio' ? 'صوت جلسه‌های علمی، سخنرانی‌ها، ادعیه و زیارات.' : 'ویدیوها، نشست‌های تخصصی و کلیپ‌های تصویری مدرسه.',
    ]) ?>

    <?= jhd_listing_toolbar([
        'chips' => '<a class="jhd-chip ' . ($kind === 'video' ? 'jhd-chip--all' : '') . '" href="' . sanitize(url('videos')) . '"><i class="bi bi-camera-video"></i> ویدیو</a>'
            . '<a class="jhd-chip ' . ($kind === 'audio' ? 'jhd-chip--all' : '') . '" href="' . sanitize(url('audios')) . '"><i class="bi bi-headphones"></i> صوت</a>',
        'extra' => $total > 0 ? '<span class="text-muted small ms-auto">' . number_format($total) . ' فایل رسانه‌ای</span>' : '',
    ]) ?>

    <?php if (empty($items)): ?>
    <?= renderEmptyState($kind === 'audio' ? 'bi-headphones' : 'bi-camera-video', 'هنوز فایل رسانه‌ای در این بخش منتشر نشده است.', url(), 'بازگشت به صفحه اصلی') ?>
    <?php else: ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($items as $i => $item):
          $itemUrl = $item['target'] === 'lesson' ? lessonUrl($item['slug']) : postUrl($item['slug']);
          // همان کارت رسانه‌ای سراسری؛ برای صوت، پخش‌کننده داخل بدنهٔ کارت می‌آید
          // تا شنیدن بدون ترک صفحه ممکن باشد و طراحی هم یکسان بماند.
          $player = $kind === 'audio'
              ? '<div class="jhd-player jhd-player--card"><audio controls preload="none" src="' . sanitize(imgUrl((string)$item['path'])) . '" aria-label="' . sanitize((string)$item['title']) . '"></audio></div>'
              : '';
          echo renderMediaCard([
              'kind'         => $kind,
              'title'        => (string)$item['title'],
              'thumbnail'    => (string)($item['featured_image'] ?? ''),
              'published_at' => (string)($item['date'] ?? ''),
              'body_extra'   => $player,
          ], ['url' => $itemUrl, 'eager' => $i < 3, 'cta' => $kind === 'audio' ? 'صفحهٔ صوت' : 'پخش و دریافت']);
      endforeach; ?>
    <?= jhd_grid_close() ?>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
    <nav class="mt-4" aria-label="صفحه‌بندی رسانه"><?= paginate($total, $limit, $page, url('media', ['kind' => $kind, 'page' => '%d'])) ?></nav>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
