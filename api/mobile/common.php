<?php
/**
 * api/mobile/common.php — مشترکات API بومی موبایل (جامعة‌الهدی)
 *
 * این خانواده از مسیرها فقط JSON برمی‌گرداند و قرارداد پایداری برای اپلیکیشن
 * اندروید فراهم می‌کند:
 *   • محتوای عمومی منتشرشده: خانه، فهرست‌ها، جزئیات، رسانه، جست‌وجو، موضوع‌ها
 *   • احراز هویت عضو با توکن Bearer (بدون cookie و بدون نشست وب)
 *   • لایک امن با قید یکتایی سمت دیتابیس (بدون شمارندهٔ قابل جعل)
 *
 * قواعد امنیتی:
 *   • هیچ رمز، کلید یا اطلاعات مدیریتی در پاسخ‌ها داده نمی‌شود.
 *   • توکن‌ها فقط به‌صورت hash (sha256) ذخیره می‌شوند؛ خود توکن در دیتابیس نیست.
 *   • نقش هرگز از ورودی خوانده نمی‌شود؛ ثبت‌نام همیشه role = user می‌سازد.
 *   • فقط محتوای published/فعال به اپ نشان داده می‌شود.
 *   • POSTهای عضو فقط با Bearer معتبر؛ عملیات عضو نیازی به CSRF cookie ندارند
 *     (Authority در هدر Authorization است، نه cookie محیطی).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/identity.php';
require_once __DIR__ . '/../../includes/member-auth.php';
require_once __DIR__ . '/../../includes/media.php';

// ─── پاسخ JSON ───────────────────────────────────────────────────────────────

/** هدرهای امن و ثابت همهٔ پاسخ‌های API موبایل. */
function jhd_api_headers(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/**
 * @param array<string,mixed> $payload
 */
function jhd_api_json(array $payload, int $status = 200): never {
    http_response_code($status);
    jhd_api_headers();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * @param list<array<string,mixed>> $items
 * @param array<string,mixed> $meta
 */
function jhd_api_list(array $items, array $meta = [], int $status = 200): never {
    jhd_api_json(['ok' => true, 'items' => $items, 'meta' => $meta], $status);
}

/**
 * @param array<string,mixed> $data
 */
function jhd_api_data(array $data, array $meta = [], int $status = 200): never {
    jhd_api_json(['ok' => true, 'data' => $data, 'meta' => $meta], $status);
}

function jhd_api_error(string $code, string $message, int $status = 400, array $extra = []): never {
    jhd_api_json(array_merge(['ok' => false, 'error' => $code, 'message' => $message], $extra), $status);
}

function jhd_api_method(array $allowed): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Allow: ' . implode(', ', $allowed) . ', OPTIONS');
        http_response_code(204);
        exit;
    }
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed) . ', OPTIONS');
        jhd_api_error('method_not_allowed', 'روش درخواست مجاز نیست.', 405);
    }
}

/** بدنهٔ JSON یا فرم درخواست؛ حداکثر ۶۴ کیلوبایت. */
function jhd_api_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return $_POST;
    if (strlen($raw) > 64 * 1024) jhd_api_error('payload_too_large', 'حجم درخواست بیش از حد مجاز است.', 413);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json') || str_starts_with(ltrim($raw), '{') || str_starts_with(ltrim($raw), '[')) {
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            jhd_api_error('invalid_json', 'بدنهٔ درخواست JSON معتبر نیست.', 400);
        }
        if (!is_array($decoded)) jhd_api_error('invalid_json', 'بدنهٔ درخواست JSON معتبر نیست.', 400);
        return $decoded;
    }
    $form = [];
    parse_str($raw, $form);
    return is_array($form) ? $form : [];
}

/** مقدار رشته‌ای تمیز از آرایهٔ ورودی. */
function jhd_api_str(array $body, string $key, int $max = 500): string {
    $value = $body[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) return '';
    $value = trim((string)$value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max, 'UTF-8');
}

