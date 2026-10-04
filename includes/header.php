<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/member-auth.php';

/**
 * HTML cache policy.
 *
 * Browsers still never reuse a page blindly (`max-age=0`): a redeploy or an
 * edit must reach the visitor on the next navigation. What changes here is the
 * *shared* cache. An anonymous GET of a public page is identical for every
 * visitor, so the Vercel edge may hold it for a short window and answer repeat
 * traffic without invoking PHP or PostgreSQL at all — this is what turns the
 * measured `x-vercel-cache: MISS` on every single homepage hit into a HIT.
 *
 * Nothing personalised is ever shared: the shared-cache policy is only applied
 * to a request that carried no cookie at all, started no session and emitted no
 * Set-Cookie. Anything else keeps `no-cache`. Admin pages set their own
 * `no-store` and are not touched here.
 *
 * The decision is taken at the END of the request, because a controller may
 * still start a session while rendering (a CSRF-protected form, a flash
 * message). Only a response that is still carrying exactly this default
 * `no-cache` is ever upgraded, so a page that deliberately set `no-store`
 * (the whole admin panel) keeps its own policy untouched.
 */
header('Cache-Control: no-cache');
if (!function_exists('jhd_register_public_cache_policy')) {
    function jhd_register_public_cache_policy(): void {
        static $registered = false;
        if ($registered) return;
        $registered = true;
        $seconds = (int)env_value('JHD_PUBLIC_CACHE_SECONDS', '60');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($seconds <= 0 || !in_array($method, ['GET', 'HEAD'], true)) return;
        register_shutdown_function(static function () use ($seconds): void {
            if (headers_sent()) return;
            if (http_response_code() !== 200) return;
            // A session was materialised, or a cookie is being issued/was sent:
            // the response is visitor-specific and must not enter a shared cache.
            if (session_status() === PHP_SESSION_ACTIVE) return;
            if (!empty($_COOKIE)) return;
            $current = '';
            foreach (headers_list() as $line) {
                if (stripos($line, 'Set-Cookie:') === 0) return;
                if (stripos($line, 'Cache-Control:') === 0) $current = trim(substr($line, 14));
            }
            if (strtolower($current) !== 'no-cache') return; // controller set its own policy
            /**
             * Browsers must always revalidate (a signed-in visitor must never
             * reuse the anonymous shell from their own cache).
             *
             * The shared cache is driven by `Vercel-CDN-Cache-Control`, which
             * Vercel consumes and does not forward to the browser.
             *
             * Measured reason for not using `Vary: Cookie` here: Vercel's CDN
             * refuses to cache any response whose Vary names Cookie — six
             * sequential GETs of "/" returned `x-vercel-cache: MISS` every
             * time with Vary set. This response is only produced for a request
             * that carried no cookie at all and started no session, so there
             * is nothing visitor-specific in it to leak.
             */
            header('Cache-Control: public, max-age=0, must-revalidate');
            header(sprintf(
                'Vercel-CDN-Cache-Control: public, s-maxage=%d, stale-while-revalidate=%d',
                $seconds,
                max($seconds * 10, 600)
            ));
            header(sprintf(
                'CDN-Cache-Control: public, s-maxage=%d, stale-while-revalidate=%d',
                $seconds,
                max($seconds * 10, 600)
            ));
        });
    }
}
jhd_register_public_cache_policy();

// The homepage is intentionally DB-optional before installation. Controllers
// other than index.php keep the normal authenticated/database behavior.
// Every controller (not just the homepage) shares one readiness check.
$jhdPublicDbReady = array_key_exists('JHD_PUBLIC_DB_READY', $GLOBALS)
    ? (bool)$GLOBALS['JHD_PUBLIC_DB_READY']
    : jhd_db_ready();
$GLOBALS['JHD_PUBLIC_DB_READY'] = $jhdPublicDbReady;
$jhdDbNotice = $jhdPublicDbReady ? '' : jhd_db_notice();

