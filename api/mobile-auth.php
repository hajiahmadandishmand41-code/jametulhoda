<?php
/**
 * Native Android account API.
 *
 * Access and refresh tokens are random opaque values. Only SHA-256 digests are
 * persisted; browser session cookies are not used by this API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Vary: Authorization');

const JHD_MOBILE_ACCESS_TTL = 900;       // 15 minutes
const JHD_MOBILE_REFRESH_TTL = 2592000;  // 30 days

function jhd_mobile_auth_json(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jhd_mobile_auth_now_sql(): string {
    return databaseDriver() === 'sqlite' ? "datetime('now')" : 'NOW()';
}

function jhd_mobile_auth_expiry_sql(int $ttl): string {
    return match (databaseDriver()) {
        'mysql' => "DATE_ADD(NOW(), INTERVAL " . max(1, $ttl) . " SECOND)",
        'sqlite' => "datetime('now', '+" . max(1, $ttl) . " seconds')",
        default => "NOW() + INTERVAL '" . max(1, $ttl) . " seconds'",
    };
}

/** The token table is deliberately separate from browser sessions. */
function jhd_mobile_auth_ensure_schema(): void {
    static $ready = false;
    if ($ready) return;
    $db = getDB();
    switch (databaseDriver()) {
        case 'mysql':
            $db->exec("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
                token_hash CHAR(64) NOT NULL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                auth_version INT NOT NULL,
                family_id CHAR(32) NOT NULL,
                token_type VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                last_used_at DATETIME NULL,
                INDEX mobile_api_tokens_user_idx (user_id),
                INDEX mobile_api_tokens_family_idx (family_id),
                INDEX mobile_api_tokens_expiry_idx (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            break;
        case 'sqlite':
            $db->exec("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
                token_hash TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                auth_version INTEGER NOT NULL,
                family_id TEXT NOT NULL,
                token_type TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL,
                last_used_at TEXT NULL
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS mobile_api_tokens_user_idx ON mobile_api_tokens(user_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS mobile_api_tokens_family_idx ON mobile_api_tokens(family_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS mobile_api_tokens_expiry_idx ON mobile_api_tokens(expires_at)");
            break;
        default:
            $db->exec("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
                token_hash CHAR(64) PRIMARY KEY,
                user_id BIGINT NOT NULL,
                auth_version INT NOT NULL,
                family_id CHAR(32) NOT NULL,
                token_type VARCHAR(16) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMPTZ NOT NULL,
                revoked_at TIMESTAMPTZ NULL,
                last_used_at TIMESTAMPTZ NULL
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS mobile_api_tokens_user_idx ON mobile_api_tokens(user_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS mobile_api_tokens_family_idx ON mobile_api_tokens(family_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS mobile_api_tokens_expiry_idx ON mobile_api_tokens(expires_at)");
            break;
    }
    $ready = true;
}

function jhd_mobile_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header) || !preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $m)) return '';
    return strtolower($m[1]);
}

function jhd_mobile_safe_user(array $user): array {
    $avatar = trim((string)($user['avatar'] ?? ''));
    return [
        'id' => (int)($user['id'] ?? 0),
        'full_name' => (string)($user['full_name'] ?? ''),
        'username' => (string)($user['username'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'avatar_url' => $avatar !== '' ? imgUrl($avatar) : '',
        'role' => jhd_normalize_role($user['role'] ?? null),
        'must_change_password' => (bool)($user['must_change_password'] ?? false),
    ];
}

/** Issue a token pair atomically; only token hashes are stored. */
function jhd_mobile_issue_pair(int $userId, ?string $familyId = null): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, auth_version FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('mobile_user_unavailable');

    $version = max(1, (int)($row['auth_version'] ?? 1));
    $familyId = $familyId ?? bin2hex(random_bytes(16));
    $access = bin2hex(random_bytes(32));
    $refresh = bin2hex(random_bytes(32));
    $nowSql = jhd_mobile_auth_now_sql();

    $startedTransaction = !$db->inTransaction();
    if ($startedTransaction) $db->beginTransaction();
    try {
        $accessExpiry = jhd_mobile_auth_expiry_sql(JHD_MOBILE_ACCESS_TTL);
        $refreshExpiry = jhd_mobile_auth_expiry_sql(JHD_MOBILE_REFRESH_TTL);
        $insert = $db->prepare("INSERT INTO mobile_api_tokens
            (token_hash, user_id, auth_version, family_id, token_type, created_at, expires_at, revoked_at, last_used_at)
            VALUES (?, ?, ?, ?, ?, $nowSql, " . $accessExpiry . ", NULL, NULL)");
        $insert->execute([hash('sha256', $access), $userId, $version, $familyId, 'access']);
        $insert = $db->prepare("INSERT INTO mobile_api_tokens
            (token_hash, user_id, auth_version, family_id, token_type, created_at, expires_at, revoked_at, last_used_at)
            VALUES (?, ?, ?, ?, ?, $nowSql, " . $refreshExpiry . ", NULL, NULL)");
        $insert->execute([hash('sha256', $refresh), $userId, $version, $familyId, 'refresh']);
        if ($startedTransaction) $db->commit();
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $issuedAt = time();
    return [
        'access_token' => $access,
        'token_type' => 'Bearer',
        'expires_in' => JHD_MOBILE_ACCESS_TTL,
        'access_expires_at' => gmdate('c', $issuedAt + JHD_MOBILE_ACCESS_TTL),
        'refresh_token' => $refresh,
        'refresh_expires_in' => JHD_MOBILE_REFRESH_TTL,
        'refresh_expires_at' => gmdate('c', $issuedAt + JHD_MOBILE_REFRESH_TTL),
    ];
}

function jhd_mobile_revoke_family(string $familyId): void {
    if (!preg_match('/^[a-f0-9]{32}$/', $familyId)) return;
    $db = getDB();
    $db->prepare('UPDATE mobile_api_tokens SET revoked_at = ' . jhd_mobile_auth_now_sql() . ' WHERE family_id = ? AND revoked_at IS NULL')
       ->execute([$familyId]);
}

function jhd_mobile_find_token(string $rawToken, string $type): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken) || !in_array($type, ['access', 'refresh'], true)) return null;
    jhd_mobile_auth_ensure_schema();
    $db = getDB();
    $validExpr = '(expires_at > ' . jhd_mobile_auth_now_sql() . ')';
    $stmt = $db->prepare("SELECT token_hash, user_id, auth_version, family_id, token_type, revoked_at,
            CASE WHEN $validExpr THEN 1 ELSE 0 END AS unexpired
        FROM mobile_api_tokens WHERE token_hash = ? AND token_type = ? LIMIT 1");
    $stmt->execute([hash('sha256', strtolower($rawToken)), $type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Resolve an active access token against current account status and auth_version. */
function jhd_mobile_require_user(?string $rawToken = null): array {
    $rawToken = $rawToken ?? jhd_mobile_bearer_token();
    if ($rawToken === '') jhd_mobile_auth_json(['ok' => false, 'error' => 'unauthorized', 'message' => 'برای ادامه وارد حساب شوید.'], 401);
    jhd_mobile_auth_ensure_schema();
    $db = getDB();
    $nowSql = jhd_mobile_auth_now_sql();
    $stmt = $db->prepare("SELECT t.family_id, t.user_id, t.auth_version AS token_auth_version,
            u.id, u.username, u.email, u.phone, u.full_name, u.role, u.avatar,
            u.must_change_password, u.is_active, u.auth_version
        FROM mobile_api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.token_type = 'access'
          AND t.revoked_at IS NULL AND t.expires_at > $nowSql
        LIMIT 1");
    $stmt->execute([hash('sha256', strtolower($rawToken))]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int)$user['is_active'] !== 1 || (int)$user['token_auth_version'] !== (int)$user['auth_version']) {
        header('WWW-Authenticate: Bearer');
        jhd_mobile_auth_json(['ok' => false, 'error' => 'unauthorized', 'message' => 'نشست معتبر نیست یا منقضی شده است.'], 401);
    }
    return $user;
}

function jhd_mobile_read_json_body(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 16384) jhd_mobile_auth_json(['ok' => false, 'error' => 'payload_too_large', 'message' => 'حجم درخواست بیش از حد مجاز است.'], 413);
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'unsupported_media_type', 'message' => 'درخواست باید JSON باشد.'], 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (!is_string($raw) || strlen($raw) > 16384) jhd_mobile_auth_json(['ok' => false, 'error' => 'payload_too_large', 'message' => 'حجم درخواست بیش از حد مجاز است.'], 413);
    try {
        $data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_json', 'message' => 'ساختار JSON معتبر نیست.'], 400);
    }
    if (!is_array($data) || array_is_list($data)) jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_json', 'message' => 'بدنه باید یک شیء JSON باشد.'], 400);
    return $data;
}

function jhd_mobile_string_field(array $data, string $key, int $max = 4096): string {
    $value = $data[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max) return '';
    return $value;
}

function jhd_mobile_token_row_for_rotation(string $rawToken): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) return null;
    jhd_mobile_auth_ensure_schema();
    $db = getDB();
    $lock = databaseDriver() === 'sqlite' ? '' : ' FOR UPDATE';
    $stmt = $db->prepare("SELECT t.token_hash, t.user_id, t.auth_version, t.family_id, t.revoked_at,
            CASE WHEN t.expires_at > " . jhd_mobile_auth_now_sql() . " THEN 1 ELSE 0 END AS unexpired,
            u.is_active, u.auth_version AS current_auth_version
        FROM mobile_api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.token_type = 'refresh'
        LIMIT 1" . $lock);
    $stmt->execute([hash('sha256', strtolower($rawToken))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    jhd_mobile_auth_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

if ($method === 'GET' || $method === 'HEAD') {
    $user = jhd_mobile_require_user();
    $profile = jhd_mobile_safe_user($user);
    jhd_mobile_auth_json(['ok' => true, 'user' => $profile]);
}

$data = jhd_mobile_read_json_body();
$action = jhd_mobile_string_field($data, 'action', 32);
if ($action === '') jhd_mobile_auth_json(['ok' => false, 'error' => 'action_required', 'message' => 'نوع عملیات مشخص نیست.'], 400);

try {
    jhd_mobile_auth_ensure_schema();
    // Native registration shares the existing anti-brute-force table with browser auth.
    ensureCoreAuthTables();

    if ($action === 'login') {
        $identifier = trim(jhd_mobile_string_field($data, 'identifier', 190));
        $password = jhd_mobile_string_field($data, 'password', 4096);
        if ($identifier === '' || $password === '') {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'validation_error', 'message' => 'شناسه و رمز عبور را وارد کنید.'], 422);
        }
        // Reuse the site's credential verification, throttling and last-login logic,
        // but explicitly avoid creating a browser cookie/session for a native client.
        $result = jhd_login($identifier, $password, false, false);
        if (empty($result['ok'])) {
            $code = (string)($result['code'] ?? '');
            $status = $code === 'db_unavailable' ? 503 : 401;
            jhd_mobile_auth_json([
                'ok' => false,
                'error' => $code === 'locked' ? 'rate_limited' : 'invalid_credentials',
                'message' => $status === 503 ? 'ورود فعلاً ممکن نیست. بعداً دوباره تلاش کنید.' : (string)($result['error'] ?? 'شناسه یا رمز عبور نادرست است.'),
            ], $status);
        }
        $tokens = jhd_mobile_issue_pair((int)$result['user']['id']);
        $fresh = jhd_user_by_id((int)$result['user']['id']);
        jhd_mobile_auth_json(['ok' => true] + $tokens + ['user' => jhd_mobile_safe_user($fresh ?? $result['user'])]);
    }

    if ($action === 'register') {
        $fields = [
            'full_name', 'country', 'phone', 'email', 'password', 'password_confirm',
        ];
        $register = [];
        foreach ($fields as $field) $register[$field] = jhd_mobile_string_field($data, $field, $field === 'password' || $field === 'password_confirm' ? 4096 : 250);
        $agreed = $data['agreed_terms'] ?? false;
        $register['agreed_terms'] = ($agreed === true || $agreed === 1 || $agreed === '1') ? '1' : '';
        $result = registerMember($register, false);
        if (empty($result['ok'])) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'registration_failed', 'message' => (string)($result['error'] ?? 'ثبت‌نام انجام نشد.')], 422);
        }
        $userId = (int)$result['id'];
        $fresh = jhd_user_by_id($userId);
        if (!$fresh) jhd_mobile_auth_json(['ok' => false, 'error' => 'registration_failed', 'message' => 'ساخت حساب کامل نشد. دوباره تلاش کنید.'], 503);
        $tokens = jhd_mobile_issue_pair($userId);
        jhd_mobile_auth_json(['ok' => true] + $tokens + ['user' => jhd_mobile_safe_user($fresh)], 201);
    }

    if ($action === 'refresh') {
        $refresh = jhd_mobile_string_field($data, 'refresh_token', 64);
        if (!preg_match('/^[a-f0-9]{64}$/i', $refresh)) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_refresh_token', 'message' => 'نشست معتبر نیست؛ دوباره وارد شوید.'], 401);
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            $row = jhd_mobile_token_row_for_rotation($refresh);
            if (!$row) {
                $db->rollBack();
                jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_refresh_token', 'message' => 'نشست معتبر نیست؛ دوباره وارد شوید.'], 401);
            }
            $family = (string)$row['family_id'];
            if (!empty($row['revoked_at'])) {
                // A replayed refresh token indicates token theft; revoke that family.
                jhd_mobile_revoke_family($family);
                $db->commit();
                jhd_mobile_auth_json(['ok' => false, 'error' => 'refresh_reuse_detected', 'message' => 'نشست امنیتی باطل شد؛ دوباره وارد شوید.'], 401);
            }
            if (!(int)$row['unexpired'] || (int)$row['is_active'] !== 1
                || (int)$row['auth_version'] !== (int)$row['current_auth_version']) {
                jhd_mobile_revoke_family($family);
                $db->commit();
                jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_refresh_token', 'message' => 'نشست منقضی شده است؛ دوباره وارد شوید.'], 401);
            }
            $expire = jhd_mobile_auth_now_sql();
            $update = $db->prepare("UPDATE mobile_api_tokens SET revoked_at = $expire
                WHERE token_hash = ? AND revoked_at IS NULL");
            $update->execute([hash('sha256', strtolower($refresh))]);
            if ($update->rowCount() !== 1) {
                jhd_mobile_revoke_family($family);
                $db->commit();
                jhd_mobile_auth_json(['ok' => false, 'error' => 'refresh_reuse_detected', 'message' => 'نشست امنیتی باطل شد؛ دوباره وارد شوید.'], 401);
            }
            $tokens = jhd_mobile_issue_pair((int)$row['user_id'], $family);
            $fresh = jhd_user_by_id((int)$row['user_id']);
            $db->commit();
            jhd_mobile_auth_json(['ok' => true] + $tokens + ['user' => jhd_mobile_safe_user($fresh ?? [])]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    if ($action === 'logout') {
        $raw = jhd_mobile_bearer_token();
        if ($raw === '') $raw = jhd_mobile_string_field($data, 'refresh_token', 64);
        if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            $tokenRow = jhd_mobile_find_token($raw, 'access') ?? jhd_mobile_find_token($raw, 'refresh');
            if ($tokenRow) jhd_mobile_revoke_family((string)$tokenRow['family_id']);
        }
        // Idempotent logout avoids exposing whether a token was ever valid.
        jhd_mobile_auth_json(['ok' => true]);
    }

    if ($action === 'change_password') {
        $user = jhd_mobile_require_user();
        $current = jhd_mobile_string_field($data, 'current_password', 4096);
        $next = jhd_mobile_string_field($data, 'new_password', 4096);
        $confirm = jhd_mobile_string_field($data, 'password_confirm', 4096);
        if ($current === '' || $next === '' || $confirm === '') {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'validation_error', 'message' => 'رمز فعلی، رمز جدید و تکرار آن را وارد کنید.'], 422);
        }
        $changed = jhd_change_password((int)$user['id'], $current, $next, $confirm, true);
        if (empty($changed['ok'])) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'password_change_failed', 'message' => (string)($changed['error'] ?? 'تغییر رمز انجام نشد.')], 422);
        }
        $db = getDB();
        $db->prepare('UPDATE mobile_api_tokens SET revoked_at = ' . jhd_mobile_auth_now_sql() . ' WHERE user_id = ? AND revoked_at IS NULL')
           ->execute([(int)$user['id']]);
        $tokens = jhd_mobile_issue_pair((int)$user['id']);
        $fresh = jhd_user_by_id((int)$user['id']);
        jhd_mobile_auth_json(['ok' => true] + $tokens + ['user' => jhd_mobile_safe_user($fresh ?? [])]);
    }

    jhd_mobile_auth_json(['ok' => false, 'error' => 'unknown_action', 'message' => 'این عملیات پشتیبانی نمی‌شود.'], 400);
} catch (Throwable $e) {
    error_log('Mobile auth operation failed: ' . get_class($e));
    jhd_mobile_auth_json(['ok' => false, 'error' => 'server_error', 'message' => 'درخواست انجام نشد. دوباره تلاش کنید.'], 500);
}
