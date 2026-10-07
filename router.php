<?php
/**
 * router.php — تنها درگاه ورودی درخواست‌ها (front controller)
 *
 * `.htaccess` همه درخواست‌ها را اینجا می‌فرستد. این فایل سه کار می‌کند:
 *   ۱) سرو کردن مستقیم فایل‌های استاتیک `assets/` و `uploads/`
 *   ۲) resolving مسیرها فقط از روی `config/routes.php`
 *   ۳) ۴۰۴ برای هر چیز دیگر (هیچ فایل PHP دیگری از بیرون قابل اجرا نیست)
 */
require_once __DIR__ . '/config/config.php';
// Routing helpers (jhd_routes / jhd_resolve_query / url …) live in functions.php;
// loading it here is side-effect free (functions only, lazy DB) and lets the
// router resolve Query and Pretty URLs from one shared registry.
require_once __DIR__ . '/includes/functions.php';

if (env_value('VERCEL') && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4 * 1024 * 1024) {
    http_response_code(413);
    exit('حجم درخواست بیش از حد مجاز است.');
}
foreach ($_GET as $value) {
    if (!is_string($value)) {
        http_response_code(400);
        exit('Invalid query parameter');
    }
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
// ErrorDocument 404 /router.php (InfinityFree without mod_rewrite): recover the
// original pretty path so /admin/login still reaches the login controller.
if ($path === '/router.php' || str_ends_with($path, '/router.php')) {
    $redirected = (string)($_SERVER['REDIRECT_URL'] ?? $_SERVER['REDIRECT_URI'] ?? '');
    if ($redirected !== '') {
        $path = rawurldecode(parse_url($redirected, PHP_URL_PATH) ?: $redirected);
    }
}
if (BASE_PATH) {
    if ($path === BASE_PATH) $path = '/';
    elseif (str_starts_with($path, BASE_PATH . '/')) $path = substr($path, strlen(BASE_PATH));
    else { http_response_code(404); exit; }
}
if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\')) {
    http_response_code(404);
    exit;
}

// Google Search Console HTML verification.
// IMPORTANT: do not read the verification file from the Vercel Serverless
// filesystem. The PHP function bundle is the reliable execution path here,
// while root-level static files are not guaranteed to be present inside the
// runtime bundle. Return the exact token directly for this one URL only.
// This exact-match exception runs before the application route table and does
// not alter any other PHP route.
if ($path === '/google2b2831df6b773d4d.html') {
    $verification = 'google-site-verification: google2b2831df6b773d4d.html';
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: noindex');
    header('Content-Length: ' . strlen($verification));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $verification;
    }
    exit;
}

