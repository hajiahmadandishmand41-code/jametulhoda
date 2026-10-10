<?php
/**
 * Mobile bearer API for the native Android app.
 *
 * Tokens are opaque random values; only SHA-256 hashes are stored in the shared
 * application database. The users table, password rules, profile validation,
 * and auth_version revocation remain shared with the website.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function jhd_mobile_auth_json(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jhd_mobile_ensure_token_table(PDO $db): void {
    $driver = databaseDriver();
    if ($driver === 'mysql') {
        $db->exec("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
            token_hash CHAR(64) PRIMARY KEY,
            user_id INT NOT NULL,
            auth_version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } elseif ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
            token_hash CHAR(64) PRIMARY KEY,
            user_id INTEGER NOT NULL,
            auth_version INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            last_used_at TEXT NULL
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
            token_hash CHAR(64) PRIMARY KEY,
            user_id INTEGER NOT NULL,
            auth_version INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            expires_at TIMESTAMPTZ NOT NULL,
            last_used_at TIMESTAMPTZ NULL
        )");
    }
    try { $db->exec('DELETE FROM mobile_api_tokens WHERE expires_at < CURRENT_TIMESTAMP'); }
    catch (Throwable $e) { error_log('Mobile token cleanup skipped: ' . get_class($e)); }
}

function jhd_mobile_expiry_sql(): string {
    return match (databaseDriver()) {
        'mysql' => 'DATE_ADD(NOW(), INTERVAL 30 DAY)',
        'sqlite' => "datetime('now', '+30 days')",
        default => "NOW() + INTERVAL '30 days'",
    };
}

/** Issue a 30-day opaque token; never persist or log the raw token. */
function jhd_mobile_issue_token(array $user): array {
    $db = getDB();
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $sql = 'INSERT INTO mobile_api_tokens (token_hash,user_id,auth_version,expires_at) VALUES (?,?,?,' . jhd_mobile_expiry_sql() . ')';
    $db->prepare($sql)->execute([$hash, (int)$user['id'], (int)($user['auth_version'] ?? 1)]);
    return ['token' => $token, 'expires_in' => 2592000, 'token_hash' => $hash];
}