function jhd_api_int(array $body, string $key, int $default = 0): int {
    $value = $body[$key] ?? null;
    if (is_int($value)) return $value;
    if (is_string($value) && preg_match('/^-?\d+$/', $value)) return (int)$value;
    return $default;
}

/** صفحه‌بندی یکنواخت. */
function jhd_api_page_params(array $source = []): array {
    $page = max(1, jhd_api_int($source, 'page', 1));
    $perPage = jhd_api_int($source, 'per_page', 20);
    $perPage = max(5, min(50, $perPage));
    return [$page, $perPage, ($page - 1) * $perPage];
}

function jhd_api_page_meta(int $page, int $perPage, int $total): array {
    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 0;
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
        'has_more' => $page < $totalPages,
    ];
}

// ─── اسکیمای موبایل (idempotent؛ هیچ داده‌ای حذف نمی‌شود) ─────────────────────

function jhd_mobile_api_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $driver = databaseDriver();
        if ($driver === 'mysql') {
            $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                device_label VARCHAR(120) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                UNIQUE KEY api_tokens_hash (token_hash),
                KEY api_tokens_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS content_likes (
                user_id INT NOT NULL,
                ref_type VARCHAR(20) NOT NULL,
                ref_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, ref_type, ref_id),
                KEY content_likes_ref (ref_type, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } elseif ($driver === 'sqlite') {
            $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                device_label TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at TEXT NULL,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL
            )");
            $db->exec('CREATE INDEX IF NOT EXISTS api_tokens_user ON api_tokens(user_id)');
            $db->exec("CREATE TABLE IF NOT EXISTS content_likes (
                user_id INTEGER NOT NULL,
                ref_type TEXT NOT NULL,
                ref_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, ref_type, ref_id)
            )");
            $db->exec('CREATE INDEX IF NOT EXISTS content_likes_ref ON content_likes(ref_type, ref_id)');
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
                id BIGSERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                device_label VARCHAR(120) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_used_at TIMESTAMPTZ NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                revoked_at TIMESTAMPTZ NULL
            )");
            $db->exec('CREATE INDEX IF NOT EXISTS api_tokens_user ON api_tokens(user_id)');
            $db->exec("CREATE TABLE IF NOT EXISTS content_likes (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                ref_type VARCHAR(20) NOT NULL,
                ref_id INTEGER NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (user_id, ref_type, ref_id)
            )");
            $db->exec('CREATE INDEX IF NOT EXISTS content_likes_ref ON content_likes(ref_type, ref_id)');
        }
    } catch (Throwable $e) {
        error_log('Mobile API schema ensure failed: ' . get_class($e));
    }
}

// ─── توکن‌های دسترسی ─────────────────────────────────────────────────────────

/** عمر توکن دسترسی: ۶۰ روز. */
const JHD_API_TOKEN_TTL = 60 * 24 * 3600;

/**
 * ساخت توکن تازه. خود توکن فقط یک‌بار برمی‌گردد؛ در دیتابیس hash آن است.
 *
 * @return array{token:string,expires_at:string}
 */
