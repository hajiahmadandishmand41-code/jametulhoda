<?php
/** Persistent editorial homepage; legacy query routes are kept intact. */
$metaTitleOverride = 'مدرسه جامعه‌الهدی | اخبار و مقالات علوم اسلامی';
$pageTitle = 'مدرسه جامعه‌الهدی';
$pageDesc = 'پایگاه علمی مدرسه جامعه‌الهدی؛ تازه‌ترین اخبار مدرسه، مقالات و پژوهش‌های علوم اسلامی.';
require_once __DIR__ . '/config/config.php';

// Resolve the query route before conditionally loading authentication.
$__p = (isset($_GET['p']) && is_string($_GET['p'])) ? trim($_GET['p']) : '';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
if (($legacyTarget = jhd_legacy_listing_target($__p)) !== null) {
    $legacyParams = $_GET; unset($legacyParams['p']);
    header('Location: ' . url($legacyTarget, $legacyParams), true, 301);
    exit;
}


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
            . '</div></main></body></html>';         exit;
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

// Persistent, data-driven editorial homepage. Introduction remains on /about only.
$homeSettings = jhd_homepage_settings();
$layout = $homeSettings['layout'];
uasort($layout, static fn($a, $b) => $a['order'] <=> $b['order']);
// آستانهٔ «پربازدید» از تنظیمات مدیریت می‌آید؛ هرگز عدد ساختگی نیست.
$popularMinViews = jhd_popular_min_views();
$popular = [];
if (!empty($homeSettings['sections']['popular'])) {
    $popularCfg = $homeSettings['layout']['popular'];
    $popular = getPosts(['limit' => $popularCfg['limit'], 'sort' => 'popular', 'min_views' => $popularMinViews, 'cat' => $popularCfg['category']]);
}
// وقتی «اطلاعیه‌ها» بخش مستقل خودش را دارد، بخش اخبار فقط خبر می‌گیرد تا
// یک مطلب دو بار در صفحهٔ اصلی تکرار نشود.
$announcementsOn = !empty($homeSettings['sections']['announcements']);
require_once __DIR__ . '/includes/header.php';
?>
<div class="jhd-homepage jhd-homepage--editorial" id="home-content" data-density="<?= sanitize($homeSettings['density']) ?>" data-thumb="<?= sanitize($homeSettings['thumb']) ?>">
<div class="container jhd-home-titlebar"><h1 class="h3"><?= sanitize($siteName) ?>؛ پایگاه علمی و خبری</h1></div>
<?php
$shown = false;
foreach ($layout as $key => $section) {
    if ($key === 'topics') continue; // بخش عمومی «موضوعات علمی» از خروجی عمومی حذف شد.
    if (empty($homeSettings['sections'][$key])) continue;
    $rows = []; $lead = null;
    $opts = ['limit' => $section['limit'], 'sort' => 'newest'];
    if ($section['category']) $opts['cat'] = $section['category'];
    switch ($key) {
        case 'feature': $lead = jhd_home_feature($homeSettings); break;
        case 'popular':
            // Popular is always ordered by the actual counter, regardless of editor input.
            $rows = getPosts(array_replace($opts, ['sort' => 'popular', 'min_views' => $popularMinViews])); break;
        case 'latest': $rows = getPosts($opts + ['exclude_ids' => array_column($popular, 'id')]); break;
        case 'news': $rows = getPosts($opts + ['types' => $announcementsOn ? ['news'] : ['news', 'announcement']]); break;
        case 'announcements': $rows = getPosts($opts + ['type' => 'announcement']); break;
        case 'writing': $rows = getPosts($opts + ['type' => 'article']); break;
        case 'research': $rows = getPosts($opts + ['type' => 'research']); break;
        case 'books': $rows = getBooks(['limit' => $section['limit']]); break;
        case 'lessons': $rows = getLessons(['limit' => $section['limit']]); break;
        case 'topics': $rows = jhd_home_topics($section['limit']); break;
        case 'media': $rows = getLatestMediaItems($section['limit']); break;
    }
    if (!$lead && !$rows) continue;
    $shown = true;
    if (in_array($key, ['popular','latest','news','announcements','writing','research'], true)) jhd_preload_post_images($rows);
    /*
     * حالت چیدمان هر بخش از پنل مدیریت می‌آید:
     *   auto → ردیف فشردهٔ افقی در موبایل، کاشی کوچک دوستونی از تبلت به بالا؛
     *   rows → همیشه ردیف افقی تمام‌عرض (کم‌ارتفاع‌ترین حالت)؛
     *   grid → کارت بزرگ‌تر شبکه‌ای (چیدمان پیشین).
     * در همهٔ حالت‌ها تصویر کوچک یک طرف و عنوان طرف دیگر است.
     */
    $mode = (string)($section['mode'] ?? 'auto');
    $cardOpts = [];
    if ($mode !== 'grid') $cardOpts['compact'] = true;
    if ($mode === 'auto') $cardOpts['col'] = 'col-12 col-md-6';
    elseif ($mode === 'rows') $cardOpts['col'] = 'col-12';
    ?>
    <section class="jhd-section" data-home-section="<?= sanitize($key) ?>" aria-labelledby="home-<?= sanitize($key) ?>-heading">
      <div class="container">
      <?php /* فهرست «همهٔ موضوعات» از پیش به آرشیو مقالات هدایت می‌شود؛ پس
             برای بخش موضوعات پیوند «مشاهدهٔ همه» نمی‌گذاریم — هر کاشی مستقیماً
             به هاب همان موضوع می‌رود. */ ?>
      <?php $archive = ['news' => 'news', 'announcements' => 'announcements', 'writing' => 'articles', 'research' => 'articles', 'books' => 'books', 'lessons' => 'lessons', 'media' => 'media'][$key] ?? null; ?>
      <?= jhd_section_head(['title' => $section['title'], 'title_id' => 'home-' . $key . '-heading',
          'url' => $archive ? url($archive, $key === 'research' ? ['type' => 'research'] : []) : '',
          'link' => $key === 'news' ? 'همهٔ اخبار' : ($key === 'announcements' ? 'همهٔ اطلاعیه‌ها' : 'مشاهدهٔ همه')]) ?>
      <?php if ($lead): ?>
        <?= jhd_lead_story($lead) ?>
      <?php else: ?>
        <div class="row <?= $mode === 'grid' ? 'g-3' : 'g-2' ?>">
        <?php foreach ($rows as $row) {
            if ($key === 'books') echo renderBookCard($row, $cardOpts);
            elseif ($key === 'lessons') echo renderLessonCard($row, $cardOpts);
            elseif ($key === 'topics') echo renderTopicCard($row, $cardOpts + ['minimal' => true]);
            elseif ($key === 'media') echo renderMediaCard([
                'kind' => $row['media_kind'], 'id' => $row['media_id'], 'title' => $row['title'],
                'slug' => $row['slug'], 'target' => $row['target'], 'post_type' => $row['post_type'],
                'published_at' => $row['published_at'], 'thumbnail' => $row['featured_image'],
            ], $cardOpts);
            else echo renderPostCard($row, $cardOpts);
        } ?>
        </div>
      <?php endif; ?>
      </div>
    </section>
<?php }
if (!$shown) echo '<div class="container py-5"><p role="status">' . (jhd_db_ready() ? 'هنوز محتوای منتشرشده‌ای برای نمایش وجود ندارد.' : 'مطالب سایت موقتاً در دسترس نیستند.') . '</p></div>';
?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
