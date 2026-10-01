<?php
/**
 * auth.php — نشست، احراز هویت و کنترل دسترسی
 *
 * یک سیستم ورود واحد برای همه:
 *   • عضو عمومی، مدیر و مدیر ارشد همه از یک نشست و یک صفحهٔ ورود استفاده می‌کنند.
 *   • نقش از دیتابیس خوانده می‌شود و مسیر بعد از ورود را تعیین می‌کند.
 *   • /admin/* فقط برای نقش‌های کارکنان باز است.
 *
 * امنیت:
 *   • session_regenerate_id() پس از ورود (جلوگیری از session fixation)
 *   • auth_version برای باطل‌کردن نشست‌ها پس از تغییر رمز
 *   • محدودیت تلاش ناموفق (login_limits) روی حساب و IP
 *   • رمز فقط با password_hash()/password_verify()
 *   • هیچ پیام خطایی اطلاعات داخلی سرور را نمایش نمی‌دهد.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/identity.php';

/** حداقل طول رمز برای حساب‌های مدیریتی. */
if (!defined('JHD_MIN_PASSWORD_LENGTH')) define('JHD_MIN_PASSWORD_LENGTH', 8);
if (!defined('JHD_MIN_ADMIN_PASSWORD_LENGTH')) define('JHD_MIN_ADMIN_PASSWORD_LENGTH', 10);

/**
 * Secure flag follows the actual request scheme. Forcing Secure whenever
 * APP_ENV=production (as the InfinityFree installer sets it) would break
 * panel login with an endless redirect loop on plain-HTTP visits, because
 * the browser never sends a Secure cookie over http.
 */
function sessionCookieSecure(): bool {
    if (env_value('VERCEL') !== '') return true; // Vercel is always HTTPS.
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Table bootstrap for DB-backed sessions and the brute-force limiter. */
function ensureCoreAuthTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $driver = databaseDriver();
        if ($driver === 'mysql') {
            $db->exec("CREATE TABLE IF NOT EXISTS app_sessions (id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL, expires_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS login_limits (limit_key VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } elseif ($driver === 'sqlite') {
            $db->exec("CREATE TABLE IF NOT EXISTS app_sessions (id TEXT PRIMARY KEY, data TEXT NOT NULL, expires_at TEXT NOT NULL)");
            $db->exec("CREATE TABLE IF NOT EXISTS login_limits (limit_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL)");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS app_sessions (id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL, expires_at TIMESTAMPTZ NOT NULL)");
            $db->exec("CREATE TABLE IF NOT EXISTS login_limits (limit_key VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, expires_at TIMESTAMPTZ NOT NULL)");
        }
    } catch (Throwable $e) {
        error_log('Auth table ensure failed: ' . get_class($e));
    }
}

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Keep session IDs long and hexadecimal for strong entropy and stable security audits.
        ini_set('session.sid_length', '32');
        ini_set('session.sid_bits_per_character', '4');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        if (env_value('SESSION_DRIVER', 'database') === 'database') {
            // Database-backed sessions are the production default and always
            // follow the driver of the current deployment (PostgreSQL/Neon on
            // Vercel, MySQL on InfinityFree) — never another host's database.
            if (tryGetDB() !== null) {
                ensureCoreAuthTables();
                require_once __DIR__ . '/session.php';
                session_set_save_handler(new DatabaseSessionHandler(), true);
            } else {
                // No reachable database: keep the request alive with a
                // request-local session store so public pages still render.
                // Nothing is bypassed — signing in still requires the database
                // and fails with an explicit message until it is available.
                $fallbackDir = rtrim(sys_get_temp_dir(), '/') . '/jhd-sessions';
                if (is_dir($fallbackDir) || @mkdir($fallbackDir, 0700, true)) {
                    session_save_path($fallbackDir);
                }
                error_log('Session store degraded: database unavailable, using temporary file sessions.');
            }
        } elseif (APP_ENV === 'production' || env_value('VERCEL')) {
            throw new RuntimeException('Production requires database sessions.');
        }
        session_name(SESSION_NAME);
        $cookiePath = (defined('BASE_PATH') && BASE_PATH !== '') ? BASE_PATH . '/' : '/';
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => $cookiePath,
            'secure'   => sessionCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// ─── نشست کاربر ──────────────────────────────────────────────────────────────

