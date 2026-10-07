<?php
/**
 * index.php — صفحه اصلی پورتال علمی جامعة‌الهدی
 * ───────────────────────────────────────────────────────────────────────────
 * چیدمان رسانه‌ای و محتوامحور (هر بخش دقیقاً یک‌بار):
 *  ۱. تابلوی فشردهٔ برند        ۲. مطلب شاخص + دو مطلب کناری
 *  ۳. تازه‌ترین مطالب           ۴. اخبار (شاخص + فهرست)
 *  ۵. مقالات (سرمقاله‌ای)       ۶. گزارش‌ها (تصویرمحور)
 *  ۷. پژوهش (رسمی)             ۸. موضوعات (کاشی‌های اطلس)
 *  ۹. رویدادها (خط زمان)       ۱۰. کتابخانه دیجیتال
 * ۱۱. دروس                     ۱۲. رسانه (ویدیو + صوت)
 * در نبود دیتابیس هیچ «جعبهٔ خالی» تکراری نمایش داده نمی‌شود؛ یک یادداشت
 * فشرده و صادق کافی است.
 */
/*
 * Homepage identity.
 *
 * The brand leads the homepage title — it is the site's primary identity, so
 * it comes first rather than being pushed behind a descriptive phrase. The
 * remainder states plainly what the school is and where it is; nothing else.
 */
$metaTitleOverride = 'مدرسه جامعه‌الهدی | مدرسه علوم اسلامی در کابل، افغانستان';
$pageTitle = 'مدرسه جامعه‌الهدی';
$pageDesc = 'مدرسه جامعه‌الهدی در کابل، افغانستان؛ مرکز علمی، آموزشی و پژوهشی علوم اسلامی با دسترسی به اخبار، مقالات، پژوهش‌ها، گزارش‌ها، کتاب‌ها، دروس و موضوعات مرتبط.';
require_once __DIR__ . '/config/config.php';

// Resolve the query route before conditionally loading authentication.
$__p = (isset($_GET['p']) && is_string($_GET['p'])) ? trim($_GET['p']) : '';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// The public homepage must remain reachable on Vercel even when the production
// database is not configured there (the production MySQL database lives on the
// shared host). Authentication is loaded only for DB-backed/query routes.
require_once __DIR__ . '/includes/auth.php';

// A scalar query string is the only supported public GET contract. Reject
// array-shaped parameters before controllers pass them to string functions or
// SQL, including when index.php is executed directly without router.php.
foreach ($_GET as $__queryValue) {
    if (!is_string($__queryValue)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Invalid query parameter');
    }
}

// The session store itself degrades gracefully (includes/auth.php), so this is
// safe on a deployment whose database is not reachable yet.
if ($__p !== '' && $__p !== 'home') {
    startPublicSession();
}

// ─── Query-URL front controller ────────────────────────────────────────────
// index.php?p=news / index.php?p=topic&slug=x must work even when mod_rewrite
// is unavailable (InfinityFree). This is the same allowlist dispatch router.php
// uses for ?p=, so both entry points converge on one controller per route and
// nothing is ever include()d from user input. No ?p= → fall through to home.
$__p = (isset($_GET['p']) && is_string($_GET['p'])) ? trim($_GET['p']) : '';
// `?p=home` is this very file. Dispatching it again would require index.php
// from inside index.php forever (memory exhaustion / HTTP 500), so the home
// route is answered here directly — exactly like a bare `/`.
if ($__p === 'home' || $__p === '/') $__p = '';
if ($__p !== '') {
    $__resolved = jhd_resolve_query($__p, $_GET);
    if ($__resolved === null) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width"><title>۴۰۴ — صفحه پیدا نشد</title>'
            . '<style>body{font-family:Tahoma,system-ui,sans-serif;background:#f6f7f4;color:#182d39;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}'
            . 'main{max-width:520px;padding:32px;background:#fff;border:1px solid #e2e6e2;border-radius:16px;text-align:center;line-height:2}'
            . 'a{color:#245c4c}h1{font-size:1.4rem;margin:.4rem 0}</style>'
            . '<main><div style="font-size:3rem;font-weight:900;color:#245c4c">۴۰۴</div>'
            . '<h1>صفحه مورد نظر یافت نشد</h1><p class="text-muted">نشانی وارد شده معتبر نیست.</p>'
            . '<p><a href="' . htmlspecialchars(url(), ENT_QUOTES, 'UTF-8') . '">بازگشت به صفحه اصلی</a></p></main></html>';
        exit;
    }
    foreach ($__resolved['get'] as $__k => $__v) $_GET[$__k] ??= $__v;
    if (!empty($__resolved['expected_type'])) $_GET['expected_type'] = $__resolved['expected_type'];
    if (!empty($__resolved['kind'])) $_GET['kind'] = $__resolved['kind'];
    $_SERVER['JHD_ROUTE_NAME'] = $__p;
    $_SERVER['JHD_ROUTE_PATH'] = BASE_PATH . jhd_route_path($__p, $_GET);
    $__file = realpath(__DIR__ . '/' . $__resolved['file']);
    if ($__file !== false && str_starts_with($__file, realpath(__DIR__) . DIRECTORY_SEPARATOR) && is_file($__file)) {
        require $__file;
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>۴۰۴</title>'
        . '<main style="font-family:Tahoma;padding:40px;text-align:center">صفحه پیدا نشد — '
        . '<a href="' . htmlspecialchars(url(), ENT_QUOTES, 'UTF-8') . '">صفحه اصلی</a></main></html>';
    exit;
}

