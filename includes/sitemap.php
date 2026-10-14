<?php
/** Canonical sitemap data. Read-only; uses the same publication/URL contracts as public pages. */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

/** Only persisted, strictly valid dates; never "today", relative dates or PHP's date rollover. */
function jhd_sitemap_lastmod(array $row): ?string {
    foreach (['updated_at', 'published_at', 'created_at'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)?)?$~D', $value, $m)) continue;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) continue;
        if (isset($m[4]) && ((int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59)) continue;
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) continue;
            // Future event/publication dates are not a trustworthy last modification.
            if ($date->getTimestamp() < 0 || $date->getTimestamp() > time()) continue;
            return $date->format('Y-m-d');
        } catch (Throwable) { /* Try the next persisted date, or omit lastmod. */ }
    }
    return null;
}

/** Validate the configured installation URL, not the visitor-controlled Host header. */
function jhd_sitemap_origin(): string {
    $origin = rtrim(SITE_URL, '/');
    $parts = parse_url($origin);
    if (!filter_var($origin, FILTER_VALIDATE_URL) || !is_array($parts)
        || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
        || empty($parts['host'])
        || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
        || rtrim((string)($parts['path'] ?? ''), '/') !== BASE_PATH) {
        throw new RuntimeException('Invalid canonical site URL');
    }
    return $origin;
}

/** Guard all generated URLs, including query-mode and subdirectory installations. */
function jhd_sitemap_url(string $relative, string $origin): string {
    $loc = jhd_absolute_url($relative);
    $parts = parse_url($loc);
    if (!is_array($parts) || !filter_var($loc, FILTER_VALIDATE_URL)
        || !str_starts_with($loc, $origin . '/') || isset($parts['fragment'])
        || isset($parts['user']) || preg_match('//u', $loc) !== 1) {
        throw new RuntimeException('Invalid sitemap URL');
    }
    $path = substr((string)($parts['path'] ?? '/'), strlen(BASE_PATH));
    if (preg_match('~^/(?:admin|login|register|logout|account|profile|password-change|search|read|php|install|api|config|includes|pages|storage|uploads|tests|bin|assets|topic|topics)(?:[/.]|$)~i', $path)) {
        throw new RuntimeException('Non-indexable sitemap URL');
    }
    parse_str((string)($parts['query'] ?? ''), $query);
    if (array_diff(array_keys($query), ['p', 'slug', 'id', 'collection', 'volume'])) throw new RuntimeException('Filtered sitemap URL');
    foreach ($query as $value) {
        if (!is_string($value) || $value === '') throw new RuntimeException('Invalid sitemap parameter');
    }
    if (isset($query['p']) && !in_array($query['p'], [
        'news','articles','reports','books','lessons','media','audios','events','programs',
        'announcements','religious-activities','qa','speeches','about','contact',
        'post','article','research','report','event','announcement','program','religious',
        'speech','book','lesson','category','audio','video',
    ], true)) throw new RuntimeException('Private query route');
    return $loc;
}

