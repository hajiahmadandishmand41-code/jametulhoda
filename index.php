<?php
/**
 * index.php — صفحهٔ نخست تحریریه‌ای جامعة‌الهدی.
 *
 * صفحهٔ اصلی عمداً به دو فهرست متنی محدود است: خبرهای مدرسه و مقالات/پژوهش‌ها.
 * آرشیوهای دیگر، محتوای رسانه‌ای و ابزارهای مدیریت در بخش‌های داخلی باقی‌اند؛
 * در صفحهٔ اول کارت، نوار دسترسی سریع یا فهرست موضوعات ساخته نمی‌شود.
 */
/*
 * Homepage identity.
 *
 * The brand leads the homepage title — it is the site's primary identity, so
 * it comes first rather than being pushed behind a descriptive phrase. The
 * remainder states plainly what the school is and where it is; nothing else.
 */
$metaTitleOverride = 'مدرسه جامعه‌الهدی | اخبار و مقالات علوم اسلامی';
$pageTitle = 'مدرسه جامعه‌الهدی';
$pageDesc = 'پایگاه علمی مدرسه جامعه‌الهدی؛ تازه‌ترین اخبار مدرسه، مقالات و پژوهش‌های علوم اسلامی.';
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

/*
 * ─── صفحهٔ نخست تحریریه ───────────────────────────────────────────────────
 *
 * چیدمان این صفحه کاملاً از تنظیمات ذخیره‌شدهٔ پنل مدیریت پیروی می‌کند
 * (jhd_homepage_settings()). هر تیک در «تنظیمات › چیدمان صفحهٔ اصلی» یک
 * بخش را روشن یا خاموش می‌کند و «مطلب شاخص» تعیین می‌کند کدام خبر در
 * بالای صفحه، بزرگ و با تصویر نمایش داده شود. اگر تنظیمی وجود نداشته
 * باشد، همهٔ بخش‌ها روشن‌اند.
 */
$jhdPublicDbReady = jhd_db_ready();
$db = jhd_db();
$GLOBALS['JHD_PUBLIC_DB_READY'] = $jhdPublicDbReady;

$homeSettings = jhd_homepage_settings();
$homeSections = $homeSettings['sections'];
$homeSectionOn = static fn(string $key): bool => !empty($homeSections[$key]);

$homeLead = null;
$homeSecondary = [];
$homeHubs = [];
$homeMedia = [];
$homeCounts = ['news' => 0, 'writing' => 0, 'books' => 0, 'lessons' => 0, 'media' => 0];

if ($jhdPublicDbReady) {
    startPublicSession();
    try {
        /*
         * خبر اصلی: ابتدا «مطلب شاخص» انتخاب‌شده در پنل مدیریت، سپس
         * برگزیدهٔ سردبیر، و در پایان تازه‌ترین خبر منتشرشده.
         */
        $heroId = (int)($homeSettings['hero_post_id'] ?? 0);
        if ($heroId > 0) {
            $homeLead = getPublishedPostById($heroId);
        }
        if ($homeLead === null) {
            $featured = getPosts(['featured' => true, 'limit' => 1, 'sort' => 'newest']);
            if (!$featured) $featured = getPosts(['limit' => 1, 'sort' => 'newest']);
            $homeLead = $featured[0] ?? null;
        }
        if ($homeLead !== null) {
            $homeLead['url'] = postUrl($homeLead, (string)($homeLead['post_type'] ?? 'news'));
            $leadId = (int)($homeLead['id'] ?? 0);
            $homeSecondary = array_values(array_filter(
                getPosts(['limit' => 6, 'sort' => 'newest']),
                static fn(array $row): bool => (int)($row['id'] ?? 0) !== $leadId
            ));
            array_splice($homeSecondary, 3);
        }

        // شمارش برای «ریل هاب» و آمار معرفی مدرسه
        $homeCounts['news']    = countPosts(['types' => ['news', 'announcement']]);
        $homeCounts['writing'] = countPosts(['types' => ['article', 'research', 'report']]);
        $homeCounts['books']   = (int)(getDB()->query("SELECT COUNT(*) FROM books WHERE status='published'")->fetchColumn() ?: 0);
        $homeCounts['lessons'] = (int)(getDB()->query("SELECT COUNT(*) FROM lessons WHERE status='published'")->fetchColumn() ?: 0);
        $homeCounts['media']   = (int)(getDB()->query("SELECT COUNT(*) FROM media_files WHERE kind IN ('audio','video')")->fetchColumn() ?: 0);

        /* هاب نوشتارها — مقاله، پژوهش و گزارش در یک شبکهٔ یکدست */
        if ($homeSectionOn('writing')) {
            $rows = getPosts(['types' => ['article', 'research', 'report'], 'limit' => 3, 'sort' => 'newest']);
            if ($rows) {
                $homeHubs['writing'] = [
                    'title' => 'نوشتارها و پژوهش‌ها',
                    'eyebrow' => 'اندیشه و مطالعه',
                    'icon' => 'bi-journal-richtext',
                    'url' => url('articles'),
                    'link' => 'آرشیو نوشتارها',
                    'rows' => $rows,
                    'kind' => 'posts',
                ];
            }
        }

        /* هاب کتابخانهٔ دیجیتال */
        if ($homeSectionOn('books')) {
            $books = getBooks(['limit' => 4]);
            if ($books) {
                $homeHubs['books'] = [
                    'title' => 'کتابخانهٔ دیجیتال',
                    'eyebrow' => 'خواندن و مطالعه',
                    'icon' => 'bi-book',
                    'url' => url('books'),
                    'link' => 'همهٔ کتاب‌ها',
                    'rows' => $books,
                    'kind' => 'books',
                ];
            }
        }

        /* هاب دروس و دوره‌ها */
        if ($homeSectionOn('lessons')) {
            $lessons = getLessons(['limit' => 4]);
            if ($lessons) {
                $homeHubs['lessons'] = [
                    'title' => 'دروس و دوره‌ها',
                    'eyebrow' => 'آموزش حوزوی',
                    'icon' => 'bi-mortarboard',
                    'url' => url('lessons'),
                    'link' => 'همهٔ درس‌ها',
                    'rows' => $lessons,
                    'kind' => 'lessons',
                ];
            }
        }

        /* هاب صوت و تصویر */
        if ($homeSectionOn('media')) {
            $homeMedia = getLatestMediaItems(4);
        }
    } catch (Throwable $e) {
        error_log('Homepage feeds unavailable: ' . $e->getMessage());
        $homeLead = null;
        $homeSecondary = [];
        $homeHubs = [];
        $homeMedia = [];
    }
}
$homeHasAnyContent = $homeLead !== null || $homeSecondary !== [] || $homeHubs !== [] || $homeMedia !== [];