/**
 * کاربر جاری از روی نشست، با اعتبارسنجی دیتابیس (یک بار در هر درخواست).
 *
 * @return array{id:int,username:string,email:string,full_name:string,role:string,avatar:string,must_change_password:int,is_active:int,last_login:string}|null
 */
function jhd_session_user(bool $refresh = false): ?array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache ?: null;
    startSecureSession();
    $cache = false;
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id < 1) return null;
    if (($_SESSION['last_activity'] ?? 0) < time() - SESSION_LIFETIME) {
        jhd_clear_identity_session();
        return null;
    }
    try {
        jhd_ensure_identity_schema_once();
        $stmt = getDB()->prepare('SELECT ' . JHD_USER_PUBLIC_FIELDS . ', auth_version FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('Session user lookup failed: ' . get_class($e));
        return null;
    }
    if (!$row || (int)$row['is_active'] !== 1) {
        jhd_clear_identity_session();
        return null;
    }
    if ((int)$row['auth_version'] !== (int)($_SESSION['auth_version'] ?? 0)) {
        // رمز تغییر کرده یا حساب جای دیگری وارد شده است.
        jhd_clear_identity_session();
        return null;
    }
    $user = jhd_user_public($row);
    $_SESSION['last_activity'] = time();
    $_SESSION['role'] = $user['role'];
    $_SESSION['uname'] = $user['full_name'] !== '' ? $user['full_name'] : ($user['username'] !== '' ? $user['username'] : $user['phone']);
    $_SESSION['uemail'] = $user['email'];
    $_SESSION['must_change_password'] = $user['must_change_password'];
    // آینه‌های سازگاری برای قالب‌های قدیمی
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_user'] = $user['username'] !== '' ? $user['username'] : $user['email'];
    $_SESSION['admin_name'] = $user['full_name'];
    $_SESSION['admin_role'] = $user['role'];
    $_SESSION['member_id'] = $user['id'];
    $_SESSION['member_name'] = $user['full_name'];
    $_SESSION['member_auth_version'] = (int)$row['auth_version'];
    $_SESSION['last_member_activity'] = time();
    $cache = $user;
    return $user;
}

function jhd_clear_identity_session(): void {
    foreach (['uid', 'role', 'uname', 'uemail', 'uavatar', 'auth_version', 'last_activity', 'must_change_password',
              'admin_id', 'admin_user', 'admin_name', 'admin_role',
              'member_id', 'member_name', 'member_auth_version', 'last_member_activity',
              'login_attempts', 'login_lock_until'] as $key) {
        unset($_SESSION[$key]);
    }
}

/** نقش کاربر جاری ('' اگر وارد نشده باشد). */
function currentUserRole(): string {
    $user = jhd_session_user();
    return $user === null ? '' : jhd_normalize_role($user['role']);
}

/** کاربر جاری برای نمایش؛ هیچ فیلد حساسی برنمی‌گرداند. */
function currentUser(): array {
    $user = jhd_session_user();
    if ($user === null) {
        return ['id' => 0, 'username' => '', 'email' => '', 'full_name' => '', 'role' => '', 'avatar' => '', 'must_change_password' => 0, 'phone' => ''];
    }
    return $user;
}

/**
 * پس از تغییر رمز توسط خود کاربر، نشست جاری معتبر می‌ماند ولی همهٔ نشست‌های
 * دیگر باطل می‌شوند: مقدار auth_version ذخیره‌شده در نشست با دیتابیس هم‌گام
 * می‌شود و بقیهٔ دستگاه‌ها (که هنوز مقدار قدیمی را دارند) از حساب خارج می‌شوند.
 */
