<?php
/**
 * index.php — صفحهٔ اصلی پایگاه «جامعة‌الهدی»: پنج هاب، یک هویت
 *
 * ساختار بازچینی‌شدهٔ ۱۴۰۵: صفحه از «ده ستونِ هم‌شکلِ فهرست مطلب» به پنج هاب
 * هدفمند تبدیل شده است — دروس، کتابخانه، نوشتارها، رسانه و اخبار/مطلب شاخص —
 * که هرکدام درِ همان آرشیو مستقلی است که در ناوبری و فوتر باقی است. فهرست‌های
 * مجزای گزارش‌ها، رویدادها، پرسش‌وپاسخ و سخنرانی‌ها از صفحهٔ اول حذف شد؛
 * محتوایشان در `/reports`، `/events`، `/qa` و `/speeches` بدون تغییر خدمت
 * می‌کند و پیوندهایشان از ناوبری «بیشتر»، فوتر و sitemap زنده است.
 *
 * سه قانون این صفحه است:
 *   ۱) هیچ رکوردی دو بار نمایش داده نمی‌شود ($homeUsedPosts + dedupe).
 *   ۲) هیچ تصویر ساختگی تولید نمی‌شود؛ بدون رسانه، کادر جایگزین متنی می‌آید.
 *   ۳) هیچ کوئری بی‌مصرف اجرا نمی‌شود؛ شمارش‌ها از همان داده‌های خوانده‌شده
 *      ساخته می‌شوند، نه با COUNT(*) جداگانه.
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

/*
 * دادهٔ صفحهٔ اصلی در همین یک بلوک خوانده می‌شود و هر رکورد پس از پذیرش
 * «رزرو» می‌گردد؛ بنابراین یک مطلب نه دو بار به‌عنوان شاخص و خبر می‌آید و نه
 * میان دو هاب تکرار می‌شود. $homeUsedPosts هر آنچه نمایش داده شده را نگه
 * می‌دارد تا `jhd_preload_post_topics()` یک‌باره و بدون کوئری تکراری (N+1)
 * موضوعات را بار کند.
 */
$homeUsedPosts = [];
$featurePost = null;
$newsItems = [];
$sidePicks = [];
$collections = [];
$featuredLessons = [];
$latestBooks = [];
$writingRows = [];
$writingCards = [];
$mediaItems = [];
$featuredTopics = [];
$specialBanner = null;

