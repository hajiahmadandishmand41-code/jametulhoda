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

// صفحهٔ اصلی فقط اخبار و مقاله‌ها را نمایش می‌دهد؛ سایر آرشیوها از مسیرهای اصلی خود باقی‌اند.
$jhdPublicDbReady = jhd_db_ready();
$db = jhd_db();
$GLOBALS['JHD_PUBLIC_DB_READY'] = $jhdPublicDbReady;

$newsItems = [];
$writingRows = [];

if ($jhdPublicDbReady) {
    // همان نشست عمومی موجود حفظ می‌شود تا وضعیت ورود در پوستهٔ سایت درست بماند.
    startPublicSession();
    try {
        $newsItems = getPosts([
            'types' => ['news', 'announcement'],
            'limit' => 5,
            'sort' => 'newest',
        ]);
        $writingRows = getPosts([
            'types' => ['article', 'research'],
            'limit' => 5,
            'sort' => 'newest',
        ]);
    } catch (Throwable) {
        // اگر آرشیو هنوز آماده نباشد، صفحهٔ عمومی بدون خطای PHP رندر می‌شود.
        $newsItems = [];
        $writingRows = [];
    }
}

$homeHasAnyContent = !empty($newsItems) || !empty($writingRows);

require_once __DIR__ . '/includes/header.php';
?>

<div class="jhd-homepage jhd-homepage--editorial">
    <section class="jhd-hero" aria-labelledby="home-brand-title">
        <div class="container">
            <div class="jhd-home-intro">
                <div class="jhd-hero-copy">
                    <p class="jhd-hero-kicker">پایگاه علمی، آموزشی و پژوهشی</p>
                    <h1 id="home-brand-title"><?= sanitize($siteName) ?></h1>
                    <?php if (trim((string)$siteSlogan) !== ''): ?>
                    <p class="jhd-hero-slogan"><?= sanitize($siteSlogan) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php if (!$homeHasAnyContent): ?>
    <section class="jhd-section jhd-home-empty">
        <div class="container">
            <p role="status">
                <?= $jhdPublicDbReady
                    ? 'هنوز خبر یا مقاله‌ای برای نمایش منتشر نشده است.'
                    : 'مطالب سایت موقتاً در دسترس نیستند. لطفاً بعداً دوباره مراجعه کنید.' ?>
            </p>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($newsItems): ?>
    <section class="jhd-section jhd-home-editorial-section" aria-labelledby="home-news-heading">
        <div class="container jhd-home-editorial-container">
            <?= jhd_section_head([
                'eyebrow' => 'تازه‌های مدرسه',
                'title' => 'اخبار مدرسه',
                'title_id' => 'home-news-heading',
                'icon' => 'bi-newspaper',
                'url' => url('news'),
                'link' => 'همهٔ خبرها',
            ]) ?>
            <?= jhd_news_list($newsItems, ['hide_type' => true]) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($writingRows): ?>
    <section class="jhd-section jhd-home-editorial-section" aria-labelledby="home-articles-heading">
        <div class="container jhd-home-editorial-container">
            <?= jhd_section_head([
                'eyebrow' => 'مطالعه و اندیشه',
                'title' => 'مقالات و پژوهش‌ها',
                'title_id' => 'home-articles-heading',
                'icon' => 'bi-journal-text',
                'url' => url('articles'),
                'link' => 'همهٔ مقاله‌ها',
            ]) ?>
            <?= jhd_news_list($writingRows, ['hide_type' => true]) ?>
        </div>
    </section>
    <?php endif; ?>
</div><!-- /.jhd-homepage -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
