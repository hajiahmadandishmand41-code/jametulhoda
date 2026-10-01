<?php
/**
 * media.php — صفحه جزئیات یک رسانه (ویدیو یا صوت)
 * Routeها: /video/{id} و /audio/{id} — شناسه رکورد media_files.
 * - kind باید با رکورد مطابق باشد، وگرنه 404
 * - والد (مطلب/درس) باید منتشرشده باشد، وگرنه 404
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/auth.php';
startSecureSession();

$kind = $_GET['kind'] ?? '';
if (!is_string($kind)) $kind = '';
$id = (int)($_GET['id'] ?? 0);

$db = jhd_db();
if ($db === null) {
    jhd_render_content_unavailable('رسانه', url($kind === 'audio' ? 'audios' : 'videos'), 'بازگشت به رسانه‌ها');
}
$media = getMediaById($id, $kind);
if (!$media) {
    http_response_code(404);
    $pageTitle = 'رسانه یافت نشد';
    $pageDesc = 'رسانه مورد نظر یافت نشد';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-5 text-center"><h1>رسانه یافت نشد</h1><p class="text-muted">ممکن است حذف شده یا آدرس نادرست باشد.</p><a href="' . siteUrl($kind === 'audio' ? 'audios' : 'videos') . '" class="btn btn-primary mt-3">بازگشت</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Published parent (post or lesson) — draft parents must not leak media.
$parent = null;
$parentTopics = [];
if (($media['ref_type'] ?? '') === 'post') {
    $stmt = $db->prepare('SELECT id, title, slug, post_type, featured_image, published_at FROM posts WHERE id=? AND status=? LIMIT 1');
    $stmt->execute([(int)$media['ref_id'], 'published']);
    $parent = $stmt->fetch();
    if ($parent) {
        $parentTopics = getTopicsForPost((int)$parent['id']);
        $parentUrl = postUrl($parent);
    }
} elseif (($media['ref_type'] ?? '') === 'lesson') {
    $stmt = $db->prepare('SELECT id, title, slug, teacher, featured_image, created_at AS published_at FROM lessons WHERE id=? AND status=? LIMIT 1');
    $stmt->execute([(int)$media['ref_id'], 'published']);
    $parent = $stmt->fetch();
    if ($parent) {
        $parentTopics = getTopicsForLesson((int)$parent['id']);
        $parentUrl = lessonUrl($parent);
    }
}
if (!$parent) {
    http_response_code(404);
    $pageTitle = 'رسانه یافت نشد';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-5 text-center"><h1>رسانه در دسترس نیست</h1><a href="' . siteUrl($kind === 'audio' ? 'audios' : 'videos') . '" class="btn btn-primary mt-3">بازگشت</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$isAudio = $kind === 'audio';
$mediaTitle = $media['title'] ?: $parent['title'];
$pageTitle = $mediaTitle;
$pageDesc = excerpt(($isAudio ? 'صوت: ' : 'ویدیو: ') . $parent['title'], 160);
$canonicalOverride = mediaUrl($kind, (int)$media['id']);
$fileUrl = siteUrl(ltrim($media['file_path'], '/'));
$poster = !empty($parent['featured_image']) ? imgUrl($parent['featured_image']) : '';

// Related: same parent first, then latest of the same kind.
$related = [];
try {
    $stmt = $db->prepare('SELECT m.*, ' . ($media['ref_type'] === 'post' ? "p.title AS parent_title, p.slug AS parent_slug, p.post_type AS parent_type" : "l.title AS parent_title, l.slug AS parent_slug, 'lesson' AS parent_type")
        . ' FROM media_files m '
        . ($media['ref_type'] === 'post' ? 'JOIN posts p ON p.id=m.ref_id AND m.ref_type=?' : 'JOIN lessons l ON l.id=m.ref_id AND m.ref_type=?')
        . ' WHERE m.kind=? AND m.ref_id=? AND m.id!=? ORDER BY m.sort_order ASC, m.id ASC LIMIT 6');
    $stmt->execute([$media['ref_type'], $kind, (int)$media['ref_id'], $id]);
    $related = $stmt->fetchAll();
    if (count($related) < 6) {
        $stmt = $db->prepare('SELECT m.*, ' . ($media['ref_type'] === 'post' ? "p.title AS parent_title, p.slug AS parent_slug, p.post_type AS parent_type" : "l.title AS parent_title, l.slug AS parent_slug, 'lesson' AS parent_type")
            . ' FROM media_files m '
            . ($media['ref_type'] === 'post' ? 'JOIN posts p ON p.id=m.ref_id AND m.ref_type=? AND p.status=?' : 'JOIN lessons l ON l.id=m.ref_id AND m.ref_type=? AND l.status=?')
            . ' WHERE m.kind=? AND m.id!=? ORDER BY m.id DESC LIMIT 6');
        $stmt->execute([$media['ref_type'], 'published', $kind, $id]);
        $ids = array_column($related, 'id');
        foreach ($stmt->fetchAll() as $row) {
            if (!in_array($row['id'], $ids, true)) $related[] = $row;
            if (count($related) >= 6) break;
        }
    }
} catch (PDOException $e) { $related = []; }

$listUrl = siteUrl($isAudio ? 'audios' : 'videos');
$listLabel = $isAudio ? 'صوت‌ها' : 'ویدیوها';
$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => SITE_URL ? rtrim(SITE_URL, '/') . '/' : siteUrl()],
    ['name' => $listLabel, 'url' => $listUrl],
    ['name' => $mediaTitle, 'url' => canonicalUrl(mediaUrl($kind, (int)$media['id']))],
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);
$mediaSchema = [
    '@context' => 'https://schema.org',
    '@type' => $isAudio ? 'AudioObject' : 'VideoObject',
    'name' => $mediaTitle,
    'description' => $pageDesc,
    'contentUrl' => $fileUrl,
    'uploadDate' => $media['created_at'] ?? null,
    'inLanguage' => 'fa',
];
if ($isAudio) {
    $mediaSchema['associatedMedia'] = ['@type' => 'CreativeWork', 'name' => $parent['title']];
} else {
    $mediaSchema['embedUrl'] = canonicalUrl(mediaUrl($kind, (int)$media['id']));
    if ($poster !== '') $mediaSchema['thumbnailUrl'] = $poster;
}
$mediaJsonLd = json_encode($mediaSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar"><div class="container"><nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
<li class="breadcrumb-item"><a href="<?= siteUrl() ?>">صفحه اصلی</a></li>
<li class="breadcrumb-item"><a href="<?= $listUrl ?>"><?= $listLabel ?></a></li>
<li class="breadcrumb-item active" aria-current="page"><?= sanitize(mb_strimwidth($mediaTitle, 0, 60, '...')) ?></li>
</ol></nav></div></div>

<div class="jhd-section"><div class="container"><div class="row g-4">
<div class="col-lg-8">
<article class="jhd-media-detail">
<div class="jhd-cat-strip">
<span class="jhd-chip <?= $isAudio ? '' : 'jhd-chip--gold' ?>"><i class="bi bi-<?= $isAudio ? 'headphones' : 'camera-video' ?>"></i><?= $isAudio ? 'صوت' : 'ویدیو' ?></span>
<?php if ($parentTopics): foreach (array_slice($parentTopics, 0, 3) as $t): ?><a href="<?= topicUrl($t) ?>" class="jhd-chip"><?= sanitize($t['name']) ?></a><?php endforeach; endif; ?>
</div>
<h1 class="jhd-detail-title"><?= sanitize($mediaTitle) ?></h1>
<div class="jhd-meta-bar"><span><i class="bi bi-calendar3"></i><?= persianDate($parent['published_at'] ?? $media['created_at']) ?></span><?php if (!empty($parent['teacher'])): ?><span><i class="bi bi-person"></i><?= sanitize($parent['teacher']) ?></span><?php endif; ?></div>

<div class="jhd-player">
<?php if ($isAudio): ?>
<audio controls preload="metadata" class="w-100" aria-label="<?= sanitize($mediaTitle) ?>"><source src="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>" type="audio/mpeg">مرورگر شما از پخش صوت پشتیبانی نمی‌کند.</audio>
<?php else: ?>
<video controls playsinline preload="metadata" class="w-100" style="border-radius:12px;background:#000;max-height:520px" <?= $poster ? 'poster="' . htmlspecialchars($poster, ENT_QUOTES) . '"' : '' ?> aria-label="<?= sanitize($mediaTitle) ?>"><source src="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>" type="video/mp4">مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.</video>
<?php endif; ?>
<div class="jhd-dl-strip mb-0"><i class="bi bi-download" aria-hidden="true"></i><span>این فایل برای دریافت مستقیم نیز در دسترس است.</span><a class="btn-read-more" href="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>" download>دانلود فایل <i class="bi bi-arrow-left"></i></a><a class="btn-read-more" href="<?= $parentUrl ?>"><?= $media['ref_type'] === 'post' ? 'مطلب کامل' : 'درس کامل' ?> <i class="bi bi-arrow-left"></i></a></div>
</div>

<div class="jhd-side-card mt-3">
<h3><i class="bi bi-link-45deg" aria-hidden="true"></i><?= $media['ref_type'] === 'post' ? 'مطلب مرتبط' : 'درس مرتبط' ?></h3>
<div class="d-flex gap-2 align-items-center">
<?php if ($poster): ?><img src="<?= htmlspecialchars($poster, ENT_QUOTES) ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px" loading="lazy"><?php endif; ?>
<a href="<?= $parentUrl ?>" class="fw-bold small"><?= sanitize($parent['title']) ?></a>
</div>
</div>
</article>
</div>
<div class="col-lg-4">
<div class="d-grid gap-3">
<div class="jhd-side-card"><h3><i class="bi bi-collection-play" aria-hidden="true"></i><?= $isAudio ? 'صوت‌های مرتبط' : 'ویدیوهای مرتبط' ?></h3>
<?php if (empty($related)): ?><p class="text-muted small mb-0">رسانه مرتبط دیگری موجود نیست.</p><?php else: ?>
<div class="related-posts"><?php foreach ($related as $rel): ?><div class="related-item">
<div class="related-info"><a href="<?= mediaUrl($kind, (int)$rel['id']) ?>" class="related-title"><?= sanitize(mb_strimwidth($rel['title'] ?: ($rel['parent_title'] ?? 'رسانه'), 0, 55, '...')) ?></a><span class="related-date"><?= sanitize(mb_strimwidth($rel['parent_title'] ?? '', 0, 40, '...')) ?></span></div>
</div><?php endforeach; ?></div>
<?php endif; ?>
</div>
<div class="jhd-side-card"><h3><i class="bi bi-tags" aria-hidden="true"></i>موضوعات</h3><div class="jhd-cat-strip"><?php foreach (array_slice($parentTopics ?: getTopics(['active' => 1, 'limit' => 12]), 0, 12) as $ct): ?><a href="<?= topicUrl($ct) ?>" class="jhd-chip"><?= sanitize($ct['name']) ?></a><?php endforeach; ?></div></div>
</div>
</div>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