if ($jhdPublicDbReady) {
    startPublicSession();
    $siteName = getSetting('site_name', SITE_NAME);
    if ($siteName === 'مدرسه علمیه جامعه‌الهدی') $siteName = SITE_NAME;
    $siteSlogan = getSetting('site_slogan', SITE_SLOGAN);
    if ($siteSlogan === 'علم، معرفت و تهذیب در پرتو قرآن و عترت') $siteSlogan = SITE_SLOGAN;
    $siteLogo = getSetting('site_logo', 'assets/img/logo.png');
    $isAdminLoggedIn = isLoggedIn();
    $isMemberLoggedIn = isMemberLoggedIn();
    $navTopicTree = getTopicTree();
} else {
    $siteName = SITE_NAME;
    $siteSlogan = SITE_SLOGAN;
    $siteLogo = 'assets/img/logo.png';
    $isAdminLoggedIn = false;
    $isMemberLoggedIn = false;
    $navTopicTree = [];
}
if ($siteLogo === 'assets/img/logo.png') $siteLogo = 'assets/img/logo.png';

$currentPath = current_path();
$isLoggedIn = $isAdminLoggedIn; // backward compatible for existing templates

$metaTitle = !empty($pageTitle) ? $pageTitle . ' | ' . $siteName : $siteName . ' | ' . $siteSlogan;
$metaDesc = $pageDesc ?? $siteSlogan;
if (mb_strlen($metaDesc, 'UTF-8') > 160) {
    $metaDesc = mb_substr($metaDesc, 0, 157, 'UTF-8') . '...';
}

// Canonical URL — always the single public URL for the page, never a physical
// file (pages/topic.php, router.php, index.php internals). Detail pages set
// $canonicalOverride to a registry-generated URL; listings derive it from the
// resolved route. In Query mode the canonical is the ?p=… form; in Pretty mode
// the /path form — exactly one version, matching what url() generates.
$responseIs404 = http_response_code() === 404;
$canonical = '';
if (SITE_URL && !$responseIs404) {
    if (!empty($canonicalOverride)) {
        $canonical = jhd_absolute_url($canonicalOverride);
    } else {
        $routeName = isset($_SERVER['JHD_ROUTE_NAME']) ? (string)$_SERVER['JHD_ROUTE_NAME'] : '';
        if (JHD_PRETTY_URLS) {
            $routePath = $_SERVER['JHD_ROUTE_PATH'] ?? current_path();
            $canonical = jhd_absolute_url(ltrim(substr($routePath, strlen(BASE_PATH)), '/'));
        } else {
            $canonical = jhd_absolute_url(jhd_query_canonical($routeName));
        }
    }
}
// Search results and 404s stay out of the index (Query or Pretty spelling).
$authRoutes = ['login', 'register', 'logout', 'account', 'profile', 'password-change'];
$routeNameForRobots = isset($_SERVER['JHD_ROUTE_NAME']) ? (string)$_SERVER['JHD_ROUTE_NAME'] : (string)($_GET['p'] ?? '');
$authNoindex = in_array($routeNameForRobots, $authRoutes, true)
    || preg_match('~^/(login|register|logout|account|profile|password-change)(/|$)~', $currentPath);
$noindexSeo = !empty($noindexSeo)
    || $responseIs404
    || $authNoindex
    || $routeNameForRobots === 'search'
    || $currentPath === '/search';
$ogType = isset($post) || isset($book) || isset($lesson) ? 'article' : 'website';

// Helper for active navigation link
$isActiveNav = function (string $route) use ($currentPath): bool {
    if ($route === '/') return $currentPath === '/' || $currentPath === '';
    return str_starts_with($currentPath, '/' . trim($route, '/'));
};
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#103d2e">
<meta name="plyr-sprite" content="<?= asset('vendor/plyr.svg') ?>">
<?php
/**
 * CSRF meta tag.
 *
 * It is emitted only when a session already exists. Measured reason: no script
 * in this project reads `meta[name=csrf-token]` (grep over assets/js, admin,
 * includes, pages finds zero consumers), yet generateCsrfToken() force-starts
 * a database-backed session. That single tag was opening a SECOND PostgreSQL
 * connection, running two CREATE TABLE IF NOT EXISTS statements and taking a
 * `SELECT … FOR UPDATE` row lock on every anonymous page view.
 *
 * Forms keep using csrfField(), which still creates the session on demand, so
 * CSRF protection is unchanged.
 */
