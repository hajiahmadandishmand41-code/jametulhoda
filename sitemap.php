<?php
/**
 * sitemap.php — canonical XML sitemap for Google and other crawlers.
 *
 * Rules:
 * - Only public, indexable URLs are included.
 * - URLs are generated through the same route helpers used by the site,
 *   so canonical/sitemap links stay aligned.
 * - Search, authentication, admin, installer and other private URLs stay out.
 * - Published content is discovered directly from the database.
 * - Topic paths preserve parent/child hierarchy.
 */
require_once __DIR__ . '/includes/functions.php';

$base = SITE_URL && filter_var(SITE_URL, FILTER_VALIDATE_URL)
    ? rtrim(SITE_URL, '/')
    : 'https://jametulhoda.vercel.app';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=900, stale-while-revalidate=3600');

$entries = [];
$seen = [];

/**
 * Add one URL after generating its exact public form.
 * $params are passed to the central url() helper.
 */
$add = static function (
    string $route,
    array $params = [],
    ?string $lastmod = null
) use (&$entries, &$seen): void {
    $relative = $route === 'home' ? url() : url($route, $params);
    $loc = jhd_absolute_url($relative);

    if ($loc === '' || isset($seen[$loc])) return;
    $seen[$loc] = true;

    $entry = ['loc' => $loc];
    if ($lastmod) {
        $ts = strtotime($lastmod);
        if ($ts !== false) {
            $entry['lastmod'] = gmdate('Y-m-d', $ts);
        }
    }
    $entries[] = $entry;
};

/**
 * Public landing pages which are real, crawlable parts of the site.
 * Search and account/authentication pages are intentionally excluded.
 */
foreach ([
    'home',
    'news',
    'articles',
    'reports',
    'research',
    'books',
    'lessons',
    'topics',
    'media',
    'videos',
    'audios',
    'speeches',
    'events',
    'programs',
    'announcements',
    'religious-activities',
    'qa',
    'about',
    'contact',
] as $route) {
    $add($route);
}

$db = jhd_db();

if ($db !== null) {
    // ── Topics: build full parent/child slug paths in memory, avoiding N+1 DB queries.
    try {
        $rows = $db->query("
            SELECT id, parent_id, slug
            FROM topics
            WHERE is_active = 1
              AND COALESCE(slug, '') <> ''
            ORDER BY id
            LIMIT 50000
        ")->fetchAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = [
                'parent_id' => (int)($row['parent_id'] ?? 0),
                'slug' => trim((string)$row['slug']),
            ];
        }

        $topicPath = static function (int $id, array $trail = []) use (&$topicPath, $byId): string {
            if (!isset($byId[$id]) || isset($trail[$id])) return '';
            $trail[$id] = true;

            $own = trim($byId[$id]['slug']);
            if ($own === '') return '';

            $parent = (int)$byId[$id]['parent_id'];
            if ($parent > 0 && isset($byId[$parent])) {
                $parentPath = $topicPath($parent, $trail);
                if ($parentPath !== '') return $parentPath . '/' . $own;
            }
            return $own;
        };

        foreach ($byId as $id => $_row) {
            $slugPath = $topicPath((int)$id);
            if ($slugPath !== '') $add('topic', ['slug' => $slugPath]);
        }
    } catch (Throwable $e) {
        error_log('sitemap topics failed: ' . get_class($e));
    }

    // ── Published posts: one canonical detail URL per published post.
    try {
        $stmt = $db->query("
            SELECT slug,
                   post_type,
                   COALESCE(updated_at, published_at, created_at) AS lastmod
            FROM posts
            WHERE status = 'published'
              AND COALESCE(slug, '') <> ''
            ORDER BY COALESCE(updated_at, published_at, created_at) DESC, id DESC
            LIMIT 50000
        ");

        foreach ($stmt->fetchAll() as $row) {
            $route = match ((string)$row['post_type']) {
                'article' => 'article',
                'news' => 'news',
                'research' => 'research',
                'report' => 'report',
                'speech' => 'speech',
                'program', 'religious', 'announcement' => 'event',
                default => 'post',
            };
            $add($route, ['slug' => (string)$row['slug']], $row['lastmod'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('sitemap posts failed: ' . get_class($e));
    }

    // ── Published lessons.
    try {
        $stmt = $db->query("
            SELECT slug, COALESCE(updated_at, created_at) AS lastmod
            FROM lessons
            WHERE status = 'published'
              AND COALESCE(slug, '') <> ''
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
            LIMIT 50000
        ");
        foreach ($stmt->fetchAll() as $row) {
            $add('lesson', ['slug' => (string)$row['slug']], $row['lastmod'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('sitemap lessons failed: ' . get_class($e));
    }

    // ── Published books.
    try {
        $stmt = $db->query("
            SELECT id, slug, COALESCE(updated_at, created_at) AS lastmod
            FROM books
            WHERE status = 'published'
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
            LIMIT 50000
        ");
        foreach ($stmt->fetchAll() as $row) {
            $slug = trim((string)($row['slug'] ?? ''));
            $params = $slug !== ''
                ? ['slug' => $slug]
                : ['id' => (int)$row['id']];
            $add('book', $params, $row['lastmod'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('sitemap books failed: ' . get_class($e));
    }

    // ── Active categories.
    try {
        $stmt = $db->query("
            SELECT slug
            FROM categories
            WHERE is_active = 1
              AND COALESCE(slug, '') <> ''
            ORDER BY id
            LIMIT 50000
        ");
        foreach ($stmt->fetchAll() as $row) {
            $add('category', ['slug' => (string)$row['slug']]);
        }
    } catch (Throwable $e) {
        error_log('sitemap categories failed: ' . get_class($e));
    }

    // ── Public audio/video detail pages attached to published content.
    try {
        $stmt = $db->query("
            SELECT DISTINCT m.id, m.kind, m.created_at
            FROM media_files m
            LEFT JOIN posts p
              ON m.ref_type = 'post' AND p.id = m.ref_id
            LEFT JOIN lessons l
              ON m.ref_type = 'lesson' AND l.id = m.ref_id
            WHERE m.kind IN ('audio', 'video')
              AND (p.status = 'published' OR l.status = 'published')
            ORDER BY m.id
            LIMIT 50000
        ");

        foreach ($stmt->fetchAll() as $row) {
            $kind = $row['kind'] === 'audio' ? 'audio' : 'video';
            $add($kind, ['id' => (int)$row['id']], $row['created_at'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('sitemap media failed: ' . get_class($e));
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($entries as $entry) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    if (!empty($entry['lastmod'])) {
        echo '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</lastmod>\n";
    }
    echo "  </url>\n";
}

echo "</urlset>\n";