// صفحهٔ اصلی نباید برای بازدیدکنندهٔ تازه‌وارد به نصب وابسته باشد.
$jhdPublicDbReady = jhd_db_ready();
$db = jhd_db();
$GLOBALS['JHD_PUBLIC_DB_READY'] = $jhdPublicDbReady;

if ($jhdPublicDbReady) {
    // Anonymous GETs get no session at all (see startPublicSession): that is
    // where the second PostgreSQL connection, the two CREATE TABLE statements
    // and the `SELECT … FOR UPDATE` session row lock used to come from.
    startPublicSession();

    // ─── ۱. مطلب شاخص و دو مطلب کناری ─────────────────────────────────────
    $heroPost = getPosts(['featured' => 1, 'limit' => 1])[0]
        ?? getPosts(['type' => 'news', 'limit' => 1])[0]
        ?? getPosts(['type' => 'article', 'limit' => 1])[0]
        ?? getPosts(['limit' => 1])[0]
        ?? null;
    $heroId = $heroPost ? (int)$heroPost['id'] : 0;
    // The hero's topic is resolved below from the shared preload (one bulk
    // query for every card on the page) instead of its own extra round trip.
    $heroTopic = null;
    // One combined list feeds both the sidebar and the "latest" strip. The
    // previous code ran getPosts(limit 6) and getPosts(limit 12) separately
    // even though the first result set is a prefix of the second.
    $recentPool = getPosts(['limit' => 12]);
    $featuredSide = array_slice(array_values(array_filter(
        array_slice($recentPool, 0, 6),
        static fn(array $p): bool => (int)$p['id'] !== $heroId
    )), 0, 2);

    // ─── ۲. تازه‌ترین مطالب (ترکیبی) ─────────────────────────────────────
    $usedIds = array_merge([$heroId], array_map(static fn(array $p): int => (int)$p['id'], $featuredSide));
    $latest = array_slice(array_values(array_filter(
        $recentPool,
        static fn(array $p): bool => !in_array((int)$p['id'], $usedIds, true)
    )), 0, 6);

    // ─── ۳. بخش‌های محتوایی ──────────────────────────────────────────────
    $newsPool = array_values(array_filter(getPosts(['type' => 'news', 'limit' => 5]), static fn(array $p): bool => (int)$p['id'] !== $heroId));
    $latestArticles = getPosts(['type' => 'article', 'limit' => 4]);
    $latestReports = getPosts(['type' => 'report', 'limit' => 3]);
    $latestResearch = getPosts(['type' => 'research', 'limit' => 3]);

    $featuredTopics = [];
    try {
        $stmt = $db->query("SELECT t.*, COUNT(pt.post_id) as post_count FROM topics t LEFT JOIN post_topics pt ON pt.topic_id = t.id WHERE t.is_active = 1 GROUP BY t.id ORDER BY t.is_featured DESC, t.sort_order ASC, post_count DESC LIMIT 8");
        $featuredTopics = $stmt->fetchAll();
    } catch (Throwable) {
        $featuredTopics = getTopics(['limit' => 8]);
    }

    // Programs, religious activities and announcements come from one table and
    // are merged straight away: one query instead of three round trips.
    $eventPool = getPosts(['types' => ['program', 'religious', 'announcement'], 'limit' => 12]);
    $now = time();
    $upcoming = array_values(array_filter($eventPool, static fn(array $e): bool => strtotime((string)($e['published_at'] ?? $e['created_at'] ?? '')) >= $now));
    $past = array_values(array_filter($eventPool, static fn(array $e): bool => strtotime((string)($e['published_at'] ?? $e['created_at'] ?? '')) < $now));
    usort($upcoming, static fn(array $a, array $b): int => strcmp((string)($a['published_at'] ?? ''), (string)($b['published_at'] ?? '')));
    usort($past, static fn(array $a, array $b): int => strcmp((string)($b['published_at'] ?? $b['created_at'] ?? ''), (string)($a['published_at'] ?? $a['created_at'] ?? '')));
    $latestEvents = array_slice(array_merge($upcoming, $past), 0, 5);

    $latestBooks = getBooks(['featured' => 1, 'limit' => 5]);

    $latestLessons = [];
    try {
        $stmt = $db->prepare("
            SELECT l.*, c.title AS collection_title, c.slug AS collection_slug
            FROM lessons l
            LEFT JOIN lesson_collections c ON c.id = l.collection_id
            WHERE l.status = 'published' AND l.is_featured = 1
            ORDER BY l.sort_order ASC, l.id DESC
            LIMIT 6
        ");
        $stmt->execute();
        $latestLessons = $stmt->fetchAll();
    } catch (Throwable) {
        $latestLessons = [];
    }

    $latestVideos = [];
    $latestAudios = [];
    try {
        $stmt = $db->prepare("
            SELECT m.*, p.title as post_title, p.slug as post_slug, p.post_type as post_type
            FROM media_files m
            LEFT JOIN posts p ON p.id = m.ref_id AND m.ref_type = 'post'
            WHERE m.kind = 'video' AND (p.status = 'published' OR p.status IS NULL)
            ORDER BY m.id DESC LIMIT 3
        ");
        $stmt->execute();
        $latestVideos = $stmt->fetchAll();
        $stmt = $db->prepare("
            SELECT m.*, p.title as post_title, p.slug as post_slug, p.post_type as post_type
            FROM media_files m
            LEFT JOIN posts p ON p.id = m.ref_id AND m.ref_type = 'post'
            WHERE m.kind = 'audio' AND (p.status = 'published' OR p.status IS NULL)
            ORDER BY m.id DESC LIMIT 2
        ");
        $stmt->execute();
        $latestAudios = $stmt->fetchAll();
    } catch (Throwable) {
        $latestVideos = [];
        $latestAudios = [];
    }

    $specialBanner = getActiveBanner();
    // NOTE: five COUNT(*) queries (posts/topics/books/lessons/media) used to run
    // here into $homeCounts/$homeContentTotal. Neither variable is rendered
    // anywhere on this page, so they were five full table scans per homepage
    // request with no consumer. Removed.
} else {
    // حالت پیش‌نصب: قالب اصلی سایت کاملاً رندر می‌شود و فقط داده‌ها خالی‌اند.
    $heroPost = null; $heroTopic = null; $featuredSide = []; $latest = [];
    $newsPool = []; $latestArticles = []; $latestReports = []; $latestResearch = [];
    $featuredTopics = []; $latestEvents = []; $latestBooks = []; $latestLessons = [];
    $latestVideos = []; $latestAudios = []; $specialBanner = null;
}
jhd_preload_post_topics(array_merge(
    $heroPost ? [$heroPost] : [], $featuredSide, $latest, $newsPool,
    $latestArticles, $latestReports, $latestResearch, $latestEvents
));
if ($heroPost) $heroTopic = jhd_card_topics($heroPost, 1)[0] ?? null;
require_once __DIR__ . '/includes/header.php';
$homeHasAnyContent = $heroPost || $latest || $newsPool || $latestArticles || $latestReports || $latestResearch || $featuredTopics || $latestEvents || $latestBooks || $latestLessons || $latestVideos || $latestAudios;
?>

<!-- ─── ۱. تابلوی فشردهٔ برند ─────────────────────────────────────────────── -->
<section class="jhd-brand-board" aria-labelledby="home-brand-title">
    <div class="container">
        <div class="jhd-home-hero">
            <div class="jhd-home-hero-copy">
                <p class="jhd-board-official">پایگاه رسمی علمی · آموزشی · پژوهشی</p>
                <h1 id="home-brand-title"><?= sanitize($siteName) ?></h1>
                <p class="jhd-board-slogan">مرکز علمی، آموزشی و پژوهشی در پرتو قرآن و عترت</p>
                <ul class="jhd-board-tags">
                    <li>اندیشه</li><li>آموزش</li><li>پژوهش</li><li>معارف اسلامی</li>
                </ul>
                <form class="jhd-home-search" action="<?= sanitize(formUrl('search')) ?>" method="get" role="search" aria-label="جستجو در محتوای پایگاه">
                    <?= formRouteFields('search') ?>
                    <label class="visually-hidden" for="home-search-query">عبارت مورد جستجو</label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="home-search-query" type="search" name="q" maxlength="200" enterkeyhint="search" placeholder="جستجو در مقالات، کتاب‌ها و موضوعات…">
                    <button type="submit"><span>جستجو</span><i class="bi bi-arrow-left" aria-hidden="true"></i></button>
                </form>
            </div>
            <aside class="jhd-home-quick" aria-labelledby="home-quick-title">
                <p class="jhd-home-quick-eyebrow">دسترسی سریع</p>
                <h2 id="home-quick-title">بخش‌های پایگاه</h2>
                <nav aria-label="دسترسی سریع به بخش‌های پایگاه">
                    <ul class="jhd-home-quick-links">
                        <li><a href="<?= sanitize(url('topics')) ?>"><span class="jhd-home-quick-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span><span class="jhd-home-quick-copy"><strong>موضوعات و معارف</strong><small>محورهای علمی</small></span></a></li>
                        <li><a href="<?= sanitize(url('books')) ?>"><span class="jhd-home-quick-icon"><i class="bi bi-book" aria-hidden="true"></i></span><span class="jhd-home-quick-copy"><strong>کتابخانه دیجیتال</strong><small>منابع مطالعاتی</small></span></a></li>
                        <li><a href="<?= sanitize(url('lessons')) ?>"><span class="jhd-home-quick-icon"><i class="bi bi-mortarboard" aria-hidden="true"></i></span><span class="jhd-home-quick-copy"><strong>درس‌ها و آموزش‌ها</strong><small>مجموعه‌های آموزشی</small></span></a></li>
                        <li><a href="<?= sanitize(url('research')) ?>"><span class="jhd-home-quick-icon"><i class="bi bi-journal-richtext" aria-hidden="true"></i></span><span class="jhd-home-quick-copy"><strong>پژوهش‌ها</strong><small>دستاوردهای علمی</small></span></a></li>
                    </ul>
                </nav>
            </aside>
        </div>
        <span class="jhd-board-mark" aria-hidden="true">۞</span>
    </div>
</section>

<?php if (!empty($specialBanner)): ?>
<section class="jhd-section--tight jhd-section--paper">
    <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-megaphone ms-1"></i>اعلان ویژه</span>
            <span class="fw-bold"><?= sanitize($specialBanner['title']) ?></span>
            <?php if (!empty($specialBanner['content'])): ?>
            <span class="d-none d-md-inline text-muted small">— <?= sanitize(excerpt($specialBanner['content'], 90)) ?></span>
            <?php endif; ?>
        </div>
        <?php
        $bannerLink = trim((string)($specialBanner['link'] ?? ''));
        $bannerHref = '';
        $bannerExternal = false;
        if (str_starts_with($bannerLink, '/') && !str_starts_with($bannerLink, '//') && !str_contains($bannerLink, '\\') && !preg_match('/[\x00-\x20]/', $bannerLink)) {
            $bannerHref = jhd_web_path(ltrim($bannerLink, '/'));
        } else {
            $bannerHref = safeExternalUrl($bannerLink);
            $bannerExternal = $bannerHref !== '';
        }
        ?>
        <?php if ($bannerHref !== ''): ?>
        <a href="<?= sanitize($bannerHref) ?>" class="btn btn-sm btn-outline-primary fw-bold"<?= $bannerExternal ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
            <?= sanitize($specialBanner['link_text'] ?: 'مشاهده جزییات') ?> <i class="bi bi-arrow-left ms-1"></i>
        </a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!$homeHasAnyContent): ?>
<section class="jhd-section">
    <div class="container">
        <?= renderEmptyState('bi-journal-bookmark', 'هنوز محتوایی در این پایگاه منتشر نشده است؛ پس از ثبت نخستین مطالب در سامانه، همین صفحه به ویترین علمی مدرسه تبدیل می‌شود.', url('about'), 'آشنایی با جامعة‌الهدی') ?>
    </div>
</section>
<?php endif; ?>

<?php if ($heroPost): ?>
<!-- ─── ۲. مطلب شاخص + دو مطلب کناری (چیدمان رسانه‌ای) ───────────────────── -->
<section class="jhd-section" aria-label="مطلب شاخص">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8">
                <?= renderPostCard($heroPost, ['featured' => true, 'col' => 'col-12', 'cta' => 'مطالعه کامل مطلب', 'excerpt' => 190, 'eager' => true, 'topics' => $heroTopic ? [$heroTopic] : []]) ?>
            </div>
            <div class="col-lg-4">
                <div class="jhd-side-card h-100">
                    <h3><i class="bi bi-stars"></i> برگزیدهٔ سردبیر</h3>
                    <?php if ($featuredSide): ?>
                        <?php foreach ($featuredSide as $side): ?><?= renderMiniItem($side) ?><?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-0">مطلب دیگری برای نمایش ثبت نشده است.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($latest): ?>
<!-- ─── ۳. تازه‌ترین مطالب ───────────────────────────────────────────────── -->
<section class="jhd-section jhd-section--paper" id="latest-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'تازه‌ها',
            'icon' => 'bi-clock-history',
            'title' => 'تازه‌ترین مطالب',
            'url' => url('articles'),
            'link' => 'همه مطالب',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
            <?php foreach (array_slice($latest, 0, 6) as $item): ?><?= renderPostCard($item, ['cta' => 'مشاهده مطلب', 'excerpt' => 110]) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if ($newsPool): ?>
<!-- ─── ۴. اخبار ─────────────────────────────────────────────────────────── -->
<section class="jhd-section" id="news-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'اطلاع‌رسانی جاری',
            'icon' => 'bi-newspaper',
            'title' => 'اخبار مدرسه',
            'url' => url('news'),
            'link' => 'همه اخبار',
        ]) ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <?= renderPostCard($newsPool[0], ['featured' => true, 'col' => 'col-12', 'cta' => 'ادامه مطلب', 'excerpt' => 130]) ?>
            </div>
            <div class="col-lg-5">
                <?php foreach (array_slice($newsPool, 1, 4) as $n): ?><?= renderMiniItem($n) ?><?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($latestArticles): ?>
