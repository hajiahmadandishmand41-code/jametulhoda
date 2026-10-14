<?php
/** Text-only, database-independent crawler policy for this installation. */
declare(strict_types=1);
require_once __DIR__ . '/includes/sitemap.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    $sitemapUrl = jhd_sitemap_url(url('sitemap.xml'), jhd_sitemap_origin());
} catch (Throwable $e) {
    error_log('Robots canonical URL unavailable: ' . get_class($e));
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 300');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo "User-agent: *\nDisallow: /\n";
    return;
}
header('Cache-Control: public, max-age=300');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
echo "User-agent: *\n";
if (!in_array(APP_ENV, ['production', 'local'], true)) {
    echo "Disallow: /\n";
    return;
}
$base = rtrim(BASE_PATH, '/');
foreach ([
    '/admin', '/login', '/logout', '/register', '/account', '/profile',
    '/password-change', '/search', '/read', '/install', '/php/', '/migrate',
    '/api/', '/bin/', '/config/', '/database/', '/includes/', '/pages/', '/storage/', '/tests/',
] as $path) echo "Disallow: {$base}{$path}\n";

if (JHD_PRETTY_URLS) {
    // Legacy query URLs redirect to the clean canonical paths on Vercel.
    echo "Disallow: {$base}/*?p=\nDisallow: {$base}/index.php?p=\n";
} else {
    // Keep public query-mode content crawlable while excluding private forms.
    foreach (['login','register','logout','account','profile','password-change','search','read','admin'] as $route) {
        echo "Disallow: {$base}/*?p={$route}\n";
        echo "Disallow: {$base}/*&p={$route}\n";
    }
}
echo "Allow: {$base}/\nSitemap: {$sitemapUrl}\n";
