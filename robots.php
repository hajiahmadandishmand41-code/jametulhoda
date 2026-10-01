<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
if (APP_ENV !== 'production' && APP_ENV !== 'local') {
    echo "Disallow: /\n";
} else {
    $adminBase = rtrim(BASE_PATH, '/') . '/admin/';
    $siteBase = rtrim(BASE_PATH, '/') . '/';
    $sitemapUrl = absolute_url('sitemap.xml');

    echo "Disallow: {$adminBase}\n";
    echo "Disallow: " . rtrim(BASE_PATH, '/') . "/bin/\n";
    echo "Disallow: " . rtrim(BASE_PATH, '/') . "/config/\n";
    echo "Allow: {$siteBase}\n";
    echo "Sitemap: {$sitemapUrl}\n";
}
