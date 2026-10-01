<?php
/**
 * Browser installer for shared hosting / InfinityFree.
 * Creates a private config/local.php and applies the MySQL schema.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

function installerEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function installerSplitSql(string $sql): array {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $out = [];
    $buffer = '';
    $quote = null;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($quote !== null) {
            $buffer .= $ch;
            if ($ch === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $i++;
                } elseif ($i === 0 || $sql[$i - 1] !== '\\') {
                    $quote = null;
                }
            }
            continue;
        }

        // Skip -- line comments so a semicolon inside a comment never splits
        // a statement mid-comment.
        if ($ch === '-' && $next === '-' && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t")) {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $buffer .= "\n";
            continue;
        }
        if ($ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $buffer .= "\n";
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === chr(96)) {
            $quote = $ch;
            $buffer .= $ch;
            continue;
        }

        if ($ch === ';') {
            $statement = trim($buffer);
            if ($statement !== '') $out[] = $statement;
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $statement = trim($buffer);
    if ($statement !== '') $out[] = $statement;
    return $out;
}

$localPath = __DIR__ . '/../config/local.php';
$lockPath = __DIR__ . '/../config/install.lock';
$alreadyInstalled = is_file($lockPath);

// The public installer mutates schema and creates the first administrator;
// use the same session-backed CSRF protection as the rest of the application.
if (!$alreadyInstalled) {
    require_once __DIR__ . '/../includes/auth.php';
    startSecureSession();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') requirePostCsrf();
}

/**
 * Optional operator-managed bootstrap values. The tracked repository contains
 * no bootstrap credential; config/bootstrap-admin.php is git-ignored.
 */
$bootstrapFile = __DIR__ . '/../config/bootstrap-admin.php';
$bootstrap = is_file($bootstrapFile) ? (require $bootstrapFile) : [];
if (!is_array($bootstrap)) $bootstrap = [];

$defaults = [
    'db_host' => env_value('DB_HOST'),
    'db_port' => env_value('DB_PORT', '3306'),
    'db_name' => env_value('DB_NAME'),
    'db_user' => env_value('DB_USER'),
    'site_url' => env_value('SITE_URL', ''),
    'admin_username' => env_value('ADMIN_USERNAME', (string)($bootstrap['username'] ?? DEFAULT_ADMIN_USERNAME)),
    'admin_name' => (string)($bootstrap['full_name'] ?? 'مدیر سایت'),
    'admin_email' => env_value('ADMIN_EMAIL', (string)($bootstrap['email'] ?? env_value('SITE_EMAIL', ''))),
];
$bootstrapPassword = (string)($bootstrap['password'] ?? '');

$error = '';
$success = '';
$siteUrlInsecure = false;
$steps = [];
$step = static function (string $label, bool $ok, string $detail = '') use (&$steps): void {
    $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};