function jhd_keep_current_session_after_password_change(int $userId): void {
    if ($userId < 1) return;
    startSecureSession();
    try {
        $stmt = getDB()->prepare('SELECT auth_version FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $version = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($version < 1) return;
    $_SESSION['auth_version'] = $version;
    $_SESSION['member_auth_version'] = $version;
    $_SESSION['last_activity'] = time();
    jhd_session_user(true);
}

/** ورود هر کاربری (عضو، مدیر، مدیر ارشد) برقرار است. */
function isMemberLoggedIn(): bool {
    return jhd_session_user() !== null;
}

/** ورود کاربر با نقش مدیریتی برقرار است. */
function isLoggedIn(): bool {
    $user = jhd_session_user();
    return $user !== null && jhd_role_is_staff($user['role']);
}

function isSuperAdmin(): bool {
    return jhd_role_is_super(currentUserRole());
}

/** سازگاری با قالب‌های قدیمی پنل. */
function currentAdmin(): array {
    $user = currentUser();
    return [
        'id'   => (int)$user['id'],
        'user' => $user['username'] !== '' ? $user['username'] : $user['email'],
        'name' => $user['full_name'],
        'role' => $user['role'],
        'email' => $user['email'],
    ];
}

function currentMember(): array {
    $user = currentUser();
    return ['id' => (int)$user['id'], 'name' => (string)$user['full_name'], 'role' => (string)$user['role']];
}

// ─── ورود و خروج ─────────────────────────────────────────────────────────────

/**
 * آدرس بازگشت امن: فقط مسیر داخلی همین سایت پذیرفته می‌شود
 * (جلوگیری از open redirect). مسیرهای خارجی نادیده گرفته می‌شوند.
 */
function jhd_safe_redirect_target(mixed $target, string $fallback = '/'): string {
    if (!is_string($target)) return $fallback;
    $target = trim($target);
    if ($target === '') return $fallback;
    if (strpbrk($target, "\r\n") !== false) return $fallback;
    // فقط مسیر نسبی/داخلی: بدون scheme، protocol-relative یا backslash؛
    // مرورگرها backslash را در URLهای HTTP به slash تبدیل می‌کنند.
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $target) || str_starts_with($target, '//') || str_contains($target, '\\')) {
        return $fallback;
    }
    if (!str_starts_with($target, '/')) return $fallback;
    if (defined('BASE_PATH') && BASE_PATH !== '' && !str_starts_with($target, BASE_PATH . '/') && $target !== BASE_PATH) {
        return $fallback;
    }
    return $target;
}

/**
 * محدودکنندهٔ تلاش ورود. PostgreSQL و MySQL و SQLite نسخهٔ خودشان را دارند
 * (MySQL تابع RETURNING ندارد و مقدارش با یک SELECT خوانده می‌شود).
 */
function recordLoginAttempt(PDO $db, string $key): int {
    if (databaseDriver() === 'mysql') {
        $db->prepare("INSERT INTO login_limits (limit_key,attempts,expires_at) VALUES (?,1,NOW()+INTERVAL 15 MINUTE) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<NOW(),1,attempts+1), expires_at=IF(expires_at<NOW(),NOW()+INTERVAL 15 MINUTE,expires_at)")->execute([$key]);
        $stmt = $db->prepare('SELECT attempts FROM login_limits WHERE limit_key=?');
        $stmt->execute([$key]);
        return (int)$stmt->fetchColumn();
    }
    if (databaseDriver() === 'sqlite') {
        $limit = $db->prepare("INSERT INTO login_limits (limit_key,attempts,expires_at) VALUES (?,1,datetime('now','+15 minutes')) ON CONFLICT (limit_key) DO UPDATE SET attempts=CASE WHEN login_limits.expires_at<datetime('now') THEN 1 ELSE login_limits.attempts+1 END, expires_at=CASE WHEN login_limits.expires_at<datetime('now') THEN datetime('now','+15 minutes') ELSE login_limits.expires_at END RETURNING attempts");
        $limit->execute([$key]);
        return (int)$limit->fetchColumn();
    }
    $limit = $db->prepare("INSERT INTO login_limits (limit_key,attempts,expires_at) VALUES (?,1,NOW()+INTERVAL '15 minutes') ON CONFLICT (limit_key) DO UPDATE SET attempts=CASE WHEN login_limits.expires_at<NOW() THEN 1 ELSE login_limits.attempts+1 END, expires_at=CASE WHEN login_limits.expires_at<NOW() THEN NOW()+INTERVAL '15 minutes' ELSE login_limits.expires_at END RETURNING attempts");
    $limit->execute([$key]);
    return (int)$limit->fetchColumn();
}

function resetLoginAttempts(PDO $db, string $key): void {
    try { $db->prepare('DELETE FROM login_limits WHERE limit_key=?')->execute([$key]); } catch (Throwable $e) { }
}

/**
 * ورود واحد: یک شناسه (ایمیل/نام کاربری/تلفن) + رمز.
 * پس از موفقیت، نشست بازسازی می‌شود و نقش از دیتابیس می‌آید.
 *
 * @return array{ok:bool,user?:array,error?:string,code?:string}
 */
