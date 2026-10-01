<?php
require_once __DIR__ . '/includes/functions.php';
// Sitemap needs an absolute base URL
$__sitemapBase = SITE_URL && filter_var(SITE_URL, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', SITE_URL)
    ? rtrim(SITE_URL, '/')
    : 'https://jametulhoda.gt.tc';
// SITE_URL may intentionally be only an origin while BASE_PATH denotes a
// subdirectory. Include that prefix exactly once in every sitemap location.
if (BASE_PATH !== '' && !str_ends_with($__sitemapBase, BASE_PATH)) {
    $__sitemapBase .= BASE_PATH;
}
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=900');

/**
 * Sitemap entries are built from the central route registry so every <loc>
 * matches the site's active URL mode: index.php?p=… in Query mode (works with
 * or without mod_rewrite) or the /pretty/path form in Pretty mode. This keeps
 * the sitemap crawlable on InfinityFree where pretty URLs are not guaranteed.
 */
$entries = [];
$add = static function (string $route, array $params, string $priority) use (&$entries): void {
    $entries[] = ['route' => $route, 'params' => $params, 'priority' => $priority];
};

$add('', [], '1.0');
foreach ([
    'news', 'articles', 'reports', 'events', 'books', 'lessons', 'research',
    'media', 'topics', 'about', 'contact', 'speeches', 'announcements',
    'programs', 'religious-activities', 'videos', 'audios', 'qa',
] as $listing) {
    $add($listing, [], '0.7');
}

$db = jhd_db();
if ($db !== null):
// Topics
try {
    foreach ($db->query("SELECT slug FROM topics WHERE is_active=1 ORDER BY id LIMIT 10000") as $row) {
        $add('topic', ['slug' => $row['slug']], '0.9');
    }
} catch (Exception $e) {}

// Posts with typed detail URLs (same canonical logic as postUrl()).
try {
    foreach ($db->query("SELECT slug, post_type FROM posts WHERE status='published' ORDER BY id LIMIT 10000") as $row) {
        $route = match ($row['post_type']) {
            'article' => 'article',
            'news' => 'news',
            'research' => 'research',
            'report' => 'report',
            'speech' => 'speech',
            'program', 'religious', 'announcement' => 'event',
            default => 'post',
        };
        $add($route, ['slug' => $row['slug']], '0.8');
    }
} catch (Exception $e) {}

// Media detail pages (/video/{id}, /audio/{id}).
try {
    // فقط صوت و ویدیو صفحهٔ جزئیات دارند؛ اسناد (PDF/Word) مقصد /video/{id}
    // ندارند و اگر وارد نقشهٔ سایت شوند یک نشانی ۴۰۴ به موتور جستجو می‌دهند.
    foreach ($db->query("SELECT m.id, m.kind FROM media_files m LEFT JOIN posts p ON p.id=m.ref_id AND m.ref_type='post' LEFT JOIN lessons l ON l.id=m.ref_id AND m.ref_type='lesson' WHERE m.kind IN ('audio','video') AND (p.status='published' OR l.status='published') ORDER BY m.id LIMIT 10000") as $row) {
        $add($row['kind'] === 'audio' ? 'audio' : 'video', ['id' => (int)$row['id']], '0.6');
    }
} catch (Exception $e) {}

// Lessons
try {
    foreach ($db->query("SELECT slug FROM lessons WHERE status='published' ORDER BY id LIMIT 10000") as $row) {
        $add('lesson', ['slug' => $row['slug']], '0.8');
    }
} catch (Exception $e) {}

// Lesson collections
try {
    foreach ($db->query("SELECT slug FROM lesson_collections WHERE is_active=1 LIMIT 1000") as $row) {
        $add('lessons', ['collection' => $row['slug']], '0.6');
    }
} catch (Exception $e) {}

// Books
try {
    foreach ($db->query("SELECT id, slug FROM books WHERE status='published' ORDER BY id LIMIT 10000") as $row) {
        $slug = trim($row['slug'] ?? '');
        $add('book', $slug !== '' ? ['slug' => $slug] : ['id' => (int)$row['id']], '0.7');
    }
} catch (Exception $e) {}

endif;

// Categories (helper already degrades to an empty list without a database)
try {
    foreach (getCategories() as $row) {
        $add('category', ['slug' => $row['slug']], '0.6');
    }
} catch (Exception $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($entries as $entry) {
    // Home is the bare origin; every other entry uses the central url() helper
    // so the location is exactly the public URL the site links to.
    $loc = $entry['route'] === ''
        ? rtrim($__sitemapBase, '/') . '/'
        : jhd_absolute_url(url($entry['route'], $entry['params']));
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' . "\n";
    echo '    <priority>' . $entry['priority'] . '</priority>' . "\n";
    echo '  </url>' . "\n";
}
echo '</urlset>' . "\n";
