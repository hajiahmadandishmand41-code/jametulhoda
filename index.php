<?php
/**
 * index.php — صفحهٔ اصلی پورتال علمی جامعة‌الهدی
 *
 * A compact editorial homepage: one real feature, a deduplicated latest feed,
 * a few published topics, and direct links to the existing public archives.
 * Detail and archive routes remain independent of the homepage layout.
 */
/*
 * Homepage identity.
 *
 * The brand leads the homepage title — it is the site's primary identity, so
 * it comes first rather than being pushed behind a descriptive phrase. The
 * remainder states plainly what the school is and where it is; nothing else.
 */
$metaTitleOverride = 'مدرسه جامعه‌الهدی | علوم اسلامی، آموزش دینی و پژوهش';
$pageTitle = 'مدرسه جامعه‌الهدی';
$pageDesc = 'مدرسه جامعه‌الهدی؛ پایگاه علمی، آموزشی و پژوهشی علوم اسلامی با اخبار، مقالات، دروس، کتاب‌ها و پژوهش‌های دینی.';
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
            . '<body class="jhd-public-site"><main class="jhd-section" style="min-height:100vh;display:grid;place-items:center;padding-block:3rem">'
            . '<div class="jhd-empty-state" style="width:min(100%,520px);padding:2rem 1.25rem">'
            . '<i class="bi bi-compass" aria-hidden="true" style="font-size:1.4rem;width:52px;height:52px"></i>'
            . '<div style="font-size:2.7rem;font-weight:900;line-height:1;color:var(--jhd-green)">۴۰۴</div>'
            . '<h4 style="font-size:1.15rem">صفحه مورد نظر یافت نشد</h4>'
            . '<p>نشانی وارد شده معتبر نیست یا صفحه جابه‌جا شده است.</p>'
            . '<a href="' . htmlspecialchars(url(), ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary">بازگشت به صفحه اصلی</a>'
            . '</div></main></body></html>';        exit;
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
$homeConfig = jhd_homepage_settings();
$homeSections = $homeConfig['sections'];

if ($jhdPublicDbReady) {
    // Anonymous GETs get no session at all (see startPublicSession): that is
    // where the second PostgreSQL connection, the two CREATE TABLE statements
    // and the `SELECT … FOR UPDATE` session row lock used to come from.
    startPublicSession();

    // The home page uses a small, deduplicated editorial feed. Each displayed
    // story is reserved before the next section is built, so the same record
    // cannot be repeated as both a feature and a recent item.
    $heroPost = null;
    if (!empty($homeSections['hero'])) {
        $heroPost = $homeConfig['hero_post_id'] > 0
            ? (getPostsByIds([$homeConfig['hero_post_id']])[0] ?? null)
            : null;
        if (!$heroPost) {
            $heroPost = getPosts(['featured' => 1, 'limit' => 1])[0]
                ?? getPosts(['type' => 'news', 'limit' => 1])[0]
                ?? getPosts(['type' => 'article', 'limit' => 1])[0]
                ?? getPosts(['limit' => 1])[0]
                ?? null;
        }
    }
    $heroId = (int)($heroPost['id'] ?? 0);
    $heroTopic = null;
    $acceptedHomepagePosts = $heroPost ? [$heroPost] : [];

    // Only explicitly featured published records are labelled as editor picks.
    $featuredSide = [];
    if (!empty($homeSections['editor_picks'])) {
        $featuredCandidates = array_values(array_filter(
            getPosts(['featured' => 1, 'limit' => 8]),
            static fn(array $post): bool => (int)$post['id'] !== $heroId
        ));
        $featuredSide = jhd_homepage_unique_posts($featuredCandidates, $acceptedHomepagePosts, 3);
    }

    // Existing homepage settings now control which published post types enter
    // one chronological feed, instead of creating a long stack of duplicate
    // category-specific sections.
    $homePostTypesBySection = [
        'news' => ['news'],
        'articles' => ['article'],
        'reports' => ['report'],
        'research' => ['research'],
        'events' => ['event', 'program', 'religious', 'announcement'],
        'speeches' => ['speech'],
        'qa' => ['qa'],
    ];
    $latestTypes = [];
    foreach ($homePostTypesBySection as $sectionKey => $postTypes) {
        if (!empty($homeSections[$sectionKey])) $latestTypes = array_merge($latestTypes, $postTypes);
    }
    $latest = [];
    if (!empty($homeSections['latest']) && $latestTypes) {
        $latestCandidates = getPosts([
            'types' => array_values(array_unique($latestTypes)),
            'limit' => 24,
            'sort' => 'newest',
        ]);
        $latest = jhd_homepage_unique_posts($latestCandidates, $acceptedHomepagePosts, 4);
    }

    $featuredTopics = [];
    if (!empty($homeSections['topics'])) {
        try {
            // Counts and topic links come only from active topics with published posts.
            $stmt = $db->query("SELECT t.*, COUNT(DISTINCT p.id) AS post_count FROM topics t LEFT JOIN post_topics pt ON pt.topic_id=t.id LEFT JOIN posts p ON p.id=pt.post_id AND p.status='published' WHERE t.is_active=1 GROUP BY t.id HAVING COUNT(DISTINCT p.id)>0 ORDER BY t.is_featured DESC, t.sort_order ASC, post_count DESC LIMIT 6");
            $featuredTopics = $stmt->fetchAll();
        } catch (Throwable) {
            $featuredTopics = [];
        }
    }

    $latestBooks = !empty($homeSections['books']) ? getBooks(['featured' => 1, 'limit' => 2]) : [];
    $latestLessons = [];
    if (!empty($homeSections['lessons'])) {
        try {
            $stmt = $db->prepare("SELECT l.*, c.title AS collection_title, c.slug AS collection_slug FROM lessons l LEFT JOIN lesson_collections c ON c.id=l.collection_id WHERE l.status='published' AND l.is_featured=1 ORDER BY l.sort_order ASC, l.id DESC LIMIT 2");
            $stmt->execute();
            $latestLessons = $stmt->fetchAll();
        } catch (Throwable) {
            $latestLessons = [];
        }
    }

    $specialBanner = getActiveBanner();
    // NOTE: five COUNT(*) queries (posts/topics/books/lessons/media) used to run
    // here into $homeCounts/$homeContentTotal. Neither variable is rendered
    // anywhere on this page, so they were five full table scans per homepage
    // request with no consumer. Removed.
} else {
    // The public shell and archive links stay useful even before the database is ready.
    $heroPost = null; $heroTopic = null; $featuredSide = []; $latest = [];
    $featuredTopics = []; $latestBooks = []; $latestLessons = [];
    $specialBanner = null;
}
jhd_preload_post_topics(array_merge(
    $heroPost ? [$heroPost] : [], $featuredSide, $latest
));
if ($heroPost) $heroTopic = jhd_card_topics($heroPost, 1)[0] ?? null;
require_once __DIR__ . '/includes/header.php';
$homeHasAnyContent =
    (!empty($homeSections['hero']) && $heroPost)
    || (!empty($homeSections['editor_picks']) && $featuredSide)
    || (!empty($homeSections['latest']) && $latest)
    || (!empty($homeSections['topics']) && $featuredTopics)
    || (!empty($homeSections['books']) && $latestBooks)
    || (!empty($homeSections['lessons']) && $latestLessons);
?>

<!-- ─── ۱. تابلوی فشردهٔ برند ─────────────────────────────────────────────── -->
<section class="jhd-brand-board" aria-labelledby="home-brand-title">
    <div class="container">
        <div class="jhd-home-hero">
            <div class="jhd-home-hero-copy">
                <p class="jhd-board-official">پایگاه رسمی علمی، آموزشی و پژوهشی</p>
                <h1 id="home-brand-title"><?= sanitize($siteName) ?></h1>
                <p class="jhd-board-slogan">مرکزی برای آموزش، پژوهش و گسترش معارف قرآنی و اهل‌بیت</p>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($specialBanner)): ?>