function jhd_login(string $identifier, string $password, bool $remember = false): array {
    // Honest failure instead of a fatal error when the database is unreachable:
    // authentication is never bypassed, it simply cannot be performed.
    $db = tryGetDB();
    if ($db === null) {
        return ['ok' => false, 'code' => 'db_unavailable', 'error' => 'ورود در این لحظه ممکن نیست: اتصال به دیتابیس برقرار نشد. لطفاً بعداً دوباره تلاش کنید.'];
    }
    ensureCoreAuthTables();
    $idKey = hash('sha256', 'user:' . mb_strtolower(trim($identifier)));
    $ipKey = hash('sha256', 'ip:' . clientIp());
    try {
        if (recordLoginAttempt($db, $ipKey) > 50) {
            return ['ok' => false, 'code' => 'locked', 'error' => 'تلاش‌های ناموفق از این دستگاه بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.'];
        }
        if (recordLoginAttempt($db, $idKey) > 8) {
            return ['ok' => false, 'code' => 'locked', 'error' => 'تلاش‌های ناموفق زیاد است. ۱۵ دقیقه بعد دوباره تلاش کنید.'];
        }
    } catch (Throwable $e) {
        error_log('Login limiter unavailable: ' . get_class($e));
    }

    $result = jhd_verify_credentials($identifier, $password);
    if (empty($result['ok'])) {
        return $result;
    }
    $user = $result['user'];
    jhd_establish_session($user, $remember);
    resetLoginAttempts($db, $idKey);
    try {
        $nowSql = databaseDriver() === 'sqlite' ? "datetime('now')" : 'NOW()';
        $db->prepare("UPDATE users SET last_login = $nowSql WHERE id = ?")->execute([$user['id']]);
    } catch (Throwable $e) {
        error_log('last_login update failed: ' . get_class($e));
    }
    return ['ok' => true, 'user' => $user];
}

/** ساخت/بازسازی نشست پس از احراز هویت موفق. */
function jhd_establish_session(array $user, bool $remember = false): void {
    startSecureSession();
    $keepCsrf = $_SESSION[CSRF_TOKEN_NAME] ?? null;
    session_regenerate_id(true); // session fixation
    $_SESSION = [];
    if (is_string($keepCsrf) && $keepCsrf !== '') $_SESSION[CSRF_TOKEN_NAME] = $keepCsrf;
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['role'] = jhd_normalize_role($user['role'] ?? null);
    $_SESSION['uname'] = ($user['full_name'] ?? '') !== '' ? $user['full_name'] : (string)($user['username'] ?? '');
    $_SESSION['uemail'] = (string)($user['email'] ?? '');
    $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
    $_SESSION['auth_version'] = (int)($user['auth_version'] ?? 1);
    $_SESSION['last_activity'] = time();
    $_SESSION['remember'] = $remember ? 1 : 0;
    $_SESSION['member_id'] = (int)$user['id'];
    $_SESSION['member_name'] = (string)($user['full_name'] ?? '');
    $_SESSION['admin_id'] = (int)$user['id'];
    $_SESSION['admin_user'] = (string)($user['username'] ?? '');
    $_SESSION['admin_name'] = (string)($user['full_name'] ?? '');
    $_SESSION['admin_role'] = (string)$_SESSION['role'];
    if ($remember) {
        // نشست طولانی‌تر بدون تغییر تنظیمات پیش‌فرض بقیهٔ کاربران.
        $cookiePath = (defined('BASE_PATH') && BASE_PATH !== '') ? BASE_PATH . '/' : '/';
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => $cookiePath,
            'secure'   => sessionCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($params);
    }
    jhd_session_user(true); // داده‌های تازه را کش می‌کند
}

/** سازگاری: ورود حساب مدیریتی با نام کاربری (CLI و تست‌ها). */
function loginAdmin(string $username, string $password): bool {
    $result = jhd_login($username, $password);
    if (empty($result['ok'])) return false;
    return jhd_role_is_staff($result['user']['role']);
}

/** سازگاری: ورود عضو عمومی. */
function loginMember(string $identifier, string $password): bool {
    $result = jhd_login($identifier, $password);
    return !empty($result['ok']);
}

/**
 * مسیر مناسب هر نقش پس از ورود.
 * مدیر و مدیر ارشد → پنل مدیریت، عضو عمومی → حساب کاربری.
 */
function jhd_home_url_for_role(?string $role): string {
    return jhd_role_is_staff($role) ? url('admin/dashboard') : accountUrl();
}

