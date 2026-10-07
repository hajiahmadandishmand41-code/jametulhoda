<?php
/**
 * config.php — تنظیمات اصلی وب‌سایت
 * مدرسه علمیه جامعه‌الهدی
 *
 * Secrets are read from environment variables so they are not committed to Git.
 */

$APP_LOCAL_CONFIG = [];
$localConfigFile = __DIR__ . '/local.php';
if (is_file($localConfigFile)) {
    $loaded = require $localConfigFile;
    if (is_array($loaded)) $APP_LOCAL_CONFIG = $loaded;
}

function env_value(string $key, string $default = ''): string {
    global $APP_LOCAL_CONFIG;
    $value = getenv($key);
    if ($value !== false && $value !== '') return (string)$value;
    if (isset($APP_LOCAL_CONFIG[$key]) && $APP_LOCAL_CONFIG[$key] !== '') return (string)$APP_LOCAL_CONFIG[$key];
    return $default;
}

define('APP_ENV', env_value('APP_ENV', env_value('VERCEL') ? 'production' : 'development'));
define('BASE_PATH', rtrim('/' . trim(env_value('BASE_PATH'), '/'), '/'));
/**
 * URL mode.
 *   false (DEFAULT, InfinityFree-safe): every internal link is a Query URL such
 *          as index.php?p=topic&slug=oloum-quran. Works with or without
 *          mod_rewrite, so the site never breaks on a shared host.
 *   true  (optional): links become Pretty URLs (/topic/oloum-quran) which need
 *          mod_rewrite (router.php + .htaccess). Purely a cosmetic upgrade.
 * The router RESOLVES both spellings regardless of this flag; it only controls
 * which form url()/the typed helpers GENERATE and which form <link canonical>
 * declares. See includes/functions.php (jhd_routes / url).
 */
