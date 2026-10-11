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
$collections = [];
$featuredLessons = [];
$latestBooks = [];
$writingRows = [];
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
    }

    if (!empty($homeSections['lessons'])) {
        $collections = jhd_home_lesson_collections(3);
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
        $bookCandidates = array_merge(getBooks(['featured' => 1, 'limit' => 3]), getBooks(['limit' => 6]));
        $seenBooks = [];
        foreach ($bookCandidates as $bookRow) {
            $bookId = (int)($bookRow['id'] ?? 0);
            if ($bookId < 1 || isset($seenBooks[$bookId])) continue;
            $seenBooks[$bookId] = true;
            $latestBooks[] = $bookRow;
            if (count($latestBooks) >= 3) break;
        }
    }

    if (!empty($homeSections['writing'])) {
        $writingRows = jhd_homepage_unique_posts(
            getPosts(['types' => ['article', 'research'], 'limit' => 8, 'sort' => 'newest']),
            $homeUsedPosts,
            3
        );
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
                 LIMIT 6"
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
    $writingRows,
));

/* دسته‌های بالای خانه فقط نام و نشانه دارند؛ شرح و شمارش محتوا در صفحهٔ نخست لازم نیست. */
$heroTiles = [];
if (!empty($homeSections['lessons'])) {
    $heroTiles[] = ['href' => url('lessons'), 'title' => 'دروس و دوره‌ها', 'icon' => 'bi-mortarboard'];
}
if (!empty($homeSections['books'])) {
    $heroTiles[] = ['href' => url('books'), 'title' => 'کتابخانهٔ دیجیتال', 'icon' => 'bi-book'];
}
if (!empty($homeSections['writing'])) {
    $heroTiles[] = ['href' => url('articles'), 'title' => 'نوشتارها و پژوهش', 'icon' => 'bi-pencil-square'];
}
if (!empty($homeSections['media'])) {
    $heroTiles[] = ['href' => url('media'), 'title' => 'صوت و تصویر', 'icon' => 'bi-play-btn'];
}
$heroTiles[] = ['href' => url('news'), 'title' => 'اخبار مدرسه', 'icon' => 'bi-megaphone'];

$homeLatestPosts = array_slice(array_merge($featurePost ? [$featurePost] : [], $newsItems), 0, 6);
$homeHasAnyContent = $featurePost || $newsItems || $collections || $featuredLessons
    || $latestBooks || $writingRows || $mediaItems || $featuredTopics;

require_once __DIR__ . '/includes/header.php';
?>

<!-- صفحهٔ نخست: معرفی کوتاه و دسترسی مینیمال به بخش‌ها -->
<div class="jhd-homepage">
<section class="jhd-hero" aria-labelledby="home-brand-title">
    <div class="container">
        <div class="jhd-home-intro">
            <div class="jhd-hero-copy">
                <p class="jhd-hero-kicker"><i class="bi bi-mortarboard" aria-hidden="true"></i>پایگاه علمی، آموزشی و پژوهشی</p>
                <h1 id="home-brand-title"><?= sanitize($siteName) ?></h1>
                <p class="jhd-hero-slogan"><?= sanitize($siteSlogan) ?></p>
            </div>
            <div class="jhd-hero-actions">
                <a class="btn btn-primary" href="<?= sanitize(url('lessons')) ?>">
                    <i class="bi bi-mortarboard ms-1" aria-hidden="true"></i>آغاز یادگیری
                </a>
                <a class="btn btn-outline-primary" href="<?= sanitize(url('books')) ?>">
                    <i class="bi bi-book ms-1" aria-hidden="true"></i>کتابخانهٔ دیجیتال
                </a>
            </div>
        </div>

        <nav class="jhd-home-categories" aria-label="دسته‌بندی‌های اصلی">
            <?php foreach ($heroTiles as $tile): ?>
            <a class="jhd-home-category" href="<?= sanitize((string)$tile['href']) ?>">
                <i class="bi <?= sanitize((string)$tile['icon']) ?>" aria-hidden="true"></i>
                <span><?= sanitize((string)$tile['title']) ?></span>
                <i class="bi bi-arrow-left jhd-home-category__arrow" aria-hidden="true"></i>
            </a>
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

<?php if ($homeLatestPosts): ?>
<section class="jhd-section jhd-home-section" aria-labelledby="home-feature-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'تازه‌های مدرسه',
            'title' => 'خبرها و مطالب تازه',
            'title_id' => 'home-feature-heading',
            'icon' => 'bi-newspaper',
            'url' => url('news'),
            'link' => 'همهٔ خبرها',
        ]) ?>
        <?= jhd_grid_open('jhd-home-card-grid') ?>
        <?php foreach ($homeLatestPosts as $index => $post): ?>
        <?= renderPostCard($post, [
            'col' => JHD_CARD_COL,
            'excerpt' => 108,
            'cta' => 'ادامهٔ مطلب',
            'eager' => $index === 0,
        ]) ?>
        <?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['lessons']) && ($collections || $featuredLessons)): ?>