<!-- ─── ۵. مقالات علمی (سرمقاله‌ای) ──────────────────────────────────────── -->
<section class="jhd-section jhd-section--paper" id="articles-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'اندیشه و پژوهش دینی',
            'icon' => 'bi-file-earmark-richtext',
            'title' => 'مقالات علمی و یادداشت‌ها',
            'url' => url('articles'),
            'link' => 'همه مقالات',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
        <?php foreach ($latestArticles as $i => $art): ?><?= renderEditorialRow($art, ['index' => $i + 1, 'excerpt' => 120, 'cta' => 'مطالعه مقاله']) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if ($latestReports): ?>
<!-- ─── ۶. گزارش‌های تصویری ───────────────────────────────────────────────── -->
<section class="jhd-section" id="reports-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'پوشش میدانی و رخدادها',
            'icon' => 'bi-card-text',
            'title' => 'گزارش‌های حوزه و جامعه',
            'url' => url('reports'),
            'link' => 'همه گزارش‌ها',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
            <?php foreach ($latestReports as $rep): ?><?= renderPostCard($rep, ['cta' => 'مشاهده گزارش', 'excerpt' => 110]) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if ($latestResearch): ?>
<!-- ─── ۷. پژوهش (رسمی) ──────────────────────────────────────────────────── -->
<section class="jhd-section jhd-section--paper" id="research-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'پژوهش‌های حوزوی',
            'icon' => 'bi-journal-richtext',
            'title' => 'پژوهش‌ها و طرح‌های علمی',
            'url' => url('research'),
            'link' => 'همه پژوهش‌ها',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
        <?php foreach ($latestResearch as $i => $rs): ?><?= renderEditorialRow($rs, ['index' => $i + 1, 'excerpt' => 120, 'cta' => 'مشاهده پژوهش']) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if ($featuredTopics): ?>