if ($jhdPublicDbReady) {
    // درخواست‌های ناشناس نشست نمی‌سازند (startPublicSession)؛ همان جایی که
    // اتصال دوم، دو CREATE TABLE و قفل ردیف نشست پیش از این هزینه داشت.
    startPublicSession();

    if (!empty($homeSections['feature'])) {
        $featurePost = $homeConfig['hero_post_id'] > 0
            ? (getPostsByIds([$homeConfig['hero_post_id']])[0] ?? null)
            : null;
        if (!$featurePost) {
            $featurePost = getPosts(['featured' => 1, 'limit' => 1])[0]
                ?? getPosts(['type' => 'news', 'limit' => 1])[0]
                ?? getPosts(['type' => 'article', 'limit' => 1])[0]
                ?? getPosts(['limit' => 1])[0]
                ?? null;
        }
        if ($featurePost) $homeUsedPosts[] = $featurePost;

        $newsCandidates = getPosts(['types' => ['news', 'announcement'], 'limit' => 12, 'sort' => 'newest']);
        $newsItems = jhd_homepage_unique_posts($newsCandidates, $homeUsedPosts, 5);

        // «برگزیدهٔ سردبیر» فقط تیکِ واقعاً خوردهٔ مدیر است، نه هر مطلب تازه.
        $pickCandidates = array_values(array_filter(
            getPosts(['featured' => 1, 'limit' => 8]),
            static fn(array $post): bool => $featurePost === null || (int)$post['id'] !== (int)$featurePost['id']
        ));
        $sidePicks = jhd_homepage_unique_posts($pickCandidates, $homeUsedPosts, 3);
    }

    if (!empty($homeSections['lessons'])) {
        $collections = jhd_home_lesson_collections(4);
        try {
            $stmt = $db->prepare(
                "SELECT l.*, c.title AS collection_title, c.slug AS collection_slug
                 FROM lessons l
                 LEFT JOIN lesson_collections c ON c.id = l.collection_id
                 WHERE l.status = 'published' AND l.is_featured = 1
                 ORDER BY l.sort_order ASC, l.id DESC LIMIT 3"
            );
            $stmt->execute();
            $featuredLessons = $stmt->fetchAll();
        } catch (Throwable) {
            $featuredLessons = [];
        }
    }

    if (!empty($homeSections['books'])) {
        // قفسهٔ منتخب: نخست کتاب‌های شاخص و در صورت کمبود، تازه‌ترین‌ها — تا
        // هاب کتابخانه هیچ‌گاه تک‌کارتِ تنها روی صفحه نماند. حذف تکراری بر
        // پایهٔ شناسه، چون هر دو کوئری یک رکورد را هم می‌بینند.
        $bookCandidates = array_merge(getBooks(['featured' => 1, 'limit' => 4]), getBooks(['limit' => 6]));
        $seenBooks = [];
        foreach ($bookCandidates as $bookRow) {
            $bookId = (int)($bookRow['id'] ?? 0);
            if ($bookId < 1 || isset($seenBooks[$bookId])) continue;
            $seenBooks[$bookId] = true;
            $latestBooks[] = $bookRow;
            if (count($latestBooks) >= 4) break;
        }
    }

    if (!empty($homeSections['writing'])) {
        $writingCandidates = jhd_homepage_unique_posts(
            getPosts(['types' => ['article', 'research'], 'limit' => 10, 'sort' => 'newest']),
            $homeUsedPosts,
            6
        );
        $writingRows = array_slice($writingCandidates, 0, 4);
        $writingCards = array_slice($writingCandidates, 4, 2);
    }

    if (!empty($homeSections['media'])) {
        $mediaItems = array_merge(jhd_home_media('video', 2), jhd_home_media('audio', 2));
    }

    if (!empty($homeSections['topics'])) {
        try {
            // فقط موضوعات فعالی که دست‌کم یک مطلب منتشرشده دارند؛ پیوند
            // به موضوع خالی، پیوند مرده است.
            $stmt = $db->query(
                "SELECT t.*, COUNT(DISTINCT p.id) AS post_count
                 FROM topics t
                 LEFT JOIN post_topics pt ON pt.topic_id = t.id
                 LEFT JOIN posts p ON p.id = pt.post_id AND p.status = 'published'
                 WHERE t.is_active = 1
                 GROUP BY t.id
                 HAVING COUNT(DISTINCT p.id) > 0
                 ORDER BY t.is_featured DESC, t.sort_order ASC, post_count DESC
                 LIMIT 8"
            );
            $featuredTopics = $stmt->fetchAll();
        } catch (Throwable) {
            $featuredTopics = [];
        }
    }

    $specialBanner = getActiveBanner();
}

jhd_preload_post_topics(array_merge(
    $featurePost ? [$featurePost] : [],
    $newsItems,
    $sidePicks,
    $writingRows,
    $writingCards
));

/*
 * پنج هاب، پنج درِ ورود. شمارش‌ها فقط از همان داده‌ای ساخته می‌شود که در بالا
 * خوانده شد؛ برای نمایش هیچ کوئری COUNT(*) اضافه‌ای اجرا نمی‌شود.
 */
$heroTiles = [];
if (!empty($homeSections['lessons'])) {
    $lessonMeta = $collections
        ? number_format(count($collections)) . ' دورهٔ فعال'
        : 'درس‌های فقه، اصول، عقاید و زبان عربی';
    $heroTiles[] = ['href' => url('lessons'), 'title' => 'دروس و دوره‌ها', 'meta' => $lessonMeta, 'icon' => 'bi-mortarboard'];
}
if (!empty($homeSections['books'])) {
    $heroTiles[] = ['href' => url('books'), 'title' => 'کتابخانهٔ دیجیتال', 'meta' => $latestBooks
        ? number_format(count($latestBooks)) . ' کتاب منتخبِ تازه'
        : 'متون، شروح و رساله‌ها با متن خوانا', 'icon' => 'bi-book'];
}
if (!empty($homeSections['writing'])) {
    $heroTiles[] = ['href' => url('articles'), 'title' => 'نوشتارها و پژوهش', 'meta' => 'مقاله، پژوهش و پاسخ به پرسش‌ها', 'icon' => 'bi-pencil-square'];
}
if (!empty($homeSections['media'])) {
    $heroTiles[] = ['href' => url('media'), 'title' => 'صوت و تصویر', 'meta' => 'سخنرانی، درس‌گفتار و مستند', 'icon' => 'bi-play-btn'];
}
$heroTiles[] = ['href' => url('news'), 'title' => 'اخبار مدرسه', 'meta' => 'جشن‌ها، امتحانات و اطلاعیه‌ها', 'icon' => 'bi-megaphone'];

