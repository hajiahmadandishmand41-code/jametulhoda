<?php
/**
 * Jametulhoda installer
 *
 * Supports:
 *   - PostgreSQL / Supabase (recommended, including Vercel)
 *   - MySQL / MariaDB (legacy shared hosting)
 *
 * The PostgreSQL schema is expected in database/database.postgres.sql and
 * existing PostgreSQL installations are only checked here; schema changes
 * should be applied from migrations, not from a public web request.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$localPath = __DIR__ . '/../config/local.php';
$lockPath  = __DIR__ . '/../config/install.lock';
$isVercelRuntime = env_value('VERCEL') !== '';
$installerEnabled = env_value('INSTALLER_ENABLED') === '1';

/*
 * Vercel Production installer is a one-time bootstrap tool only.
 * It requires an explicit platform switch and locks automatically after the
 * persistent installation marker exists. It never writes credentials to disk.
 */

/*
 * Vercel Runtime filesystem is ephemeral/read-only for application state.
 * On Vercel, installation state lives in PostgreSQL and connection settings
 * remain in platform Environment Variables. Shared hosting still uses the
 * traditional config/local.php + install.lock files.
 */
$alreadyInstalled = is_file($lockPath);
if (!$alreadyInstalled && $isVercelRuntime) {
    try {
        $dbForInstallState = tryGetDB();
        if ($dbForInstallState !== null) {
            $stmt = $dbForInstallState->prepare(
                'SELECT value FROM settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute(['installation_completed']);
            $alreadyInstalled = (string)$stmt->fetchColumn() === '1';
        }
    } catch (Throwable $e) {
        // The installer must remain reachable when the database has not yet
        // been configured. The POST path will report the real connection error.
    }
}

/*
 * In Vercel Production the installer is never generally public. It can be
 * opened only for a deliberate one-time bootstrap by setting INSTALLER_ENABLED=1.
 * Once installation_completed=1 exists, it remains locked even if the switch
 * is accidentally left enabled.
 */
if ($isVercelRuntime && APP_ENV === 'production' && (!$installerEnabled || $alreadyInstalled)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

if (!$alreadyInstalled) {
    require_once __DIR__ . '/../includes/auth.php';
    startSecureSession();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        requirePostCsrf();
    }
}

$defaults = [
    'db_driver' => env_value('DB_DRIVER', env_value('DATABASE_URL') !== '' ? 'pgsql' : 'mysql'),
    'db_host' => '',
    'db_port' => env_value('DATABASE_URL') !== '' ? '5432' : '3306',
    'db_name' => env_value('DB_NAME', 'postgres'),
    'db_user' => '',
    'site_url' => env_value('SITE_URL', ''),
    'admin_username' => env_value('ADMIN_USERNAME', DEFAULT_ADMIN_USERNAME),
    'admin_name' => env_value('ADMIN_NAME', 'مدیر سایت'),
    'admin_email' => env_value('ADMIN_EMAIL', env_value('SITE_EMAIL', '')),
];

$error = '';
$success = '';
$steps = [];

$step = static function (string $label, bool $ok, string $detail = '') use (&$steps): void {
    $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};

function installerEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function installerPgDsn(string $host, int $port, string $name): string {
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) {
        throw new RuntimeException('میزبان PostgreSQL معتبر نیست.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('پورت دیتابیس معتبر نیست.');
    }
    if (!preg_match('/^[a-zA-Z0-9_.$-]+$/', $name)) {
        throw new RuntimeException('نام دیتابیس معتبر نیست.');
    }
    return 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';sslmode=require;connect_timeout=10';
}

function installerMysqlDsn(string $host, int $port, string $name): string {
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) {
        throw new RuntimeException('میزبان MySQL معتبر نیست.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('پورت دیتابیس معتبر نیست.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new RuntimeException('نام دیتابیس معتبر نیست.');
    }
    return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . rawurlencode($name) . ';charset=utf8mb4';
}

function installerPostgresUrl(string $host, int $port, string $name, string $user, string $pass): string {
    return 'postgresql://' . rawurlencode($user) . ':' . rawurlencode($pass) . '@' . $host . ':' . $port . '/' . rawurlencode($name) . '?sslmode=require';
}

function installerRequiredTables(PDO $pdo, string $driver): array {
    $required = ['users','categories','topics','posts','post_images','post_topics','lesson_collections',
        'lesson_volumes','lessons','lesson_topics','contact_messages','media_files','settings','books',
        'book_topics','site_banners','featured_banners','app_sessions','login_limits','stored_files',
        'storage_deletions','pending_uploads'];

    if ($driver === 'pgsql') {
        $q = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public'");
        $existing = array_fill_keys(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
    } else {
        $q = $pdo->query('SHOW TABLES');
        $existing = array_fill_keys(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    return array_values(array_diff($required, array_keys($existing)));
}

if (!$alreadyInstalled && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $field = static fn(string $key, string $default = ''): string =>
        jhd_string_field($_POST, $key, $default);

    try {
        if ($isVercelRuntime) {
            // Vercel never accepts a second, browser-supplied DB endpoint.
            // The installer and application share exactly the same canonical
            // DATABASE_URL + Vercel-managed password source.
            $driver = 'pgsql';
            $runtimeUrl = postgresDatabaseUrl();
            $runtimeParsed = parse_url($runtimeUrl);
            $host = strtolower((string)($runtimeParsed['host'] ?? ''));
            $port = (int)($runtimeParsed['port'] ?? 5432);
            $name = rawurldecode(ltrim((string)($runtimeParsed['path'] ?? ''), '/'));
            $user = rawurldecode((string)($runtimeParsed['user'] ?? ''));
            $pass = '';
            $pdo = newDatabaseConnection();
        } else {
            $driver = strtolower(trim($field('db_driver', $defaults['db_driver'])));
            if (!in_array($driver, ['pgsql', 'mysql'], true)) {
                throw new RuntimeException('نوع دیتابیس باید PostgreSQL یا MySQL باشد.');
            }

            $host = trim($field('db_host', $defaults['db_host']));
            $port = (int)$field('db_port', $defaults['db_port']);
            $name = trim($field('db_name', $defaults['db_name']));
            $user = trim($field('db_user', $defaults['db_user']));
            $pass = $field('db_pass');

            if ($host === '' || $name === '' || $user === '' || $pass === '') {
                throw new RuntimeException('Host، Port، Database، User و Password را کامل وارد کنید.');
            }
        }

        $siteUrl = rtrim(trim($field('site_url', $defaults['site_url'])), '/');
        if ($siteUrl === '') {
            $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
            if (preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/', $requestHost)) {
                $siteUrl = 'https://' . preg_replace('/:[0-9]+$/', '', $requestHost);
            }
        }
        if ($siteUrl !== '' && !filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('آدرس سایت معتبر نیست.');
        }

        $adminUsername = trim($field('admin_username', $defaults['admin_username']));
        $adminName = trim($field('admin_name', $defaults['admin_name']));
        $adminEmail = trim($field('admin_email', $defaults['admin_email']));
        $adminPassword = $field('admin_password');
        $resetPassword = $field('reset_password') === '1';

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $adminUsername)) {
            throw new RuntimeException('نام کاربری مدیر معتبر نیست.');
        }
        if (strlen($adminPassword) < 14) {
            throw new RuntimeException('رمز مدیر باید حداقل ۱۴ نویسه باشد.');
        }
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('ایمیل مدیر معتبر نیست.');
        }

        if (!$isVercelRuntime) {
            if ($driver === 'pgsql') {
                $dsn = installerPgDsn($host, $port, $name);
            } else {
                $dsn = installerMysqlDsn($host, $port, $name);
            }

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]);
        }

        $version = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $step('اتصال به دیتابیس', true, strtoupper($driver) . ' — نسخه سرور: ' . $version);

        /*
         * On Vercel the submitted form cannot persist secrets to the runtime
         * filesystem. Verify the platform Environment Variables now, before
         * changing any database rows, so the next request uses the same DB.
         */
        if ($isVercelRuntime) {
            $savedLocalConfig = $GLOBALS['APP_LOCAL_CONFIG'] ?? [];
            $GLOBALS['APP_LOCAL_CONFIG'] = [];
            try {
                $runtimeUrl = postgresDatabaseUrl();
                if ($runtimeUrl === '') {
                    throw new RuntimeException(
                        'در Vercel هیچ اتصال PostgreSQL قابل استفاده‌ای در Environment Variables پیدا نشد. DATABASE_URL یا متغیرهای STORAGE_POSTGRES_* را تنظیم کنید.'
                    );
                }

                $runtimePdo = newDatabaseConnection();
                $runtimeCheck = $runtimePdo->query(
                    'SELECT current_database() AS db_name, current_user AS db_user'
                )->fetch();
                if (!is_array($runtimeCheck)
                    || strcasecmp((string)($runtimeCheck['db_name'] ?? ''), $name) !== 0
                    || (string)($runtimeCheck['db_user'] ?? '') !== $user
                ) {
                    throw new RuntimeException(
                        'Environment Variables دیتابیس Vercel به همان Database/User واردشده در Installer اشاره نمی‌کنند.'
                    );
                }
            } finally {
                $GLOBALS['APP_LOCAL_CONFIG'] = $savedLocalConfig;
            }
            $step('بررسی اتصال پایدار Vercel', true, 'Environment Variables همان PostgreSQL واردشده را تأیید کردند.');
        }

        $missing = installerRequiredTables($pdo, $driver);
        if ($missing !== []) {
            throw new RuntimeException(
                'این جداول در دیتابیس وجود ندارند: ' . implode('، ', $missing) .
                ' — ابتدا database/database.postgres.sql گیت‌هاب را در Supabase اجرا کنید.'
            );
        }
        $step('بررسی ۲۲ جدول پروژه', true, 'همه جداول مورد نیاز از قبل وجود دارند و دوباره ساخته نمی‌شوند.');

        $databaseUrl = $driver === 'pgsql'
            ? ($isVercelRuntime ? $runtimeUrl : installerPostgresUrl($host, $port, $name, $user, $pass))
            : '';

        $localConfig = [
            'APP_ENV' => 'production',
            'DB_DRIVER' => $driver,
            'DB_HOST' => $host,
            'DB_PORT' => (string)$port,
            'DB_NAME' => $name,
            'DB_USER' => $user,
            'DB_PASS' => $pass,
            'DATABASE_URL' => $databaseUrl,
            'SITE_URL' => $siteUrl,
            'SITE_EMAIL' => $adminEmail,
            'UPLOAD_STORAGE' => 'local',
            'UPLOAD_LOCAL_PATH' => __DIR__ . '/../uploads',
            'UPLOAD_BASE_URL' => '/uploads',
            'SESSION_DRIVER' => 'database',
            'JHD_PRETTY_URLS' => env_value('JHD_PRETTY_URLS', 'false'),
        ];

        $GLOBALS['APP_LOCAL_CONFIG'] = $localConfig;

        require_once __DIR__ . '/../includes/identity.php';

        $identity = jhd_ensure_identity_schema(true);
        if (empty($identity['ok'])) {
            throw new RuntimeException((string)($identity['error'] ?? 'ساختار users قابل بررسی/ترمیم نیست.'));
        }
        $step('بررسی ساختار کاربران', true, 'ساختار identity آماده است.');

        $db = getDB();

        if ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'INSERT INTO settings (setting_key, value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO settings (setting_key, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
            );
        }
        $stmt->execute(['site_name', 'جامعة‌الهدی']);
        $stmt->execute(['site_slogan', 'مرکز علمی، آموزشی و پژوهشی در پرتو قرآن و عترت']);
        if ($adminEmail !== '') $stmt->execute(['site_email', $adminEmail]);
        $step('تنظیمات پایه', true, 'تنظیمات اصلی سایت ثبت شد.');

        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $existingStmt = $db->prepare(
            $driver === 'pgsql'
                ? 'SELECT id FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1'
                : 'SELECT id FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1'
        );
        $existingStmt->execute([$adminUsername, $adminEmail]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId === false) {
            $stmt = $db->prepare(
                'INSERT INTO users (username,email,password,full_name,role,is_active,must_change_password,auth_version)
                 VALUES (?,?,?,?,?,1,1,1)'
            );
            $stmt->execute([$adminUsername, $adminEmail !== '' ? $adminEmail : null, $hash, $adminName, 'super_admin']);
            $step('ساخت حساب مدیر', true, 'حساب «' . $adminUsername . '» با نقش super_admin ساخته شد.');
        } elseif ($resetPassword) {
            $stmt = $db->prepare(
                'UPDATE users SET password=?, email=?, full_name=?, role=?, is_active=1,
                 must_change_password=1, auth_version=COALESCE(auth_version,1)+1 WHERE id=?'
            );
            $stmt->execute([$hash, $adminEmail !== '' ? $adminEmail : null, $adminName, 'super_admin', $existingId]);
            $step('بازنشانی رمز مدیر', true, 'رمز مدیر تغییر کرد.');
        } else {
            $stmt = $db->prepare('UPDATE users SET role=?, is_active=1 WHERE id=?');
            $stmt->execute(['super_admin', $existingId]);
            $step('حساب مدیر موجود بود', true, 'رمز قبلی حفظ شد.');
        }

        if (!is_dir(__DIR__ . '/../uploads')) @mkdir(__DIR__ . '/../uploads', 0755, true);
        if (!is_dir(__DIR__ . '/../storage/logs')) @mkdir(__DIR__ . '/../storage/logs', 0755, true);

        if ($isVercelRuntime) {
            // Never depend on a writable application filesystem on Vercel.
            // The database connection is supplied by Environment Variables.
            $step(
                'ذخیره تنظیمات اتصال',
                true,
                'Vercel از Environment Variables استفاده می‌کند؛ config/local.php روی Runtime ذخیره نمی‌شود.'
            );
        } else {
            $configDir = dirname($localPath);
            if (!is_dir($configDir) || !is_writable($configDir)) {
                throw new RuntimeException(
                    'پوشه config قابل نوشتن نیست. دسترسی نوشتن آن را فعال کنید یا نصب را روی Vercel با Environment Variables انجام دهید.'
                );
            }

            $php = "<?php\n// Generated by Jametulhoda installer. Keep this file out of Git.\nreturn "
                . var_export($localConfig, true) . ";\n";
            if (file_put_contents($localPath, $php, LOCK_EX) === false) {
                throw new RuntimeException('config/local.php ساخته نشد؛ مجوز نوشتن پوشه config را بررسی کنید.');
            }
            $step('ذخیره تنظیمات اتصال', true, 'config/local.php ساخته شد.');
        }

        $GLOBALS['APP_LOCAL_CONFIG'] = $localConfig;

        $verified = jhd_verify_credentials($adminUsername, $adminPassword);
        if (empty($verified['ok'])) {
            throw new RuntimeException((string)($verified['error'] ?? 'آزمون ورود مدیر ناموفق بود.'));
        }
        $admin = jhd_user_by_identifier($adminUsername);
        if ($admin === null || $admin['role'] !== 'super_admin' || (int)$admin['is_active'] !== 1) {
            throw new RuntimeException('آزمون نهایی مدیر موفق نبود.');
        }
        $step('آزمون واقعی ورود مدیر', true, 'ورود با همان کد اصلی برنامه موفق شد.');

        // Persistent installation marker. This is mandatory on Vercel because
        // install.lock cannot be relied on across serverless instances.
        if ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'INSERT INTO settings (setting_key, value) VALUES (?, ?)
                 ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO settings (setting_key, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)'
            );
        }
        $stmt->execute(['installation_completed', '1']);
        $step('ثبت دائمی وضعیت نصب', true, 'وضعیت نصب در PostgreSQL ثبت شد.');

        if ($isVercelRuntime) {
            $step('قفل نصب', true, 'در Vercel قفل نصب در دیتابیس نگهداری می‌شود و به install.lock وابسته نیست.');
        } else {
            $lockValue = 'Installed: ' . date('c') . PHP_EOL . 'Driver: ' . $driver . PHP_EOL;
            if (file_put_contents($lockPath, $lockValue, LOCK_EX) === false) {
                throw new RuntimeException('قفل نصب ساخته نشد؛ دسترسی نوشتن پوشه config را بررسی کنید.');
            }
            $step('قفل نصب', true, 'install.lock ساخته شد.');
        }

        $success = 'نصب با موفقیت انجام شد و اتصال دیتابیس و ورود مدیر آزمایش شد.';
        $alreadyInstalled = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>نصب جامعة‌الهدی</title>
