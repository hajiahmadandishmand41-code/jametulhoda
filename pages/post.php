<?php
/**
 * post.php — صفحه مطلب (گزارش/مقاله/پژوهش/اطلاعیه/خبر) محتوامحور + SEO
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/media.php';
startPublicSession();

$slug = trim($_GET['slug'] ?? '');
if (!$slug) {
    redirect(url('articles'));
}

// Typed URLs (/article/X, /news/X, /research/X, /report/X, ...) resolve here;
// a slug that belongs to another post type is genuinely "not found" under this prefix.
$expectedType = trim($_GET['expected_type'] ?? '');
$normalizedExpected = match ($expectedType) {
    'articles', 'article' => 'article',
    'news' => 'news',
    'researches', 'research' => 'research',
    'reports', 'report' => 'report',
    'announcements', 'announcement' => 'announcement',
    'programs', 'program' => 'program',
    'religious', 'religious-activities' => 'religious',
    'events', 'event' => 'event',
    'qa' => 'qa',
    'speeches', 'speech' => 'speech',
    default => $expectedType,
};

$db = jhd_db();
if ($db === null) {
    jhd_render_content_unavailable('مطلب', url('articles'), 'بازگشت به مقالات');
}
$post = null;
try {
    $stmt = $db->prepare("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name FROM posts p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN users u ON u.id=p.author_id WHERE (p.slug=? OR p.slug=? OR p.slug=?) AND p.status='published' LIMIT 1");
    $stmt->execute([$slug, rawurlencode($slug), urldecode($slug)]);
    $post = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log('post lookup failed: ' . $e->getMessage());
}

// `/event/<slug>` is the legacy shared namespace for everything event-shaped
// (programs, religious activities and announcements each own a prefix now).
// It keeps resolving for old links, then forwards to the canonical URL.
$isEventMatch = $normalizedExpected === 'event'
    && in_array($post['post_type'] ?? '', ['event', 'program', 'religious', 'announcement'], true);

if (!$post || ($normalizedExpected !== '' && ($post['post_type'] ?? '') !== $normalizedExpected && !$isEventMatch)) {
    http_response_code(404);
    $pageTitle = 'مطلب یافت نشد';
    $pageDesc = 'مطلب مورد نظر یافت نشد';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-5 text-center"><h1>مطلب مورد نظر یافت نشد</h1><p class="text-muted">ممکن است حذف شده یا نشانی نادرست باشد.</p><a href="' . url() . '" class="btn btn-primary mt-3">بازگشت به صفحه اصلی</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/*
 * One content item = one URL.
 *
 * The post resolved successfully, so the request may still have arrived
 * through a legacy spelling (/post/x, /article/x, /event/x, ?p=…). Send a
 * permanent redirect to the canonical URL that postUrl() generates, so the
 * canonical <link>, the sitemap, the breadcrumb and every card on the site all
 * agree on a single address. `/speech/<slug>` is served by pages/speech.php,
 * so a speech reached here is redirected there as well.
 */
jhd_redirect_to_canonical(postUrl($post), jhd_post_canonical_path($post));

require_once __DIR__ . '/../includes/post-gallery.php';

// ── رسانهٔ شاخص (چند تصویر + چند ویدیو) ────────────────────────────────────
// رسانهٔ شاخص از رسانهٔ داخل متن جداست: این فایل‌ها بالای مطلب و در کارت‌ها
// دیده می‌شوند، بقیهٔ تصاویر در نگارخانهٔ پایین مطلب می‌مانند.
$featuredImages = getPostFeaturedImages((int)$post['id']);
$featuredVideos = getPostFeaturedVideos((int)$post['id']);
$featuredImagePaths = array_map(static fn(array $r): string => (string)$r['image_path'], $featuredImages);
if (!$featuredImagePaths && !empty($post['featured_image'])) $featuredImagePaths = [(string)$post['featured_image']];