define('JHD_PRETTY_URLS', filter_var(env_value('JHD_PRETTY_URLS', env_value('VERCEL') ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN));
/** Absolute project root (the directory that holds router.php). */
define('BASE_DIR', dirname(__DIR__));
define('STORAGE_DIR', BASE_DIR . '/storage');

// Site
/**
 * Brand identity — one official name, used verbatim everywhere.
 *
 * `SITE_NAME` is the school's primary brand: it is the first and strongest
 * identity signal, and it must appear unchanged in the page <title>, in
 * og:site_name and in the Organization / WebSite structured data. Nothing may
 * substitute a variant (the Arabic spelling, a longer legal name or a slogan)
 * for it in those positions.
 *
 * `SITE_ALT_NAMES` lists only names that are genuinely used for the school on
 * this very site; they are published as Organization.alternateName so search
 * engines can reconcile the variants with the primary brand instead of seeing
 * them as competing identities.
 *
 * The public site no longer reads these from the database settings: the stored
 * `site_name` was a variant, which produced a different brand string in the
 * title, in og:site_name and in structured data. Branding is fixed in code —
 * exactly like the public logo — so every public surface agrees.
 */
define('SITE_NAME',   'مدرسه جامعه‌الهدی');
define('SITE_SLOGAN', 'مرکز علمی، آموزشی و پژوهشی در پرتو قرآن و عترت');
define('SITE_ALT_NAMES', ['جامعة‌الهدی', 'مدرسه علمیه جامعه‌الهدی']);
/** Short official description reused by Organization and the homepage. */
define('SITE_DESCRIPTION', 'مدرسه علمی، آموزشی و پژوهشی علوم اسلامی در کابل، افغانستان؛ با تمرکز بر آموزش علوم اسلامی، تربیت طلاب، پژوهش دینی و ترویج فرهنگ قرآنی و اهل‌بیت (ع).');
/** Real, deployed logo. The only logo file referenced by public code. */
define('SITE_LOGO_PATH', 'assets/img/logo.png');
/** Intrinsic pixel size of logo.png, published with the social/OG image tags. */
define('SITE_LOGO_WIDTH',  702);
define('SITE_LOGO_HEIGHT', 723);
/**
 * Founder, published as Organization.founder. Kept here in one place so the
 * homepage, the about page and every other page emit the identical value.
 */
define('SITE_FOUNDER', 'آیت‌الله محمدحسین حلیمی');
// Default canonical origin follows the domain supplied for this installation;
// custom domains must override SITE_URL in the environment/local config.
define('SITE_URL',    env_value('SITE_URL', env_value('VERCEL') ? 'https://jametulhoda.vercel.app' : 'https://jametulhoda.gt.tc'));
define('SITE_EMAIL',  env_value('SITE_EMAIL', 'hajiahmads299@gmail.com'));
define('SITE_PHONE',  env_value('SITE_PHONE', '0798228441'));
define('SITE_ADDRESS',env_value('SITE_ADDRESS', 'کابل، افغانستان'));

// Upload
define('UPLOAD_STORAGE', env_value('UPLOAD_STORAGE', 'local'));
define('UPLOAD_DIR', rtrim(env_value('UPLOAD_LOCAL_PATH', __DIR__ . '/../uploads'), '/') . '/');
define('UPLOAD_BASE_URL', rtrim(env_value('UPLOAD_BASE_URL', BASE_PATH . '/uploads'), '/'));
define('UPLOAD_IMAGES', 'images');
// `audios`/`videos` are the current folder names; the older `audio`/`video`
// folders stay readable (see the allowlist in includes/storage.php) so files
// uploaded before this rename keep working.
define('UPLOAD_AUDIO', env_value('UPLOAD_AUDIO_DIR', 'audios'));
define('UPLOAD_VIDEO', env_value('UPLOAD_VIDEO_DIR', 'videos'));
define('UPLOAD_DOCUMENTS', 'documents');
define('MAX_FILE_SIZE', 20 * 1024 * 1024);
define('MAX_VIDEO_SIZE', 150 * 1024 * 1024);
define('ALLOWED_IMG',   ['image/jpeg','image/png','image/gif','image/webp']);
define('ALLOWED_AUDIO', ['audio/mpeg','audio/mp3','audio/ogg','audio/wav','audio/mp4','audio/x-m4a','audio/x-mpeg']);
define('ALLOWED_VIDEO', ['video/mp4','video/webm','video/ogg','video/quicktime','video/x-matroska']);

// Session
define('SESSION_NAME', 'jamiat_session');
define('SESSION_LIFETIME', 7200);

// Pagination
define('POSTS_PER_PAGE', 12);
define('LESSONS_PER_PAGE', 12);

// Security
define('CSRF_TOKEN_NAME', 'csrf_token');

/**
 * Installer account defaults. Username may default to `admin`; administrator
 * passwords must be explicitly supplied as unique deployment secrets.
 */
define('DEFAULT_ADMIN_USERNAME', env_value('DEFAULT_ADMIN_USERNAME', 'admin'));
define('DEFAULT_ADMIN_PASSWORD', env_value('DEFAULT_ADMIN_PASSWORD'));

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Production logs into storage/logs/php-error.log when that folder is writable.
// Development keeps the server's own log so warnings stay visible in CI output.
if (APP_ENV === 'production' && is_dir(STORAGE_DIR . '/logs') && is_writable(STORAGE_DIR . '/logs')) {
    ini_set('error_log', STORAGE_DIR . '/logs/php-error.log');
}

// Timezone
date_default_timezone_set('Asia/Kabul');

// Opt-in request profiler (Server-Timing). Completely inert unless JHD_PROFILE=1.
require_once __DIR__ . '/../includes/profiler.php';
jhd_profile_boot();

// All entrypoints share non-disclosing failures and response hardening.
if (PHP_SAPI !== 'cli') {
    // A pre-existing PHP buffer may auto-flush after 4096 bytes. Keep our own
    // unbounded response buffer until controllers finish redirect/header decisions.
    ob_start(null, 0);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (APP_ENV === 'production') header('X-Frame-Options: SAMEORIGIN');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: object-src 'none'; base-uri 'self'" . (APP_ENV === 'production' ? "; frame-ancestors 'self'" : ''));
    if (APP_ENV === 'production') header('Strict-Transport-Security: max-age=31536000');
}
/**
 * Actionable failure page. The technical detail stays in the server log; the
 * visitor sees what is missing (installation or database) and what to do.
 */
function renderFailurePage(string $id, bool $installed, bool $databaseError): string {
    $installUrl = htmlspecialchars(BASE_PATH . '/php/install', ENT_QUOTES, 'UTF-8');
    $host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'example.com', ENT_QUOTES, 'UTF-8');
    if (!$installed) {
        $title = 'سایت هنوز نصب نشده است';
        $body = '<p>نصب این سایت کامل نشده است: فایل تنظیمات خصوصی <code>config/local.php</code> وجود ندارد.</p>'
            . '<p>برای نصب، این آدرس را باز کنید و مشخصات دیتابیس MySQL و حساب مدیر را وارد کنید:</p>'
            . '<p><a href="' . $installUrl . '">https://' . $host . $installUrl . '</a></p>'
            . '<p class="muted">اگر فایل‌ها را تازه آپلود کرده‌اید، مطمئن شوید فایل مخفی <code>.htaccess</code> هم منتقل شده و پوشه config قابل نوشتن است.</p>';
    } elseif ($databaseError) {
        $title = 'اتصال به دیتابیس برقرار نشد';
        $body = '<p>برنامه اجرا می‌شود ولی نمی‌تواند به دیتابیس وصل شود.</p><ol>'
            . '<li>در کنترل‌پنل میزبان بررسی کنید دیتابیس فعال و رمز کاربر همان رمز ذخیره‌شده در <code>config/local.php</code> باشد.</li>'
            . '<li>مقدار <code>DB_HOST</code> باید همان میزبان MySQLی باشد که میزبان دیتابیس اعلام کرده است (مثلاً <code>sql###.infinityfree.com</code>).</li>'
            . '<li>دیتابیس‌های هاست‌های اشتراکی فقط از داخل همان هاست در دسترس‌اند؛ اجرای همین کد روی یک میزبان دیگر (مثل Vercel) نمی‌تواند به آن وصل شود.</li>'
            . '<li>برای جزئیات فنی، Error Logs میزبان را با شناسه پیگیری زیر بررسی کنید.</li>'
            . '</ol>';
    } else {
        $title = 'سرویس موقتاً در دسترس نیست';
        $body = '<p>یک خطای موقت رخ داد. چند لحظه بعد دوباره تلاش کنید.</p>'
            . '<p class="muted">اگر تکرار شد، Error Logs میزبان را با شناسه پیگیری زیر بررسی کنید.</p>';
    }
    return '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width">'
        . '<title>' . $title . '</title>'
        . '<style>body{font-family:Tahoma,system-ui,sans-serif;background:#f4f7fb;color:#162033;margin:0}'
        . 'main{max-width:680px;margin:12vh auto;padding:24px;background:#fff;border:1px solid #e1e7ef;border-radius:16px;line-height:2}'
        . 'h1{font-size:1.3rem;margin-top:0}code{background:#f1f5f9;padding:2px 6px;border-radius:6px;direction:ltr;display:inline-block}'
        . '.muted{color:#64748b;font-size:.9rem}ol{padding-inline-start:1.2rem}a{color:#183b70}</style>'
        . '<main><h1>' . $title . '</h1>' . $body
        . '<p class="muted">شناسه پیگیری: <code>' . $id . '</code></p></main></html>';
}