if ($jhdPublicDbReady && session_status() === PHP_SESSION_ACTIVE): ?><meta name="csrf-token" content="<?= sanitize(generateCsrfToken()) ?>"><?php endif; ?>
<title><?= sanitize($metaTitle) ?></title>
<meta name="description" content="<?= sanitize($metaDesc) ?>">
<?php if ($noindexSeo): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
<?php if ($canonical): ?>
<link rel="canonical" href="<?= sanitize($canonical) ?>">
<meta property="og:url" content="<?= sanitize($canonical) ?>">
<?php endif; ?>
<meta property="og:locale" content="fa_AF">
<meta property="og:type" content="<?= $ogType ?>">
<meta property="og:title" content="<?= sanitize($metaTitle) ?>">
<meta property="og:description" content="<?= sanitize($metaDesc) ?>">
<meta property="og:site_name" content="<?= sanitize($siteName) ?>">
<?php if (!empty($post['published_at'])): ?><meta property="article:published_time" content="<?= sanitize($post['published_at']) ?>"><?php endif; ?>
<?php if (!empty($post['author_name'])): ?><meta property="article:author" content="<?= sanitize($post['author_name']) ?>"><?php endif; ?>
<?php if (!empty($post['featured_image']) || !empty($book['cover_image']) || !empty($lesson['featured_image'])):
    $ogImg = imgUrl($post['featured_image'] ?? $book['cover_image'] ?? $lesson['featured_image'] ?? '');
    if ($ogImg): ?><meta property="og:image" content="<?= sanitize($ogImg) ?>"><?php endif;
endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= sanitize($metaTitle) ?>">
<meta name="twitter:description" content="<?= sanitize($metaDesc) ?>">
<?php if (!empty($ogImg)): ?><meta name="twitter:image" content="<?= sanitize($ogImg) ?>"><?php endif; ?>
<?php if (SITE_URL): ?>
<?php
$jhdEntityPath = current_path();
if ($jhdEntityPath === '/' || $jhdEntityPath === '/about') {
    $jhdOrgSameAs = [];
    foreach ([
        safeExternalUrl(getSetting('social_telegram')),
        safeExternalUrl(getSetting('social_youtube')),
        safeExternalUrl(getSetting('social_instagram')),
    ] as $jhdSameAsUrl) {
        if ($jhdSameAsUrl !== '') $jhdOrgSameAs[] = $jhdSameAsUrl;
    }

    $jhdOrganizationJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'EducationalOrganization',
        '@id' => rtrim(SITE_URL, '/') . '/#organization',
        'name' => 'مدرسه علمیه جامعه‌الهدی',
        'alternateName' => ['جامعة‌الهدی', 'مدرسه علمیه جامعه‌الهدی'],
        'url' => rtrim(SITE_URL, '/') . '/',
        'logo' => canonicalUrl('assets/img/logo.png'),
        'description' => 'مرکز علمی، آموزشی و پژوهشی علوم اسلامی در کابل، افغانستان؛ با تمرکز بر آموزش علوم اسلامی، تربیت طلاب، پژوهش دینی و ترویج فرهنگ قرآنی و اهل‌بیت (ع).',
        'areaServed' => ['@type' => 'Country', 'name' => 'Afghanistan'],
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => 'کابل',
            'addressCountry' => 'AF',
        ],
        'founder' => [
            '@type' => 'Person',
            'name' => 'آیت‌الله محمدحسین حلیمی',
        ],
        'knowsAbout' => [
            'فقه و اصول',
            'تفسیر قرآن',
            'حدیث شناسی',
            'کلام و فلسفه',
            'ادبیات عرب',
            'تاریخ اسلام',
        ],
    ];
    $jhdOrgEmail = getSetting('email', SITE_EMAIL);
    $jhdOrgPhone = getSetting('phone', SITE_PHONE);
    if (filter_var($jhdOrgEmail, FILTER_VALIDATE_EMAIL)) $jhdOrganizationJsonLd['email'] = 'mailto:' . $jhdOrgEmail;
    if ($jhdOrgPhone !== '') $jhdOrganizationJsonLd['telephone'] = $jhdOrgPhone;
    if ($jhdOrgSameAs) $jhdOrganizationJsonLd['sameAs'] = $jhdOrgSameAs;
}
?>
<?php if (!empty($jhdOrganizationJsonLd)): ?><script type="application/ld+json"><?= json_encode($jhdOrganizationJsonLd, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script><?php endif; ?>
<script type="application/ld+json"><?= json_encode(['@context'=>'https://schema.org','@type'=>'WebSite','name'=>$siteName,'url'=>SITE_URL,'potentialAction'=>['@type'=>'SearchAction','target'=> rtrim(SITE_URL,'/').'/search?q={search_term_string}','query-input'=>'required name=search_term_string']], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<?php if (!empty($breadcrumbsJsonLd)): ?><script type="application/ld+json"><?= $breadcrumbsJsonLd ?></script><?php endif; ?>
<?php if (!empty($articleJsonLd)): ?><script type="application/ld+json"><?= $articleJsonLd ?></script><?php endif; ?>
<?php if (!empty($bookJsonLd)): ?><script type="application/ld+json"><?= $bookJsonLd ?></script><?php endif; ?>
<?php if (!empty($mediaJsonLd)): ?><script type="application/ld+json"><?= $mediaJsonLd ?></script><?php endif; ?>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
<script src="<?= asset('js/theme.js') ?>"></script>
<link rel="preload" href="<?= asset('fonts/Vazirmatn-Regular.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= asset('fonts/Amiri-Bold.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('vendor/bootstrap.rtl.min.css') ?>">
<link rel="stylesheet" href="<?= asset('vendor/icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= asset('vendor/plyr.css') ?>">
<link rel="stylesheet" href="<?= asset('css/design-system.css') ?>">
<script src="<?= asset('js/interface.js') ?>" defer></script>
</head>
<body class="jhd-public-site<?= !empty($authNoindex) ? ' jhd-login-site' : '' ?>">
<a class="skip-link" href="#main-content">رفتن به محتوای اصلی</a>

<!-- نوار بسمله: امضای بصری یک پایگاه دینی (بسیار باریک) -->
<div class="bismillah-bar" aria-hidden="true">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</div>

<!-- Header اصلی: برند + جستجو + حساب + پوسته، در یک ردیف فشرده -->
<header class="jhd-header" role="banner">
    <div class="container jhd-header-row">
        <a class="jhd-brand" href="<?= url() ?>" aria-label="<?= sanitize($siteName) ?>، صفحه اصلی">
            <?php if ($siteLogo): ?><img src="<?= imgUrl($siteLogo) ?>" width="40" height="41" alt="نشان <?= sanitize($siteName) ?>"><?php endif; ?>
            <span>
                <strong><?= sanitize($siteName) ?></strong>
                <small>پایگاه رسمی علمی، آموزشی و پژوهشی</small>
            </span>
        </a>

        <form class="jhd-search d-none d-lg-flex" action="<?= formUrl('search') ?>" method="get" role="search">
            <?= formRouteFields('search') ?>
            <label class="visually-hidden" for="header-search">جستجو در مقالات، اخبار، کتاب‌ها و دروس</label>
            <input id="header-search" name="q" type="search" placeholder="جستجو در اخبار، مقالات، کتاب‌ها، دروس..." maxlength="200" value="<?= sanitize($_GET['q'] ?? '') ?>">
            <button type="submit" aria-label="جستجو"><i class="bi bi-search"></i></button>
        </form>

        <div class="jhd-header-actions">
            <a class="jhd-icon-btn d-lg-none" href="<?= url('search') ?>" aria-label="جستجو"><i class="bi bi-search"></i></a>
            <button class="jhd-icon-btn" data-theme-toggle aria-label="تغییر پوسته روشن و تیره" aria-pressed="false"><i class="bi bi-moon"></i></button>

            <div class="jhd-account-cluster d-none d-md-flex">
            <?php if ($isAdminLoggedIn): ?>
            <a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" href="<?= adminDashboardUrl() ?>">
                <i class="bi bi-speedometer2"></i><span>پنل مدیریت</span>
            </a>
            <?php elseif ($isMemberLoggedIn): ?>
            <a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" href="<?= accountUrl() ?>">
                <i class="bi bi-person-circle"></i><span>حساب من</span>
            </a>
            <?php else: ?>
            <a class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" href="<?= loginUrl() ?>">
                <i class="bi bi-box-arrow-in-left"></i><span>ورود</span>
            </a>
            <a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" href="<?= registerUrl() ?>">
                <span>ثبت‌نام</span>
            </a>
            <?php endif; ?>
            </div>
            <a class="jhd-icon-btn d-md-none" href="<?= $isAdminLoggedIn ? adminDashboardUrl() : ($isMemberLoggedIn ? accountUrl() : loginUrl()) ?>" aria-label="حساب کاربری"><i class="bi bi-person-circle"></i></a>

            <button type="button" id="menuToggle" class="jhd-icon-btn d-lg-none" aria-label="باز کردن منو" aria-expanded="false" aria-controls="siteDrawer"><i class="bi bi-list"></i></button>
        </div>
    </div>

    <!-- ناوبری اصلی: یک ردیف، از عرض متوسط به بالا -->
    <nav class="jhd-navbar-desktop d-none d-lg-block" aria-label="ناوبری اصلی">
        <div class="container">
            <ul class="jhd-nav-list mb-0">
                <li><a href="<?= url() ?>" class="jhd-nav-link <?= $isActiveNav('/') ? 'active' : '' ?>" <?= $isActiveNav('/') ? 'aria-current="page"' : '' ?>>خانه</a></li>
                <?= jhd_render_desktop_nav_item(['route'=>'news','label'=>'اخبار','types'=>['news']], $isActiveNav) ?>
                <?= jhd_render_desktop_nav_item(['route'=>'articles','label'=>'مقالات','types'=>['article']], $isActiveNav, ['article']) ?>
                <?= jhd_render_desktop_nav_item(['route'=>'research','label'=>'پژوهش','types'=>['research']], $isActiveNav) ?>
                <?= jhd_render_desktop_nav_item(['route'=>'books','label'=>'کتابخانه','types'=>[]], $isActiveNav, ['book']) ?>
                <?= jhd_render_desktop_nav_item(['route'=>'lessons','label'=>'دروس','types'=>[]], $isActiveNav, ['lesson']) ?>
                <?= jhd_render_desktop_nav_item(['route'=>'events','label'=>'رویدادها','types'=>['program','religious','announcement']], $isActiveNav, ['programs','announcements','religious-activities']) ?>
                <?= jhd_render_desktop_nav_item(['route'=>'media','label'=>'رسانه','types'=>[]], $isActiveNav, ['videos','audios']) ?>
                <li class="jhd-has-sub">
                    <a href="<?= url('topics') ?>" class="jhd-nav-link <?= $isActiveNav('topics') || $isActiveNav('topic') ? 'active' : '' ?>" <?= ($isActiveNav('topics') || $isActiveNav('topic')) ? 'aria-current="page"' : '' ?> aria-haspopup="true">موضوعات</a>
                    <?php if ($navTopicTree): ?>
                    <ul class="jhd-subnav" role="menu">
                        <?= jhd_render_topic_tree_nav($navTopicTree, 'desktop') ?>
                        <li class="jhd-subnav-all"><a href="<?= url('topics') ?>">اطلس کامل موضوعات</a></li>
                    </ul>
                    <?php endif; ?>
                </li>
                <li class="jhd-has-sub">
                    <button type="button" class="jhd-nav-link jhd-nav-toggle" aria-haspopup="true" aria-expanded="false">بیشتر <i class="bi bi-chevron-down"></i></button>
                    <ul class="jhd-subnav" role="menu">
                        <li><a href="<?= url('reports') ?>">گزارش‌ها</a></li>
                        <li><a href="<?= url('qa') ?>">پرسش و پاسخ</a></li>
                        <li><a href="<?= url('about') ?>">درباره ما</a></li>
                        <li><a href="<?= url('contact') ?>">تماس با ما</a></li>
                        <li><a href="<?= url('speeches') ?>">سخنرانی‌ها</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
</header>

<!-- پس‌زمینه نیمه‌شفاف منوی همبرگر -->
<div id="drawerOverlay" class="jhd-drawer-overlay" hidden></div>

<!-- منوی کشویی موبایل (Hamburger Drawer) -->
<aside id="siteDrawer" class="jhd-drawer" role="dialog" aria-modal="true" aria-label="منوی اصلی" aria-hidden="true" inert>
    <div class="jhd-drawer-head">
        <span><i class="bi bi-grid ms-2"></i> فهرست بخش‌ها</span>
        <button id="drawerClose" class="jhd-icon-btn" aria-label="بستن منو"><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="jhd-drawer-search">
        <form action="<?= formUrl('search') ?>" method="get" role="search">
            <?= formRouteFields('search') ?>
            <label class="visually-hidden" for="drawer-search">جستجو در محتوا</label>
            <input id="drawer-search" name="q" type="search" placeholder="جستجو در محتوا..." maxlength="200" value="<?= sanitize($_GET['q'] ?? '') ?>">
            <button type="submit" aria-label="جستجو"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <div class="jhd-drawer-body">
        <div class="drawer-section">ناوبری اصلی</div>
        <a href="<?= url() ?>" class="drawer-link <?= $isActiveNav('/') ? 'active' : '' ?>"><i class="bi bi-house"></i> خانه</a>
        <?= jhd_render_drawer_nav_item(['route'=>'news','label'=>'اخبار','icon'=>'bi-newspaper','types'=>['news']], $isActiveNav) ?>
        <?= jhd_render_drawer_nav_item(['route'=>'articles','label'=>'مقالات','icon'=>'bi-file-text','types'=>['article']], $isActiveNav) ?>
        <?= jhd_render_drawer_nav_item(['route'=>'reports','label'=>'گزارش‌ها','icon'=>'bi-card-text','types'=>['report']], $isActiveNav) ?>
        <?= jhd_render_drawer_nav_item(['route'=>'research','label'=>'پژوهش','icon'=>'bi-journal-richtext','types'=>['research']], $isActiveNav) ?>
        <?= jhd_render_drawer_nav_item(['route'=>'books','label'=>'کتابخانه','icon'=>'bi-book','types'=>[]], $isActiveNav) ?>
        <?= jhd_render_drawer_nav_item(['route'=>'lessons','label'=>'دروس','icon'=>'bi-mortarboard','types'=>[]], $isActiveNav) ?>
        <?= jhd_render_drawer_nav_item(['route'=>'events','label'=>'رویدادها','icon'=>'bi-calendar-event','types'=>['program','religious','announcement']], $isActiveNav) ?>
        <?= jhd_render_drawer_nav_item(['route'=>'media','label'=>'رسانه','icon'=>'bi-play-circle','types'=>[]], $isActiveNav) ?>

        <div class="drawer-section">موضوعات</div>
        <details class="jhd-acc" <?= ($isActiveNav('topics') || $isActiveNav('topic')) ? 'open' : '' ?>>
            <summary><i class="bi bi-diagram-3"></i> موضوعات</summary>
            <div class="jhd-acc-body">
                <a href="<?= url('topics') ?>" class="drawer-link drawer-all"><i class="bi bi-grid-3x3-gap"></i> اطلس کامل موضوعات</a>
                <?= jhd_render_topic_tree_nav($navTopicTree, 'drawer') ?>
            </div>
        </details>

        <div class="drawer-section">اطلاعات و تماس</div>
        <a href="<?= url('speeches') ?>" class="drawer-link"><i class="bi bi-mic"></i> سخنرانی‌ها</a>
        <a href="<?= url('qa') ?>" class="drawer-link"><i class="bi bi-question-circle"></i> پرسش و پاسخ</a>
        <a href="<?= url('about') ?>" class="drawer-link"><i class="bi bi-info-circle"></i> درباره جامعه‌الهدی</a>
        <a href="<?= url('contact') ?>" class="drawer-link"><i class="bi bi-envelope"></i> ارتباط با ما</a>

        <div class="drawer-section">حساب کاربری</div>
        <?php if ($isAdminLoggedIn): ?>
        <a href="<?= adminDashboardUrl() ?>" class="drawer-link"><i class="bi bi-speedometer2"></i> پنل مدیریت</a>
        <a href="<?= adminProfileUrl() ?>" class="drawer-link"><i class="bi bi-person-gear"></i> پروفایل من</a>
        <a href="<?= adminLogoutUrl() ?>" class="drawer-link text-danger"><i class="bi bi-box-arrow-right"></i> خروج از حساب</a>
        <?php elseif ($isMemberLoggedIn): ?>
        <a href="<?= accountUrl() ?>" class="drawer-link"><i class="bi bi-person-circle"></i> حساب کاربری</a>
        <a href="<?= url('password-change') ?>" class="drawer-link"><i class="bi bi-key"></i> تغییر رمز</a>
        <a href="<?= logoutUrl() ?>" class="drawer-link text-danger"><i class="bi bi-box-arrow-right"></i> خروج از حساب</a>
        <?php else: ?>
        <a href="<?= loginUrl() ?>" class="drawer-link"><i class="bi bi-box-arrow-in-left"></i> ورود</a>
        <a href="<?= registerUrl() ?>" class="drawer-link"><i class="bi bi-person-plus"></i> ثبت‌نام</a>
        <?php endif; ?>
    </div>
</aside>

<main id="main-content" tabindex="-1">
<?php if (!empty($jhdDbNotice)): ?>
<div class="jhd-db-notice" role="status">
  <div class="container">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span><?= sanitize($jhdDbNotice) ?></span>
  </div>
</div>
<?php endif; ?>
