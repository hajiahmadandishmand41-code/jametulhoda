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
    // A development or staging instance must not be crawled at all, but the
    // sitemap line still belongs in the file: the crawler policy is per
    // environment, the declaration of the sitemap is not.
    echo "Disallow: /\n";
    echo "Sitemap: {$sitemapUrl}\n";
    return;
}
$base = rtrim(BASE_PATH, '/');
foreach ([
    '/admin', '/install', '/php/', '/migrate',
    '/api/', '/bin/', '/config/', '/database/', '/includes/', '/pages/', '/storage/', '/tests/',
] as $path) echo "Disallow: {$base}{$path}\n";

// Do not blanket-block public noindex pages or legacy query URLs. Crawlers must
// be able to observe their noindex tags and follow redirects to canonical URLs.
// The admin query route remains disallowed, in addition to the /admin path.
echo "Disallow: {$base}/*?p=admin\nDisallow: {$base}/*&p=admin\n";
echo "Allow: {$base}/\nSitemap: {$sitemapUrl}\n";