<!-- ─── ۸. اطلس موضوعات ──────────────────────────────────────────────────── -->
<section class="jhd-section" id="topics-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'ستون فقرات معارف اسلامی',
            'icon' => 'bi-diagram-3',
            'title' => 'موضوعات و محورهای پژوهشی',
            'url' => url('topics'),
            'link' => 'اطلس کامل موضوعات',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
            <?php foreach ($featuredTopics as $tp): ?><?= renderTopicCard($tp, ['counts' => [['value' => (int)($tp['post_count'] ?? 0), 'label' => 'مطلب', 'icon' => 'bi-journal-text']]]) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if ($latestEvents): ?>
<!-- ─── ۹. رویدادها و برنامه‌ها (خط زمان) ────────────────────────────────── -->
<section class="jhd-section jhd-section--paper" id="events-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'تقویم حوزه و مناسبت‌ها',
            'icon' => 'bi-calendar-event',
            'title' => 'رویدادها و برنامه‌ها',
            'url' => url('events'),
            'link' => 'همه رویدادها',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
        <?php foreach ($latestEvents as $ev): ?><?= renderEventRow($ev) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if ($latestBooks): ?>
<!-- ─── ۱۰. کتابخانه دیجیتال ─────────────────────────────────────────────── -->
<section class="jhd-section" id="books-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'مرکز اسناد و نشر آثار',
            'icon' => 'bi-book',
            'title' => 'کتابخانه دیجیتال',
            'url' => url('books'),
            'link' => 'همه کتاب‌ها',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
            <?php foreach ($latestBooks as $b): ?><?= renderBookCard($b) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if ($latestLessons): ?>