$homeHasAnyContent = $featurePost || $newsItems || $sidePicks || $collections || $featuredLessons
    || $latestBooks || $writingRows || $mediaItems || $featuredTopics;

require_once __DIR__ . '/includes/header.php';
?>

<!-- ─── . تابلوی اصلی: هویت، تصویر درست و پنج هاب ───────────────────────── -->
<section class="jhd-hero" aria-labelledby="home-brand-title">
    <div class="container">
        <div class="jhd-hero-grid">
            <div class="jhd-hero-copy">
                <p class="jhd-hero-kicker"><i class="bi bi-mosque" aria-hidden="true"></i>پایگاه رسمی علمی، آموزشی و پژوهشی</p>
                <h1 id="home-brand-title">
                    <?= sanitize($siteName) ?>
                    <span class="jhd-hero-display" lang="ar">«طَلَبُ الْعِلْمِ فَرِيضَةٌ عَلَی کُلِّ مُسْلِمٍ»</span>
                </h1>
                <p class="jhd-hero-slogan"><?= sanitize($siteSlogan) ?></p>
                <div class="jhd-hero-actions">
                    <a class="btn btn-primary" href="<?= sanitize(url('lessons')) ?>">
                        <i class="bi bi-mortarboard me-1" aria-hidden="true"></i>آغاز یادگیری
                    </a>
                    <a class="btn btn-outline-primary" href="<?= sanitize(url('books')) ?>">کتابخانه</a>
                    <a class="btn btn-outline-primary" href="<?= sanitize(url('about')) ?>">دربارهٔ مدرسه</a>
                </div>
            </div>

            <figure class="jhd-hero-media">
                <?= jhd_picture(
                    'img/hero/hero-hawza',
                    [640, 960, 1280],
                    'صحن و رواق مدرسهٔ علمیه با کاشی‌کاری فیروزه‌ای و قفسه‌های کتاب',
                    ['eager' => true, 'ratio' => 1.6, 'sizes' => '(min-width: 992px) 46vw, 100vw']
                ) ?>
                <span class="jhd-hero-media__tag"><i class="bi bi-geo-alt" aria-hidden="true"></i><?= sanitize(SITE_ADDRESS) ?></span>
                <figcaption>
                    <i class="bi bi-camera-reels" aria-hidden="true"></i>
                    <span>صحنِ مدرسه؛ جایی که درس، کتاب و بحث کنار هم نشسته‌اند</span>
                </figcaption>
            </figure>
        </div>

        <nav class="jhd-hub-rail jhd-hub-rail--grid" aria-label="بخش‌های اصلی پایگاه">
            <?php foreach ($heroTiles as $tile): ?>
            <?= jhd_hub_tile((string)$tile['href'], (string)$tile['title'], (string)$tile['meta'], (string)$tile['icon']) ?>
            <?php endforeach; ?>
        </nav>
    </div>
</section>

<?php if (!empty($specialBanner)): ?>
<section class="jhd-section--tight jhd-banner-section" aria-label="اعلان ویژه">
    <div class="container">
        <div class="jhd-banner-strip">
            <span class="jhd-banner-strip__label"><i class="bi bi-megaphone" aria-hidden="true"></i>اعلان ویژه</span>
            <span class="jhd-banner-strip__text">
                <strong><?= sanitize($specialBanner['title']) ?></strong>
                <?php if (!empty($specialBanner['content'])): ?>
                <span class="d-none d-md-inline">— <?= sanitize(excerpt($specialBanner['content'], 110)) ?></span>
                <?php endif; ?>
            </span>
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
            <a class="jhd-banner-strip__cta" href="<?= sanitize($bannerHref) ?>"<?= $bannerExternal ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                <?= sanitize($specialBanner['link_text'] ?: 'مشاهدهٔ جزئیات') ?>
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$homeHasAnyContent): ?>
<section class="jhd-section">
    <div class="container">
        <?= renderEmptyState(
            'bi-journal-bookmark',
            $jhdPublicDbReady
                ? 'هنوز محتوایی منتشر نشده است؛ به‌محض انتشار نخستین مطلب، همین صفحه پر می‌شود.'
                : 'برای راه‌اندازی پایگاه، دیتابیس را پیکربندی و `php bin/migrate.php` را اجرا کنید.',
            url('about'),
            'آشنایی با جامعة‌الهدی'
        ) ?>
    </div>
