<?php
/**
 * identity.php — مخزن یکپارچهٔ هویت (کاربر، مدیر، مدیر ارشد)
 *
 * همهٔ حساب‌ها در یک جدول `users` زندگی می‌کنند و نقش (`role`) تعیین می‌کند که
 * حساب به کدام بخش دسترسی دارد:
 *
 *   user        → فقط حساب کاربری عمومی
 *   admin       → پنل مدیریت محتوا
 *   super_admin → همهٔ پنل + کاربران + تنظیمات
 *
 * رمز عبور همیشه با password_hash() ذخیره و فقط با password_verify() بررسی
 * می‌شود؛ هیچ‌جا رمز ساده با هش مقایسه یا در پاسخ/لاگ نمایش داده نمی‌شود.
 *
 * این فایل همچنین مهاجرت امن و تکرارپذیر نصب‌های قدیمی را انجام می‌دهد:
 *   - افزودن ستون‌های تازهٔ users (username/email قابل NULL، تلفن، نقش‌های جدید…)
 *   - نگاشت نقش‌های قدیمی superadmin → super_admin و editor → admin
 *   - انتقال اعضای جدول قدیمی `members` به users با نقش user (رمز هش‌شده حفظ می‌شود)
 *   - ساخت ایندکس‌های یکتا روی ایمیل و شمارهٔ تلفن
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/roles.php';

/** نسخهٔ طرح‌وارهٔ هویت؛ با هر تغییر ساختار users یک عدد بالا می‌رود. */
const JHD_IDENTITY_SCHEMA_VERSION = 2;

// ─── ابزارهای بررسی طرح‌واره ─────────────────────────────────────────────────

/** نام جدول فقط از این فهرست می‌آید تا هیچ رشتهٔ کاربری وارد SQL نشود. */
function jhd_schema_tables(): array {
    return ['users', 'members', 'settings', 'posts', 'app_sessions', 'login_limits', 'books', 'lessons', 'topics', 'media_files'];
}

