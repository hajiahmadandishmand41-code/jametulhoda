<?php
/**
 * speech.php — صفحه اختصاصی هر سخنرانی با پلیر حرفه‌ای، Playlist و Prev/Next
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/media.php';
startPublicSession();

$slug = trim($_GET['slug'] ?? '');
$post = $slug ? getPostBySlug($slug) : null;

if (!$post || $post['post_type'] !== 'speech') {
    http_response_code(404);
    $pageTitle = 'سخنرانی یافت نشد';
    $pageDesc = 'سخنرانی درخواستی در آرشیو مدرسه مدرسه جامعه‌الهدی در دسترس نیست.';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-5 text-center"><h2>سخنرانی مورد نظر یافت نشد</h2><a href="' . siteUrl('speeches') . '" class="btn btn-primary mt-3">بازگشت به سخنرانی‌ها</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$db = jhd_db();
if ($db === null) {
    jhd_render_content_unavailable('سخنرانی', url('speeches'), 'بازگشت به سخنرانی‌ها');
}

$audioList = getMediaFor('post', (int)$post['id'], 'audio');
$videoList = getMediaFor('post', (int)$post['id'], 'video');

// سخنرانی‌های دیگر برای Playlist سراسری — فقط سخنرانی‌هایی که تیک "سخنرانی‌ها" دارن
$others = $db->prepare("SELECT id, title, slug, featured_image, published_at FROM posts WHERE post_type='speech' AND status='published' AND id != ? ORDER BY published_at DESC LIMIT 20");
$others->execute([$post['id']]);
$otherSpeeches = $others->fetchAll();

// قبلی / بعدی — فقط سخنرانی‌هایی که تیک "سخنرانی‌ها" دارن
$prev = $db->prepare("SELECT slug, title FROM posts WHERE post_type='speech' AND status='published' AND published_at < ? ORDER BY published_at DESC LIMIT 1");
$prev->execute([$post['published_at']]);
$prevRow = $prev->fetch();

$next = $db->prepare("SELECT slug, title FROM posts WHERE post_type='speech' AND status='published' AND published_at > ? ORDER BY published_at ASC LIMIT 1");
$next->execute([$post['published_at']]);
$nextRow = $next->fetch();

$pageTitle = $post['title'];
$pageDesc = !empty($post['summary']) ? excerpt($post['summary'], 160) : excerpt(strip_tags($post['content'] ?? ''), 160);
// One speech = one URL: /speech/<slug>. Anything else (/post/<slug>,
// /speeches/<slug>, ?p=…) forwards here.
jhd_redirect_to_canonical(speechUrl($post), jhd_route_path('speech', ['slug' => (string)$post['slug']]));
$canonicalOverride = speechUrl($post);

// Breadcrumb trail: صفحه اصلی › سخنرانی‌ها › این سخنرانی. The visible trail and
// the BreadcrumbList below are built from the same array, so they cannot drift.
$speechCrumbs = [
    ['name' => 'صفحه اصلی', 'url' => SITE_URL ? rtrim(SITE_URL, '/') . '/' : url()],
    ['name' => 'سخنرانی‌ها', 'url' => url('speeches')],
    ['name' => (string)$post['title'], 'url' => canonicalUrl(speechUrl($post))],
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($speechCrumbs);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar"><div class="container"><nav><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= siteUrl() ?>">صفحه اصلی</a></li>
    <li class="breadcrumb-item"><a href="<?= siteUrl('speeches') ?>">سخنرانی‌ها</a></li>
    <li class="breadcrumb-item active"><?= sanitize(mb_strimwidth($post['title'], 0, 50, '...')) ?></li>
</ol></nav></div></div>

<div class="jhd-section"><div class="container">
    <div class="row g-4">
        <div class="col-lg-8">
            <article class="single-post">
                <header class="single-post-header">
                    <span class="badge bg-info ms-1">سخنرانی</span>
                    <?php if ($post['cat_name']): ?><span class="badge bg-secondary ms-1"><?= sanitize($post['cat_name']) ?></span><?php endif; ?>
                    <h1 class="single-post-title mt-3"><?= sanitize($post['title']) ?></h1>
                    <div class="single-post-meta">
                        <?php if ($post['author_name']): ?><span><i class="bi bi-person-fill ms-1"></i><?= sanitize($post['author_name']) ?></span><?php endif; ?>
                        <span><i class="bi bi-calendar3 ms-1"></i><?= persianDate($post['published_at'] ?? $post['created_at']) ?></span>
                        
                    </div>
                </header>

                <?php if ($post['featured_image']): ?>
                <div class="single-post-img-wrap">
                    <img src="<?= imgUrl($post['featured_image']) ?>" alt="<?= sanitize($post['title']) ?>" class="single-post-img" loading="lazy" decoding="async">
                </div>
                <?php endif; ?>

                <?php if (!empty($videoList)): ?>
                <div class="media-player-wrap my-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0"><i class="bi bi-camera-video ms-2 text-gold"></i>ویدیوی سخنرانی</h5>
                        <span class="badge bg-dark" id="videoCounter">۱ / <?= count($videoList) ?></span>
                    </div>
                    <video id="mainVideo" controls preload="none" playsinline class="w-100 rounded-xl" poster="<?= $post['featured_image'] ? imgUrl($post['featured_image']) : '' ?>">
                        <source data-src="<?= siteUrl($videoList[0]['file_path']) ?>" src="" type="video/mp4">
                        مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                    </video>
                    <?php if (count($videoList) > 1): ?>
                    <div class="playlist mt-3" id="videoPlaylist" data-kind="video">
                        <?php foreach ($videoList as $i => $v): ?>
                        <button type="button" class="playlist-item <?= $i===0?'active':'' ?>"
                                data-src="<?= siteUrl($v['file_path']) ?>" data-index="<?= $i ?>">
                            <span class="pl-num"><?= $i+1 ?></span>
                            <span class="pl-title"><?= sanitize($v['title'] ?: ('ویدیو ' . ($i+1))) ?></span>
                            <i class="bi bi-play-circle-fill pl-icon"></i>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="videoPrevBtn"><i class="bi bi-skip-end-fill"></i> قبلی</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="videoNextBtn">بعدی <i class="bi bi-skip-start-fill"></i></button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($audioList)): ?>
                <div class="media-player-wrap my-4 p-4 bg-soft rounded-xl">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0"><i class="bi bi-mic-fill ms-2 text-gold"></i>فایل‌های صوتی</h5>
                        <span class="badge bg-dark" id="audioCounter">۱ / <?= count($audioList) ?></span>
                    </div>
                    <audio id="mainAudio" controls preload="none" class="w-100">
                        <source data-src="<?= siteUrl($audioList[0]['file_path']) ?>" src="" type="audio/mpeg">
                    </audio>
                    <div class="d-flex gap-2 mt-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="audioPrevBtn"><i class="bi bi-skip-end-fill"></i> قبلی</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="audioNextBtn">بعدی <i class="bi bi-skip-start-fill"></i></button>
                        <a href="<?= siteUrl($audioList[0]['file_path']) ?>" download id="audioDownload" class="btn btn-outline-success btn-sm"><i class="bi bi-download ms-1"></i>دانلود</a>
                    </div>
                    <?php if (count($audioList) > 1): ?>
                    <div class="playlist mt-3" id="audioPlaylist" data-kind="audio">
                        <?php foreach ($audioList as $i => $a): ?>
                        <button type="button" class="playlist-item <?= $i===0?'active':'' ?>"
                                data-src="<?= siteUrl($a['file_path']) ?>" data-index="<?= $i ?>">
                            <span class="pl-num"><?= $i+1 ?></span>
                            <span class="pl-title"><?= sanitize($a['title'] ?: ('صوت ' . ($i+1))) ?></span>
                            <i class="bi bi-play-circle-fill pl-icon"></i>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($post['summary']): ?>
                <div class="single-post-summary"><p><?= sanitize($post['summary']) ?></p></div>
                <?php endif; ?>

                <div class="single-post-content">
                    <?= safeRichText($post['content']) ?: '<p class="text-muted">متنی وارد نشده است.</p>' ?>
                </div>

                <!-- ─── دانلود فایل‌های رسانه‌ای ───────────────────────────────── -->
                <?php
                $allMedia = array_merge(
                    array_map(fn($v) => array_merge($v, ['kind' => 'video']), $videoList),
                    array_map(fn($a) => array_merge($a, ['kind' => 'audio']), $audioList)
                );
                if (!empty($allMedia)): ?>
                <div class="media-player-wrap mt-4 p-3">
                    <h6 class="fw-bold mb-3">
                        <i class="bi bi-cloud-arrow-down ms-2 text-gold"></i>دانلود فایل‌ها
                    </h6>
                    <ul class="speech-download-list">
                    <?php foreach ($allMedia as $m):
                        $filePath = siteUrl($m['file_path']);
                        $ext      = strtoupper(pathinfo($m['file_path'], PATHINFO_EXTENSION));
                        $label    = sanitize($m['title'] ?: ($m['kind'] === 'video' ? 'ویدیو' : 'صوت'));
                        $iconClass = $m['kind'] === 'video' ? 'bi bi-camera-video-fill text-primary' : 'bi bi-mic-fill text-success';
                        // اندازه فایل اگر در دسترس باشد
                        $bytes = (int)($m['size'] ?? 0);
                        $size = $bytes ? number_format($bytes / 1024 / 1024, 1) . ' MB' : '';
                    ?>
                        <li>
                            <i class="<?= $iconClass ?> file-icon"></i>
                            <span class="file-name"><?= $label ?> <small class="text-muted">(<?= $ext ?>)</small></span>
                            <?php if ($size): ?><span class="file-size"><?= $size ?></span><?php endif; ?>
                            <a href="<?= htmlspecialchars($filePath, ENT_QUOTES) ?>" download
                               class="dl-badge ms-auto">
                                <i class="bi bi-download"></i> دانلود
                            </a>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Prev / Next Navigation between speeches -->
                <div class="post-nav mt-5 d-flex justify-content-between gap-2 flex-wrap">
                    <?php if ($prevRow): ?>
                    <a href="<?= speechUrl($prevRow) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-right ms-1"></i> سخنرانی قبلی: <?= sanitize(mb_strimwidth($prevRow['title'],0,30,'...')) ?>
                    </a>
                    <?php else: ?><span></span><?php endif; ?>
                    <?php if ($nextRow): ?>
                    <a href="<?= speechUrl($nextRow) ?>" class="btn btn-outline-secondary">
                        سخنرانی بعدی: <?= sanitize(mb_strimwidth($nextRow['title'],0,30,'...')) ?> <i class="bi bi-arrow-left ms-1"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <aside class="col-lg-4">
            <div class="sidebar">
                <div class="sidebar-widget">
                    <h5 class="sidebar-title"><i class="bi bi-collection-play ms-2 text-gold"></i>سایر سخنرانی‌ها</h5>
                    <ul class="sidebar-list">
                        <?php foreach ($otherSpeeches as $os): ?>
                        <li>
                            <a href="<?= speechUrl($os) ?>"><?= sanitize(mb_strimwidth($os['title'],0,55,'...')) ?></a>
                            <span class="sidebar-date"><i class="bi bi-calendar3 ms-1"></i><?= persianDate($os['published_at']) ?></span>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($otherSpeeches)): ?><li class="text-muted small">سخنرانی دیگری موجود نیست.</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </aside>
    </div>
</div></div>

<script src="<?= asset('js/media-player.js') ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
