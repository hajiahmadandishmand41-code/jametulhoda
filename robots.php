<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
if (APP_ENV !== 'production' && APP_ENV !== 'local') {
    echo "Disallow: /\n";
} else {
    $base = rtrim(BASE_PATH, '/');
    $sitemapUrl = absolute_url('sitemap.xml');

    // Private / non-content areas. These are kept out of the sitemap too; the
    // robots rules are the second, independent line of defence.
    $privatePaths = [
        '/admin/',
        '/login',
        '/logout',
        '/register',
        '/account',
        '/profile',
        '/password-change',
        '/install',
        '/install.php',
        '/php/',
        '/migrate',
        '/api/',
        '/bin/',
        '/config/',
        '/database/',
        '/includes/',
        '/pages/',
        '/storage/',
        '/tests/',
    ];
    foreach ($privatePaths as $path) {
        echo "Disallow: {$base}{$path}\n";
    }

    // In Pretty-URL mode the published address of every page is the clean path;
    // the ?p= form is a legacy alias that must not be crawled as a second,
    // duplicate copy of the same page. In Query mode it is the canonical form,
    // so it is left crawlable.
    if (JHD_PRETTY_URLS) {
        echo "Disallow: {$base}/*?p=\n";
        echo "Disallow: {$base}/index.php?p=\n";
    }

    echo "Allow: {$base}/\n";
    echo "Sitemap: {$sitemapUrl}\n";
}