$extraImgs = $db->prepare('SELECT * FROM post_images WHERE post_id=? ORDER BY sort_order, id');
$extraImgs->execute([$post['id']]);
$allPostImages = $extraImgs->fetchAll();
$extraImages = array_values(array_filter(
    $allPostImages,
    static fn(array $row): bool => !in_array((string)$row['image_path'], $featuredImagePaths, true)
));

$postVideos = array_values(array_filter(
    getMediaFor('post', (int)$post['id'], 'video'),
    static fn(array $row): bool => (int)($row['sort_order'] ?? 0) !== -1000000
));
// ویدیوهای شاخص همیشه ابتدای پخش‌کننده قرار می‌گیرند و ترتیبشان حفظ می‌شود.
$featuredVideoPaths = array_map(static fn(array $r): string => (string)$r['file_path'], $featuredVideos);
if (!$featuredVideoPaths && !empty($post['featured_video'])) $featuredVideoPaths = [(string)$post['featured_video']];
if ($featuredVideoPaths) {
    $ordered = [];
    foreach ($featuredVideoPaths as $path) {
        foreach ($postVideos as $index => $file) {
            if ((string)$file['file_path'] === $path) { $ordered[] = $file; unset($postVideos[$index]); continue 2; }
        }
        $ordered[] = ['id' => 0, 'file_path' => $path, 'title' => (string)$post['title'], 'thumbnail' => null, 'kind' => 'video'];
    }
    $postVideos = array_merge($ordered, array_values($postVideos));
}
$postVideos = array_values($postVideos);
$postAudios = getMediaFor('post', (int)$post['id'], 'audio');
$postDocuments = getMediaFor('post', (int)$post['id'], 'document');

// Topics for this post
$postTopics = getTopicsForPost((int)$post['id']);
$primaryTopic = $postTopics[0] ?? null;

// Related content via shared topic or same type
$related = [];
if ($primaryTopic) {
    $related = getPostsByTopic((int)$primaryTopic['id'], ['limit' => 4]);
    $related = array_filter($related, fn($r) => $r['id'] != $post['id']);
    $related = array_slice($related, 0, 3);
}
if (count($related) < 3) {
    $more = getPosts(['type' => $post['post_type'], 'limit' => 6]);
    $more = array_filter($more, fn($r) => $r['id'] != $post['id'] && !in_array($r['id'], array_column($related, 'id')));
    $related = array_slice(array_merge($related, $more), 0, 3);
}

// Related books/lessons via same topic
$relatedBooks = $primaryTopic ? getBooksByTopic((int)$primaryTopic['id'], 3) : [];
$relatedLessons = $primaryTopic ? getLessonsByTopic((int)$primaryTopic['id'], 3) : [];

$pageTitle = $post['title'];
$pageDesc = $post['summary'] ? excerpt($post['summary'], 160) : excerpt(strip_tags($post['content'] ?? ''), 160);

