<?php
/**
 * Public, read-only JSON feed used by the native Android application.
 * Only content published on the school's own website is returned.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=600');

function jhd_mobile_json(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    header('Allow: GET, HEAD');
    jhd_mobile_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
$limit = max(5, min(40, $limit));

$siteOrigin = rtrim(SITE_URL, '/');
$basePath = trim(BASE_PATH, '/');
$basePrefix = $basePath !== '' ? '/' . $basePath : '';

$absoluteUrl = static function (string $path) use ($siteOrigin, $basePrefix): string {
    if (preg_match('~^https://~i', $path)) return $path;
    return $siteOrigin . $basePrefix . '/' . ltrim($path, '/');
};

$cleanText = static function ($value, int $max = 7000): string {
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
    return trim(mb_substr($text, 0, $max, 'UTF-8'));
};

$imageUrl = static function (array $row) use ($absoluteUrl): string {
    foreach (['featured_image', 'image_url', 'image', 'cover_image', 'thumbnail', 'cover'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value === '') continue;
        if (preg_match('~^https://~i', $value)) return $value;
        if (str_starts_with($value, '//')) return 'https:' . $value;
        return $absoluteUrl($value);
    }
    return '';
};

$items = [];

$types = ['news', 'article', 'research', 'report', 'announcement', 'event', 'program', 'speech', 'qa'];
$posts = getPosts(['types' => $types, 'limit' => $limit, 'offset' => 0]);
$typePath = [
    'news' => 'news',
    'article' => 'articles',
    'research' => 'research',
    'report' => 'reports',
    'announcement' => 'announcements',
    'event' => 'events',
    'program' => 'programs',
    'speech' => 'speeches',
    'qa' => 'qa',
];
foreach ($posts as $post) {
    $title = $cleanText($post['title'] ?? '', 180);
    if ($title === '') continue;
    $type = (string)($post['post_type'] ?? 'news');
    $slug = trim((string)($post['slug'] ?? ''));
    $path = isset($typePath[$type]) ? $typePath[$type] : 'news';
    $postUrl = $slug !== ''
        ? $absoluteUrl($path . '/' . rawurlencode($slug))
        : $absoluteUrl('post/' . (string)($post['id'] ?? ''));
    $content = $cleanText($post['content'] ?? '', 7000);
    $summary = $cleanText($post['summary'] ?? '', 700);
    if ($summary === '') $summary = mb_substr($content, 0, 520, 'UTF-8');
    $items[] = [
        'id' => 'site-' . (string)($post['id'] ?? sha1($title)),
        'source' => 'website',
        'type' => $type,
        'title' => $title,
        'summary' => $summary,
        'content' => $content !== '' ? $content : $summary,
        'author' => $cleanText($post['author_name'] ?? '', 100),
        'created_at' => (string)($post['published_at'] ?? $post['created_at'] ?? ''),
        'url' => $postUrl,
        'image_url' => $imageUrl($post),
    ];
}

// Active course collections, public lessons, active topics, and media whose parent is published.
// Every query is read-only and deliberately filters unpublished records at the database boundary.
$discoveryLimit = min(12, $limit);

try {
    $db = getDB();

    $courseStmt = $db->prepare(
        "SELECT c.id, c.title, c.slug, c.description, c.cover_image, c.created_at
         FROM lesson_collections c
         WHERE c.is_active = 1
           AND EXISTS (SELECT 1 FROM lessons l WHERE l.collection_id = c.id AND l.status = 'published')
         ORDER BY c.is_featured DESC, c.sort_order ASC, c.title ASC
         LIMIT ?"
    );
    $courseStmt->bindValue(1, $discoveryLimit, PDO::PARAM_INT);
    $courseStmt->execute();
    foreach ($courseStmt->fetchAll(PDO::FETCH_ASSOC) as $course) {
        $title = $cleanText($course['title'] ?? '', 180);
        $slug = trim((string)($course['slug'] ?? ''));
        if ($title === '' || $slug === '') continue;
        $description = $cleanText($course['description'] ?? '', 2500);
        $items[] = [
            'id' => 'course-' . (string)$course['id'],
            'source' => 'website',
            'type' => 'course',
            'title' => $title,
            'summary' => mb_substr($description, 0, 520, 'UTF-8'),
            'content' => $description,
            'author' => '',
            'created_at' => (string)($course['created_at'] ?? ''),
            'url' => $absoluteUrl('lessons/' . rawurlencode($slug)),
            'image_url' => $imageUrl($course),
        ];
    }

    $lessonStmt = $db->prepare(
        "SELECT l.id, l.title, l.slug, l.summary, l.content, l.teacher, l.featured_image,
                l.created_at, c.title AS collection_title
         FROM lessons l
         LEFT JOIN lesson_collections c ON c.id = l.collection_id
         WHERE l.status = 'published'
         ORDER BY l.is_featured DESC, l.created_at DESC, l.id DESC
         LIMIT ?"
    );
    $lessonStmt->bindValue(1, $discoveryLimit, PDO::PARAM_INT);
    $lessonStmt->execute();
    foreach ($lessonStmt->fetchAll(PDO::FETCH_ASSOC) as $lesson) {
        $title = $cleanText($lesson['title'] ?? '', 180);
        $slug = trim((string)($lesson['slug'] ?? ''));
        if ($title === '' || $slug === '') continue;
        $content = $cleanText($lesson['content'] ?? '', 7000);
        $summary = $cleanText($lesson['summary'] ?? '', 700);
        if ($summary === '') $summary = mb_substr($content, 0, 520, 'UTF-8');
        $collection = $cleanText($lesson['collection_title'] ?? '', 120);
        if ($collection !== '') $summary = trim($collection . ' — ' . $summary);
        $items[] = [
            'id' => 'lesson-' . (string)$lesson['id'],
            'source' => 'website',
            'type' => 'lesson',
            'title' => $title,
            'summary' => $summary,
            'content' => $content !== '' ? $content : $summary,
            'author' => $cleanText($lesson['teacher'] ?? '', 120),
            'created_at' => (string)($lesson['created_at'] ?? ''),
            'url' => $absoluteUrl('lesson/' . rawurlencode($slug)),
            'image_url' => $imageUrl($lesson),
        ];
    }

    $mediaStmt = $db->prepare(
        "SELECT m.id, m.kind, m.file_path, m.title AS media_title, m.created_at,
                COALESCE(p.title, l.title) AS parent_title,
                COALESCE(p.author_name, l.teacher, '') AS author,
                COALESCE(p.featured_image, l.featured_image, '') AS featured_image
         FROM media_files m
         LEFT JOIN posts p ON m.ref_type = 'post' AND p.id = m.ref_id AND p.status = 'published'
         LEFT JOIN lessons l ON m.ref_type = 'lesson' AND l.id = m.ref_id AND l.status = 'published'
         WHERE m.kind IN ('audio', 'video')
           AND ((m.ref_type = 'post' AND p.id IS NOT NULL)
             OR (m.ref_type = 'lesson' AND l.id IS NOT NULL))
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT ?"
    );
    $mediaStmt->bindValue(1, min(16, $limit), PDO::PARAM_INT);
    $mediaStmt->execute();
    foreach ($mediaStmt->fetchAll(PDO::FETCH_ASSOC) as $media) {
        $kind = (string)($media['kind'] ?? '');
        $mediaId = (int)($media['id'] ?? 0);
        $title = $cleanText($media['media_title'] ?? '', 180);
        $parentTitle = $cleanText($media['parent_title'] ?? '', 180);
        if ($mediaId < 1 || !in_array($kind, ['audio', 'video'], true)) continue;
        if ($title === '') $title = $parentTitle;
        if ($title === '' || $parentTitle === '') continue;
        $mediaPath = imgUrl((string)($media['file_path'] ?? ''));
        if (!preg_match('~^https://~i', $mediaPath)) continue;
        $items[] = [
            'id' => 'media-' . $kind . '-' . $mediaId,
            'source' => 'website',
            'type' => $kind,
            'title' => $title,
            'summary' => ($kind === 'audio' ? 'فایل صوتی مرتبط با: ' : 'ویدیوی مرتبط با: ') . $parentTitle,
            'content' => $parentTitle,
            'author' => $cleanText($media['author'] ?? '', 120),
            'created_at' => (string)($media['created_at'] ?? ''),
            'url' => $absoluteUrl(($kind === 'audio' ? 'audio/' : 'video/') . $mediaId),
            'image_url' => $imageUrl(['featured_image' => $media['featured_image'] ?? '']),
        ];
    }
} catch (Throwable $e) {
    // A partial read-only feed remains useful if an optional content section is
    // temporarily unavailable; do not include SQL errors or credentials in logs.
    error_log('Native feed: optional lesson/media sections unavailable.');
}

try {
    foreach (getTopics(['active' => 1, 'limit' => $discoveryLimit]) as $topic) {
        $title = $cleanText($topic['name'] ?? '', 150);
        $slug = trim((string)($topic['slug'] ?? ''));
        if ($title === '' || $slug === '') continue;
        $description = $cleanText($topic['intro'] ?? $topic['description'] ?? '', 2500);
        $items[] = [
            'id' => 'topic-' . (string)($topic['id'] ?? sha1($slug)),
            'source' => 'website',
            'type' => 'topic',
            'title' => $title,
            'summary' => mb_substr($description, 0, 520, 'UTF-8'),
            'content' => $description,
            'author' => '',
            'created_at' => (string)($topic['created_at'] ?? ''),
            'url' => $absoluteUrl('topics/' . rawurlencode($slug)),
            'image_url' => $imageUrl($topic),
        ];
    }
} catch (Throwable $e) {
    error_log('Native feed: published topics unavailable.');
}

try {
    $books = getBooks(['limit' => min(12, $limit), 'offset' => 0]);
    foreach ($books as $book) {
        $title = $cleanText($book['title'] ?? '', 180);
        if ($title === '') continue;
        $slug = trim((string)($book['slug'] ?? ''));
        $bookPath = $slug !== '' ? 'books/' . rawurlencode($slug) : 'books/' . (string)($book['id'] ?? '');
        $description = $cleanText($book['description'] ?? '', 2500);
        $items[] = [
            'id' => 'book-' . (string)($book['id'] ?? sha1($title)),
            'source' => 'website',
            'type' => 'book',
            'title' => $title,
            'summary' => mb_substr($description, 0, 520, 'UTF-8'),
            'content' => $description,
            'author' => $cleanText($book['author'] ?? '', 120),
            'created_at' => (string)($book['created_at'] ?? ''),
            'url' => $absoluteUrl($bookPath),
            'image_url' => $imageUrl($book),
        ];
    }
} catch (Throwable $e) {
    error_log('Native feed: published books unavailable.');
}

usort($items, static function (array $a, array $b): int {
    $aTime = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $bTime = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    if ($aTime === $bTime) return 0;
    return $bTime <=> $aTime;
});
$items = array_slice($items, 0, 55);

jhd_mobile_json([
    'ok' => true,
    'items' => $items,
    'meta' => [
        'count' => count($items),
        'refreshed_at' => gmdate('c'),
    ],
]);