/* ریل پنج هاب — کوتاه‌ترین مسیر از خانه به هر بخش */
$homeHubRail = [];
if ($homeSectionOn('hero')) {
    $hubCount = static function(int $count): string {
        return $count > 0 ? jhd_persian_digits($count) . ' مورد' : '';
    };
    $homeHubRail = array_values(array_filter([
        $homeSectionOn('feature') ? ['href' => url('news'), 'title' => 'اخبار مدرسه', 'meta' => trim('تازه‌ترین خبرها و اطلاعیه‌ها · ' . $hubCount($homeCounts['news'])), 'icon' => 'bi-newspaper'] : null,
        $homeSectionOn('writing')  ? ['href' => url('articles'), 'title' => 'نوشتارها', 'meta' => trim('مقاله، پژوهش و گزارش · ' . $hubCount($homeCounts['writing'])), 'icon' => 'bi-journal-richtext'] : null,
        $homeSectionOn('books')    ? ['href' => url('books'), 'title' => 'کتابخانه', 'meta' => trim('کتاب‌های دیجیتال · ' . $hubCount($homeCounts['books'])), 'icon' => 'bi-book'] : null,
        $homeSectionOn('lessons')  ? ['href' => url('lessons'), 'title' => 'دروس', 'meta' => trim('دوره‌های حوزوی · ' . $hubCount($homeCounts['lessons'])), 'icon' => 'bi-mortarboard'] : null,
        $homeSectionOn('media')    ? ['href' => url('media'), 'title' => 'رسانه‌ها', 'meta' => trim('صوت‌ها و ویدیوها · ' . $hubCount($homeCounts['media'])), 'icon' => 'bi-play-circle'] : null,
    ], static fn($row) => is_array($row)));
}

/* متن معرفی مدرسه — از تنظیمات مدیر، وگرنه متن پیش‌فرضِ کد */
$homeAboutText = trim((string)getSetting('about_short', ''));
$homeAboutStats = array_values(array_filter([
    ['label' => 'سال‌های فعالیت', 'value' => 'از ۱۳۹۳'],
    ['label' => 'طلاب و دانش‌پژوهان', 'value' => 'صنف‌های علمی'],
    ['label' => 'برنامه‌های آموزشی', 'value' => 'دوره‌های حوزوی'],
    ['label' => 'انتشارات', 'value' => 'کتابخانهٔ دیجیتال'],
], static fn($row) => is_array($row)));

require_once __DIR__ . '/includes/header.php';
?>