/** @return list<array{loc:string,lastmod?:string}> */
function jhd_sitemap_entries(PDO $db): array {
    $origin = jhd_sitemap_origin();
    $entries = [];
    $add = static function (string $relative, array $row = []) use (&$entries, $origin): void {
        $loc = jhd_sitemap_url($relative, $origin);
        if (isset($entries[$loc])) return;
        $entry = ['loc' => $loc];
        $date = jhd_sitemap_lastmod($row);
        if ($date !== null) $entry['lastmod'] = $date;
        $entries[$loc] = $entry;
    };
    foreach (['', 'about', 'contact'] as $route) $add(url($route));

    // status='published' is the public controllers' publication rule. Do not
    // introduce a new scheduler: future event dates are intentionally public.
    $postCounts = [];
    $posts = $db->query("SELECT id, slug, post_type, updated_at, published_at, created_at FROM posts WHERE status='published' ORDER BY id");
    while ($row = $posts->fetch()) {
        $type = (string)$row['post_type'];
        $postCounts[$type] = ($postCounts[$type] ?? 0) + 1;
        if (trim((string)$row['slug']) !== '') $add(postUrl($row), $row);
    }
    $posts->closeCursor();
    $archiveTypes = [
        'news' => ['news','announcement'], 'articles' => ['article','research'],
        'reports' => ['report'], 'announcements' => ['announcement'],
        'programs' => ['program'], 'religious-activities' => ['religious'],
        'events' => ['program','religious','announcement'], 'speeches' => ['speech'], 'qa' => ['qa'],
    ];
    foreach ($archiveTypes as $route => $types) {
        foreach ($types as $type) {
            if (!empty($postCounts[$type])) { $add(url($route)); break; }
        }
    }
    // /research and /topic(s) are legacy redirects. Research detail pages above
    // remain indexable; /articles?type=research is a noindex filter, not a new URL.

    foreach (['books' => 'bookUrl', 'lessons' => 'lessonUrl'] as $table => $helper) {
        $rows = $db->query("SELECT id, slug, updated_at, created_at FROM $table WHERE status='published' ORDER BY id");
        $hasContent = false;
        while ($row = $rows->fetch()) {
            $hasContent = true;
            if ($table === 'lessons' && trim((string)$row['slug']) === '') continue;
            $add($helper($row), $row); // Books without slugs retain their working ID URL.
        }
        $rows->closeCursor();
        if ($hasContent) $add(url($table));
    }

    // Optional legacy tables are discovered read-only; no migration/bootstrap
    // is run by a crawler request. Include only non-empty, public archives.
    $optional = array_fill_keys(['categories','lesson_collections','lesson_volumes'], false);
    // Unlike the UI's best-effort discovery helper, query errors here must
    // propagate. Only a genuinely absent optional table may be skipped.
    $names = "'categories','lesson_collections','lesson_volumes'";
    $tableSql = match (databaseDriver()) {
        'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name IN ($names)",
        'mysql' => "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($names)",
        default => "SELECT table_name AS name FROM information_schema.tables WHERE table_schema=ANY(current_schemas(false)) AND table_name IN ($names)",
    };
    foreach ($db->query($tableSql)->fetchAll() as $table) {
        $name = (string)($table['name'] ?? $table['NAME'] ?? '');
        if (array_key_exists($name, $optional)) $optional[$name] = true;
    }
    if ($optional['categories']) {
        $rows = $db->query("SELECT c.slug FROM categories c WHERE COALESCE(c.slug,'')<>'' AND EXISTS (SELECT 1 FROM posts p WHERE p.category_id=c.id AND p.status='published') ORDER BY c.id");
        foreach ($rows->fetchAll() as $row) $add(categoryUrl($row));
    }
    if ($optional['lesson_collections']) {
        $rows = $db->query("SELECT c.id,c.slug,c.updated_at,c.created_at FROM lesson_collections c WHERE c.is_active=1 AND COALESCE(c.slug,'')<>'' AND EXISTS (SELECT 1 FROM lessons l WHERE l.collection_id=c.id AND l.status='published') ORDER BY c.id");
        foreach ($rows->fetchAll() as $row) $add(collectionUrl($row), $row);
        if ($optional['lesson_volumes']) {
            $rows = $db->query("SELECT c.slug AS collection_slug,v.slug,v.updated_at,v.created_at FROM lesson_volumes v JOIN lesson_collections c ON c.id=v.collection_id WHERE c.is_active=1 AND COALESCE(c.slug,'')<>'' AND COALESCE(v.slug,'')<>'' AND EXISTS (SELECT 1 FROM lessons l WHERE l.collection_id=c.id AND l.volume_id=v.id AND l.status='published') ORDER BY v.id");
            foreach ($rows->fetchAll() as $row) $add(collectionUrl($row['collection_slug'], $row['slug']), $row);
        }
    }

    $mediaCounts = ['audio' => 0, 'video' => 0];
    $rows = $db->query("SELECT m.id,m.kind,m.created_at FROM media_files m
        LEFT JOIN posts p ON m.ref_type='post' AND p.id=m.ref_id
        LEFT JOIN lessons l ON m.ref_type='lesson' AND l.id=m.ref_id
        WHERE m.kind IN ('audio','video') AND COALESCE(m.file_path,'')<>''
        AND (p.status='published' OR l.status='published') ORDER BY m.id");
    foreach ($rows->fetchAll() as $row) {
        $mediaCounts[$row['kind']]++;
        $add(mediaUrl($row['kind'], (int)$row['id']), $row);
    }
    // The media library also reads legacy direct fields without media_files rows.
    foreach (['audio','video'] as $kind) {
        $column = $kind . '_file';
        $mediaCounts[$kind] += (int)$db->query("SELECT COUNT(*) FROM lessons WHERE status='published' AND COALESCE($column,'')<>''")->fetchColumn();
    }
    $mediaCounts['video'] += (int)$db->query("SELECT COUNT(*) FROM posts WHERE status='published' AND COALESCE(featured_video,'')<>''")->fetchColumn();
    if ($mediaCounts['video']) $add(url('media')); // /videos is the identical default-video archive.
    if ($mediaCounts['audio']) $add(url('audios'));
    return array_values($entries);
}

function jhd_sitemap_xml(array $entries): string {
    // Never publish a truncated or protocol-invalid sitemap as a successful one.
    if (count($entries) > 50000) throw new RuntimeException('Sitemap index required');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($entries as $entry) {
        $xml .= '  <url><loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</loc>';
        if (isset($entry['lastmod'])) $xml .= '<lastmod>' . $entry['lastmod'] . '</lastmod>';
        $xml .= "</url>\n";
    }
    $xml .= "</urlset>\n";
    if (strlen($xml) > 50 * 1024 * 1024) throw new RuntimeException('Sitemap size limit exceeded');
    return $xml;
}