/** Resolve bearer token and re-check active status + auth_version on every call. */
function jhd_mobile_bearer_user(PDO $db): ?array {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', trim($header), $match)) return null;
    $hash = hash('sha256', strtolower($match[1]));
    $sql = 'SELECT u.id,u.username,u.email,u.phone,u.country,u.country_code,u.full_name,u.role,u.is_active,u.must_change_password,u.avatar,u.created_at,u.auth_version,t.token_hash
            FROM mobile_api_tokens t
            INNER JOIN users u ON u.id=t.user_id
            WHERE t.token_hash=? AND t.expires_at>CURRENT_TIMESTAMP
              AND t.auth_version=u.auth_version AND u.is_active=1
            LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    try { $db->prepare('UPDATE mobile_api_tokens SET last_used_at=CURRENT_TIMESTAMP WHERE token_hash=?')->execute([$hash]); }
    catch (Throwable $e) { error_log('Mobile token activity update skipped: ' . get_class($e)); }
    return [
        'token_hash' => (string)$row['token_hash'],
        'user' => [
            'id' => (int)$row['id'],
            'username' => (string)($row['username'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'country' => (string)($row['country'] ?? ''),
            'country_code' => (string)($row['country_code'] ?? ''),
            'full_name' => (string)($row['full_name'] ?? ''),
            'role' => jhd_normalize_role($row['role'] ?? null),
            'is_active' => (int)$row['is_active'],
            'must_change_password' => (int)($row['must_change_password'] ?? 0),
            'avatar' => (string)($row['avatar'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ],
    ];
}

function jhd_mobile_request_json(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 16384) jhd_mobile_auth_json(['ok' => false, 'error' => 'request_too_large'], 413);
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') return [];
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_json_object'], 400);
        }
        return $value;
    } catch (JsonException $e) {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_json'], 400);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    jhd_mobile_auth_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
    $db = tryGetDB();
    if (!$db instanceof PDO) jhd_mobile_auth_json(['ok' => false, 'error' => 'service_unavailable'], 503);
    jhd_ensure_identity_schema_once();
    jhd_mobile_ensure_token_table($db);
} catch (Throwable $e) {
    error_log('Mobile auth initialisation failed: ' . get_class($e));
    jhd_mobile_auth_json(['ok' => false, 'error' => 'service_unavailable'], 503);
}

if ($method === 'GET') {
    $session = jhd_mobile_bearer_user($db);
    if ($session === null) jhd_mobile_auth_json(['ok' => false, 'error' => 'unauthorized'], 401);
    jhd_mobile_auth_json(['ok' => true, 'user' => $session['user']]);
}

$input = jhd_mobile_request_json();
$action = is_string($input['action'] ?? null) ? strtolower(trim($input['action'])) : '';

if ($action === 'login') {
    $identifier = is_string($input['identifier'] ?? null) ? trim($input['identifier']) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    if ($identifier === '' || $password === '' || strlen($identifier) > 190 || strlen($password) > 4096) {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_credentials'], 422);
    }

    ensureCoreAuthTables();
    $idKey = hash('sha256', 'user:' . mb_strtolower($identifier));
    $ipKey = hash('sha256', 'ip:' . clientIp());
    try {
        if (recordLoginAttempt($db, $ipKey) > 50 || recordLoginAttempt($db, $idKey) > 8) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
    } catch (Throwable $e) {
        error_log('Mobile login limiter unavailable: ' . get_class($e));
        jhd_mobile_auth_json(['ok' => false, 'error' => 'service_unavailable'], 503);
    }

    $verified = jhd_verify_credentials($identifier, $password);
    if (empty($verified['ok'])) {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_credentials'], 401);
    }
    resetLoginAttempts($db, $idKey);
    $userId = (int)$verified['user']['id'];
    try {
        $nowSql = databaseDriver() === 'sqlite' ? "datetime('now')" : 'NOW()';
        $db->prepare("UPDATE users SET last_login=$nowSql WHERE id=?")->execute([$userId]);
        $current = jhd_user_by_id($userId);
        if ($current === null || (int)$current['is_active'] !== 1) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $public = jhd_user_public($current);
        $issued = jhd_mobile_issue_token($public);
        unset($issued['token_hash']);
        jhd_mobile_auth_json(['ok' => true, 'token' => $issued['token'], 'token_type' => 'Bearer', 'expires_in' => $issued['expires_in'], 'user' => $public]);
    } catch (Throwable $e) {
        error_log('Mobile token issue failed: ' . get_class($e));
        jhd_mobile_auth_json(['ok' => false, 'error' => 'service_unavailable'], 503);
    }
}

if ($action === 'register') {
    $name = is_string($input['full_name'] ?? null) ? trim($input['full_name']) : '';
    $country = is_string($input['country'] ?? null) ? strtoupper(trim($input['country'])) : 'AF';
    $phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
    $email = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $confirm = is_string($input['password_confirm'] ?? null) ? $input['password_confirm'] : '';
    $agreed = ($input['agreed_terms'] ?? false) === true || ($input['agreed_terms'] ?? null) === 1 || ($input['agreed_terms'] ?? null) === '1';

    $countries = jhd_countries();
    if (!isset($countries[$country])) jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_country'], 422);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_name'], 422);
    if (!$agreed) jhd_mobile_auth_json(['ok' => false, 'error' => 'terms_required'], 422);
    if (strlen($password) < 8 || strlen($password) > 4096 || !hash_equals($password, $confirm)) {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_password'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_email'], 422);
    $dial = (string)$countries[$country]['dial'];
    $normalized = normalizePhone($dial, $phone);
    if ($normalized === '' || strlen($normalized) < 8) jhd_mobile_auth_json(['ok' => false, 'error' => 'invalid_phone'], 422);

    ensureCoreAuthTables();
    try {
        if (recordLoginAttempt($db, hash('sha256', 'member-reg:' . clientIp())) > 20) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
    } catch (Throwable $e) {
        error_log('Mobile registration limiter unavailable: ' . get_class($e));
        jhd_mobile_auth_json(['ok' => false, 'error' => 'service_unavailable'], 503);
    }

    $created = jhd_create_user([
        'full_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'phone_normalized' => $normalized,
        'country' => (string)$countries[$country]['name'],
        'country_code' => $dial,
        'password' => $password,
        'role' => JHD_ROLE_USER,
        'is_active' => 1,
        'agreed_terms' => 1,
    ]);
    if (empty($created['ok'])) {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'registration_failed'], 409);
    }
    try {
        $createdUser = jhd_user_by_id((int)$created['id']);
        if ($createdUser === null || (int)$createdUser['is_active'] !== 1) {
            jhd_mobile_auth_json(['ok' => false, 'error' => 'registration_failed'], 503);
        }
        $public = jhd_user_public($createdUser);
        $issued = jhd_mobile_issue_token($public);
        unset($issued['token_hash']);
        jhd_mobile_auth_json(['ok' => true, 'token' => $issued['token'], 'token_type' => 'Bearer', 'expires_in' => $issued['expires_in'], 'user' => $public], 201);
    } catch (Throwable $e) {
        error_log('Mobile registration token issue failed: ' . get_class($e));
        jhd_mobile_auth_json(['ok' => false, 'error' => 'service_unavailable'], 503);
    }
}