if (!$alreadyInstalled && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postString = static fn(string $key, string $default = ''): string => jhd_string_field($_POST, $key, $default);
    $host = trim($postString('db_host', $defaults['db_host']));
    $port = (int)$postString('db_port', $defaults['db_port']);
    $name = trim($postString('db_name', $defaults['db_name']));
    $user = trim($postString('db_user', $defaults['db_user']));
    $pass = $postString('db_pass');
    $siteUrl = rtrim(trim($postString('site_url')), '/');
    $adminUsername = trim($postString('admin_username', $defaults['admin_username']));
    $adminPassword = $postString('admin_password');
    // A password comes only from the form, an operator-private bootstrap file,
    // or the DEFAULT_ADMIN_PASSWORD deployment secret; no public value exists.
    if ($adminPassword === '') $adminPassword = $bootstrapPassword !== '' ? $bootstrapPassword : DEFAULT_ADMIN_PASSWORD;
    $resetExistingPassword = $postString('reset_password') === '1';
    $adminName = trim($postString('admin_name', $defaults['admin_name']));
    $adminEmail = trim($postString('admin_email', $defaults['admin_email']));

    try {
        if ($host === '' || !preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) throw new RuntimeException('نام میزبان MySQL معتبر نیست.');
        if ($port < 1 || $port > 65535) throw new RuntimeException('پورت MySQL معتبر نیست.');
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new RuntimeException('نام دیتابیس معتبر نیست.');
        if ($user === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $user)) throw new RuntimeException('نام کاربری دیتابیس معتبر نیست.');
        if ($pass === '') throw new RuntimeException('رمز عبور MySQL را وارد کنید.');
        if ($siteUrl === '') {
            // Shared hosts answer on the real public domain: derive the HTTPS
            // root from the request instead of storing an empty SITE_URL,
            // which would disable robots/sitemap output.
            $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
            if (preg_match('/^[a-zA-Z0-9.-]+(:[0-9]+)?$/', $requestHost)) {
                $siteUrl = 'https://' . preg_replace('/:[0-9]+$/', '', $requestHost);
            }
        }
        if ($siteUrl !== '' && !filter_var($siteUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('آدرس سایت معتبر نیست.');
        $siteUrlInsecure = $siteUrl !== '' && !preg_match('~^https://~i', $siteUrl);
        if ($adminUsername === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $adminUsername)) throw new RuntimeException('نام کاربری مدیر معتبر نیست.');
        if (strlen($adminPassword) < 14) throw new RuntimeException('برای مدیر یک رمز یکتا با حداقل ۱۴ نویسه وارد کنید.');
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('ایمیل مدیر معتبر نیست.');

        // ── 1) اتصال به دیتابیس ──────────────────────────────────────────
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . rawurlencode($name) . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        $serverVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $step('اتصال به دیتابیس MySQL', true, 'نسخهٔ سرور: ' . $serverVersion);

        // ── 2) ساخت/به‌روزرسانی جداول ─────────────────────────────────────
        $schemaPath = __DIR__ . '/../database/database.mysql.sql';
        $schema = file_get_contents($schemaPath);
        if ($schema === false) throw new RuntimeException('فایل schema پیدا نشد.');
        $applied = 0;
        $skipped = 0;
        foreach (installerSplitSql($schema) as $statement) {
            if ($statement === '') continue;
            try {
                $pdo->exec($statement);
                $applied++;
            } catch (PDOException $e) {
                if (preg_match('/already exists|duplicate key name|duplicate key|duplicate entry|duplicate column/i', $e->getMessage())) { $skipped++; continue; }
                throw $e;
            }
        }
        $step('ساخت جداول دیتابیس', true, $applied . ' دستور اجرا شد' . ($skipped > 0 ? '، ' . $skipped . ' مورد از قبل موجود بود' : ''));

        // ── 2.1) سازگارکردن دیتابیس‌های قدیمی ─────────────────────────
        // CREATE TABLE IF NOT EXISTS جدول موجود را تغییر نمی‌دهد. برای همین
        // همان موتور identity برنامه را قبل از ساخت حساب مدیر اجرا می‌کنیم تا
        // ستون‌های ناقص، نقش‌های قدیمی، CHECK constraintها و ایندکس‌های هویت
        // یک‌جا و با همان منطق runtime ترمیم شوند.
        $GLOBALS['APP_LOCAL_CONFIG'] = [
            'APP_ENV' => 'production',
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => (string)$port,
            'DB_NAME' => $name,
            'DB_USER' => $user,
            'DB_PASS' => $pass,
            'SITE_URL' => $siteUrl,
            'SITE_EMAIL' => $adminEmail,
            'SESSION_DRIVER' => 'database',
            'UPLOAD_STORAGE' => 'local',
        ];
        require_once __DIR__ . '/../includes/identity.php';
        $identity = jhd_ensure_identity_schema(true);
        if (empty($identity['ok'])) {
            throw new RuntimeException((string)($identity['error'] ?? 'ساختار جدول کاربران قابل ترمیم نیست.'));
        }
        $step(
            'ترمیم ساختار هویت کاربران',
            true,
            !empty($identity['columns'])
                ? 'ستون‌های مفقود تکمیل شد: ' . implode('، ', $identity['columns'])
                : 'ساختار هویت کامل بود'
        );

        // ── 3) تنظیمات پایه ──────────────────────────────────────────────
        $seedSettings = [
            'site_name' => 'جامعة‌الهدی',
            'site_slogan' => 'مرکز علمی، آموزشی و پژوهشی در پرتو قرآن و عترت',
            'site_email' => $adminEmail,
            'schema_version' => '0',
        ];
        $settingStmt = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, value) VALUES (?, ?)');
        foreach ($seedSettings as $key => $value) {
            if ($value !== '') $settingStmt->execute([$key, $value]);
        }
        $step('تنظیمات پایهٔ سایت', true, 'کلیدهای ضروری بررسی شدند');

        // ── 4) حساب مدیر ارشد ────────────────────────────────────────────
        // رمز هرگز به‌صورت متن ساده ذخیره نمی‌شود؛ فقط خروجی password_hash().
        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') throw new RuntimeException('ساخت هش رمز عبور ناموفق بود.');

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR (email IS NOT NULL AND email <> ? AND email = ?) LIMIT 1');
        $check->execute([$adminUsername, '', $adminEmail]);
        $existingAdmin = (int)$check->fetchColumn();

        if (!$existingAdmin) {
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password, full_name, role, is_active, must_change_password, auth_version) VALUES (?, ?, ?, ?, ?, 1, 1, 1)');
            $stmt->execute([$adminUsername, $adminEmail !== '' ? $adminEmail : null, $hash, $adminName, 'super_admin']);
            $step('ساخت حساب مدیر ارشد', true, '«' . $adminUsername . '» با نقش super_admin ساخته شد و در نخستین ورود باید رمز را تغییر دهد.');
        } elseif ($resetExistingPassword) {
            $stmt = $pdo->prepare('UPDATE users SET password = ?, email = ?, full_name = ?, role = ?, is_active = 1, must_change_password = 1, auth_version = COALESCE(auth_version, 1) + 1 WHERE id = ?');
            $stmt->execute([$hash, $adminEmail !== '' ? $adminEmail : null, $adminName, 'super_admin', $existingAdmin]);
            $step('بازنشانی رمز مدیر ارشد', true, 'رمز تازه ذخیره و همهٔ نشست‌های فعال باطل شدند.');
        } else {
            $stmt = $pdo->prepare('UPDATE users SET role = ?, is_active = 1 WHERE id = ?');
            $stmt->execute(['super_admin', $existingAdmin]);
            $step('حساب مدیر از قبل موجود بود', true, 'رمز دست‌نخورده ماند. برای بازنشانی، گزینهٔ «بازنشانی رمز» را تیک بزنید.');
        }

        // ── 5) پوشه‌های آپلود و لاگ ──────────────────────────────────────
        $dirs = ['uploads', 'uploads/images', 'uploads/avatars', 'uploads/audios', 'uploads/videos',
                 'uploads/documents', 'uploads/posts', 'uploads/books', 'uploads/media', 'uploads/site',
                 'storage', 'storage/logs'];
        $created = 0;
        foreach ($dirs as $dir) {
            $path = __DIR__ . '/../' . $dir;
            if (!is_dir($path)) {
                if (!@mkdir($path, 0755, true)) throw new RuntimeException('پوشهٔ ' . $dir . ' ساخته نشد.');
                $created++;
            }
        }
        $step('پوشه‌های آپلود و لاگ', true, $created > 0 ? $created . ' پوشهٔ تازه ساخته شد' : 'همهٔ پوشه‌ها از قبل موجود بودند');

        // ── 6) بررسی دسترسی نوشتن ────────────────────────────────────────
        $notWritable = [];
        foreach (['config', 'uploads', 'storage', 'storage/logs'] as $dir) {
            $path = __DIR__ . '/../' . $dir;
            if (!is_dir($path) || !is_writable($path)) $notWritable[] = $dir;
        }
        if ($notWritable) {
            $step('دسترسی نوشتن', false, 'این مسیرها قابل نوشتن نیستند: ' . implode('، ', $notWritable));
        } else {
            $step('دسترسی نوشتن', true, 'config، uploads و storage قابل نوشتن هستند');
        }

        // ── 7) نوشتن تنظیمات خصوصی ──────────────────────────────────────
        $prettyUrls = filter_var(env_value('JHD_PRETTY_URLS', 'false'), FILTER_VALIDATE_BOOLEAN);
        $localConfig = [
            'APP_ENV' => 'production',
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => (string)$port,
            'DB_NAME' => $name,
            'DB_USER' => $user,
            'DB_PASS' => $pass,
            'SITE_URL' => $siteUrl,
            'BASE_PATH' => '',
            'SITE_EMAIL' => $adminEmail,
            'UPLOAD_STORAGE' => 'local',
            'UPLOAD_LOCAL_PATH' => __DIR__ . '/../uploads',
            'UPLOAD_BASE_URL' => '/uploads',
            'SESSION_DRIVER' => 'database',
            'JHD_PRETTY_URLS' => $prettyUrls ? 'true' : 'false',
        ];

        $php = "<?php\n// Generated by Jametulhoda browser installer. Keep this file outside Git.\nreturn " . var_export($localConfig, true) . ";\n";
        if (file_put_contents($localPath, $php, LOCK_EX) === false) {
            throw new RuntimeException('فایل تنظیمات خصوصی ساخته نشد. دسترسی نوشتن پوشه config را بررسی کنید.');
        }
        $step('نوشتن config/local.php', true, 'تنظیمات دیتابیس و مسیر آپلود ذخیره شد');

        // ── 8) آزمون واقعی ورود با همان کنترلر برنامه ────────────────────
        // پس از نوشتن تنظیمات، همان کد برنامه (نه کد نصاب) بارگذاری می‌شود و
        // ورود مدیر آزمایش می‌شود: password_verify + نقش + وضعیت فعال.
        $GLOBALS['APP_LOCAL_CONFIG'] = $localConfig;
        $selfTest = 'بررسی انجام نشد.';
        $selfTestOk = false;
        try {
            require_once __DIR__ . '/../includes/identity.php';
            $schemaResult = jhd_ensure_identity_schema(true);
            if (empty($schemaResult['ok'])) throw new RuntimeException('مهاجرت ساختار هویت ناموفق بود.');
            $verified = jhd_verify_credentials($adminUsername, $adminPassword);
            if (empty($verified['ok'])) throw new RuntimeException((string)($verified['error'] ?? 'ورود آزمایشی ناموفق بود.'));
            $user = jhd_user_by_identifier($adminUsername);
            $selfTestOk = $user !== null && $user['role'] === 'super_admin' && (int)$user['is_active'] === 1;
            $selfTest = $selfTestOk
                ? 'ورود آزمایشی با رمز انجام‌شده موفق بود؛ نقش حساب: super_admin'
                : 'حساب ساخته شد اما نقش یا وضعیت آن درست نیست.';
        } catch (Throwable $e) {
            $selfTest = 'آزمون ورود ناموفق بود: ' . $e->getMessage();
        }
        $step('آزمون ورود مدیر (E2E)', $selfTestOk, $selfTest);

        if (!$selfTestOk) throw new RuntimeException('آزمون پایانی ورود موفق نبود؛ نصب قفل نشد تا دوباره تلاش کنید. ' . $selfTest);

        // ── 9) قفل نصب ───────────────────────────────────────────────────
        $lockValue = 'Installed: ' . date('c') . PHP_EOL . 'Schema statements: ' . $applied . PHP_EOL;
        if (file_put_contents($lockPath, $lockValue, LOCK_EX) === false) {
            throw new RuntimeException('قفل نصب ساخته نشد. دسترسی نوشتن پوشه config را بررسی کنید.');
        }
        $step('قفل نصب', true, 'اجرای دوبارهٔ نصاب تا وقتی این فایل وجود دارد ممکن نیست');

        $alreadyInstalled = true;
        $success = 'نصب با موفقیت انجام شد. همهٔ مراحل با آزمون واقعی بررسی شدند.';
        if ($siteUrlInsecure) {
            $success .= ' توجه: آدرس سایت با https ذخیره نشد؛ تا زمانی که SSL رایگان را فعال نکنید، robots.txt محدود می‌ماند و sitemap.xml خطا می‌دهد.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// وضعیت نصب‌شده: یک بررسی کوتاه و بدون افشای اطلاعات حساس.
$installedStatus = [];
if ($alreadyInstalled) {
    try {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../includes/identity.php';
        $installedStatus[] = ['label' => 'اتصال دیتابیس', 'ok' => true, 'detail' => ''];
        $installedStatus[] = ['label' => 'پوشهٔ آپلود', 'ok' => is_writable(__DIR__ . '/../uploads'), 'detail' => ''];
        $installedStatus[] = ['label' => 'پوشهٔ لاگ', 'ok' => is_dir(__DIR__ . '/../storage/logs'), 'detail' => ''];
        $installedStatus[] = ['label' => 'پروندهٔ رمز اولیه', 'ok' => !is_file($bootstrapFile), 'detail' => is_file($bootstrapFile) ? 'فایل محلی config/bootstrap-admin.php را پس از نصب حذف کنید.' : ''];
    } catch (Throwable $e) {
        $installedStatus[] = ['label' => 'اتصال دیتابیس', 'ok' => false, 'detail' => 'تنظیمات config/local.php را بررسی کنید.'];
    }
}

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>نصب Jametulhoda</title>
<style>
.steps{list-style:none;margin:0 0 18px;padding:0;display:grid;gap:8px}
.steps li{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-radius:12px;border:1px solid #dbe6df;background:#fbfdfb}
.steps li.ok{border-color:#bfe3cd;background:#f1fbf5}
.steps li.bad{border-color:#f4c7cf;background:#fff6f7}
.steps .mark{font-weight:800}
.steps li.ok .mark{color:#15803d}
.steps li.bad .mark{color:#b91c1c}
.steps .detail{display:block;color:#52675d;font-size:.86rem}
.hint{background:#f6f9f7;border:1px dashed #cddbd3;border-radius:12px;padding:10px 12px;color:#52675d;font-size:.87rem;margin:8px 0 0}
@font-face{font-family:Vazirmatn;src:url(<?= htmlspecialchars(BASE_PATH . '/assets/fonts/Vazirmatn-Regular.woff2', ENT_QUOTES, 'UTF-8') ?>) format('woff2');font-style:normal;font-weight:400;font-display:swap}*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:'Vazirmatn',system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:radial-gradient(circle at 88% 8%,rgba(179,140,54,.12),transparent 19rem),linear-gradient(135deg,#edf4ef,#f8faf8 56%,#f4f1e8);color:#172820;line-height:1.75}
.wrap{max-width:820px;margin:clamp(16px,5vh,52px) auto;padding:16px}.card{background:rgba(255,255,255,.96);border:1px solid #dbe6df;border-radius:22px;padding:clamp(20px,4vw,38px);box-shadow:0 18px 48px rgba(18,55,38,.11)}
h1{margin:0 0 8px;color:#184f38;font-size:clamp(1.5rem,4vw,2.1rem);line-height:1.45}.muted{color:#52675d}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}label{display:block;font-weight:750;margin-bottom:6px;color:#1d3327}input{width:100%;padding:11px 12px;border:1px solid #cfddd4;border-radius:10px;background:#fff;color:#172820;font:inherit;transition:border-color .16s,box-shadow .16s}input:focus{outline:0;border-color:#1b5e43;box-shadow:0 0 0 4px rgba(27,94,67,.12)}button,.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;padding:11px 20px;background:#1b5e43;color:#fff;text-decoration:none;font:inherit;font-weight:750;cursor:pointer;transition:transform .16s,background .16s}button:hover,.btn:hover{background:#124430;color:#fff;transform:translateY(-1px)}.notice{padding:12px 14px;border-radius:10px;margin-bottom:16px}.error{background:#fff1f2;color:#9f1239}.success{background:#ecfdf5;color:#166534}.section{border-top:1px solid #e3ebe5;margin-top:24px;padding-top:22px}.section h2{color:#184f38;font-size:1.2rem}@media(max-width:640px){.wrap{padding:10px}.card{border-radius:16px}.grid{grid-template-columns:1fr;gap:12px}.full{grid-column:auto}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;scroll-behavior:auto!important}}
</style>
</head>
<body class="jhd-installer">
<div class="wrap"><div class="card">
<h1>راه‌اندازی جامعة‌الهدی</h1>
<p class="muted">راه‌اندازی MySQL برای InfinityFree و ساخت اولین حساب مدیر.</p>

<?php if ($error): ?><div class="notice error"><?= installerEscape($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="notice success"><?= installerEscape($success) ?></div><?php endif; ?>
<?php if ($steps): ?>
<h2 style="font-size:1.1rem;margin:14px 0 10px">مراحل نصب</h2>
<ul class="steps">
<?php foreach ($steps as $item): ?>
  <li class="<?= $item['ok'] ? 'ok' : 'bad' ?>">
    <span class="mark"><?= $item['ok'] ? '✓' : '✕' ?></span>
    <span><?= installerEscape($item['label']) ?><?php if ($item['detail'] !== ''): ?><span class="detail"><?= installerEscape($item['detail']) ?></span><?php endif; ?></span>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($alreadyInstalled): ?>
<p>این نصب قبلاً انجام شده و مسیر نصب قفل شده است. برای نصب دوباره، پروندهٔ <code>config/install.lock</code> را حذف کنید.</p>
<?php if ($installedStatus): ?>
<ul class="steps">
<?php foreach ($installedStatus as $item): ?>
  <li class="<?= $item['ok'] ? 'ok' : 'bad' ?>">
    <span class="mark"><?= $item['ok'] ? '✓' : '✕' ?></span>
    <span><?= installerEscape($item['label']) ?><?php if ($item['detail'] !== ''): ?><span class="detail"><?= installerEscape($item['detail']) ?></span><?php endif; ?></span>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<a class="btn" href="<?= installerEscape(loginUrl()) ?>">ورود به حساب</a>
<a class="btn" href="<?= installerEscape(BASE_PATH === '' ? '/' : BASE_PATH . '/') ?>">صفحه اصلی</a>
<?php else: ?>
<form method="post" autocomplete="off">
<?= csrfField() ?>
<div class="grid">
<div><label for="db-host">میزبان MySQL</label><input id="db-host" name="db_host" value="<?= installerEscape($defaults['db_host']) ?>" required></div>
<div><label for="db-port">درگاه اتصال</label><input id="db-port" name="db_port" type="number" value="<?= installerEscape($defaults['db_port']) ?>" required></div>
<div><label for="db-name">نام دیتابیس</label><input id="db-name" name="db_name" value="<?= installerEscape($defaults['db_name']) ?>" required></div>
<div><label for="db-user">نام کاربری MySQL</label><input id="db-user" name="db_user" value="<?= installerEscape($defaults['db_user']) ?>" required></div>
<div class="full"><label for="db-pass">رمز عبور MySQL</label><input id="db-pass" name="db_pass" type="password" required></div>
<div class="full"><label for="site-url">نشانی کامل سایت (HTTPS)</label><input id="site-url" name="site_url" value="<?= installerEscape($defaults['site_url']) ?>" placeholder="https://jametulhoda.gt.tc"></div>
</div>

<div class="section"><h2>حساب مدیر</h2>
<div class="grid">
<div><label for="admin-username">نام کاربری مدیر</label><input id="admin-username" name="admin_username" value="<?= installerEscape($defaults['admin_username']) ?>" required></div>
<div><label for="admin-email">ایمیل مدیر</label><input id="admin-email" name="admin_email" type="email" value="<?= installerEscape($defaults['admin_email']) ?>"></div>
<div><label for="admin-name">نام مدیر</label><input id="admin-name" name="admin_name" value="<?= installerEscape($defaults['admin_name']) ?>"></div>
<div><label for="admin-password">رمز مدیر</label><input id="admin-password" name="admin_password" type="password" minlength="14" autocomplete="new-password">
<p class="hint">رمز مدیر را اینجا وارد کنید (حداقل ۱۴ نویسه). در صورت خالی‌بودن، فقط مقدار محرمانهٔ <code>DEFAULT_ADMIN_PASSWORD</code> یا فایل محلیِ خارج از Git استفاده می‌شود؛ رمز عمومی پیش‌فرض وجود ندارد.</p></div>
<div class="full"><label class="jhd-inline"><input type="checkbox" name="reset_password" value="1" style="width:auto"> اگر حساب مدیر از قبل وجود دارد، رمز آن بازنشانی شود</label></div>
</div></div>

<div class="section"><button type="submit">شروع نصب</button>
<p class="hint">نصاب پیش از ساخت حساب، اتصال دیتابیس را می‌آزماید؛ سپس جداول، پوشه‌های آپلود، تنظیمات خصوصی و قفل نصب را می‌سازد و در پایان ورود مدیر را با همان کد برنامه به‌صورت واقعی آزمایش می‌کند.</p></div>
</form>
<?php endif; ?>
</div></div>
</body>
</html>