// Breadcrumbs + JSON-LD
$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => SITE_URL ? rtrim(SITE_URL, '/') . '/' : url()],
];
// Breadcrumbs name the section that owns this content, and that section URL is
// the same one the sitemap and the navigation use.
$typeMap = [
    'news'         => ['label' => 'اخبار',            'url' => url('news')],
    'article'      => ['label' => 'مقالات',           'url' => url('articles')],
    'research'     => ['label' => 'مقالات و پژوهش‌ها', 'url' => url('articles', ['type' => 'research'])],
    'report'       => ['label' => 'گزارش‌ها',          'url' => url('reports')],
    'announcement' => ['label' => 'اطلاعیه‌ها',        'url' => url('announcements')],
    'program'      => ['label' => 'برنامه‌های آموزشی', 'url' => url('programs')],
    'religious'    => ['label' => 'فعالیت‌های مذهبی',  'url' => url('religious-activities')],
    'event'        => ['label' => 'رویدادها',          'url' => url('events')],
    'speech'       => ['label' => 'سخنرانی‌ها',        'url' => url('speeches')],
    'qa'           => ['label' => 'پرسش و پاسخ',       'url' => url('qa')],
];
if (isset($typeMap[$post['post_type']])) {
    $breadcrumbs[] = ['name' => $typeMap[$post['post_type']]['label'], 'url' => $typeMap[$post['post_type']]['url']];
}
if ($primaryTopic) {
    foreach (getTopicBreadcrumbs((int)$primaryTopic['id']) as $bt) {
        $breadcrumbs[] = ['name' => $bt['name'], 'url' => topicUrl($bt)];
    }
}
// Use the URL-mode-aware canonical also used by cards, redirects and the
// sitemap: Pretty deployments publish the clean typed path; Query-mode hosts
// keep the working index.php?p=… canonical rather than pointing crawlers at a
// rewrite-only path that the server may not resolve.
$postCanonical = postUrl($post);
$postCanonicalUrl = jhd_absolute_url($postCanonical);
$canonicalOverride = $postCanonical;
$breadcrumbs[] = ['name' => $post['title'], 'url' => $postCanonicalUrl];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);
// Structured data matches the content type: a question page is a QAPage, a
// news item a NewsArticle, a research piece a ScholarlyArticle, …
if (($post['post_type'] ?? '') === 'qa') {
    $qaJsonLd = qaJsonLd($post);
} else {
    $articleJsonLd = articleJsonLd($post);
}
// Give social previews the post's own image when it has one.
if (!empty($featuredImagePaths[0])) {
    $ogImage = jhd_absolute_url(imgUrl((string)$featuredImagePaths[0]));
    $ogImageAlt = (string)$post['title'];
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar"><div class="container"><nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
<?php foreach ($breadcrumbs as $i => $bc): $isLast = ($i === count($breadcrumbs) - 1); ?>
<li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>><?php if(!$isLast): ?><a href="<?= sanitize($bc['url']) ?>"><?= sanitize($bc['name']) ?></a><?php else: ?><?= sanitize(mb_strimwidth($bc['name'], 0, 60, '...')) ?><?php endif; ?></li>
<?php endforeach; ?>
</ol></nav></div></div>

<div class="jhd-section"><div class="container"><div class="row g-4">
<div class="col-lg-8">
<article class="jhd-article" itemscope itemtype="https://schema.org/Article">
    <header class="jhd-article-head">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="jhd-card-badge jhd-card-badge--inline"><?= sanitize(postTypeLabel((string)$post['post_type'])) ?></span>
            <?php if (!empty($post['cat_name'])): ?><a href="<?= categoryUrl($post['cat_slug']) ?>" class="jhd-chip"><?= sanitize($post['cat_name']) ?></a><?php endif; ?>
            <?php if ($primaryTopic): ?><a href="<?= topicUrl($primaryTopic) ?>" class="jhd-chip"><i class="bi bi-tag"></i><?= sanitize($primaryTopic['name']) ?></a><?php endif; ?>
        </div>
        <h1 class="jhd-article-title" itemprop="headline"><?= sanitize($post['title']) ?></h1>
        <?php if (!empty($post['summary'])): ?><p class="jhd-article-lead" itemprop="description"><?= sanitize($post['summary']) ?></p><?php endif; ?>
        <div class="jhd-meta-bar">
            <span><i class="bi bi-calendar3"></i><?= persianDate($post['published_at'] ?? $post['created_at']) ?></span>
            <?php if (!empty($post['author_name'])): ?><span><i class="bi bi-person"></i><?= sanitize($post['author_name']) ?></span><?php endif; ?>
            <?php if ($postVideos): ?><span><i class="bi bi-camera-video"></i><?= count($postVideos) ?> ویدیو</span><?php endif; ?>
            <?php if ($postAudios): ?><span><i class="bi bi-headphones"></i><?= count($postAudios) ?> صوت</span><?php endif; ?>
        </div>
    </header>

    <?php if (count($featuredImagePaths) > 1): ?>
    <!-- چند تصویر شاخص: همان نگارخانهٔ استاندارد سایت، در بالای مطلب -->
    <section class="jhd-featured-media" aria-label="تصاویر شاخص مطلب">
        <?= jhd_gallery(array_map(static function (string $path) use ($featuredImages, $post): array {
            $alt = (string)$post['title'];
            foreach ($featuredImages as $row) if ((string)$row['image_path'] === $path && !empty($row['alt_text'])) $alt = (string)$row['alt_text'];
            return ['path' => $path, 'alt' => $alt];
        }, $featuredImagePaths), ['title' => 'تصاویر شاخص']) ?>
    </section>
    <?php elseif ($featuredImagePaths): ?>
    <figure class="jhd-article-figure">
        <img src="<?= imgUrl($featuredImagePaths[0]) ?>" alt="<?= sanitize($post['title']) ?>" loading="eager" fetchpriority="high" decoding="async" itemprop="image">
    </figure>
    <?php endif; ?>

    <?php if ($postVideos): ?>
    <?php $firstVideoUrl = url($postVideos[0]['file_path']); $videoPoster = !empty($postVideos[0]['thumbnail']) ? imgUrl($postVideos[0]['thumbnail']) : ''; ?>
    <section class="jhd-player" id="videoSection" aria-label="ویدیوهای مطلب">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0"><i class="bi bi-camera-video ms-1 text-gold"></i> <?= $featuredVideoPaths ? 'ویدیوهای شاخص و مرتبط' : 'ویدیوهای مرتبط' ?></h2>
            <?php if (count($postVideos) > 1): ?><span class="badge bg-light border" id="postVideoCounter">۱ / <?= count($postVideos) ?></span><?php endif; ?>
        </div>
        <div class="video-lazy-container" id="videoLazyWrap" data-src="<?= htmlspecialchars($firstVideoUrl, ENT_QUOTES) ?>">
            <div class="video-thumb" id="videoPoster" <?= $videoPoster ? '' : '' ?> <?= $videoPoster ? 'style="background:url(\'' . htmlspecialchars($videoPoster, ENT_QUOTES) . '\') center/cover"' : '' ?>>
                <?php if (!$videoPoster): ?><div class="video-thumb__placeholder h-100 d-flex align-items-center justify-content-center"><i class="bi bi-camera-video" aria-hidden="true"></i></div><?php endif; ?>
                <div class="video-play-overlay"><div class="video-play-btn"><i class="bi bi-play-fill"></i> پخش ویدیو</div></div>
            </div>
        </div>
        <div id="plyrVideoHolder" style="display:none;" class="mt-2"><video id="mainPostVideo" controls playsinline preload="none" poster="<?= htmlspecialchars($videoPoster, ENT_QUOTES) ?>" class="w-100"><source src="" data-src="<?= htmlspecialchars($firstVideoUrl, ENT_QUOTES) ?>" type="video/mp4">مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.</video></div>
        <?php if (count($postVideos) > 1): ?>
        <div class="playlist mt-2" id="postVideoPlaylist"><?php foreach($postVideos as $vi=>$v): ?><button type="button" class="playlist-item <?= $vi===0?'active':'' ?>" data-src="<?= htmlspecialchars(url($v['file_path']), ENT_QUOTES) ?>" data-index="<?= $vi ?>"><span class="pl-num"><?= $vi+1 ?></span><span class="pl-title"><?= sanitize($v['title'] ?: ('ویدیو '.($vi+1))) ?></span><i class="bi bi-play-circle-fill pl-icon"></i></button><?php endforeach; ?></div>
        <div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-outline-primary btn-sm" id="postVideoPrev"><i class="bi bi-skip-end-fill"></i> قبلی</button><button type="button" class="btn btn-outline-primary btn-sm" id="postVideoNext">بعدی <i class="bi bi-skip-start-fill"></i></button></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($postAudios): ?>
    <section class="jhd-player" aria-label="فایل‌های صوتی مطلب">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0"><i class="bi bi-headphones ms-1 text-gold"></i> فایل‌های صوتی</h2>
            <span class="badge bg-light border" id="audioCounter">۱ / <?= count($postAudios) ?></span>
        </div>
        <audio id="mainAudio" controls preload="none" class="w-100"><source data-src="<?= url($postAudios[0]['file_path']) ?>" src="" type="audio/mpeg"></audio>
        <div class="d-flex gap-2 mt-2 flex-wrap">
            <button type="button" class="btn btn-outline-primary btn-sm" id="audioPrevBtn"><i class="bi bi-skip-end-fill"></i> قبلی</button>
            <button type="button" class="btn btn-outline-primary btn-sm" id="audioNextBtn">بعدی <i class="bi bi-skip-start-fill"></i></button>
            <a href="<?= url($postAudios[0]['file_path']) ?>" download id="audioDownload" class="btn btn-outline-primary btn-sm"><i class="bi bi-download ms-1"></i>دانلود</a>
        </div>
        <?php if (count($postAudios) > 1): ?><div class="playlist mt-2" id="audioPlaylist"><?php foreach($postAudios as $ai=>$a): ?><button type="button" class="playlist-item <?= $ai===0?'active':'' ?>" data-src="<?= url($a['file_path']) ?>" data-index="<?= $ai ?>"><span class="pl-num"><?= $ai+1 ?></span><span class="pl-title"><?= sanitize($a['title'] ?: ('صوت '.($ai+1))) ?></span><i class="bi bi-play-circle-fill pl-icon"></i></button><?php endforeach; ?></div><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
    $articleOutline = [];
    $articleBodyHtml = safeRichText($post['content']);
    if (($post['post_type'] ?? '') === 'article') $articleBodyHtml = jhd_promote_article_headings($articleBodyHtml);
    $articleBodyHtml = jhd_add_contextual_internal_links($articleBodyHtml, (int)$post['id'], 6);
    if (($post['post_type'] ?? '') === 'article') {
        $outline = jhd_article_outline($articleBodyHtml);
        $articleBodyHtml = $outline['html'];
        $articleOutline = $outline['items'];
    }
    ?>
    <?php if ($articleOutline): ?>
    <nav class="jhd-article-toc" aria-labelledby="article-toc-title">
        <details open>
            <summary id="article-toc-title"><i class="bi bi-list-ul" aria-hidden="true"></i><span>فهرست مطالب</span></summary>
            <ol>
                <?php foreach ($articleOutline as $outlineItem): ?>
                <li class="jhd-article-toc__level-<?= (int)$outlineItem['level'] ?>"><a href="#<?= sanitize($outlineItem['id']) ?>"><?= sanitize($outlineItem['title']) ?></a></li>
                <?php endforeach; ?>
            </ol>
        </details>
    </nav>
    <?php endif; ?>
    <div class="jhd-prose" itemprop="articleBody"><?= $articleBodyHtml ?: '<p class="text-muted">محتوایی ثبت نشده است.</p>' ?></div>

    <?php if (!empty($post['sources'])): ?>
    <section class="jhd-side-card mt-4">
        <h2 class="h5"><i class="bi bi-journal-text"></i> منابع و مآخذ</h2>
        <div class="jhd-prose" style="font-size:.9rem"><?= safeRichText($post['sources']) ?></div>
    </section>
    <?php endif; ?>

    <?php if ($postDocuments): ?>
    <section class="jhd-attachments" aria-label="فایل‌های تکمیلی">
        <div class="jhd-attachments__head">
            <h2 class="jhd-attachments__title"><i class="bi bi-paperclip" aria-hidden="true"></i>فایل‌های تکمیلی</h2>
            <p class="jhd-attachments__lead">جزوه‌ها و اسناد مرتبط با این محتوا</p>
        </div>
        <div class="jhd-files">
        <?php foreach ($postDocuments as $file): ?>
            <?php
            $fileTitle = (string)($file['title'] ?: basename((string)$file['file_path']));
            $isPdf = (bool)preg_match('~\.pdf$~i', (string)$file['file_path']);
            $readerHref = $isPdf ? documentReaderUrl('post', (string)$post['slug'], (int)($file['id'] ?? 0)) : '';
            $fileSize = (string)($file['file_size'] ?? ($file['size'] ?? ''));
            echo jhd_file_row($fileTitle, imgUrl((string)$file['file_path']), $fileSize, 'bi-file-earmark', $isPdf ? ['reader_target' => $readerHref] : []);
            ?>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($extraImages): ?>
    <div class="mt-4">
        <?= jhd_gallery(array_map(static fn(array $i): array => ['path' => (string)$i['image_path'], 'alt' => (string)($i['alt_text'] ?? '')], $extraImages), ['title' => 'گالری تصاویر مطلب']) ?>
    </div>
    <?php endif; ?>

    <?php if ($postTopics): ?>
    <section class="mt-4" aria-label="موضوعات مطلب">
        <h2 class="h6"><i class="bi bi-diagram-3 ms-1 text-gold"></i> موضوعات مرتبط</h2>
        <div class="jhd-cat-strip mb-0"><?php foreach ($postTopics as $t): ?><a href="<?= topicUrl($t) ?>" class="jhd-chip"><?= sanitize($t['name']) ?></a><?php endforeach; ?></div>
    </section>
    <?php endif; ?>

    <?= jhd_share_row($postCanonicalUrl, (string)$post['title']) ?>

    <?php
    // ناوبری مطالب پیوسته (قبلی/بعدی در همان نوع محتوا)
    $adjacent = getAdjacentPosts((int)$post['id']);
    if (!empty($adjacent['prev']) || !empty($adjacent['next'])) {
        echo '<nav class="jhd-post-nav-wrap" aria-label="مطالب پیوسته">'
            . '<h2 class="h6 text-muted mb-2"><i class="bi bi-arrow-left-right ms-1"></i> مطالب پیوسته</h2>'
            . jhd_post_nav($adjacent['prev'] ?? null, $adjacent['next'] ?? null)
            . '</nav>';
    }
    ?>
</article>
</div>

<div class="col-lg-4">
<div class="d-grid gap-3">
    <?php if (!empty($related)): ?>
    <div class="jhd-side-card">
        <h3><i class="bi bi-grid"></i> مطالب مرتبط</h3>
        <ul class="jhd-side-list">
        <?php foreach ($related as $r): ?>
            <li><a href="<?= postUrl($r) ?>"><?= sanitize(mb_strimwidth($r['title'], 0, 55, '...')) ?></a><small><?= persianDate($r['published_at'] ?? $r['created_at']) ?></small></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($relatedBooks): ?>
    <div class="jhd-side-card">
        <h3><i class="bi bi-book"></i> کتاب‌های مرتبط</h3>
        <ul class="jhd-side-list">
        <?php foreach ($relatedBooks as $b): ?><li><a href="<?= bookUrl($b) ?>"><i class="bi bi-book ms-1 text-gold"></i><?= sanitize(mb_strimwidth($b['title'], 0, 40, '...')) ?></a></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($relatedLessons): ?>
    <div class="jhd-side-card">
        <h3><i class="bi bi-mortarboard"></i> درس‌های مرتبط</h3>
        <ul class="jhd-side-list">
        <?php foreach ($relatedLessons as $ls): ?><li><a href="<?= lessonUrl($ls) ?>"><i class="bi bi-mortarboard ms-1 text-gold"></i><?= sanitize(mb_strimwidth($ls['title'], 0, 40, '...')) ?></a></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="jhd-side-card">
        <h3><i class="bi bi-newspaper"></i> تازه‌های مدرسه</h3>
        <?php
        $sidePool = array_merge(
            getPosts(['type' => 'news', 'limit' => 3]),
            getPosts(['type' => 'report', 'limit' => 3])
        );
        usort($sidePool, static fn(array $a, array $b): int => strcmp(
            (string)($b['published_at'] ?? $b['created_at'] ?? ''),
            (string)($a['published_at'] ?? $a['created_at'] ?? '')
        ));
        $sideAccepted = [];
        $sideCombined = jhd_homepage_unique_posts($sidePool, $sideAccepted, 5);
        ?>
        <ul class="jhd-side-list">
        <?php foreach (array_slice($sideCombined, 0, 5) as $sn): ?>
            <li><a href="<?= postUrl($sn) ?>"><?= sanitize(mb_strimwidth($sn['title'], 0, 60, '...')) ?></a><small><?= persianDate($sn['published_at'] ?? $sn['created_at']) ?></small></li>
        <?php endforeach; ?>
        </ul>
    </div>

    <?php $sideTopics = getTopics(['active' => 1, 'limit' => 12]); if ($sideTopics): ?>
    <div class="jhd-side-card">
        <h3><i class="bi bi-tags"></i> موضوعات</h3>
        <div class="jhd-cat-strip mb-0"><?php foreach ($sideTopics as $ct): ?><a href="<?= topicUrl($ct) ?>" class="jhd-chip"><?= sanitize($ct['name']) ?></a><?php endforeach; ?></div>
    </div>
    <?php endif; ?>
</div>
</div>
</div></div></div>

<script>
(function(){
    var lazyWrap=document.getElementById('videoLazyWrap'); var poster=document.getElementById('videoPoster'); var holder=document.getElementById('plyrVideoHolder'); var videoEl=document.getElementById('mainPostVideo'); var playlist=document.getElementById('postVideoPlaylist'); var prevBtn=document.getElementById('postVideoPrev'); var nextBtn=document.getElementById('postVideoNext'); var counter=document.getElementById('postVideoCounter'); var plyrInstance=null; var currentIdx=0; var items=playlist?Array.from(playlist.querySelectorAll('.playlist-item')):[];
    function activatePlayer(src, autoplay){
        if(poster) poster.style.display='none'; if(holder) holder.style.display='block';
        var source=videoEl?videoEl.querySelector('source'):null; if(source) source.setAttribute('src',src); if(videoEl) videoEl.setAttribute('src',src);
        if(!plyrInstance && typeof Plyr!=='undefined'){
            plyrInstance=new Plyr(videoEl,{controls:['play-large','play','progress','current-time','duration','mute','volume','fullscreen'],loadSprite:true,iconUrl:document.querySelector('meta[name=plyr-sprite]')?.content,blankVideo:'',autoplay:false,keyboard:{focused:true,global:false},tooltips:{controls:false,seek:true},i18n:{play:'پخش',pause:'مکث',mute:'بی‌صدا',volume:'صدا',enterFullscreen:'تمام‌صفحه',exitFullscreen:'خروج'}});
            if(autoplay) plyrInstance.once('ready', function(){ plyrInstance.play().catch(function(){}); });
        } else if(plyrInstance){ plyrInstance.source={type:'video',sources:[{src:src,type:'video/mp4'}]}; if(autoplay) plyrInstance.play().catch(function(){}); }
        else { if(videoEl){ videoEl.load(); if(autoplay) videoEl.play().catch(function(){}); } }
    }
    function loadPlaylistItem(idx, autoplay){
        if(!items.length) return; if(idx<0) idx=items.length-1; if(idx>=items.length) idx=0; currentIdx=idx; var src=items[idx].getAttribute('data-src'); items.forEach(function(b,i){b.classList.toggle('active',i===idx);}); if(counter) counter.textContent=(idx+1)+' / '+items.length; activatePlayer(src, autoplay);
    }
    if(poster){ poster.addEventListener('click', function(){ var src=lazyWrap?lazyWrap.getAttribute('data-src'):(videoEl?(videoEl.querySelector('source')||{}).getAttribute('data-src'):''); activatePlayer(src,true); }); }
    items.forEach(function(btn,i){ btn.addEventListener('click', function(){ loadPlaylistItem(i,true); }); });
    if(prevBtn) prevBtn.addEventListener('click', function(){ loadPlaylistItem(currentIdx-1,true); });
    if(nextBtn) nextBtn.addEventListener('click', function(){ loadPlaylistItem(currentIdx+1,true); });
})();
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