function jhd_table_exists(string $table): bool {
    if (!in_array($table, jhd_schema_tables(), true)) return false;
    $db = getDB();
    try {
        switch (databaseDriver()) {
            case 'mysql':
                $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->execute([$table]);
                return (int)$stmt->fetchColumn() > 0;
            case 'sqlite':
                $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
                $stmt->execute([$table]);
                return (int)$stmt->fetchColumn() > 0;
            default:
                $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?');
                $stmt->execute([$table]);
                return (int)$stmt->fetchColumn() > 0;
        }
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string,bool> مجموعهٔ ستون‌های موجود یک جدول (کلید: نام کوچک‌شده) */
function jhd_table_columns(string $table): array {
    if (!in_array($table, jhd_schema_tables(), true)) return [];
    $db = getDB();
    $columns = [];
    try {
        switch (databaseDriver()) {
            case 'mysql':
                $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->execute([$table]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) $columns[strtolower((string)$name)] = true;
                break;
            case 'sqlite':
                foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
                    $columns[strtolower((string)($row['name'] ?? ''))] = true;
                }
                break;
            default:
                $stmt = $db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?');
                $stmt->execute([$table]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) $columns[strtolower((string)$name)] = true;
                break;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $columns;
}

function jhd_index_exists(string $table, string $index): bool {
    $db = getDB();
    try {
        switch (databaseDriver()) {
            case 'mysql':
                $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
                $stmt->execute([$table, $index]);
                return (int)$stmt->fetchColumn() > 0;
            case 'sqlite':
                $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?");
                $stmt->execute([$table, $index]);
                return (int)$stmt->fetchColumn() > 0;
            default:
                $stmt = $db->prepare('SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?');
                $stmt->execute([$table, $index]);
                return (int)$stmt->fetchColumn() > 0;
        }
    } catch (Throwable $e) {
        return false;
    }
}

/** ستون‌های یکتای users که مقدارشان نباید تکراری باشد. */
function jhd_user_unique_columns(): array {
    return [
        'username' => 'users_username_unique',
        'email' => 'users_email_unique',
        'phone_normalized' => 'users_phone_normalized_unique',
    ];
}

// ─── ساخت و مهاجرت جدول هویت ─────────────────────────────────────────────────

function jhd_users_ddl(): string {
    switch (databaseDriver()) {
        case 'mysql':
            return 'CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT,
                username VARCHAR(80) NULL,
                email VARCHAR(180) NULL,
                phone VARCHAR(32) NULL,
                phone_normalized VARCHAR(32) NULL,
                country VARCHAR(80) NOT NULL DEFAULT \'\',
                country_code VARCHAR(8) NOT NULL DEFAULT \'\',
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(120) NOT NULL DEFAULT \'\',
                role VARCHAR(20) NOT NULL DEFAULT \'user\',
                is_active SMALLINT NOT NULL DEFAULT 1,
                must_change_password SMALLINT NOT NULL DEFAULT 0,
                agreed_terms SMALLINT NOT NULL DEFAULT 0,
                avatar VARCHAR(350) NULL,
                last_login DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                auth_version INT NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY users_username_unique (username),
                UNIQUE KEY users_email_unique (email),
                UNIQUE KEY users_phone_normalized_unique (phone_normalized)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        case 'sqlite':
            return 'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(80) NULL,
                email VARCHAR(180) NULL,
                phone VARCHAR(32) NULL,
                phone_normalized VARCHAR(32) NULL,
                country VARCHAR(80) NOT NULL DEFAULT \'\',
                country_code VARCHAR(8) NOT NULL DEFAULT \'\',
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(120) NOT NULL DEFAULT \'\',
                role VARCHAR(20) NOT NULL DEFAULT \'user\',
                is_active SMALLINT NOT NULL DEFAULT 1,
                must_change_password SMALLINT NOT NULL DEFAULT 0,
                agreed_terms SMALLINT NOT NULL DEFAULT 0,
                avatar VARCHAR(350) NULL,
                last_login DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                auth_version INT NOT NULL DEFAULT 1,
                UNIQUE (username),
                UNIQUE (email),
                UNIQUE (phone_normalized)
            )';
        default:
            return 'CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(80) NULL,
                email VARCHAR(180) NULL,
                phone VARCHAR(32) NULL,
                phone_normalized VARCHAR(32) NULL,
                country VARCHAR(80) NOT NULL DEFAULT \'\',
                country_code VARCHAR(8) NOT NULL DEFAULT \'\',
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(120) NOT NULL DEFAULT \'\',
                role VARCHAR(20) NOT NULL DEFAULT \'user\',
                is_active SMALLINT NOT NULL DEFAULT 1,
                must_change_password SMALLINT NOT NULL DEFAULT 0,
                agreed_terms SMALLINT NOT NULL DEFAULT 0,
                avatar VARCHAR(350) NULL,
                last_login TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                auth_version INT NOT NULL DEFAULT 1
            )';
    }
}

/** تعریف ستون‌های تازه برای نصب‌های قدیمی: نام ⇒ DDL هر درایور. */
function jhd_user_column_definitions(): array {
    return [
        'username'             => ['mysql' => 'VARCHAR(80) NULL',        'sqlite' => 'VARCHAR(80) NULL',        'pgsql' => 'VARCHAR(80) NULL'],
        'email'                => ['mysql' => 'VARCHAR(180) NULL',       'sqlite' => 'VARCHAR(180) NULL',       'pgsql' => 'VARCHAR(180) NULL'],
        'phone'                => ['mysql' => 'VARCHAR(32) NULL',        'sqlite' => 'VARCHAR(32) NULL',        'pgsql' => 'VARCHAR(32) NULL'],
        'phone_normalized'     => ['mysql' => 'VARCHAR(32) NULL',        'sqlite' => 'VARCHAR(32) NULL',        'pgsql' => 'VARCHAR(32) NULL'],
        'country'              => ['mysql' => "VARCHAR(80) NOT NULL DEFAULT ''",  'sqlite' => "VARCHAR(80) NOT NULL DEFAULT ''",  'pgsql' => "VARCHAR(80) NOT NULL DEFAULT ''"],
        'country_code'         => ['mysql' => "VARCHAR(8) NOT NULL DEFAULT ''",   'sqlite' => "VARCHAR(8) NOT NULL DEFAULT ''",   'pgsql' => "VARCHAR(8) NOT NULL DEFAULT ''"],
        'password'             => ['mysql' => "VARCHAR(255) NOT NULL DEFAULT ''", 'sqlite' => "VARCHAR(255) NOT NULL DEFAULT ''", 'pgsql' => "VARCHAR(255) NOT NULL DEFAULT ''"],
        'full_name'            => ['mysql' => "VARCHAR(120) NOT NULL DEFAULT ''", 'sqlite' => "VARCHAR(120) NOT NULL DEFAULT ''", 'pgsql' => "VARCHAR(120) NOT NULL DEFAULT ''"],
        'role'                 => ['mysql' => "VARCHAR(20) NOT NULL DEFAULT 'user'", 'sqlite' => "VARCHAR(20) NOT NULL DEFAULT 'user'", 'pgsql' => "VARCHAR(20) NOT NULL DEFAULT 'user'"],
        'is_active'            => ['mysql' => 'SMALLINT NOT NULL DEFAULT 1', 'sqlite' => 'SMALLINT NOT NULL DEFAULT 1', 'pgsql' => 'SMALLINT NOT NULL DEFAULT 1'],
        'must_change_password' => ['mysql' => 'SMALLINT NOT NULL DEFAULT 0', 'sqlite' => 'SMALLINT NOT NULL DEFAULT 0', 'pgsql' => 'SMALLINT NOT NULL DEFAULT 0'],
        'agreed_terms'         => ['mysql' => 'SMALLINT NOT NULL DEFAULT 0', 'sqlite' => 'SMALLINT NOT NULL DEFAULT 0', 'pgsql' => 'SMALLINT NOT NULL DEFAULT 0'],
        'avatar'               => ['mysql' => 'VARCHAR(350) NULL',       'sqlite' => 'VARCHAR(350) NULL',       'pgsql' => 'VARCHAR(350) NULL'],
        'last_login'           => ['mysql' => 'DATETIME NULL',           'sqlite' => 'DATETIME NULL',           'pgsql' => 'TIMESTAMPTZ NULL'],
        'updated_at'           => ['mysql' => 'DATETIME NULL',           'sqlite' => 'DATETIME NULL',           'pgsql' => 'TIMESTAMPTZ NULL'],
        'auth_version'         => ['mysql' => 'INT NOT NULL DEFAULT 1',  'sqlite' => 'INT NOT NULL DEFAULT 1',  'pgsql' => 'INT NOT NULL DEFAULT 1'],
    ];
}

/**
 * حذف قیدهای CHECK قدیمی روی users. طرح‌وارهٔ پیشین فقط
 * ('superadmin','admin','editor') را مجاز می‌کرد و بنابراین ذخیرهٔ
 * role='super_admin' یا 'user' با خطای قید شکست می‌خورد.
 */
function jhd_drop_user_check_constraints(PDO $db): void {
    if (databaseDriver() !== 'mysql') return;
    try {
        $stmt = $db->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND CONSTRAINT_TYPE = 'CHECK'"
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $name = (string)$name;
            if ($name === '' || strlen($name) > 255) continue;
            // Constraint names come from information_schema (not user input).
            // Escape backticks so names such as users.role can be addressed.
            $quoted = str_replace(chr(96), chr(96) . chr(96), $name);
            $dropped = false;
            foreach ([
                "ALTER TABLE users DROP CHECK " . chr(96) . $quoted . chr(96),
                "ALTER TABLE users DROP CONSTRAINT " . chr(96) . $quoted . chr(96),
            ] as $sql) {
                try {
                    $db->exec($sql);
                    $dropped = true;
                    break;
                } catch (Throwable $e) {
                    // Try the alternate MariaDB/MySQL spelling.
                }
            }
            if (!$dropped) {
                throw new RuntimeException('Unable to remove legacy users CHECK constraint.');
            }
        }
    } catch (Throwable $e) {
        error_log('Unable to inspect/drop users CHECK constraints: ' . get_class($e));
        throw $e;
    }
}