$session = jhd_mobile_bearer_user($db);
if ($session === null) jhd_mobile_auth_json(['ok' => false, 'error' => 'unauthorized'], 401);
$userId = (int)$session['user']['id'];

if ($action === 'logout') {
    $db->prepare('DELETE FROM mobile_api_tokens WHERE token_hash=?')->execute([$session['token_hash']]);
    jhd_mobile_auth_json(['ok' => true]);
}

if ($action === 'update_profile') {
    $allowed = [];
    foreach (['full_name', 'email'] as $field) {
        if (array_key_exists($field, $input) && is_string($input[$field])) $allowed[$field] = $input[$field];
    }
    if (!$allowed) jhd_mobile_auth_json(['ok' => false, 'error' => 'no_profile_fields'], 422);
    $updated = jhd_update_profile($userId, $allowed);
    if (empty($updated['ok'])) jhd_mobile_auth_json(['ok' => false, 'error' => 'profile_update_failed', 'message' => (string)($updated['error'] ?? '')], 422);
    $fresh = jhd_user_by_id($userId);
    if ($fresh === null) jhd_mobile_auth_json(['ok' => false, 'error' => 'unauthorized'], 401);
    jhd_mobile_auth_json(['ok' => true, 'user' => jhd_user_public($fresh)]);
}

if ($action === 'change_password') {
    $currentPassword = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
    $newPassword = is_string($input['new_password'] ?? null) ? $input['new_password'] : '';
    $confirmPassword = is_string($input['password_confirm'] ?? null) ? $input['password_confirm'] : '';
    $changed = jhd_change_password($userId, $currentPassword, $newPassword, $confirmPassword, true);
    if (empty($changed['ok'])) {
        jhd_mobile_auth_json(['ok' => false, 'error' => 'password_change_failed', 'message' => (string)($changed['error'] ?? '')], 422);
    }
    // Password changes increment auth_version; deleting all app tokens makes revocation immediate.
    $db->prepare('DELETE FROM mobile_api_tokens WHERE user_id=?')->execute([$userId]);
    jhd_mobile_auth_json(['ok' => true, 'requires_login' => true]);
}

jhd_mobile_auth_json(['ok' => false, 'error' => 'unsupported_action'], 400);
