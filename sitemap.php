<?php
/**
 * sitemap.php — canonical XML sitemap for Google and other crawlers.
 *
 * Rules:
 * - Only public, indexable URLs are included.
 * - Every content URL is produced by the SAME helper that renders the link on
 *   the page (postUrl / lessonUrl / bookUrl / topicUrl / categoryUrl /
 *   mediaUrl). The sitemap therefore can never drift from the canonical.
 * - Published content is discovered straight from the database, so anything
 *   published from the admin panel appears here automatically.
 * - Drafts, private areas (admin, login, register, account, profile, search,
 *   installer, migration, storage endpoints) and duplicate/legacy spellings
 *   are excluded by an explicit guard — not by hoping they are not generated.
 * - Topic paths preserve the parent/child hierarchy.
 */
require_once __DIR__ . '/includes/functions.php';

$origin = SITE_URL && filter_var(SITE_URL, FILTER_VALIDATE_URL)
    ? rtrim(SITE_URL, '/')
    : 'https://jametulhoda.vercel.app';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=900, stale-while-revalidate=3600');

/**
 * Path prefixes that must never appear in a public sitemap.
 * Matched against the path portion (without query string) of every candidate.
 */
$sitemapDeny = '~^/(?:' . implode('|', [
    'admin(?:/|$)',
    'login(?:/|$)',
    'logout(?:/|$)',
    'register(?:/|$)',
    'account(?:/|$)',
    'profile(?:/|$)',
    'password-change(?:/|$)',
    'search(?:/|$)',
    'install(?:/|$)',
    'php/(?:install|migrate)(?:/|$)',
    'migrate(?:/|$)',
    'config(?:/|$)',
    'includes(?:/|$)',
    'database(?:/|$)',
    'bin(?:/|$)',
    'storage(?:/|$)',
    'tests(?:/|$)',
    'uploads(?:/|$)',
    'api(?:/|$)',
]) . '~i';

/**
 * Reject any URL that is not a plain public page of this origin.
 * http:// is accepted because a local/dev origin uses it; production always
 * configures SITE_URL as https, so a published sitemap is https throughout.
 */
$sitemapAllowed = static function (string $url) use ($origin, $sitemapDeny): bool {
    if (!preg_match('~^https?://~i', $url)) return false;
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') return false;
    if (!str_starts_with($url, $origin . '/') && $url !== $origin . '/') return false;
    if (preg_match($sitemapDeny, $path)) return false;
    return true;
};

$entries = [];
$seen = [];

/** Add an already-generated URL (the same string a page would render). */
$addUrl = static function (string $relative, ?string $lastmod = null) use (&$entries, &$seen, $sitemapAllowed): void {
    $loc = jhd_absolute_url($relative);
    if (!$sitemapAllowed($loc)) return;

    // Normalise: one trailing-slash-free path, no fragment, no query string.
    // The port is preserved — dropping it would rewrite a non-default-port
    // origin (a local or dev instance) into a different host entirely.
    $parts = parse_url($loc);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return;
    $path = rtrim((string)($parts['path'] ?? '/'), '/');
    if ($path === '') $path = '/';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $loc = $parts['scheme'] . '://' . $parts['host'] . $port . $path;

    if (isset($seen[$loc])) return;
    $seen[$loc] = true;

    $entry = ['loc' => $loc];
    if ($lastmod) {
        $ts = strtotime((string)$lastmod);
        if ($ts !== false) $entry['lastmod'] = gmdate('Y-m-d', $ts);
    }
    $entries[] = $entry;
};

/** Add a listing/detail route through the central url() helper. */
$add = static function (string $route, array $params = [], ?string $lastmod = null) use ($addUrl): void {
    $addUrl($route === 'home' ? url() : url($route, $params), $lastmod);
};

/**
 * Public landing pages which are real, crawlable parts of the site.
 * Search and every authentication page are intentionally absent.
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
    //     postUrl() owns the type → prefix mapping, so a newly published
    //     announcement/program/research/… is listed here with no code change.
    try {
        $stmt = $db->query("
            SELECT id, slug, post_type,
                   COALESCE(updated_at, published_at, created_at) AS lastmod
            FROM posts
            WHERE status = 'published'
              AND COALESCE(slug, '') <> ''
            ORDER BY COALESCE(updated_at, published_at, created_at) DESC, id DESC
            LIMIT 50000
        ");

        foreach ($stmt->fetchAll() as $row) {
            $addUrl(postUrl($row), $row['lastmod'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('sitemap posts failed: ' . get_class($e));
    }

    // ── Published lessons.
    try {
        $stmt = $db->query("
            SELECT id, slug, COALESCE(updated_at, created_at) AS lastmod
            FROM lessons
            WHERE status = 'published'
              AND COALESCE(slug, '') <> ''
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
            LIMIT 50000
        ");
        foreach ($stmt->fetchAll() as $row) {
            $addUrl(lessonUrl($row), $row['lastmod'] ?? null);
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
            $addUrl(bookUrl($row), $row['lastmod'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('sitemap books failed: ' . get_class($e));
    }

    // ── Active categories.
    try {
        $stmt = $db->query("
            SELECT slug
            FROM categories
            WHERE COALESCE(slug, '') <> ''
            ORDER BY id
            LIMIT 50000
        ");
        foreach ($stmt->fetchAll() as $row) {
            $addUrl(categoryUrl((string)$row['slug']));
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
            $addUrl(mediaUrl($kind, (int)$row['id']), $row['created_at'] ?? null);
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