/** خروج کامل (مدیر و عضو یک نشست دارند). */
function jhd_logout(): void {
    startSecureSession();
    $_SESSION = [];
    $cookiePath = (defined('BASE_PATH') && BASE_PATH !== '') ? BASE_PATH . '/' : '/';
    setcookie(SESSION_NAME, '', ['expires' => time() - 3600, 'path' => $cookiePath, 'secure' => sessionCookieSecure(), 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}

function logoutAdmin(): void {
    jhd_logout();
    redirect(loginUrl());
}

function logoutMember(): void {
    jhd_logout();
    redirect(url());
}

// ─── کنترل دسترسی ────────────────────────────────────────────────────────────

function jhd_login_url_with_redirect(?string $target = null): string {
    if ($target === null) $target = currentUrlPathWithQuery();
    $base = loginUrl();
    if ($target === '' || $target === '/' && !JHD_PRETTY_URLS) return $base;
    $separator = str_contains($base, '?') ? '&' : '?';
    return $base . $separator . 'redirect=' . rawurlencode($target);
}

/** مسیر جاری همراه با پارامترها (برای بازگشت پس از ورود). */
function currentUrlPathWithQuery(): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if (str_contains($uri, "\r") || str_contains($uri, "\n")) return '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $query = parse_url($uri, PHP_URL_QUERY);
    $path = rawurldecode($path);
    if (str_contains($path, '..')) return '/';
    if (defined('BASE_PATH') && BASE_PATH !== '' && str_starts_with($path, BASE_PATH)) {
        $path = substr($path, strlen(BASE_PATH)) ?: '/';
    }
    // خود صفحهٔ ورود/خروج هرگز مقصد بازگشت نیست (جلوگیری از حلقه).
    if (preg_match('~^/(login|logout|admin/login|admin/logout)~', $path)) return '/';
    return $path . ($query ? '?' . $query : '');
}

/** ورود لازم است؛ کاربر مهمان به صفحهٔ ورود با آدرس بازگشت هدایت می‌شود. */
function requireLogin(string $target = ''): void {
    if (isLoggedIn()) return;
    $redirectTo = $target !== '' ? $target : currentUrlPathWithQuery();
    redirect(jhd_login_url_with_redirect($redirectTo));
}

function requireMember(): void {
    if (isMemberLoggedIn()) return;
    redirect(jhd_login_url_with_redirect(currentUrlPathWithQuery()));
}

/** بررسی نقش؛ نقش‌های قدیمی خودکار نگاشت می‌شوند. */
function requireRole(array $roles): void {
    requireLogin();
    $allowed = array_map('jhd_normalize_role', $roles);
    if (!in_array(currentUserRole(), $allowed, true)) {
        http_response_code(403);
        jhd_render_403();
    }
}

/** بررسی قابلیت (توصیه‌شده برای کدهای تازه). */
function requireCapability(string $capability): void {
    requireLogin();
    if (!jhd_can($capability)) {
        http_response_code(403);
        jhd_render_403();
    }
}

/** صفحهٔ ۴۰۳ کوتاه و بدون افشای اطلاعات داخلی. */
function jhd_render_403(): void {
    header('Content-Type: text/html; charset=utf-8');
    $home = htmlspecialchars(url('admin/dashboard'), ENT_QUOTES, 'UTF-8');
    exit('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>دسترسی مجاز نیست</title>'
        . '<body style="font-family:Vazirmatn,Tahoma,sans-serif;background:#f4f6f5;color:#1b2e24;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">'
        . '<main style="background:#fff;border:1px solid #e2e8e5;border-radius:16px;padding:32px;max-width:520px;text-align:center;line-height:2">'
        . '<div style="font-size:2.4rem;color:#b39250">۴۰۳</div>'
        . '<h1 style="font-size:1.2rem;margin:.4rem 0 1rem">دسترسی شما به این بخش مجاز نیست</h1>'
        . '<p style="color:#6b7d74;font-size:.92rem">برای این عملیات به سطح دسترسی بالاتری نیاز است.</p>'
        . '<p><a href="' . $home . '" style="color:#2d6a4f">بازگشت به داشبورد</a></p></main></body></html>');
}

function requirePostCsrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); http_response_code(405); exit('Method not allowed'); }
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) { http_response_code(403); exit('درخواست نامعتبر است.'); }
}

/** سازگاری با کدهای قدیمی که این تابع را از auth.php انتظار داشتند. */
if (!function_exists('jhd_password_is_hash')) {
    function jhd_password_is_hash(string $stored): bool {
        return \jhd_password_is_hash($stored);
    }
}