<section class="jhd-section--tight jhd-section--paper" aria-label="اعلان ویژه">
    <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-megaphone ms-1" aria-hidden="true"></i>اعلان ویژه</span>
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
            <?= sanitize($specialBanner['link_text'] ?: 'مشاهده جزئیات') ?> <i class="bi bi-arrow-left ms-1" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php
$homeQuickLinks = [
    ['route' => 'news', 'label' => 'اخبار', 'icon' => 'bi-newspaper'],
    ['route' => 'articles', 'label' => 'مقالات و پژوهش', 'icon' => 'bi-journal-text'],
    ['route' => 'reports', 'label' => 'گزارش‌ها', 'icon' => 'bi-card-text'],
    ['route' => 'topics', 'label' => 'موضوعات', 'icon' => 'bi-diagram-3'],
    ['route' => 'books', 'label' => 'کتابخانه', 'icon' => 'bi-book'],
    ['route' => 'lessons', 'label' => 'دروس', 'icon' => 'bi-mortarboard'],
    ['route' => 'media', 'label' => 'صوت و تصویر', 'icon' => 'bi-play-circle'],
];
?>
<section class="jhd-section--tight jhd-home-quick-access" aria-labelledby="home-quick-links-title">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'پایگاه جامعة‌الهدی',
            'title' => 'دسترسی سریع به بخش‌ها',
            'title_id' => 'home-quick-links-title',
            'url' => url('search'),
            'link' => 'جستجو در آرشیو',
        ]) ?>
        <nav class="jhd-home-quick-links" aria-label="بخش‌های اصلی پایگاه">
            <?php foreach ($homeQuickLinks as $link): ?>
            <a class="jhd-home-quick-link" href="<?= sanitize(url($link['route'])) ?>">
                <i class="bi <?= sanitize($link['icon']) ?>" aria-hidden="true"></i>
                <span><?= sanitize($link['label']) ?></span>
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>