<div class="jhd-homepage jhd-homepage--editorial">

    <?php if ($homeSectionOn('hero')): ?>
    <section class="jhd-hero" aria-labelledby="home-brand-title">
        <div class="container">
            <div class="jhd-home-intro">
                <div class="jhd-hero-copy">
                    <p class="jhd-hero-kicker">پایگاه علمی، آموزشی و پژوهشی</p>
                    <h1 id="home-brand-title"><?= sanitize($siteName) ?></h1>
                    <?php if (trim((string)$siteSlogan) !== ''): ?>
                    <p class="jhd-hero-slogan"><?= sanitize($siteSlogan) ?></p>
                    <?php endif; ?>
                    <div class="jhd-hero-actions">
                        <a class="btn btn-primary" href="<?= url('about') ?>">دربارهٔ مدرسه</a>
                        <a class="btn btn-outline-primary" href="<?= url('contact') ?>">تماس با ما</a>
                        <a class="btn btn-outline-secondary" href="<?= url('search') ?>"><i class="bi bi-search" aria-hidden="true"></i> جست‌وجو</a>
                    </div>
                </div>
            </div>

            <?php if ($homeHubRail !== []): ?>
            <nav class="jhd-hub-rail" aria-label="محورهای اصلی پایگاه">
                <div class="jhd-hub-rail--grid">
                    <?php foreach ($homeHubRail as $hub): ?>
                    <?= jhd_hub_tile($hub['href'], $hub['title'], $hub['meta'], $hub['icon']) ?>
                    <?php endforeach; ?>
                </div>
            </nav>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($homeSectionOn('hero')): ?>
    <?= jhd_about_band([
        'eyebrow' => 'معرفی مدرسه',
        'title' => SITE_FOUNDER !== '' ? 'مدرسهٔ جامعه‌الهدی به رهبری ' . SITE_FOUNDER : (string)$siteName,
        'text' => $homeAboutText,
        'stats' => $homeAboutStats,
        'cta' => 'دربارهٔ جامعه‌الهدی بیشتر بخوانید',
        'cta_url' => url('about'),
    ]) ?>
    <?php endif; ?>

    <?php if (!$homeHasAnyContent): ?>
    <section class="jhd-section jhd-home-empty"><div class="container">
        <p role="status"><?= $jhdPublicDbReady ? 'هنوز محتوای منتشرشده‌ای برای نمایش وجود ندارد.' : 'مطالب سایت موقتاً در دسترس نیستند. لطفاً بعداً مراجعه کنید.' ?></p>
    </div></section>
    <?php endif; ?>

    <?php if ($homeSectionOn('feature') && ($homeLead !== null || $homeSecondary !== [])): ?>
    <section class="jhd-section jhd-home-editorial-section" aria-labelledby="home-lead-heading">
        <div class="container jhd-home-editorial-container">
            <?= jhd_section_head([
                'eyebrow' => 'تازه‌ترین',
                'title' => 'خبرهای مدرسه',
                'title_id' => 'home-lead-heading',
                'icon' => 'bi-newspaper',
                'url' => url('news'),
                'link' => 'همهٔ اخبار',
            ]) ?>
            <?php if ($homeLead !== null): ?>
            <?= jhd_lead_story($homeLead) ?>
            <?php endif; ?>
            <?= jhd_secondary_stories($homeSecondary) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php foreach ($homeHubs as $hubKey => $hub): ?>
    <section class="jhd-section jhd-home-editorial-section" aria-labelledby="home-<?= sanitize($hubKey) ?>-heading">
        <div class="container jhd-home-editorial-container">
            <?= jhd_section_head([
                'eyebrow' => $hub['eyebrow'],
                'title' => $hub['title'],
                'title_id' => 'home-' . $hubKey . '-heading',
                'icon' => $hub['icon'],
                'url' => $hub['url'],
                'link' => $hub['link'],
            ]) ?>
            <?php if ($hub['kind'] === 'books'): ?>
            <div class="row g-3 jhd-home-card-grid">
                <?php foreach ($hub['rows'] as $book): ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <?= renderBookCard($book) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($hub['kind'] === 'lessons'): ?>
            <div class="row g-3 jhd-home-card-grid">
                <?php foreach ($hub['rows'] as $lesson): ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <?= renderLessonCard($lesson) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="row g-3 jhd-home-card-grid">
                <?php foreach ($hub['rows'] as $row): ?>
                <div class="col-12 col-sm-6 col-lg-4">
                    <?= renderPostCard($row) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <?php if ($homeSectionOn('media') && $homeMedia !== []): ?>
    <section class="jhd-section jhd-home-editorial-section" aria-labelledby="home-media-heading">
        <div class="container jhd-home-editorial-container">
            <?= jhd_section_head([
                'eyebrow' => 'نگارخانه',
                'title' => 'صوت و تصویر',
                'title_id' => 'home-media-heading',
                'icon' => 'bi-play-circle',
                'url' => url('media'),
                'link' => 'همهٔ رسانه‌ها',
            ]) ?>
            <div class="row g-3 jhd-home-card-grid">
                <?php foreach ($homeMedia as $item): ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <?= renderMediaCard([
                        'kind'         => (string)($item['media_kind'] ?? 'video'),
                        'id'           => (int)($item['media_id'] ?? 0),
                        'title'        => (string)($item['title'] ?? ''),
                        'post_slug'    => (string)($item['slug'] ?? ''),
                        'post_type'    => (string)($item['post_type'] ?? ''),
                        'published_at' => (string)($item['published_at'] ?? ''),
                        'thumbnail'    => (string)($item['featured_image'] ?? ''),
                    ]) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

</div><!-- /.jhd-homepage -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