/** 404 page shared by every unmatched request. */
function jhdNotFound(): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $homeUrl = htmlspecialchars(BASE_PATH . '/', ENT_QUOTES, 'UTF-8');
    $newsUrl = htmlspecialchars(BASE_PATH . '/news', ENT_QUOTES, 'UTF-8');
    $articlesUrl = htmlspecialchars(BASE_PATH . '/articles', ENT_QUOTES, 'UTF-8');
    $reportsUrl = htmlspecialchars(BASE_PATH . '/reports', ENT_QUOTES, 'UTF-8');
    $booksUrl = htmlspecialchars(BASE_PATH . '/books', ENT_QUOTES, 'UTF-8');
    $lessonsUrl = htmlspecialchars(BASE_PATH . '/lessons', ENT_QUOTES, 'UTF-8');
    $searchUrl = htmlspecialchars(BASE_PATH . '/search', ENT_QUOTES, 'UTF-8');
    $logoUrl = htmlspecialchars(BASE_PATH . '/assets/img/logo.png', ENT_QUOTES, 'UTF-8');
    $fontUrl = htmlspecialchars(BASE_PATH . '/assets/fonts/Vazirmatn-Regular.woff2', ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,follow,max-image-preview:large">
<title>۴۰۴ — صفحه پیدا نشد | جامعه‌الهدی</title>
<style>
@font-face {
    font-family: 'Vazirmatn';
    src: url('$fontUrl') format('woff2');
    font-weight: 400 700;
    font-display: swap;
}
:root {
    --bg: #f8f7f2;
    --surface: #ffffff;
    --text: #182d39;
    --muted: #657572;
    --green: #245c4c;
    --gold: #b39250;
    --border: #dfe5df;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Vazirmatn', Tahoma, sans-serif;
    background-color: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px;
    line-height: 1.8;
}
.error-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    max-width: 620px;
    width: 100%;
    padding: 40px 32px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
}
.error-logo {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    border: 2px solid var(--gold);
    margin-bottom: 20px;
}
.error-code {
    font-size: 3.5rem;
    font-weight: 900;
    color: var(--green);
    line-height: 1;
    margin-bottom: 12px;
}
.error-title {
    font-size: 1.4rem;
    font-weight: 700;
    margin-bottom: 12px;
}
.error-desc {
    color: var(--muted);
    font-size: 0.95rem;
    margin-bottom: 28px;
}
.actions-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 30px;
}
.btn-home {
    background: var(--green);
    color: #fff;
    padding: 10px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.92rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
}
.btn-home:hover {
    background: #173e34;
}
.quick-links {
    border-top: 1px solid var(--border);
    padding-top: 20px;
}
.quick-links-title {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 14px;
    font-weight: 700;
}
.links-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
}
.links-grid a {
    color: var(--green);
    text-decoration: none;
    background: var(--bg);
    border: 1px solid var(--border);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.84rem;
    transition: all 0.2s;
}
.links-grid a:hover {
    border-color: var(--gold);
    background: var(--surface);
}
</style>
</head>
<body>
<main class="error-card">
    <img src="$logoUrl" alt="جامعه‌الهدی" class="error-logo" onerror="this.style.display='none'">
    <div class="error-code">۴۰۴</div>
    <h1 class="error-title">صفحه مورد نظر یافت نشد</h1>
    <p class="error-desc">نشانی وارد شده تغییر کرده یا صفحه از روی سایت برداشته شده است.</p>
    <div class="actions-row">
        <a href="$homeUrl" class="btn-home">بازگشت به صفحه اصلی</a>
        <a href="$searchUrl" class="btn-home" style="background:#556b2f">جستجو در سایت</a>
    </div>
    <div class="quick-links">
        <div class="quick-links-title">بخش‌های اصلی جامعه‌الهدی</div>
        <div class="links-grid">
            <a href="$newsUrl">اخبار</a>
            <a href="$articlesUrl">مقالات</a>
            <a href="$reportsUrl">گزارش‌ها</a>
            <a href="$booksUrl">کتابخانه</a>
            <a href="$lessonsUrl">دروس حوزوی</a>
        </div>
    </div>
</main>
</body>
</html>
HTML;
    exit;
}

/**
 * Expand the canonical table from config/routes.php into every accepted URL
 * spelling: /x, /x/ and /x.php (plus /x/index.php for directory controllers).
 * Paths that already contain a dot (/sitemap.xml, /robots.txt, /index.php) stay
 * exact. Aliases reuse the canonical entry of their target.
 */
function jhdRouteTable(array $routes, array $aliases): array {
    $add = static function (array &$table, string $path, array $entry, bool $variants = true): void {
        $table[$path] = $entry;
        if ($path === '/' || str_contains(substr($path, 1), '.')) return;
        $table[$path . '/'] = $entry;
        if (!$variants) return; // aliases answer as written (plus a trailing slash)
        $table[$path . '.php'] = $entry;
        if (basename($entry['file']) === 'index.php') $table[$path . '/index.php'] = $entry;
    };
    $table = [];
    foreach ($routes as $path => $target) {
        $entry = is_array($target) ? $target : ['file' => $target];
        $entry['canonical'] = $path;
        $add($table, $path, $entry);
    }
    foreach ($aliases as $from => $to) {
        if (isset($table[$to])) $add($table, $from, $table[$to], false);
    }
    return $table;
}

/** Stream a static file with content type, caching, ETag and range support. */
function jhdServeStatic(string $file): void {
    $types = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'webmanifest' => 'application/manifest+json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? (new finfo(FILEINFO_MIME_TYPE))->file($file)));
    // Asset URLs generated by asset() carry a ?v=<fingerprint> that changes
    // with the file, so a versioned request can be cached permanently. An
    // unversioned request (a direct link, a legacy bookmark) gets a day.
    // On Vercel this path is only a fallback: vercel.json serves /assets/*
    // straight from the CDN without invoking PHP at all.
    $versioned = isset($_GET['v']) && is_string($_GET['v']) && $_GET['v'] !== '';
    header('Cache-Control: ' . ($versioned
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=86400, stale-while-revalidate=604800'));
    $etag = '"' . dechex(filemtime($file)) . '-' . dechex(filesize($file)) . '"';
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    if (in_array($ext, ['pdf', 'doc', 'docx'], true)) {
        header('Content-Disposition: attachment');
    }
    $size = filesize($file);
    $start = 0;
    $end = $size - 1;
    header('Accept-Ranges: bytes');
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'], $range) || ($range[1] === '' && $range[2] === '') || ($range[1] !== '' && (int)$range[1] >= $size)) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $start = $range[1] === '' ? max(0, $size - (int)$range[2]) : (int)$range[1];
        $end = $range[1] !== '' && $range[2] !== '' ? min((int)$range[2], $end) : $end;
        if ($end < $start) {
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        while (ob_get_level()) ob_end_flush();
        $handle = fopen($file, 'rb');
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle)) {
            $data = fread($handle, min(65536, $remaining));
            echo $data;
            $remaining -= strlen($data);
        }
        fclose($handle);
    }
    exit;
}

