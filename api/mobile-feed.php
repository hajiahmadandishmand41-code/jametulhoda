<?php
/**
 * Public, read-only JSON feed used by the native Android application.
 *
 * Meta credentials are read only from server environment variables and never
 * sent to a device. Without those credentials the endpoint still serves the
 * school's published website content honestly.
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
$facebookConfigured = trim(env_value('META_PAGE_ID')) !== '' && trim(env_value('META_PAGE_ACCESS_TOKEN')) !== '';
$facebookConnected = false;
$facebookPageUrl = trim(env_value('META_PAGE_URL'));
$facebookPageName = trim(env_value('META_PAGE_NAME', 'مدرسه جامعه‌الهدی'));

if ($facebookConfigured) {
    $pageId = trim(env_value('META_PAGE_ID'));
    $accessToken = trim(env_value('META_PAGE_ACCESS_TOKEN'));
    $version = trim(env_value('META_GRAPH_API_VERSION', 'v26.0'));
    if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $pageId) &&
        preg_match('/^v[0-9]{1,2}\.[0-9]$/', $version)) {
        $query = http_build_query([
            'fields' => 'id,message,created_time,permalink_url,full_picture',
            'limit' => 15,
        ]);
        $graphUrl = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($pageId) . '/posts?' . $query;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nAuthorization: Bearer " . $accessToken . "\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $responseBody = @file_get_contents($graphUrl, false, $context);
        $graph = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if (is_array($graph) && isset($graph['data']) && is_array($graph['data']) && !isset($graph['error'])) {
            $facebookConnected = true;
            foreach ($graph['data'] as $post) {
                if (!is_array($post)) continue;
                $message = $cleanText($post['message'] ?? '', 7000);
                $picture = trim((string)($post['full_picture'] ?? ''));
                if ($message === '' && $picture === '') continue;
                $firstLine = trim(strtok($message, "\n") ?: '');
                $title = $firstLine !== '' ? mb_substr($firstLine, 0, 120, 'UTF-8') : 'پست تازه از فیسبوک';
                $items[] = [
                    'id' => 'facebook-' . (string)($post['id'] ?? sha1($message . ($post['created_time'] ?? ''))),
                    'source' => 'facebook',
                    'type' => 'facebook',
                    'title' => $title,
                    'summary' => mb_substr($message, 0, 520, 'UTF-8'),
                    'content' => $message,
                    'author' => $facebookPageName,
                    'created_at' => (string)($post['created_time'] ?? ''),
                    'url' => (string)($post['permalink_url'] ?? $facebookPageUrl),
                    'image_url' => preg_match('~^https://~i', $picture) ? $picture : '',
                ];
            }
        } else {
            $graphCode = is_array($graph) ? (string)($graph['error']['code'] ?? 'unknown') : 'no_response';
            error_log('Native feed: Meta Graph request failed (' . preg_replace('/[^A-Za-z0-9_-]/', '', $graphCode) . ').');
        }
    }
}

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
        'facebook_configured' => $facebookConfigured,
        'facebook_connected' => $facebookConnected,
        'facebook_page_url' => preg_match('~^https://(www\.)?facebook\.com/~i', $facebookPageUrl) ? $facebookPageUrl : '',
        'refreshed_at' => gmdate('c'),
    ],
]);