<style>
body{margin:0;background:#f3f7f5;color:#172820;font-family:Tahoma,system-ui,sans-serif;line-height:1.8}
.wrap{max-width:820px;margin:30px auto;padding:15px}.card{background:#fff;border:1px solid #dbe6df;border-radius:18px;padding:28px;box-shadow:0 12px 35px #0001}
h1{color:#184f38}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}
label{display:block;font-weight:700;margin-bottom:5px}input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #ccd9d2;border-radius:9px;font:inherit}
button,.btn{border:0;border-radius:9px;padding:11px 20px;background:#1b5e43;color:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.notice{padding:12px;border-radius:10px;margin:10px 0}.error{background:#fff0f2;color:#991b1b}.success{background:#ecfdf5;color:#166534}
.steps{list-style:none;padding:0}.steps li{padding:9px 12px;margin:7px 0;border-radius:9px;background:#f5faf7}.ok{border:1px solid #b9dfc9}.bad{border:1px solid #efb9c0}
.hint{font-size:.88rem;color:#5b6d64;background:#f7faf8;padding:10px;border-radius:9px}
@media(max-width:650px){.grid{grid-template-columns:1fr}.full{grid-column:auto}.card{padding:18px}}
</style>
</head>
<body>
<div class="wrap"><div class="card">
<h1>راه‌اندازی جامعة‌الهدی</h1>
<p>نسخه جدید Installer برای <strong>PostgreSQL / Supabase</strong> و MySQL.</p>

<?php if ($error): ?><div class="notice error"><?= installerEscape($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="notice success"><?= installerEscape($success) ?></div><?php endif; ?>

<?php if ($steps): ?>
<ul class="steps">
<?php foreach ($steps as $item): ?>
<li class="<?= $item['ok'] ? 'ok' : 'bad' ?>">
<strong><?= $item['ok'] ? '✓' : '✕' ?> <?= installerEscape($item['label']) ?></strong>
<?php if ($item['detail'] !== ''): ?><div><?= installerEscape($item['detail']) ?></div><?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($alreadyInstalled): ?>
<p>نصب انجام شده و برای جلوگیری از اجرای دوباره قفل شده است.</p>
<a class="btn" href="<?= installerEscape(loginUrl()) ?>">ورود به حساب</a>
<?php else: ?>
<form method="post" autocomplete="off">
<?= csrfField() ?>

<div class="grid">
<div>
<label>نوع دیتابیس</label>
<select name="db_driver" id="db_driver">
<option value="pgsql" <?= $defaults['db_driver']==='pgsql'?'selected':'' ?>>PostgreSQL / Supabase</option>
<option value="mysql" <?= $defaults['db_driver']==='mysql'?'selected':'' ?>>MySQL / MariaDB</option>
</select>
</div>
<div><label>Host</label><input name="db_host" value="<?= installerEscape($defaults['db_host']) ?>" required></div>
<div><label>Port</label><input name="db_port" type="number" value="<?= installerEscape($defaults['db_port']) ?>" required></div>
<div><label>Database</label><input name="db_name" value="<?= installerEscape($defaults['db_name']) ?>" required></div>
<div><label>User</label><input name="db_user" value="<?= installerEscape($defaults['db_user']) ?>" required></div>
<div><label>Password</label><input name="db_pass" type="password" required></div>
<div class="full"><label>آدرس سایت HTTPS</label><input name="site_url" value="<?= installerEscape($defaults['site_url']) ?>" placeholder="https://jametulhoda.vercel.app"></div>
</div>

<p class="hint">
برای Supabase از <strong>Connect → Session Pooler</strong> مقادیر Host، Port، Database، User و Password را بردار.
این Installer جدول‌های موجود را دوباره نمی‌سازد؛ ۲۲ جدول پروژه را فقط بررسی می‌کند.
</p>

<h2>حساب مدیر</h2>
<div class="grid">
<div><label>نام کاربری مدیر</label><input name="admin_username" value="<?= installerEscape($defaults['admin_username']) ?>" required></div>
<div><label>ایمیل مدیر</label><input name="admin_email" type="email" value="<?= installerEscape($defaults['admin_email']) ?>"></div>
<div><label>نام مدیر</label><input name="admin_name" value="<?= installerEscape($defaults['admin_name']) ?>"></div>
<div><label>رمز مدیر</label><input name="admin_password" type="password" minlength="14" required></div>
<div class="full"><label><input type="checkbox" name="reset_password" value="1" style="width:auto"> اگر مدیر از قبل وجود دارد، رمز او بازنشانی شود.</label></div>
</div>

<p><button type="submit">اتصال و نصب</button></p>
</form>
<?php endif; ?>
</div></div>

<script>
const driver=document.getElementById('db_driver');
if(driver){
  const port=document.querySelector('[name="db_port"]');
  driver.addEventListener('change',()=>{ if(!port.dataset.edited) port.value=driver.value==='pgsql'?'5432':'3306'; });
  port.addEventListener('input',()=>port.dataset.edited='1');
}
</script>
</body>
</html>