// ─── ۱) فایل‌های استاتیک ─────────────────────────────────────────────────────
$assetPath = preg_replace('~^/assets/images/~', '/assets/img/', $path);
if (preg_match('~^/assets/[a-zA-Z0-9_./-]+\.(css|js|mjs|svg|png|jpe?g|webp|gif|woff2?|ico)$~D', $assetPath)) {
    $file = __DIR__ . $assetPath;
    if (is_file($file) && !is_link($file)) jhdServeStatic($file);
}
/*
 * Favicon.
 *
 * /favicon.ico is the real, dedicated icon (ICO container with 16/32/48
 * bitmap sizes, square aspect). The school's branding image is
 * assets/img/logo.png (702×723, not square) and is used only as the social /
 * og:image. Serving the non-square logo as the favicon confused Google's
 * favicon parser — it requires a square image and falls back to a generic
 * globe when it cannot extract one.
 *
 * /favicon.png is an alias for the 48×48 square PNG (assets/img/favicon-48.png)
 * so any code or cached bookmark that requested favicon.png still gets a
 * valid, square icon.
 */
if ($path === '/favicon.ico') {
    $file = __DIR__ . '/favicon.ico';
    if (is_file($file)) jhdServeStatic($file);
}
if ($path === '/favicon.png') {
    $file = __DIR__ . '/assets/img/favicon-48.png';
    if (is_file($file)) jhdServeStatic($file);
}
/*
 * PWA manifest. Served through the router (like the favicon) so the identical
 * URL works on Apache, the PHP built-in server and the Vercel serverless
 * runtime, none of which serve root-level static files the same way.
 */
if ($path === '/site.webmanifest' || $path === '/manifest.json' || $path === '/manifest.webmanifest') {
    $file = __DIR__ . '/site.webmanifest';
    if (is_file($file)) jhdServeStatic($file);
}
if (UPLOAD_STORAGE === 'local' && preg_match('~^/uploads/(?:[a-zA-Z0-9_-]+/)+[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|gif|webp|mp3|ogg|wav|m4a|mp4|webm|mov|mkv|pdf|doc|docx)$~D', $path)) {
    $file = UPLOAD_DIR . substr($path, 9);
    if (is_file($file) && !is_link($file)) jhdServeStatic($file);
}

// ─── ۲) مسیرهای دقیق و الگوهای پویا ─────────────────────────────────────────
$definition = require __DIR__ . '/config/routes.php';

// Query-URL mode (index.php?p=news / ?p=topic&slug=x) is resolved first and
// independently of mod_rewrite: a shared host that cannot rewrite URLs still
// serves every page because links are generated as ?p=… by url(). Unknown ?p=
// values fall through to a real 404 (never a soft-404 or the homepage).
$queryRoute = (isset($_GET['p']) && is_string($_GET['p'])) ? trim($_GET['p']) : '';
// `?p=home` maps to index.php, which itself dispatches `?p=`; resolving it here
// would recurse. The home route is simply the homepage.
if ($queryRoute === 'home' || $queryRoute === '/') { $queryRoute = ''; unset($_GET['p']); }

/*
 * Canonicalize legacy query URLs (index.php?p=...) to the current pretty route.
 * These are valid historical URLs, not alternate canonical documents, so a
 * permanent redirect is appropriate. Detail slugs remain encoded exactly once
 * by the central url() helper.
 */
if ($path === '/index.php' && $queryRoute !== '') {
    $legacyQuery = $_GET;
    unset($legacyQuery['p']);
    $legacyCanonical = url($queryRoute, $legacyQuery);
    if ($legacyCanonical !== '') {
        header('Location: ' . $legacyCanonical, true, 301);
        exit;
    }
}
if ($path === '/library') {
    header('Location: ' . url('books'), true, 301);
    exit;
}
if ($path === '/media-library') {
    header('Location: ' . url('media'), true, 301);
    exit;
}