<?php if (!$homeHasAnyContent): ?>
<section class="jhd-section">
    <div class="container">
        <?= renderEmptyState('bi-journal-bookmark', 'هنوز محتوایی در این پایگاه منتشر نشده است؛ پس از انتشار مطالب، تازه‌ترین موارد در همین صفحه نمایش داده می‌شوند.', url('about'), 'آشنایی با جامعة‌الهدی') ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['hero']) && $heroPost): $showFeaturedSide = !empty($homeSections['editor_picks']) && !empty($featuredSide); ?>
<section class="jhd-section jhd-home-featured" aria-labelledby="home-featured-heading">
    <div class="container">
        <?= jhd_section_head(['title' => 'مطلب شاخص', 'title_id' => 'home-featured-heading', 'icon' => 'bi-stars']) ?>
        <div class="row g-4">
            <div class="<?= $showFeaturedSide ? 'col-lg-8' : 'col-12' ?>">
                <?= renderPostCard($heroPost, ['featured' => true, 'col' => 'col-12', 'cta' => 'مطالعهٔ کامل', 'excerpt' => 145, 'eager' => true, 'no_gallery' => true, 'topics' => $heroTopic ? [$heroTopic] : []]) ?>
            </div>
            <?php if ($showFeaturedSide): ?>
            <aside class="col-lg-4" aria-label="برگزیده‌های سردبیر">
                <div class="jhd-side-card h-100">
                    <h3><i class="bi bi-bookmark-star" aria-hidden="true"></i> برگزیدهٔ سردبیر</h3>
                    <div class="jhd-home-picks-list">
                        <?php foreach ($featuredSide as $side): ?><?= renderMiniItem($side, ['excerpt' => 0]) ?><?php endforeach; ?>
                    </div>
                </div>
            </aside>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php elseif (!empty($homeSections['editor_picks']) && $featuredSide): ?>
<section class="jhd-section jhd-section--paper" aria-labelledby="home-picks-heading">
    <div class="container">
        <?= jhd_section_head(['title' => 'برگزیدهٔ سردبیر', 'title_id' => 'home-picks-heading', 'icon' => 'bi-bookmark-star']) ?>
        <?= jhd_grid_open('jhd-home-picks-grid') ?>
            <?php foreach ($featuredSide as $side): ?><?= renderMiniItem($side, ['col' => 'col-12 col-sm-6 col-lg-4', 'excerpt' => 0]) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['latest']) && $latest): ?>
<section class="jhd-section" aria-labelledby="home-latest-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'تازه‌های پایگاه',
            'title' => 'تازه‌ترین مطالب',
            'title_id' => 'home-latest-heading',
            'icon' => 'bi-clock-history',
            'url' => url('search'),
            'link' => 'جستجو در آرشیو',
        ]) ?>
        <?= jhd_grid_open('jhd-home-latest-grid') ?>
            <?php foreach ($latest as $item): ?><?= renderPostCard($item, ['col' => 'col-12 col-sm-6 col-xl-3', 'excerpt' => 88, 'cta' => 'مشاهده مطلب']) ?><?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['topics']) && $featuredTopics): ?>
<section class="jhd-section jhd-section--paper" aria-labelledby="home-topics-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'اطلس موضوعی',
            'title' => 'محورهای علمی و معارف',
            'title_id' => 'home-topics-heading',
            'icon' => 'bi-diagram-3',
            'url' => url('topics'),
            'link' => 'همهٔ موضوعات',
        ]) ?>
        <nav class="jhd-home-topic-links" aria-label="موضوعات پرمحتوا">
            <?php foreach ($featuredTopics as $topic): ?>
            <a class="jhd-home-topic-link" href="<?= sanitize(topicUrl($topic)) ?>">
                <span><?= sanitize((string)$topic['name']) ?></span>
                <small><?= number_format((int)($topic['post_count'] ?? 0)) ?> مطلب</small>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>
<?php endif; ?>

<?php if ((!empty($homeSections['books']) && $latestBooks) || (!empty($homeSections['lessons']) && $latestLessons)): ?>
<section class="jhd-section" aria-labelledby="home-learning-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'منابع و آموزش',
            'title' => 'کتابخانه و درس‌های منتخب',
            'title_id' => 'home-learning-heading',
            'icon' => 'bi-mortarboard',
            'url' => url('books'),
            'link' => 'ورود به کتابخانه',
        ]) ?>
        <?= jhd_grid_open('jhd-home-collections-grid') ?>
            <?php if (!empty($homeSections['books'])): foreach ($latestBooks as $book): ?><?= renderBookCard($book, ['col' => 'col-12 col-sm-6 col-xl-3', 'excerpt' => 72]) ?><?php endforeach; endif; ?>
            <?php if (!empty($homeSections['lessons'])): foreach ($latestLessons as $lesson): ?><?= renderLessonCard($lesson, ['minimal' => true, 'col' => 'col-12 col-sm-6 col-xl-3', 'excerpt' => 72]) ?><?php endforeach; endif; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