</section>
<?php endif; ?>

<?php if ($featurePost || $sidePicks || $newsItems): ?>
<!-- ─── ۲. شاخصِ سردبیر و تازۀ خبرها ─────────────────────────────────────── -->
<section class="jhd-section" aria-labelledby="home-feature-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'انتخاب سردبیر',
            'title' => 'مطلب شاخص و تازه‌های پایگاه',
            'title_id' => 'home-feature-heading',
            'icon' => 'bi-stars',
            'url' => url('news'),
            'link' => 'همهٔ اخبار',
        ]) ?>
        <div class="row g-4">
            <?php if ($featurePost): ?>
            <div class="col-12 <?= $sidePicks || $newsItems ? 'col-lg-8' : 'col-12' ?>">
                <?= renderPostCard($featurePost, [
                    'featured' => true,
                    'col' => 'col-12',
                    'cta' => 'مطالعهٔ کامل',
                    'excerpt' => 150,
                    'eager' => true,
                ]) ?>
            </div>
            <?php endif; ?>
            <?php if ($sidePicks || $newsItems): ?>
            <aside class="col-12 col-lg-4" aria-label="برگزیده‌ها و اخبار">
                <div class="jhd-side-card h-100">
                    <h3><i class="bi bi-bookmark-star" aria-hidden="true"></i> برگزیده و خبرها</h3>
                    <?php if ($sidePicks): ?>
                    <div class="jhd-home-picks-list">
                        <?php foreach ($sidePicks as $pick): ?><?= renderMiniItem($pick, ['excerpt' => 0]) ?><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($newsItems): ?>
                    <div class="<?= $sidePicks ? 'mt-3 pt-2 border-top' : '' ?>">
                        <?= jhd_news_list(array_slice($newsItems, 0, 3), ['hide_type' => true]) ?>
                    </div>
                    <?php endif; ?>
                    <a class="jhd-section-link mt-2" href="<?= sanitize(url('news')) ?>">
                        آرشیو اخبار <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    </a>
                </div>
            </aside>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['lessons']) && ($collections || $featuredLessons)): ?>
<!-- ─── ۳. هاب دروس — مرکز ثقل یک مدرسه ──────────────────────────────────── -->
<section class="jhd-section jhd-section--paper" aria-labelledby="home-lessons-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'مدرسه',
            'title' => 'دروس و دوره‌ها',
            'title_id' => 'home-lessons-heading',
            'icon' => 'bi-mortarboard',
            'url' => url('lessons'),
            'link' => 'همهٔ دوره‌ها و درس‌ها',
        ]) ?>
        <div class="row g-4">
            <?php if ($collections): ?>
            <div class="col-12 col-lg-5">
                <div class="jhd-hub-grid jhd-hub-grid--one">
                    <?php foreach ($collections as $collection): ?>
                    <?= jhd_hub_tile(
                        collectionUrl($collection),
                        (string)$collection['title'],
                        number_format((int)($collection['lesson_count'] ?? 0)) . ' درس منتشرشده',
                        'bi-collection',
                        ['wide' => true]
                    ) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($featuredLessons): ?>
            <div class="col-12 <?= $collections ? 'col-lg-7' : 'col-12' ?>">
                <?= jhd_grid_open() ?>
                <?php foreach ($featuredLessons as $lesson): ?>
                <?= renderLessonCard($lesson, [
                    'col' => $collections ? 'col-12' : 'col-12 col-sm-6 col-xl-4',
                    'excerpt' => 78,
                    'minimal' => true,
                ]) ?>
                <?php endforeach; ?>
                <?= jhd_grid_close() ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['books']) && $latestBooks): ?>