/**
 * SQLite نمی‌تواند قید CHECK را حذف کند. اگر جدول users ساختهٔ طرح‌وارهٔ قدیمی
 * باشد (CHECK روی نقش‌های superadmin/editor)، جدول با حفظ داده‌ها بازسازی
 * می‌شود. تولیدِ سرور MySQL/PostgreSQL به این مسیر نیازی ندارد.
 */
function jhd_sqlite_users_needs_rebuild(PDO $db): bool {
    try {
        $sql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
    return $sql !== '' && stripos($sql, 'check') !== false;
}

function jhd_sqlite_rebuild_users(PDO $db): void {
    $existing = array_keys(jhd_table_columns('users'));
    $wanted = ['username', 'email', 'phone', 'phone_normalized', 'country', 'country_code', 'password', 'full_name',
               'role', 'is_active', 'must_change_password', 'agreed_terms', 'avatar', 'last_login',
               'created_at', 'updated_at', 'auth_version'];
    $copy = array_values(array_intersect($wanted, $existing));
    try {
        // legacy_alter_table=ON تا RENAME ارجاع‌های جدول‌های دیگر (posts.author_id …)
        // را به نام قدیمی تغییر ندهد.
        $db->exec('PRAGMA foreign_keys=OFF');
        $db->exec('PRAGMA legacy_alter_table=ON');
        $db->exec('DROP TABLE IF EXISTS users_legacy_rebuild');
        $db->exec('ALTER TABLE users RENAME TO users_legacy_rebuild');
        $db->exec(jhd_users_ddl());
        if ($copy) {
            $columns = implode(', ', $copy);
            $db->exec("INSERT INTO users ($columns) SELECT $columns FROM users_legacy_rebuild");
        }
        $db->exec('DROP TABLE users_legacy_rebuild');
    } catch (Throwable $e) {
        error_log('SQLite users rebuild failed: ' . get_class($e));
    } finally {
        try { $db->exec('PRAGMA legacy_alter_table=OFF'); $db->exec('PRAGMA foreign_keys=ON'); } catch (Throwable $e) { }
    }
}

/** انتقال اعضای جدول قدیمی members به users (رمز هش‌شده دست‌نخورده می‌ماند). */
function jhd_migrate_legacy_members(PDO $db): int {
    if (!jhd_table_exists('members')) return 0;
    $moved = 0;
    try {
        $rows = $db->query('SELECT * FROM members')->fetchAll();
    } catch (Throwable $e) {
        return 0;
    }
    foreach ($rows as $row) {
        $email = trim((string)($row['email'] ?? ''));
        $phone = trim((string)($row['phone'] ?? ''));
        $normalized = trim((string)($row['phone_normalized'] ?? ''));
        if ($email === '' && $normalized === '') continue;
        // عضو تکراری ساخته نمی‌شود.
        try {
            if ($email !== '') {
                $dup = $db->prepare('SELECT id FROM users WHERE LOWER(COALESCE(email,\'\')) = ? LIMIT 1');
                $dup->execute([mb_strtolower($email)]);
                if ($dup->fetchColumn()) continue;
            }
            if ($normalized !== '') {
                $dup = $db->prepare('SELECT id FROM users WHERE phone_normalized = ? LIMIT 1');
                $dup->execute([$normalized]);
                if ($dup->fetchColumn()) continue;
            }
            $db->prepare('INSERT INTO users (email, phone, phone_normalized, country, country_code, password, full_name, role, is_active, agreed_terms, auth_version, last_login, created_at)
                          VALUES (?,?,?,?,?,?,?,?, ?, ?, ?, ?, ?)')
               ->execute([
                   $email !== '' ? mb_strtolower($email) : null,
                   $phone !== '' ? $phone : null,
                   $normalized !== '' ? $normalized : null,
                   (string)($row['country'] ?? ''),
                   (string)($row['country_code'] ?? ''),
                   (string)$row['password'],
                   (string)($row['full_name'] ?? ''),
                   JHD_ROLE_USER,
                   (int)($row['is_active'] ?? 1),
                   (int)($row['agreed_terms'] ?? 0),
                   (int)($row['auth_version'] ?? 1),
                   $row['last_login'] ?? null,
                   $row['created_at'] ?? date('Y-m-d H:i:s'),
               ]);
            $moved++;
        } catch (Throwable $e) {
            error_log('Legacy member migration skipped one row: ' . get_class($e));
        }
    }
    // جدول قدیمی نگه داشته می‌شود (با نام پشتیبان) تا داده از بین نرود؛
    // دیگر هیچ کدی از آن استفاده نمی‌کند.
    try {
        if (!jhd_table_exists('members_legacy')) {
            if (databaseDriver() === 'pgsql') $db->exec('ALTER TABLE members RENAME TO members_legacy');
            else $db->exec('RENAME TABLE members TO members_legacy');
        }
    } catch (Throwable $e) {
        error_log('Legacy members table could not be renamed; rows were copied to users.');
    }
    return $moved;
}

/** آیا مقدار تکراری برای یک ستون یکتا وجود دارد؟ (قبل از ساخت ایندکس یکتا) */
function jhd_has_duplicate_values(PDO $db, string $column): bool {
    if (!array_key_exists($column, jhd_user_unique_columns())) return false;
    try {
        $sql = "SELECT COUNT(*) FROM (SELECT $column FROM users WHERE $column IS NOT NULL AND $column <> '' GROUP BY $column HAVING COUNT(*) > 1) duplicates";
        return (int)$db->query($sql)->fetchColumn() > 0;
    } catch (Throwable $e) {
        return true; // محافظه‌کارانه: ایندکس ساخته نشود
    }
}

function jhd_create_unique_index(PDO $db, string $column, string $index): void {
    if (jhd_index_exists('users', $index)) return;
    if (jhd_has_duplicate_values($db, $column)) {
        error_log("Unique index $index skipped: duplicate $column values must be cleaned first.");
        return;
    }
    $dialect = databaseDriver() === 'sqlite'
        ? "CREATE UNIQUE INDEX IF NOT EXISTS $index ON users($column)"
        : "CREATE UNIQUE INDEX $index ON users($column)";
    try {
        $db->exec($dialect);
    } catch (Throwable $e) {
        error_log("Unique index $index could not be created: " . get_class($e));
    }
}

/**
 * ساخت یا به‌روزرسانی ساختار هویت. کاملاً تکرارپذیر است و داده‌ها را پاک
 * نمی‌کند. اگر نصب انجام شده باشد، فقط چیزهای ناقص افزوده می‌شوند.
 *
 * @return array{ok:bool,created:bool,columns:array<int,string>,migrated_members:int,indexes:array<int,string>,error?:string}
 */
function jhd_ensure_identity_schema(bool $force = false): array {
    static $done = null;
    if ($done !== null && !$force) return $done;

    $result = ['ok' => false, 'created' => false, 'columns' => [], 'migrated_members' => 0, 'indexes' => []];
    try {
        $db = getDB();
        $driver = databaseDriver();
        $existed = jhd_table_exists('users');
        if (!$existed) {
            $db->exec(jhd_users_ddl());
            $result['created'] = true;
        }

        if (!$result['created']) {
            // قید CHECK قدیمی باید پیش از درج نقش‌های تازه برداشته شود.
            if ($driver === 'sqlite' && jhd_sqlite_users_needs_rebuild($db)) {
                jhd_sqlite_rebuild_users($db);
            }
            $columns = jhd_table_columns('users');
            jhd_drop_user_check_constraints($db);
            $failedColumns = [];
            foreach (jhd_user_column_definitions() as $name => $ddl) {
                if (isset($columns[$name])) continue;
                $definition = $ddl[$driver] ?? $ddl['mysql'];
                try {
                    $db->exec("ALTER TABLE users ADD COLUMN $name $definition");
                    $result['columns'][] = $name;
                } catch (Throwable $e) {
                    $failedColumns[] = $name;
                    error_log("Column users.$name could not be added: " . get_class($e));
                }
            }
            if ($failedColumns) {
                $result['error'] = 'ستون‌های ضروری جدول users اضافه نشدند: ' . implode(', ', $failedColumns);
                return $result;
            }
            // username/email در نصب‌های قدیمی NOT NULL بودند و عضو عمومی بدون
            // نام کاربری نمی‌توانست ثبت شود.
            if ($driver === 'mysql') {
                foreach (['username' => 'VARCHAR(80) NULL', 'email' => 'VARCHAR(180) NULL', 'password' => 'VARCHAR(255) NOT NULL'] as $name => $definition) {
                    try { $db->exec("ALTER TABLE users MODIFY COLUMN $name $definition"); } catch (Throwable $e) { /* بی‌خطر */ }
                }
            }
        }

        // نگاشت نقش‌های قدیمی به نقش‌های قطعی (بدون حذف هیچ حسابی).
        try {
            $db->exec("UPDATE users SET role = 'super_admin' WHERE role IN ('superadmin','super-admin','owner','root')");
            $db->exec("UPDATE users SET role = 'admin' WHERE role IN ('editor','moderator','manager','administrator')");
            $db->exec("UPDATE users SET role = 'user' WHERE role IS NULL OR role = ''");
        } catch (Throwable $e) {
            error_log('Role normalization failed: ' . get_class($e));
        }

        $result['migrated_members'] = jhd_migrate_legacy_members($db);

        foreach (jhd_user_unique_columns() as $column => $index) {
            jhd_create_unique_index($db, $column, $index);
            if (jhd_index_exists('users', $index)) $result['indexes'][] = $index;
        }

        // تاریخچهٔ به‌روزرسانی برای ردیف‌های قدیمی که ستون تازه گرفته‌اند.
        try { $db->exec('UPDATE users SET updated_at = created_at WHERE updated_at IS NULL'); } catch (Throwable $e) { }

        $result['ok'] = true;
        if (jhd_table_exists('settings')) {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
                $stmt->execute(['schema_version']);
                if ((int)$stmt->fetchColumn() > 0) {
                    $db->prepare('UPDATE settings SET value = ? WHERE setting_key = ?')->execute([(string)JHD_IDENTITY_SCHEMA_VERSION, 'schema_version']);
                } else {
                    $db->prepare('INSERT INTO settings (setting_key, value) VALUES (?, ?)')->execute(['schema_version', (string)JHD_IDENTITY_SCHEMA_VERSION]);
                }
            } catch (Throwable $e) {
                // marker اختیاری است؛ نبودش فقط باعث تکرار بررسی در درخواست بعدی می‌شود.
            }
        }
    } catch (Throwable $e) {
        $result['error'] = APP_ENV === 'production'
            ? 'ساختار جدول کاربران به‌روزرسانی نشد.'
            : $e->getMessage();
        error_log('Identity schema ensure failed: ' . get_class($e));
    }
    $done = $result;
    return $result;
}

/** آیا ساختار هویت به‌روز است؟ یک SELECT سبک روی کلید یکتای settings. */
function jhd_identity_schema_current(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT value FROM settings WHERE setting_key = ?');
        $stmt->execute(['schema_version']);
        if ((int)$stmt->fetchColumn() < JHD_IDENTITY_SCHEMA_VERSION) {
            $cached = false;
            return false;
        }
        // نشانگر نسخه به‌تنهایی کافی نیست: ممکن است پیش از این نوشته شده باشد
        // ولی مهاجرت نیمه‌کاره مانده باشد. وجود واقعی ستون‌ها بررسی می‌شود.
        if (!jhd_table_exists('users')) { $cached = false; return false; }
        $columns = jhd_table_columns('users');
        foreach (array_keys(jhd_user_column_definitions()) as $name) {
            if (!isset($columns[$name])) { $cached = false; return false; }
        }
        $cached = true;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/** اجرای مهاجرت فقط در صورت نیاز (مسیر داغ: لاگین، پنل، نصاب). */
function jhd_ensure_identity_schema_once(): void {
    if (jhd_identity_schema_current()) return;
    jhd_ensure_identity_schema();
}

// ─── خواندن و نوشتن کاربر ────────────────────────────────────────────────────

const JHD_USER_PUBLIC_FIELDS = 'id, username, email, phone, phone_normalized, country, country_code, full_name, role, is_active, must_change_password, avatar, last_login, created_at';

/** ردیف کاربر بدون ستون رمز (برای نمایش/سشن). */
function jhd_user_public(array $row): array {
    $role = jhd_normalize_role($row['role'] ?? null);
    return [
        'id' => (int)($row['id'] ?? 0),
        'username' => (string)($row['username'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'phone' => (string)($row['phone'] ?? ''),
        'phone_normalized' => (string)($row['phone_normalized'] ?? ''),
        'country' => (string)($row['country'] ?? ''),
        'country_code' => (string)($row['country_code'] ?? ''),
        'full_name' => (string)($row['full_name'] ?? ''),
        'role' => $role,
        'is_active' => (int)($row['is_active'] ?? 0),
        'must_change_password' => (int)($row['must_change_password'] ?? 0),
        'avatar' => (string)($row['avatar'] ?? ''),
        'last_login' => (string)($row['last_login'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'auth_version' => (int)($row['auth_version'] ?? 1),
    ];
}

function jhd_user_by_id(int $id): ?array {
    if ($id < 1) return null;
    jhd_ensure_identity_schema_once();
    $stmt = getDB()->prepare('SELECT ' . JHD_USER_PUBLIC_FIELDS . ', auth_version, password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? jhd_user_public($row) + ['password' => (string)$row['password']] : null;
}

function jhd_user_exists_by(string $column, string $value, int $excludeId = 0): bool {
    if (!array_key_exists($column, jhd_user_unique_columns()) && $column !== 'phone') return true;
    if ($value === '') return false;
    $db = getDB();
    $sql = "SELECT COUNT(*) FROM users WHERE $column = ?";
    $params = [$value];
    if ($column === 'email') { $sql = "SELECT COUNT(*) FROM users WHERE LOWER(COALESCE(email,'')) = ?"; $params = [mb_strtolower($value)]; }
    if ($excludeId > 0) { $sql .= ' AND id <> ?'; $params[] = $excludeId; }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * یافتن کاربر با ایمیل، نام کاربری، شمارهٔ تلفن یا شناسهٔ عددی.
 * مقایسهٔ ایمیل/نام کاربری بدون حساسیت به بزرگی و کوچکی حروف است.
 */
function jhd_user_by_identifier(string $identifier): ?array {
    $identifier = trim($identifier);
    if ($identifier === '' || strlen($identifier) > 190) return null;
    $db = getDB();
    $fields = JHD_USER_PUBLIC_FIELDS . ', auth_version, password';

    // ۱) ایمیل
    if (str_contains($identifier, '@')) {
        $stmt = $db->prepare("SELECT $fields FROM users WHERE LOWER(COALESCE(email,'')) = ? LIMIT 1");
        $stmt->execute([mb_strtolower($identifier)]);
        $row = $stmt->fetch();
        if ($row) return jhd_user_public($row) + ['password' => (string)$row['password']];
    }

    // ۲) نام کاربری
    if (preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $identifier)) {
        $stmt = $db->prepare("SELECT $fields FROM users WHERE LOWER(COALESCE(username,'')) = ? LIMIT 1");
        $stmt->execute([mb_strtolower($identifier)]);
        $row = $stmt->fetch();
        if ($row) return jhd_user_public($row) + ['password' => (string)$row['password']];
    }

    // ۳) شمارهٔ تلفن: حالت‌های نوشتاری مختلف (+93…، 0…، …)
    $digits = preg_replace('/\D+/', '', $identifier) ?? '';
    if (strlen($digits) >= 6) {
        $trimmed = ltrim($digits, '0');
        $variants = array_values(array_unique(array_filter([
            $identifier,
            $digits,
            $trimmed,
            '+' . $digits,
            '+' . $trimmed,
            '0' . $trimmed,
        ], static fn($v) => $v !== '')));
        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $db->prepare("SELECT $fields FROM users WHERE COALESCE(phone_normalized,'') IN ($placeholders) OR COALESCE(phone,'') IN ($placeholders) LIMIT 1");
        $stmt->execute(array_merge($variants, $variants));
        $row = $stmt->fetch();
        if ($row) return jhd_user_public($row) + ['password' => (string)$row['password']];

        // پسوند (شمارهٔ بدون کد کشور). نویسه‌های عام LIKE بی‌اثر می‌شوند.
        // MySQL درون رشتهٔ نقل‌قولی، خودِ بک‌اسلش را نویسهٔ فرار می‌داند؛ پس
        // برای ESCAPE باید '\\\\' نوشته شود، ولی SQLite/PostgreSQL یک بک‌اسلش
        // می‌خواهد. مقدار بر پایهٔ درایور ساخته می‌شود تا هیچ‌کدام خطای
        // «ESCAPE expression must be a single character» ندهند.
        $suffix = addcslashes($trimmed, '%_\\');
        $likeEscape = databaseDriver() === 'mysql' ? '\\\\' : '\\';
        $stmt = $db->prepare("SELECT $fields FROM users WHERE COALESCE(phone_normalized,'') LIKE ? ESCAPE '" . $likeEscape . "' LIMIT 1");
        $stmt->execute(['%' . $suffix]);
        $row = $stmt->fetch();
        if ($row) return jhd_user_public($row) + ['password' => (string)$row['password']];
    }
    return null;
}

/** هش امن رمز؛ هیچ‌گاه رمز ساده ذخیره نمی‌شود. */
function jhd_hash_password(string $password): string {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        // PASSWORD_DEFAULT روی همهٔ میزبان‌ها در دسترس است، اما اگر نبود
        // شکست باید آشکار باشد، نه ذخیرهٔ رمز ساده.
        throw new RuntimeException('Password hashing is unavailable on this server.');
    }
    return $hash;
}

/** آیا مقدار ستون رمز، یک هش واقعی ساخته‌شده با password_hash() است؟ */
function jhd_password_is_hash(string $stored): bool {
    if ($stored === '' || strlen($stored) < 50) return false;
    $info = password_get_info($stored);
    return !empty($info['algo']);
}

/**
 * ساخت حساب تازه. نقش همیشه از فهرست مجاز انتخاب می‌شود و پیش‌فرض user است؛
 * هیچ ورودی کاربری نمی‌تواند نقش را تعیین کند (registerMember نقش نمی‌پذیرد).
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function jhd_create_user(array $data): array {
    $db = getDB();
    $role = jhd_normalize_role((string)($data['role'] ?? JHD_ROLE_USER));
    if (!in_array($role, jhd_role_values(), true)) $role = JHD_ROLE_USER;
    $password = (string)($data['password'] ?? '');
    if (strlen($password) < 8 || strlen($password) > 4096) return ['ok' => false, 'error' => 'رمز عبور باید حداقل ۸ نویسه باشد.'];
    $email = mb_strtolower(trim((string)($data['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'ایمیل واردشده معتبر نیست.'];
    $username = trim((string)($data['username'] ?? ''));
    if ($username !== '' && !preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username)) return ['ok' => false, 'error' => 'نام کاربری باید ۳ تا ۸۰ نویسهٔ لاتین باشد.'];
    $phoneNormalized = trim((string)($data['phone_normalized'] ?? ''));
    if ($email !== '' && jhd_user_exists_by('email', $email)) return ['ok' => false, 'error' => 'این ایمیل قبلاً ثبت شده است.'];
    if ($username !== '' && jhd_user_exists_by('username', $username)) return ['ok' => false, 'error' => 'این نام کاربری قبلاً ثبت شده است.'];
    if ($phoneNormalized !== '' && jhd_user_exists_by('phone_normalized', $phoneNormalized)) return ['ok' => false, 'error' => 'این شمارهٔ تلفن قبلاً ثبت شده است.'];

    $values = [
        $username !== '' ? $username : null,
        $email !== '' ? $email : null,
        trim((string)($data['phone'] ?? '')) !== '' ? trim((string)$data['phone']) : null,
        $phoneNormalized !== '' ? $phoneNormalized : null,
        (string)($data['country'] ?? ''),
        (string)($data['country_code'] ?? ''),
        jhd_hash_password($password),
        trim((string)($data['full_name'] ?? '')),
        $role,
        (int)($data['is_active'] ?? 1) === 1 ? 1 : 0,
        !empty($data['must_change_password']) ? 1 : 0,
        !empty($data['agreed_terms']) ? 1 : 0,
    ];
    try {
        $stmt = $db->prepare('INSERT INTO users (username, email, phone, phone_normalized, country, country_code, password, full_name, role, is_active, must_change_password, agreed_terms) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute($values);
        $id = (int)($db->lastInsertId() ?: 0);
        if ($id < 1) {
            $lookup = $db->prepare('SELECT id FROM users WHERE LOWER(COALESCE(email,\'\')) = ? OR username = ? ORDER BY id DESC LIMIT 1');
            $lookup->execute([$email, $username]);
            $id = (int)($lookup->fetchColumn() ?: 0);
        }
        if ($id < 1) return ['ok' => false, 'error' => 'حساب ساخته نشد. دوباره تلاش کنید.'];
        return ['ok' => true, 'id' => $id];
    } catch (PDOException $e) {
        error_log('User insert failed: ' . $e->getCode());
        return ['ok' => false, 'error' => 'امکان ایجاد حساب با این اطلاعات وجود ندارد.'];
    }
}

/**
 * بررسی رمز و برگرداندن نتیجهٔ احراز هویت. تمام بررسی‌ها در یک جا انجام
 * می‌شود تا هیچ مسیری (پنل، سایت، CLI) منطق متفاوتی نداشته باشد.
 *
 * @return array{ok:bool,user?:array,error?:string,code?:string}
 */
function jhd_verify_credentials(string $identifier, string $password): array {
    if (trim($identifier) === '' || $password === '') {
        return ['ok' => false, 'code' => 'missing', 'error' => 'شناسه و رمز عبور را وارد کنید.'];
    }
    if (strlen($identifier) > 190 || strlen($password) > 4096) {
        return ['ok' => false, 'code' => 'invalid', 'error' => 'شناسه یا رمز عبور نادرست است.'];
    }
    $user = jhd_user_by_identifier($identifier);
    if ($user === null) {
        // زمان پاسخ ثابت: هش ساختگی تا تفاوت زمان پاسخ کاربر موجود/ناموجود کم شود.
        password_verify($password, '$2y$10$usesomesillystringfore7wTCnyKPeogVA6awaz8iYupZHBMjqZ');
        return ['ok' => false, 'code' => 'invalid', 'error' => 'شناسه یا رمز عبور نادرست است.'];
    }
    if ((int)$user['is_active'] !== 1) {
        error_log('Login denied for inactive account id=' . $user['id']);
        return ['ok' => false, 'code' => 'inactive', 'error' => 'این حساب غیرفعال است. با مدیریت سایت تماس بگیرید.'];
    }
    $stored = (string)($user['password'] ?? '');
    if (!jhd_password_is_hash($stored)) {
        // هرگز رمز ساده با هش مقایسه نمی‌شود و مقدار ستون در پاسخ نمی‌آید.
        error_log('Login denied: stored password is not a password_hash() digest for id=' . $user['id']);
        return ['ok' => false, 'code' => 'invalid', 'error' => 'شناسه یا رمز عبور نادرست است.'];
    }
    if (!password_verify($password, $stored)) {
        return ['ok' => false, 'code' => 'invalid', 'error' => 'شناسه یا رمز عبور نادرست است.'];
    }
    if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
        try {
            getDB()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([jhd_hash_password($password), $user['id']]);
        } catch (Throwable $e) {
            error_log('Password rehash skipped: ' . get_class($e));
        }
    }
    unset($user['password']);
    return ['ok' => true, 'user' => $user];
}

/**
 * تغییر رمز عبور با بررسی رمز فعلی.
 *
 * @return array{ok:bool,error?:string}
 */
function jhd_change_password(int $userId, string $current, string $next, string $confirm = '', bool $requireCurrent = true): array {
    if ($userId < 1) return ['ok' => false, 'error' => 'حساب کاربری معتبر نیست.'];
    $minLength = (int)(defined('JHD_MIN_PASSWORD_LENGTH') ? JHD_MIN_PASSWORD_LENGTH : 8);
    if (strlen($next) < $minLength) return ['ok' => false, 'error' => 'رمز عبور جدید باید حداقل ' . $minLength . ' نویسه باشد.'];
    if (strlen($next) > 4096) return ['ok' => false, 'error' => 'رمز عبور بیش از حد بلند است.'];
    if ($confirm !== '' && !hash_equals($next, $confirm)) return ['ok' => false, 'error' => 'تکرار رمز عبور مطابقت ندارد.'];
    $db = getDB();
    $stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $stored = (string)($stmt->fetchColumn() ?: '');
    if ($stored === '') return ['ok' => false, 'error' => 'حساب کاربری پیدا نشد.'];
    if ($requireCurrent) {
        if (!jhd_password_is_hash($stored) || !password_verify($current, $stored)) {
            return ['ok' => false, 'error' => 'رمز عبور فعلی نادرست است.'];
        }
    }
    if (jhd_password_is_hash($stored) && password_verify($next, $stored)) {
        return ['ok' => false, 'error' => 'رمز جدید باید با رمز فعلی متفاوت باشد.'];
    }
    try {
        // auth_version بالا می‌رود تا همهٔ نشست‌های دیگر باطل شوند.
        $db->prepare('UPDATE users SET password = ?, must_change_password = 0, auth_version = COALESCE(auth_version, 1) + 1, updated_at = ' . (databaseDriver() === 'sqlite' ? "datetime('now')" : 'NOW()') . ' WHERE id = ?')
           ->execute([jhd_hash_password($next), $userId]);
    } catch (Throwable $e) {
        error_log('Password change failed: ' . get_class($e));
        return ['ok' => false, 'error' => 'ذخیرهٔ رمز جدید انجام نشد.'];
    }
    return ['ok' => true];
}

/**
 * به‌روزرسانی نام/ایمیل/تلفن/تصویر کاربر. ایمیل تکراری پذیرفته نمی‌شود.
 *
 * @return array{ok:bool,error?:string,user?:array}
 */
function jhd_update_profile(int $userId, array $data): array {
    $user = jhd_user_by_id($userId);
    if ($user === null) return ['ok' => false, 'error' => 'حساب کاربری پیدا نشد.'];
    $name = trim((string)($data['full_name'] ?? $user['full_name']));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'نام باید بین ۲ تا ۱۲۰ نویسه باشد.'];
    $email = mb_strtolower(trim((string)($data['email'] ?? $user['email'])));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'ایمیل واردشده معتبر نیست.'];
    if ($email !== '' && jhd_user_exists_by('email', $email, $userId)) return ['ok' => false, 'error' => 'این ایمیل برای حساب دیگری ثبت شده است.'];
    $phone = trim((string)($data['phone'] ?? $user['phone']));
    $phoneNormalized = trim((string)($data['phone_normalized'] ?? $user['phone_normalized']));
    if ($phoneNormalized !== '' && $phoneNormalized !== $user['phone_normalized'] && jhd_user_exists_by('phone_normalized', $phoneNormalized, $userId)) {
        return ['ok' => false, 'error' => 'این شمارهٔ تلفن برای حساب دیگری ثبت شده است.'];
    }
    $avatar = $user['avatar'];
    if (array_key_exists('avatar', $data)) $avatar = trim((string)$data['avatar']);

    try {
        getDB()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, phone_normalized = ?, avatar = ?, updated_at = ' . (databaseDriver() === 'sqlite' ? "datetime('now')" : 'NOW()') . ' WHERE id = ?')
            ->execute([$name, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $phoneNormalized !== '' ? $phoneNormalized : null, $avatar !== '' ? $avatar : null, $userId]);
    } catch (Throwable $e) {
        error_log('Profile update failed: ' . get_class($e));
        return ['ok' => false, 'error' => 'ذخیرهٔ اطلاعات انجام نشد.'];
    }
    return ['ok' => true, 'user' => jhd_user_by_id($userId)];
}

/** فهرست کاربران با فیلتر نقش (برای پنل مدیریت). */
function jhd_list_users(?string $role = null, int $limit = 200): array {
    jhd_ensure_identity_schema_once();
    $limit = max(1, min(500, $limit));
    $db = getDB();
    $sql = 'SELECT ' . JHD_USER_PUBLIC_FIELDS . ' FROM users';
    $params = [];
    if ($role !== null) { $sql .= ' WHERE role = ?'; $params[] = jhd_normalize_role($role); }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map('jhd_user_public', $stmt->fetchAll());
}

/** شمارش کاربران بر اساس نقش (برای داشبورد). */
function jhd_count_users(): array {
    jhd_ensure_identity_schema_once();
    $counts = [JHD_ROLE_USER => 0, JHD_ROLE_ADMIN => 0, JHD_ROLE_SUPER_ADMIN => 0];
    try {
        $stmt = getDB()->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role');
        foreach ($stmt->fetchAll() as $row) $counts[jhd_normalize_role((string)$row['role'])] = (int)$row['total'];
    } catch (Throwable $e) {
        // جدول خالی/نبود → صفر
    }
    return $counts;
}