set_exception_handler(function (Throwable $e): void {
    $id = bin2hex(random_bytes(6));
    // Do not log DSNs, submitted passwords, SQL values or storage credentials.
    error_log('Application failure ' . $id . ': ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Operation failed; reference: $id\n"); exit(1); }
    while (ob_get_level()) ob_end_clean();
    // The installer screen is reserved exclusively for the installer URL.
    // An exception on the public homepage or any normal page must never turn
    // into a misleading "site not installed" page.
    $requestPath = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    $installerRequest = (bool)preg_match('~^/php/install(?:\.php)?/?$~', $requestPath);
    $installed = $installerRequest && is_file(__DIR__ . '/local.php');
    $databaseError = $e instanceof PDOException
        || (bool)preg_match('/SQLSTATE|MySQL|database|DATABASE_URL|DB_HOST|could not find driver/i', $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo renderFailurePage($id, $installed || !$installerRequest, $databaseError);
});

/** Trust platform-owned forwarding headers only on Vercel, never arbitrary client headers. */
function clientIp(): string {
    $ip=$_SERVER['REMOTE_ADDR']??'0.0.0.0';
    if (env_value('VERCEL')) {
        $ip=trim(explode(',',$_SERVER['HTTP_X_VERCEL_FORWARDED_FOR']??$_SERVER['HTTP_X_FORWARDED_FOR']??$ip)[0]);
    }
    return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'0.0.0.0';
}