<section class="jhd-section jhd-section--paper jhd-home-section" aria-labelledby="home-lessons-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'آموزش',
            'title' => 'دروس و دوره‌ها',
            'title_id' => 'home-lessons-heading',
            'icon' => 'bi-mortarboard',
            'url' => url('lessons'),
            'link' => 'همهٔ دوره‌ها',
        ]) ?>
        <?= jhd_grid_open('jhd-home-card-grid') ?>
        <?php if ($collections): ?>
            <?php foreach (array_slice($collections, 0, 3) as $collection): ?>
            <?= jhd_card([
                'type' => 'lesson',
                'url' => collectionUrl($collection),
                'title' => (string)($collection['title'] ?? ''),
                'summary' => excerpt((string)($collection['description'] ?? ''), 108),
                'image' => (string)($collection['cover_image'] ?? ''),
                'image_alt' => (string)($collection['title'] ?? ''),
                'icon' => 'bi-collection',
                'badge' => 'دوره',
                'meta' => [['icon' => 'bi-journal-check', 'text' => number_format((int)($collection['lesson_count'] ?? 0)) . ' درس']],
                'cta' => 'مشاهدهٔ دوره',
            ], ['col' => JHD_CARD_COL]) ?>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach (array_slice($featuredLessons, 0, 3) as $lesson): ?>
            <?= renderLessonCard($lesson, [
                'col' => JHD_CARD_COL,
                'excerpt' => 108,
                'cta' => 'مشاهدهٔ درس',
            ]) ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['books']) && $latestBooks): ?>
<section class="jhd-section jhd-home-section" aria-labelledby="home-books-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'کتابخانه',
            'title' => 'کتاب‌های منتخب',
            'title_id' => 'home-books-heading',
            'icon' => 'bi-book-half',
            'url' => url('books'),
            'link' => 'ورود به کتابخانه',
        ]) ?>
        <?= jhd_grid_open('jhd-home-card-grid') ?>
        <?php foreach (array_slice($latestBooks, 0, 3) as $index => $book): ?>
        <?= renderBookCard($book, [
            'col' => JHD_CARD_COL,
            'excerpt' => 108,
            'eager' => $index === 0,
        ]) ?>
        <?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['writing']) && $writingRows): ?>
<section class="jhd-section jhd-section--paper jhd-home-section" aria-labelledby="home-writing-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'تولید علم',
            'title' => 'نوشتارها و پژوهش‌ها',
            'title_id' => 'home-writing-heading',
            'icon' => 'bi-pencil-square',
            'url' => url('articles'),
            'link' => 'آرشیو نوشتارها',
        ]) ?>
        <?= jhd_grid_open('jhd-home-card-grid') ?>
        <?php foreach ($writingRows as $index => $post): ?>
        <?= renderPostCard($post, [
            'col' => JHD_CARD_COL,
            'excerpt' => 108,
            'cta' => 'مطالعهٔ نوشتار',
            'eager' => $index === 0,
        ]) ?>
        <?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['media']) && $mediaItems): ?>
<section class="jhd-section jhd-home-section" aria-labelledby="home-media-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'کتابخانهٔ چندرسانه‌ای',
            'title' => 'صوت و تصویر',
            'title_id' => 'home-media-heading',
            'icon' => 'bi-play-btn',
            'url' => url('media'),
            'link' => 'همهٔ رسانه‌ها',
        ]) ?>
        <?= jhd_grid_open('jhd-home-card-grid') ?>
        <?php foreach (array_slice($mediaItems, 0, 3) as $media): ?>
        <?= renderMediaCard($media, [
            'col' => JHD_CARD_COL,
            'cta' => ($media['kind'] ?? '') === 'audio' ? 'گوش دادن' : 'تماشا و دریافت',
        ]) ?>
        <?php endforeach; ?>
        <?= jhd_grid_close() ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeSections['topics']) && $featuredTopics): ?>
<section class="jhd-section jhd-section--tight jhd-home-section" aria-labelledby="home-topics-heading">
    <div class="container">
        <?= jhd_section_head([
            'eyebrow' => 'دسته‌بندی‌ها',
            'title' => 'موضوعات علمی',
            'title_id' => 'home-topics-heading',
            'icon' => 'bi-diagram-3',
            'url' => url('topics'),
            'link' => 'همهٔ موضوعات',
        ]) ?>
        <nav class="jhd-home-topic-links" aria-label="موضوعات پرمحتوا">
            <?php foreach (array_slice($featuredTopics, 0, 6) as $topic): ?>
            <a class="jhd-home-topic-link" href="<?= sanitize(topicUrl($topic)) ?>">
                <span><?= sanitize((string)$topic['name']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>
<?php endif; ?>
</div><!-- /.jhd-homepage -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