<!-- ─── ۱۱. دروس حوزوی ──────────────────────────────────────────────────── -->
<section class="jhd-section jhd-section--paper" id="lessons-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'مدرسه علمیه و آموزش مجازی',
            'icon' => 'bi-mortarboard',
            'title' => 'دروس و جلسه‌های آموزشی',
            'url' => url('lessons'),
            'link' => 'همه دروس',
        ]) ?>
        <?php
        $lessonGroups = [];
        foreach ($latestLessons as $ls) {
            $lessonGroups[(string)($ls['collection_title'] ?? 'دروس متفرقه')][] = $ls;
        }
        foreach ($lessonGroups as $groupName => $groupLessons): ?>
        <div class="jhd-lesson-group">
            <div class="jhd-lesson-group-head"><i class="bi bi-collection"></i> <?= sanitize($groupName) ?></div>
            <div class="jhd-lesson-group-body">
                <?= jhd_grid_open('jhd-card-grid--rail') ?>
                <?php foreach ($groupLessons as $ls): ?><?= renderLessonRow($ls) ?><?php endforeach; ?>
                <?= jhd_grid_close() ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($latestVideos || $latestAudios): ?>
<!-- ─── ۱۲. رسانه: ویدیو و صوت ──────────────────────────────────────────── -->
<section class="jhd-section" id="media-section">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'نگارخانه صوتی و تصویری',
            'icon' => 'bi-play-circle',
            'title' => 'رسانه و سخنرانی‌ها',
            'url' => url('media'),
            'link' => 'همه رسانه‌ها',
        ]) ?>
        <?= jhd_grid_open('jhd-card-grid--rail') ?>
            <?php foreach ($latestVideos as $v): ?><?= renderMediaCard($v, ['url' => !empty($v['post_slug']) ? postUrl($v['post_slug']) : mediaUrl('video', (int)$v['id'])]) ?><?php endforeach; ?>
            <?php foreach ($latestAudios as $a): ?><?= renderAudioRow($a) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<!-- ─── نوار دعوت فشرده ──────────────────────────────────────────────────── -->
<section class="jhd-section jhd-section--tight">
    <div class="container">
        <div class="jhd-invitation">
            <div>
                <h2>همراه مسیر علمی جامعة‌الهدی باشید</h2>
                <p>برای پیگیری مطالب، درس‌ها و برنامه‌ها عضو شوید یا با مدرسه در ارتباط باشید.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="jhd-button" href="<?= registerUrl() ?>">ثبت‌نام <i class="bi bi-arrow-left"></i></a>
                <a class="jhd-button jhd-button-ghost" href="<?= url('contact') ?>">گفت‌وگو با مدرسه</a>
            </div>
        </div>
    </div>
</section>

<!-- Modal پخش‌کننده ویدیو -->
<div class="modal fade" id="videoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close ms-auto me-0" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body p-2 p-md-3">
                <div class="ratio ratio-16x9">
                    <video id="modalVideoPlayer" controls playsinline preload="metadata">
                        مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                    </video>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