function jhd_api_issue_token(int $userId, string $deviceLabel = ''): array {
    jhd_mobile_api_schema();
    $db = getDB();
    $token = 'jh1_' . bin2hex(random_bytes(32));
    // فقط hash کل توکن (با پیشوند) ذخیره می‌شود؛ خود توکن هرگز در دیتابیس نیست.
    $hash = hash('sha256', $token);
    $expires = time() + JHD_API_TOKEN_TTL;
    $expiresSql = date('Y-m-d H:i:s', $expires);
    $label = mb_substr(trim($deviceLabel), 0, 120, 'UTF-8');
    if (databaseDriver() === 'mysql') {
        $db->prepare('INSERT INTO api_tokens (user_id, token_hash, device_label, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $hash, $label, $expiresSql]);
    } elseif (databaseDriver() === 'sqlite') {
        $db->prepare('INSERT INTO api_tokens (user_id, token_hash, device_label, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $hash, $label, $expiresSql]);
    } else {
        $db->prepare('INSERT INTO api_tokens (user_id, token_hash, device_label, expires_at) VALUES (?, ?, ?, to_timestamp(?))')
            ->execute([$userId, $hash, $label, $expires]);
    }
    return ['token' => $token, 'expires_at' => gmdate('c', $expires)];
}

/** باطل‌کردن همهٔ توکن‌های یک کاربر (تغییر رمز / خروج کامل). */
function jhd_api_revoke_tokens(int $userId, ?string $rawToken = null): void {
    jhd_mobile_api_schema();
    $db = getDB();
    if ($rawToken !== null) {
        $db->prepare('UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND token_hash = ?')
            ->execute([$userId, hash('sha256', $rawToken)]);
        return;
    }
    $db->prepare('UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND revoked_at IS NULL')
        ->execute([$userId]);
}

/** توکن Bearer درخواست جاری (بدون Bearer ⇒ null). */
function jhd_api_bearer_token(): ?string {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '') {
        // برخی میزبان‌ها Authorization را در هدر سفارشی می‌فرستند.
        $header = (string)($_SERVER['HTTP_X_AUTHORIZATION'] ?? '');
    }
    if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) return null;
    $token = $m[1];
    return str_starts_with($token, 'jh1_') ? $token : null;
}

/**
 * کاربر جاری از روی توکن Bearer. null ⇒ ناشناس/نامعتبر.
 *
 * @return array<string,mixed>|null
 */