/*
 * Canonicalize legacy listing URLs that carry a detail slug in the query
 * string, e.g. /articles?slug=... or /news?slug=....
 *
 * Older links/search results used the listing prefix plus ?slug=.  PHP decodes
 * query parameters once, so a value that arrived as %25D9... is still
 * percent-encoded at this point.  Decode at most two layers, then let the
 * single central url() helper perform the only output encoding.  This turns
 * legacy/double-encoded URLs into one permanent canonical detail URL instead
 * of rendering a listing page with a stray slug parameter.
 */
if ($queryRoute === '' && isset($_GET['slug']) && is_string($_GET['slug'])) {
    $legacyDetailRoutes = [
        'articles'              => 'article',
        'news'                  => 'news',
        'research'              => 'research',
        'reports'               => 'report',
        'announcements'         => 'announcement',
        'programs'              => 'program',
        'religious-activities'  => 'religious',
        'events'                 => 'event',
        'speeches'               => 'speech',
        'qa'                     => 'qa',
    ];
    $listingPrefix = trim($path, '/');
    if (isset($legacyDetailRoutes[$listingPrefix])) {
        $legacySlug = trim((string)$_GET['slug']);
        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($legacySlug);
            if ($decoded === $legacySlug) break;
            $legacySlug = $decoded;
        }
        $legacySlug = trim($legacySlug, '/');
        if ($legacySlug !== '') {
            $canonicalLegacyUrl = url($legacyDetailRoutes[$listingPrefix], ['slug' => $legacySlug]);
            header('Location: ' . $canonicalLegacyUrl, true, 301);
            exit;
        }
    }
}

$resolved = null;
if ($queryRoute !== '') {
    $resolved = jhd_resolve_query($queryRoute, $_GET);
    if ($resolved === null) jhdNotFound();
    $routeName = $queryRoute;
    foreach ($resolved['get'] as $k => $v) $_GET[$k] ??= $v;
    if (!empty($resolved['expected_type'])) $_GET['expected_type'] = $resolved['expected_type'];
    if (!empty($resolved['kind'])) $_GET['kind'] = $resolved['kind'];
    $target = $resolved['file'];
} else {
    $table = jhdRouteTable($definition['routes'], $definition['aliases']);
    $entry = $table[$path] ?? null;

    if ($entry === null) {
        foreach ($definition['patterns'] as [$pattern, $script, $params]) {
            if (!preg_match($pattern, $path, $matches)) continue;
            // `pages/$1.php` — only fixed alternations are captured into the script name.
            $script = preg_replace_callback('/\$(\d+)/', static fn($m) => $matches[(int)$m[1]] ?? '', $script);
            foreach ($params as $key => $index) {
                if (is_int($index)) {
                    if (($matches[$index] ?? '') !== '') $_GET[$key] = $matches[$index];
                } else {
                    $_GET[$key] = $index;
                }
            }
            // Dynamic routes accept a trailing slash, but expose one stable canonical path.
            $canonicalPath = '/' . trim($path, '/');
            $entry = ['file' => $script, 'canonical' => $canonicalPath];
            break;
        }
    }

    if ($entry === null) jhdNotFound();
    foreach ($entry['get'] ?? [] as $key => $value) $_GET[$key] ??= $value;

    $target = $entry['file'];
    $routeName = jhd_route_name_for_path($entry['canonical']);
}
$file = realpath(__DIR__ . '/' . $target);
if ($file === false || !str_starts_with($file, realpath(__DIR__) . DIRECTORY_SEPARATOR) || !is_file($file)) {
    jhdNotFound();
}

$_SERVER['SCRIPT_NAME'] = BASE_PATH . '/' . $target;
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
// Publish the logical route so current_path(), navigation and canonical behave
// identically in Query and Pretty modes. Fall back to the resolved path when a
// legacy spelling has no registry route name.
$routeName = $routeName ?? null;
if ($routeName !== null && jhd_route_exists($routeName)) {
    $_SERVER['JHD_ROUTE_NAME'] = $routeName;
    $_SERVER['JHD_ROUTE_PATH'] = BASE_PATH . jhd_route_path($routeName, $_GET);
} else {
    $_SERVER['JHD_ROUTE_PATH'] = BASE_PATH . ($entry['canonical'] ?? ('/' . ltrim($path, '/')));
}

require $file;
exit;