<!-- ─── ۴. هاب کتابخانه ──────────────────────────────────────────────────── -->
<section class="jhd-section" aria-labelledby="home-books-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'قفسهٔ دیجیتال',
            'title' => 'کتاب‌های منتخب',
            'title_id' => 'home-books-heading',
            'icon' => 'bi-book-half',
            'url' => url('books'),
            'link' => 'ورود به کتابخانه',
        ]) ?>
        <?= jhd_grid_open() ?>
        <?php foreach ($latestBooks as $book): ?>
        <?= renderBookCard($book, ['col' => JHD_CARD_COL, 'excerpt' => 84]) ?>
        <?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['writing']) && ($writingRows || $writingCards)): ?>
<!-- ─── ۵. هاب نوشتارها: مقاله، پژوهش و پرسش‌وپاسخ ───────────────────────── -->
<section class="jhd-section jhd-section--paper" aria-labelledby="home-writing-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'تولید علم',
            'title' => 'نوشتارها و پژوهش‌ها',
            'title_id' => 'home-writing-heading',
            'icon' => 'bi-pencil-square',
            'url' => url('articles'),
            'link' => 'آرشیو مقالات و پژوهش‌ها',
        ]) ?>
        <div class="row g-4">
            <?php if ($writingRows): ?>
            <div class="col-12 <?= $writingCards ? 'col-lg-7' : 'col-12' ?>">
                <?= jhd_card_list($writingRows, static fn(array $post, int $i): string => renderEditorialRow($post, [
                    'excerpt' => 108,
                    'index' => $i + 1,
                    'cta' => 'مطالعهٔ نوشتار',
                    'eager' => $i === 0,
                ]), ['grid_class' => 'jhd-home-writing-list']) ?>
            </div>
            <?php endif; ?>
            <?php if ($writingCards): ?>
            <div class="col-12 <?= $writingRows ? 'col-lg-5' : 'col-12' ?>">
                <?= jhd_grid_open() ?>
                <?php foreach ($writingCards as $card): ?>
                <?= renderPostCard($card, ['col' => 'col-12', 'excerpt' => 96, 'cta' => 'مطالعهٔ کامل']) ?>
                <?php endforeach; ?>
                <?= jhd_grid_close() ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['media']) && $mediaItems): ?>
<!-- ─── ۶. هاب رسانه: صوت و تصویر ────────────────────────────────────────── -->
<section class="jhd-section" aria-labelledby="home-media-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'کتابخانهٔ چندرسانه‌ای',
            'title' => 'صوت و تصویر',
            'title_id' => 'home-media-heading',
            'icon' => 'bi-play-btn',
            'url' => url('media'),
            'link' => 'نگارخانهٔ کامل',
        ]) ?>
        <?= jhd_grid_open() ?>
        <?php foreach ($mediaItems as $media): ?>
        <?= renderMediaCard($media, ['col' => JHD_CARD_COL, 'cta' => $media['kind'] === 'audio' ? 'پخش صوت' : 'تماشا و دانلود']) ?>
        <?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['topics']) && $featuredTopics): ?>
<!-- ─── ۷. اطلس موضوعی — پل میان همهٔ هاب‌ها ──────────────────────────────── -->
<section class="jhd-section jhd-section--tight" aria-labelledby="home-topics-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'اطلس موضوعی',
            'title' => 'محورهای علمی و معارف',
            'title_id' => 'home-topics-heading',
            'icon' => 'bi-diagram-3',
            'url' => url('topics'),
            'link' => 'اطلس کامل موضوعات',
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

<?php if ($homeHasAnyContent): ?>
<?= jhd_cta_band([
    'title' => 'یک قدم تا کلاسِ درس',
    'text' => 'برای شرکت در دروس، دریافت مجوز کتابخانه یا پرسش دربارهٔ فعالیت‌های علمی مدرسه با ما در تماس باشید.',
    'actions' => [
        ['label' => 'ارتباط با ما', 'url' => url('contact'), 'style' => 'primary'],
        ['label' => 'دربارهٔ مدرسه و اساتید', 'url' => url('about'), 'style' => 'outline-light'],
    ],
]) ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