function jhd_api_user(bool $required = false): ?array {
    static $cache = false;
    if ($cache !== false) return $cache ?: null;
    $token = jhd_api_bearer_token();
    if ($token === null) {
        if ($required) jhd_api_error('unauthorized', 'برای این عملیات باید وارد حساب شوید.', 401);
        $cache = null;
        return null;
    }
    jhd_mobile_api_schema();
    $db = getDB();
    try {
        $stmt = $db->prepare(
            'SELECT t.id AS token_id, t.expires_at, t.revoked_at, u.* FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('Mobile token lookup failed: ' . get_class($e));
        $row = false;
    }
    if (!$row) {
        if ($required) jhd_api_error('unauthorized', 'نشست شما معتبر نیست؛ دوباره وارد شوید.', 401);
        $cache = null;
        return null;
    }
    if ($row['revoked_at'] !== null && $row['revoked_at'] !== '') {
        if ($required) jhd_api_error('unauthorized', 'نشست شما باطل شده است؛ دوباره وارد شوید.', 401);
        $cache = null;
        return null;
    }
    $expires = strtotime((string)$row['expires_at']) ?: 0;
    if ($expires < time()) {
        if ($required) jhd_api_error('unauthorized', 'نشست شما منقضی شده است؛ دوباره وارد شوید.', 401);
        $cache = null;
        return null;
    }
    if ((int)$row['is_active'] !== 1) {
        jhd_api_revoke_tokens((int)$row['id']);
        if ($required) jhd_api_error('forbidden', 'حساب شما فعال نیست.', 403);
        $cache = null;
        return null;
    }
    $user = jhd_user_public($row);
    $user['auth_version'] = (int)($row['auth_version'] ?? 1);
    $user['token_id'] = (int)$row['token_id'];
    // فعال‌سازی نرم زمان استفاده؛ شکست آن امنیت را دور نمی‌زند.
    try {
        $db->prepare('UPDATE api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([(int)$row['token_id']]);
    } catch (Throwable $e) {
    }
    $cache = $user;
    return $user;
}

/** نمای عمومی حساب برای خود کاربر (بدون هیچ رمز/توکنی). */
function jhd_api_account(array $user): array {
    return [
        'id' => (int)$user['id'],
        'full_name' => (string)$user['full_name'],
        'username' => (string)$user['username'],
        'email' => (string)$user['email'],
        'phone' => (string)$user['phone'],
        'country' => (string)$user['country'],
        'country_code' => (string)$user['country_code'],
        'role' => (string)$user['role'],
        'must_change_password' => (int)$user['must_change_password'],
        'created_at' => (string)$user['created_at'],
        'last_login' => (string)$user['last_login'],
    ];
}

// ─── لایک ────────────────────────────────────────────────────────────────────

const JHD_API_LIKE_TYPES = ['post', 'book', 'lesson', 'media'];

/** آیا این محتوا برای لایک موجود و منتشرشده است؟ */
function jhd_api_content_exists(string $refType, int $refId): bool {
    if ($refId < 1 || !in_array($refType, JHD_API_LIKE_TYPES, true)) return false;
    try {
        $db = getDB();
        $sql = match ($refType) {
            'post' => "SELECT COUNT(*) FROM posts WHERE id = ? AND status = 'published'",
            'book' => "SELECT COUNT(*) FROM books WHERE id = ? AND status = 'published'",
            'lesson' => "SELECT COUNT(*) FROM lessons WHERE id = ? AND status = 'published'",
            default => "SELECT COUNT(*) FROM media_files m WHERE m.id = ? AND m.kind IN ('audio','video')
                        AND ((m.ref_type = 'post' AND EXISTS (SELECT 1 FROM posts p WHERE p.id = m.ref_id AND p.status = 'published'))
                          OR (m.ref_type = 'lesson' AND EXISTS (SELECT 1 FROM lessons l WHERE l.id = m.ref_id AND l.status = 'published'))
                          OR (m.ref_type = 'book' AND EXISTS (SELECT 1 FROM books b WHERE b.id = m.ref_id AND b.status = 'published')))",
        };
        $stmt = $db->prepare($sql);
        $stmt->execute([$refId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function jhd_api_like_count(string $refType, int $refId): int {
    try {
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM content_likes WHERE ref_type = ? AND ref_id = ?');
        $stmt->execute([$refType, $refId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function jhd_api_viewer_liked(string $refType, int $refId, ?array $user): ?bool {
    if ($user === null) return null;
    try {
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM content_likes WHERE user_id = ? AND ref_type = ? AND ref_id = ?');
        $stmt->execute([(int)$user['id'], $refType, $refId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{like_count:int,liked:?bool}
 */
function jhd_api_like_state(string $refType, int $refId, ?array $user): array {
    return [
        'like_count' => jhd_api_like_count($refType, $refId),
        'liked' => jhd_api_viewer_liked($refType, $refId, $user),
    ];
}

// ─── سریال‌ساز محتوا ─────────────────────────────────────────────────────────

function jhd_api_abs(string $path): string {
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    // نشانی‌های سایت (assets/uploads/مسیرها) با مبدأ پیکربندی‌شده یکی می‌شوند؛
    // همان قاعدهٔ فید mobile-feed. نشانی نسبی هرگز به اپ برنمی‌گردد.
    $siteOrigin = rtrim(SITE_URL, '/');
    $basePath = trim(BASE_PATH, '/');
    $basePrefix = $basePath !== '' ? '/' . $basePath : '';
    return $siteOrigin . $basePrefix . '/' . ltrim($path, '/');
}

function jhd_api_img(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    if (str_starts_with($path, '//')) return 'https:' . $path;
    $url = imgUrl($path);
    return jhd_api_abs($url !== '' ? $url : $path);
}

/** متن سادهٔ بدون HTML؛ برای نمایش کارت و جست‌وجو در اپ. */
function jhd_api_text(?string $html, int $max = 8000): string {
    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim(mb_substr(trim($text), 0, $max, 'UTF-8'));
}

function jhd_api_html(?string $html): string {
    return safeRichText((string)$html);
}

function jhd_api_date(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value);
    return $ts ? gmdate('c', $ts) : '';
}

function jhd_api_post_type(string $type): string {
    $allowed = ['news', 'article', 'research', 'report', 'announcement', 'speech', 'program', 'religious', 'qa', 'book_note', 'event'];
    return in_array($type, $allowed, true) ? $type : 'news';
}

/** نگاشت post_type به مسیر وب (برای لینک اشتراک‌گذاری). */
function jhd_api_post_url(array $row): string {
    $type = (string)($row['post_type'] ?? 'news');
    $slug = trim((string)($row['slug'] ?? ''));
    $path = match ($type) {
        'article' => 'articles',
        'research' => 'research',
        'report' => 'reports',
        'announcement' => 'announcements',
        'event' => 'events',
        'program' => 'programs',
        'religious' => 'religious-activities',
        'qa' => 'qa',
        'speech' => 'speeches',
        default => 'news',
    };
    return $slug !== '' ? jhd_api_abs($path . '/' . rawurlencode($slug)) : jhd_api_abs('post/' . (int)($row['id'] ?? 0));
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jhd_api_post_item(array $row, bool $detail = false, ?array $viewer = null): array {
    $id = (int)($row['id'] ?? 0);
    $contentHtml = (string)($row['content'] ?? '');
    $summary = jhd_api_text((string)($row['summary'] ?? ''), 700);
    $contentText = jhd_api_text($contentHtml, 12000);
    if ($summary === '') $summary = mb_substr($contentText, 0, 520, 'UTF-8');
    $item = [
        'id' => $id,
        'key' => 'post-' . $id,
        'kind' => 'post',
        'type' => jhd_api_post_type((string)($row['post_type'] ?? 'news')),
        'slug' => (string)($row['slug'] ?? ''),
        'title' => jhd_api_text((string)($row['title'] ?? ''), 200),
        'summary' => $summary,
        'author' => jhd_api_text((string)($row['author_name'] ?? ''), 120),
        'speaker' => jhd_api_text((string)($row['speaker'] ?? ''), 120),
        'category' => (($row['cat_name'] ?? '') !== '' && ($row['cat_name'] ?? null) !== null)
            ? ['id' => (int)($row['category_id'] ?? 0), 'name' => (string)$row['cat_name'], 'slug' => (string)($row['cat_slug'] ?? '')]
            : null,
        'published_at' => jhd_api_date($row['published_at'] ?? null),
        'created_at' => jhd_api_date($row['created_at'] ?? null),
        'image_url' => jhd_api_img($row['featured_image'] ?? null),
        'url' => jhd_api_post_url($row),
    ];
    if ($detail) {
        $item['content_text'] = $contentText;
        $item['content_html'] = jhd_api_html($contentHtml);
        $item['sources'] = jhd_api_text((string)($row['sources'] ?? ''), 2000);
        try {
            $stmt = getDB()->prepare('SELECT id, image_path, alt_text FROM post_images WHERE post_id = ? ORDER BY sort_order ASC, id ASC');
            $stmt->execute([$id]);
            $gallery = [];
            foreach ($stmt->fetchAll() as $image) {
                $url = jhd_api_img((string)$image['image_path']);
                if ($url === '') continue;
                $gallery[] = ['id' => (int)$image['id'], 'url' => $url, 'alt' => jhd_api_text((string)($image['alt_text'] ?? ''), 200)];
            }
            $item['gallery'] = $gallery;
        } catch (Throwable $e) {
            $item['gallery'] = [];
        }
        $attachments = [];
        foreach (['audio', 'video', 'document'] as $kind) {
            foreach (getMediaFor('post', $id, $kind) as $media) {
                $attachments[] = jhd_api_attachment_row($media, $kind);
            }
        }
        $item['attachments'] = $attachments;
        $item['featured_video_url'] = jhd_api_img($row['featured_video'] ?? null);
        try {
            $stmt = getDB()->prepare('SELECT t.id, t.name, t.slug FROM post_topics pt JOIN topics t ON t.id = pt.topic_id WHERE pt.post_id = ? AND t.is_active = 1 ORDER BY pt.is_primary DESC, t.sort_order ASC');
            $stmt->execute([$id]);
            $item['topics'] = array_map(static fn($t): array => [
                'id' => (int)$t['id'], 'name' => (string)$t['name'], 'slug' => (string)$t['slug'],
            ], $stmt->fetchAll());
        } catch (Throwable $e) {
            $item['topics'] = [];
        }
    }
    return array_merge($item, jhd_api_like_state('post', $id, $viewer));
}

/**
 * @param array<string,mixed> $media
 * @return array<string,mixed>
 */
function jhd_api_attachment_row(array $media, string $fallbackKind = ''): array {
    $kind = (string)($media['kind'] ?? $fallbackKind);
    if (!in_array($kind, ['audio', 'video', 'document', 'image'], true)) $kind = $fallbackKind;
    $path = (string)($media['file_path'] ?? '');
    $url = jhd_api_img($path);
    return [
        'id' => (int)($media['id'] ?? 0),
        'kind' => $kind,
        'title' => jhd_api_text((string)($media['title'] ?? ''), 200),
        'url' => $url,
        'size' => (int)($media['size'] ?? 0),
    ];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jhd_api_book_item(array $row, bool $detail = false, ?array $viewer = null): array {
    $id = (int)($row['id'] ?? 0);
    $slug = trim((string)($row['slug'] ?? ''));
    $item = [
        'id' => $id,
        'key' => 'book-' . $id,
        'kind' => 'book',
        'type' => 'book',
        'slug' => $slug,
        'title' => jhd_api_text((string)($row['title'] ?? ''), 220),
        'summary' => jhd_api_text((string)($row['description'] ?? ''), 700),
        'author' => jhd_api_text((string)($row['author'] ?? ''), 120),
        'translator' => jhd_api_text((string)($row['translator'] ?? ''), 120),
        'publisher' => jhd_api_text((string)($row['publisher'] ?? ''), 120),
        'publish_year' => jhd_api_text((string)($row['publish_year'] ?? ''), 20),
        'pages' => ($row['pages'] ?? null) !== null && ($row['pages'] ?? '') !== '' ? (int)$row['pages'] : null,
        'published_at' => jhd_api_date($row['created_at'] ?? null),
        'created_at' => jhd_api_date($row['created_at'] ?? null),
        'image_url' => jhd_api_img($row['cover_image'] ?? null),
        'url' => $slug !== '' ? jhd_api_abs('books/' . rawurlencode($slug)) : jhd_api_abs('books/' . $id),
    ];
    if ($detail) {
        $item['content_text'] = jhd_api_text((string)($row['description'] ?? ''), 12000);
        $item['content_html'] = jhd_api_html((string)($row['description'] ?? ''));
        $item['toc'] = jhd_api_text((string)($row['toc'] ?? ''), 8000);
        $item['pdf_url'] = jhd_api_img($row['pdf_file'] ?? null);
        $item['word_url'] = jhd_api_img($row['word_file'] ?? null);
        $attachments = [];
        foreach (getMediaFor('book', $id, 'document') as $media) {
            $attachments[] = jhd_api_attachment_row($media, 'document');
        }
        foreach (getMediaFor('book', $id, 'audio') as $media) {
            $attachments[] = jhd_api_attachment_row($media, 'audio');
        }
        $item['attachments'] = $attachments;
        try {
            $stmt = getDB()->prepare('SELECT t.id, t.name, t.slug FROM book_topics bt JOIN topics t ON t.id = bt.topic_id WHERE bt.book_id = ? AND t.is_active = 1 ORDER BY t.sort_order ASC');
            $stmt->execute([$id]);
            $item['topics'] = array_map(static fn($t): array => [
                'id' => (int)$t['id'], 'name' => (string)$t['name'], 'slug' => (string)$t['slug'],
            ], $stmt->fetchAll());
        } catch (Throwable $e) {
            $item['topics'] = [];
        }
    }
    return array_merge($item, jhd_api_like_state('book', $id, $viewer));
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jhd_api_lesson_item(array $row, bool $detail = false, ?array $viewer = null): array {
    $id = (int)($row['id'] ?? 0);
    $slug = trim((string)($row['slug'] ?? ''));
    $contentHtml = (string)($row['content'] ?? '');
    $summary = jhd_api_text((string)($row['summary'] ?? ''), 700);
    $contentText = jhd_api_text($contentHtml, 12000);
    if ($summary === '') $summary = mb_substr($contentText, 0, 520, 'UTF-8');
    $item = [
        'id' => $id,
        'key' => 'lesson-' . $id,
        'kind' => 'lesson',
        'type' => 'lesson',
        'slug' => $slug,
        'title' => jhd_api_text((string)($row['title'] ?? ''), 220),
        'summary' => $summary,
        'author' => jhd_api_text((string)($row['teacher'] ?? ''), 120),
        'subject' => jhd_api_text((string)($row['subject'] ?? ''), 150),
        'level' => (string)($row['level'] ?? ''),
        'lesson_number' => ($row['lesson_number'] ?? null) !== null && ($row['lesson_number'] ?? '') !== '' ? (int)$row['lesson_number'] : null,
        'collection_id' => (int)($row['collection_id'] ?? 0),
        'collection_title' => jhd_api_text((string)($row['collection_title'] ?? ''), 160),
        'volume_id' => (int)($row['volume_id'] ?? 0),
        'volume_title' => jhd_api_text((string)($row['volume_title'] ?? ''), 160),
        'published_at' => jhd_api_date($row['created_at'] ?? null),
        'created_at' => jhd_api_date($row['created_at'] ?? null),
        'image_url' => jhd_api_img($row['featured_image'] ?? null),
        'url' => $slug !== '' ? jhd_api_abs('lesson/' . rawurlencode($slug)) : jhd_api_abs('lessons'),
    ];
    if ($detail) {
        $item['content_text'] = $contentText;
        $item['content_html'] = jhd_api_html($contentHtml);
        $item['sources'] = jhd_api_text((string)($row['sources'] ?? ''), 2000);
        $item['audio_url'] = jhd_api_img($row['audio_file'] ?? null);
        $item['video_url'] = jhd_api_img($row['video_file'] ?? null);
        $item['pdf_url'] = jhd_api_img($row['pdf_file'] ?? null);
        $attachments = [];
        foreach (['audio', 'video', 'document'] as $kind) {
            foreach (getMediaFor('lesson', $id, $kind) as $media) {
                $attachments[] = jhd_api_attachment_row($media, $kind);
            }
        }
        $item['attachments'] = $attachments;
        try {
            $stmt = getDB()->prepare('SELECT t.id, t.name, t.slug FROM lesson_topics lt JOIN topics t ON t.id = lt.topic_id WHERE lt.lesson_id = ? AND t.is_active = 1 ORDER BY t.sort_order ASC');
            $stmt->execute([$id]);
            $item['topics'] = array_map(static fn($t): array => [
                'id' => (int)$t['id'], 'name' => (string)$t['name'], 'slug' => (string)$t['slug'],
            ], $stmt->fetchAll());
        } catch (Throwable $e) {
            $item['topics'] = [];
        }
    }
    return array_merge($item, jhd_api_like_state('lesson', $id, $viewer));
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jhd_api_media_item(array $row, bool $detail = false, ?array $viewer = null): array {
    $id = (int)($row['id'] ?? 0);
    $kind = (string)($row['kind'] ?? '');
    if (!in_array($kind, ['audio', 'video'], true)) $kind = 'audio';
    $parentTitle = jhd_api_text((string)($row['parent_title'] ?? ''), 200);
    $title = jhd_api_text((string)($row['title'] ?? ''), 200);
    if ($title === '') $title = $parentTitle;
    $item = [
        'id' => $id,
        'key' => 'media-' . $kind . '-' . $id,
        'kind' => 'media',
        'type' => $kind,
        'slug' => '',
        'title' => $title,
        'summary' => $parentTitle !== '' ? ('رسانهٔ مرتبط با: ' . $parentTitle) : '',
        'author' => jhd_api_text((string)($row['author'] ?? ''), 120),
        'parent' => [
            'kind' => (string)($row['ref_type'] ?? ''),
            'id' => (int)($row['ref_id'] ?? 0),
            'title' => $parentTitle,
            'url' => (string)($row['parent_url'] ?? ''),
        ],
        'published_at' => jhd_api_date($row['created_at'] ?? null),
        'created_at' => jhd_api_date($row['created_at'] ?? null),
        'image_url' => jhd_api_img($row['featured_image'] ?? null),
        'media_url' => jhd_api_img($row['file_path'] ?? null),
        'url' => jhd_api_abs(($kind === 'video' ? 'video/' : 'audio/') . $id),
        'size' => (int)($row['size'] ?? 0),
    ];
    return array_merge($item, jhd_api_like_state('media', $id, $viewer));
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jhd_api_topic_item(array $row): array {
    $id = (int)($row['id'] ?? 0);
    $slug = trim((string)($row['slug'] ?? ''));
    return [
        'id' => $id,
        'key' => 'topic-' . $id,
        'kind' => 'topic',
        'type' => 'topic',
        'slug' => $slug,
        'parent_id' => (int)($row['parent_id'] ?? 0),
        'title' => jhd_api_text((string)($row['name'] ?? ''), 160),
        'summary' => jhd_api_text((string)($row['intro'] ?? $row['description'] ?? ''), 700),
        'image_url' => jhd_api_img($row['cover_image'] ?? null),
        'url' => $slug !== '' ? jhd_api_abs('topics/' . rawurlencode($slug)) : jhd_api_abs('topics'),
    ];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jhd_api_collection_item(array $row): array {
    $id = (int)($row['id'] ?? 0);
    $slug = trim((string)($row['slug'] ?? ''));
    return [
        'id' => $id,
        'key' => 'collection-' . $id,
        'kind' => 'collection',
        'type' => 'collection',
        'slug' => $slug,
        'title' => jhd_api_text((string)($row['title'] ?? ''), 200),
        'summary' => jhd_api_text((string)($row['description'] ?? ''), 700),
        'image_url' => jhd_api_img($row['cover_image'] ?? null),
        'lesson_count' => (int)($row['lesson_count'] ?? 0),
        'url' => $slug !== '' ? jhd_api_abs('lessons/' . rawurlencode($slug)) : jhd_api_abs('lessons'),
    ];
}

/** تازه‌ترین درس‌های منتشرشده (برای صفحهٔ اصلی اپ). */
function jhd_api_recent_lessons(int $limit): array {
    try {
        $limit = max(1, min(50, $limit));
        $stmt = getDB()->prepare(
            "SELECT l.*, c.title AS collection_title, v.title AS volume_title
             FROM lessons l
             LEFT JOIN lesson_collections c ON c.id = l.collection_id
             LEFT JOIN lesson_volumes v ON v.id = l.volume_id
             WHERE l.status = 'published'
             ORDER BY l.is_featured DESC, l.created_at DESC, l.id DESC
             LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** اطلاعات پایهٔ سایت برای سربرگ اپ (لوگو، نام، تماس). */
function jhd_api_site_info(): array {
    return [
        'name' => SITE_NAME,
        'slogan' => SITE_SLOGAN,
        'description' => SITE_DESCRIPTION,
        'logo_url' => jhd_api_abs(SITE_LOGO_PATH),
        'url' => rtrim(SITE_URL, '/') . (BASE_PATH !== '' ? BASE_PATH : ''),
        'email' => getSetting('site_email', SITE_EMAIL),
        'phone' => getSetting('phone', SITE_PHONE),
        'address' => getSetting('address', SITE_ADDRESS),
    ];
}
