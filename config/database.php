<?php
require_once __DIR__ . '/config.php';

/**
 * Database connection:
 * - PostgreSQL / Supabase / Neon remains supported through DATABASE_URL.
 * - MySQL/MariaDB is supported for InfinityFree through DB_* settings.
 * - SQLite (DB_DRIVER=sqlite) is a local development/testing driver only and
 *   is refused in production. It lets link audits, HTTP tests and UI work run
 *   without a database server.
 * PostgreSQL-specific query fragments used by the existing application are
 * normalized transparently when the active driver is MySQL or SQLite.
 */
final class JametulhodaMySqlStatement extends PDOStatement {
    protected function __construct() {}
    public function fetchColumn(int $column = 0): mixed {
        if ($column === 0 && JametulhodaMySqlPDO::isReturningStatement($this)) {
            return JametulhodaMySqlPDO::lastInsertIdFor($this);
        }
        return parent::fetchColumn($column);
    }

    /**
     * MySQL native prepared statements (mysqlnd) bind every execute() array
     * value as MYSQL_TYPE_STRING. On MySQL 8.0.22+ that makes "LIMIT ?" fail
     * with error 1210 ("Incorrect arguments to mysqld_stmt_execute"), because
     * LIMIT/OFFSET require integer types. Track which positional placeholders
     * belong to LIMIT/OFFSET (see JametulhodaMySqlPDO::prepare) and bind
     * those as PDO::PARAM_INT, leaving every other parameter untouched.
     */
    public function execute(?array $params = null): bool {
        $intPositions = JametulhodaMySqlPDO::intParamPositions($this);
        if ($params !== null && $intPositions !== [] && array_is_list($params)) {
            $values = array_values($params);
            foreach ($values as $index => $value) {
                if (isset($intPositions[$index]) && $value !== null && is_numeric($value)) {
                    $this->bindValue($index + 1, (int)$value, PDO::PARAM_INT);
                } else {
                    $this->bindValue($index + 1, $value, PDO::PARAM_STR);
                }
            }
            return parent::execute();
        }
        return parent::execute($params);
    }
}

class JametulhodaMySqlPDO extends PDO {
    private static ?WeakMap $returningStatements = null;
    private static ?WeakMap $limitParamPositions = null;
    private static ?self $lastConnection = null;

    public function __construct($dsn, $username = null, $password = null, $options = []) {
        $options[PDO::ATTR_STATEMENT_CLASS] = [JametulhodaMySqlStatement::class, []];
        parent::__construct($dsn, $username, $password, $options);
        self::$returningStatements ??= new WeakMap();
        self::$limitParamPositions ??= new WeakMap();
        self::$lastConnection = $this;
    }
    public static function normalizeSql(string $sql): string {
        // PostgreSQL ILIKE is equivalent for the site's UTF-8 case-insensitive MySQL collation.
        $sql = preg_replace('/\bILIKE\b/i', 'LIKE', $sql) ?? $sql;

        // page_section stores comma-separated sections; replace PostgreSQL ANY(string_to_array(...)).
        $sql = preg_replace(
            "/\?\s*=\s*ANY\(string_to_array\(REPLACE\(([^,]+),\s*' ',\s*''\),\s*','\)\)/i",
            "FIND_IN_SET(?, REPLACE($1, ' ', '')) > 0",
            $sql
        ) ?? $sql;

        // ON CONFLICT DO NOTHING -> INSERT IGNORE for MySQL.
        if (preg_match('/\bON\s+CONFLICT(?:\s*\([^)]*\))?\s+DO\s+NOTHING\b/i', $sql)) {
            $sql = preg_replace('/\bON\s+CONFLICT(?:\s*\([^)]*\))?\s+DO\s+NOTHING\s*;?\s*$/i', '', $sql) ?? $sql;
            $sql = preg_replace('/^\s*INSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $sql) ?? $sql;
        }

        // PostgreSQL upserts with a conflict target, e.g.
        //   ON CONFLICT (setting_key) DO UPDATE SET value=EXCLUDED.value
        // become MySQL's ON DUPLICATE KEY UPDATE value=VALUES(value).
        // Only the simple "target column = EXCLUDED.source column" assignment
        // form is translated; anything else is left untouched so it fails
        // loudly instead of silently writing the wrong row.
        $sql = preg_replace_callback(
            '/\bON\s+CONFLICT\s*\([^)]*\)\s+DO\s+UPDATE\s+SET\s+((?:[A-Za-z_][A-Za-z0-9_.]*\s*=\s*EXCLUDED\.[A-Za-z_][A-Za-z0-9_.]*\s*,?\s*)+)(?=\s*(?:RETURNING\b|;|$))/is',
            static function (array $m): string {
                $parts = preg_split('/\s*,\s*/', rtrim(trim($m[1]), ','));
                $translated = [];
                foreach ($parts as $part) {
                    if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*EXCLUDED\.[A-Za-z_][A-Za-z0-9_.]*$/', trim($part), $pair)) return $m[0];
                    $translated[] = $pair[1] . '=VALUES(' . $pair[1] . ')';
                }
                return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $translated);
            },
            $sql
        ) ?? $sql;

        // PostgreSQL interval literals ('N hours', 'N minutes', ...) used by the
        // upload journal, sessions and rate limiting. MySQL requires the unit
        // keyword after a bare number: INTERVAL N HOUR / MINUTE / DAY / WEEK.
        $sql = preg_replace_callback(
            "/\bINTERVAL\s+'(\d+)\s+(seconds?|minutes?|hours?|days?|weeks?)'?/i",
            static function (array $m): string {
                $units = ['second' => 'SECOND', 'minute' => 'MINUTE', 'hour' => 'HOUR', 'day' => 'DAY', 'week' => 'WEEK'];
                $unit = $units[strtolower(rtrim($m[2], 's'))] ?? strtoupper(rtrim($m[2], 's'));
                return 'INTERVAL ' . $m[1] . ' ' . $unit;
            },
            $sql
        ) ?? $sql;

        // MySQL/MariaDB has no NULLS FIRST / NULLS LAST ordering option.
        // Emulate with a leading "IS NULL" sort key: IS NULL is 1 for NULL,
        // so ASC puts NULLs last and DESC puts NULLs first.
        $sql = preg_replace_callback(
            '/\b([A-Za-z_][A-Za-z0-9_.]*)\s+(ASC|DESC)\s+NULLS\s+(FIRST|LAST)\b/i',
            static function (array $m): string {
                $col = $m[1]; $dir = strtoupper($m[2]); $mode = strtoupper($m[3]);
                return $mode === 'FIRST'
                    ? "$col IS NULL DESC, $col $dir"
                    : "$col IS NULL, $col $dir";
            },
            $sql
        ) ?? $sql;

        // MySQL/MariaDB does not use PostgreSQL RETURNING for ordinary INSERTs.
        // Keep the existing callers working by removing RETURNING id and
        // serving PDO::lastInsertId() through the statement wrapper.
        $sql = preg_replace('/\s+RETURNING\s+id\s*;?\s*$/i', '', $sql) ?? $sql;

        return $sql;
    }

    /**
     * Find 0-based placeholder positions that belong to a LIMIT or OFFSET
     * clause so execute() can bind them as native integers. Quote-aware so a
     * '?' inside a string literal is never counted.
     */
    public static function limitPlaceholderPositions(string $sql): array {
        $positions = [];
        $paramIndex = 0;
        $quote = null;
        $len = strlen($sql);
        $limitOpen = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                if ($ch === $quote) $quote = null;
                elseif ($ch === '\\') $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; continue; }
            if ($ch === '?') {
                if ($limitOpen) $positions[$paramIndex] = true;
                $paramIndex++;
                continue;
            }
            if (preg_match('/\G\b(LIMIT|OFFSET)\b/i', $sql, $m, 0, $i)) {
                $limitOpen = true;
                $i += strlen($m[1]) - 1;
                continue;
            }
            if ($limitOpen && !preg_match('/[?\s,]/', $ch)) $limitOpen = false;
        }
        return $positions;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $isReturning = (bool)preg_match('/\bRETURNING\s+id\b/i', $query);
        $normalized = self::normalizeSql($query);
        $stmt = parent::prepare($normalized, $options);
        if ($stmt) {
            self::$returningStatements ??= new WeakMap();
            self::$limitParamPositions ??= new WeakMap();
            if ($isReturning) self::$returningStatements[$stmt] = true;
            $positions = self::limitPlaceholderPositions($normalized);
            if ($positions !== []) self::$limitParamPositions[$stmt] = $positions;
        }
        return $stmt;
    }

    public static function isReturningStatement(PDOStatement $statement): bool {
        return self::$returningStatements !== null && isset(self::$returningStatements[$statement]);
    }

    public static function intParamPositions(PDOStatement $statement): array {
        if (self::$limitParamPositions === null || !isset(self::$limitParamPositions[$statement])) return [];
        return self::$limitParamPositions[$statement];
    }

    public static function lastInsertIdFor(PDOStatement $statement): string {
        return self::$lastConnection?->lastInsertId() ?? '0';
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = self::normalizeSql($query);
        if ($fetchMode === null) return parent::query($query);
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false {
        return parent::exec(self::normalizeSql($statement));
    }
}

/**
 * SQLite wrapper: normalizes the PostgreSQL-flavoured SQL used across the
 * application (ILIKE, ANY(string_to_array(...)), NOW() +/- INTERVAL, row
 * locking) into SQLite equivalents. ON CONFLICT, RETURNING and NULLS
 * FIRST/LAST are native to SQLite and pass through untouched.
 */
class JametulhodaSqlitePDO extends PDO {
    public static function normalizeSql(string $sql): string {
        // Case-insensitive match. Persian script has no case, so LIKE is fine.
        $sql = preg_replace('/\bILIKE\b/i', 'LIKE', $sql) ?? $sql;

        // page_section stores comma-separated sections.
        $sql = preg_replace(
            "/\?\s*=\s*ANY\(string_to_array\(REPLACE\(([^,]+),\s*' ',\s*''\),\s*','\)\)/i",
            "(instr(',' || REPLACE($1, ' ', '') || ',', ',' || ? || ',') > 0)",
            $sql
        ) ?? $sql;

        // NOW() +/- INTERVAL 'N units' (PostgreSQL style, incl. contact.php's
        // subtraction form and the upload journal's 24h form).
        $sql = preg_replace_callback(
            "/\bNOW\(\)\s*([+-])\s*INTERVAL\s+'(\d+)\s+(seconds?|minutes?|hours?|days?|weeks?)'?/i",
            static function (array $m): string {
                $unit = strtolower(rtrim($m[3], 's')) . 's';
                return "datetime('now', '" . $m[1] . $m[2] . ' ' . $unit . "')";
            },
            $sql
        ) ?? $sql;
        // NOW()+INTERVAL N UNIT (MySQL style, kept for completeness).
        $sql = preg_replace_callback(
            "/\bNOW\(\)\s*\+\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK)S?/i",
            static function (array $m): string {
                return "datetime('now', '+" . $m[1] . ' ' . strtolower($m[2]) . "s')";
            },
            $sql
        ) ?? $sql;
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", $sql) ?? $sql;

        // SQLite has no SELECT ... FOR UPDATE / SKIP LOCKED.
        $sql = preg_replace('/\s+FOR\s+UPDATE(\s+SKIP\s+LOCKED)?\b/i', '', $sql) ?? $sql;

        // EXTRACT(YEAR FROM col) — used by the speeches year filter.
        $sql = preg_replace_callback(
            "/\bEXTRACT\s*\(\s*YEAR\s+FROM\s+([^)]+)\)/i",
            static fn(array $m): string => "CAST(strftime('%Y', " . $m[1] . ') AS INTEGER)',
            $sql
        ) ?? $sql;

        return $sql;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(self::normalizeSql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = self::normalizeSql($query);
        if ($fetchMode === null) return parent::query($query);
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false {
        return parent::exec(self::normalizeSql($statement));
    }
}

/**
 * Raised when no usable database is configured or the configured server cannot
 * be reached. It extends PDOException on purpose: every data helper in the
 * application already degrades gracefully on PDOException, so a missing
 * database turns into an empty result set instead of a fatal 503.
 */
class DatabaseUnavailableException extends PDOException {}

/**
 * Active driver for the current deployment.
 *
 *   Vercel            → always pgsql (PostgreSQL / Supabase / Neon via DATABASE_URL).
 *                       Vercel must never dial the InfinityFree MySQL host:
 *                       that database only accepts connections from inside the
 *                       shared host, so the attempt can only ever time out.
 *   InfinityFree/etc. → mysql through DB_* (or pgsql when DATABASE_URL is set).
 *   Local development → sqlite when DB_DRIVER=sqlite (refused in production).
 */
function postgresDatabaseUrl(): string {
    // Production/Vercel uses exactly one canonical secret: DATABASE_URL.
    // Neon/Vercel Postgres aliases remain supported only outside Vercel so an
    // existing development/staging setup is not broken by this migration.
    $onVercel = env_value('VERCEL') !== '';
    $keys = $onVercel
        ? ['DATABASE_URL']
        : [
            'DATABASE_URL', 'DATABASE_URL_UNPOOLED',
            'STORAGE_POSTGRES_URL_NON_POOLING', 'STORAGE_DATABASE_URL_UNPOOLED',
            'POSTGRES_URL_NON_POOLING', 'STORAGE_POSTGRES_URL',
            'STORAGE_POSTGRES_PRISMA_URL', 'STORAGE_DATABASE_URL',
            'POSTGRES_URL', 'POSTGRES_PRISMA_URL'
        ];
    foreach ($keys as $key) {
        $value = trim(env_value($key));
        if ($value !== '') return $value;
    }

    if ($onVercel) return '';

    // Non-Vercel development/legacy fallback from component variables.
    $host = trim(env_value('STORAGE_PGHOST_UNPOOLED', env_value('STORAGE_PGHOST', env_value('POSTGRES_HOST', env_value('PGHOST')))));
    $user = trim(env_value('STORAGE_PGUSER', env_value('POSTGRES_USER', env_value('PGUSER'))));
    $password = env_value('STORAGE_PGPASSWORD', env_value('POSTGRES_PASSWORD', env_value('PGPASSWORD')));
    $database = trim(env_value('STORAGE_PGDATABASE', env_value('STORAGE_POSTGRES_DATABASE', env_value('POSTGRES_DATABASE', env_value('PGDATABASE')))));
    if ($host !== '' && $user !== '' && $password !== '' && $database !== '') {
        $port = trim(env_value('STORAGE_PGPORT', env_value('POSTGRES_PORT', env_value('PGPORT', '5432'))));
        return 'postgresql://' . rawurlencode($user) . ':' . rawurlencode($password) . '@' . $host . ':' . $port . '/' . rawurlencode($database) . '?sslmode=require';
    }
    return '';
}

function databaseDriver(): string {
    $configured = strtolower(trim(env_value('DB_DRIVER')));
    $onVercel = env_value('VERCEL') !== '';
    if ($configured === 'sqlite') {
        if (APP_ENV === 'production' || $onVercel) {
            throw new DatabaseUnavailableException('SQLite is for local development/testing only.');
        }
        return 'sqlite';
    }
    if ($onVercel) return 'pgsql';
    if ($configured === 'mysql' || $configured === 'pgsql') return $configured;
    return postgresDatabaseUrl() !== '' ? 'pgsql' : 'mysql';
}

/**
 * Is a database configured for this environment at all? This is a pure
 * configuration check (no connection attempt), so callers can distinguish
 * "not configured yet" from "configured but unreachable".
 */
function databaseConfigured(): bool {
    try {
        $driver = databaseDriver();
    } catch (Throwable $e) {
        return false;
    }
    if ($driver === 'pgsql') return postgresDatabaseUrl() !== '';
    if ($driver === 'sqlite') return true;
    return env_value('DB_HOST') !== '' && env_value('DB_NAME') !== '' && env_value('DB_USER') !== '';
}

function newDatabaseConnection(): PDO {
    if (databaseDriver() === 'sqlite') {
        $path = trim(env_value('SQLITE_PATH', ''));
        if ($path === '') {
            $path = rtrim(sys_get_temp_dir(), '/') . '/jametulhoda-test.sqlite';
        }
        // A file database is required: :memory: would isolate each connection
        // (application vs. journal/session connections) into separate stores.
        if ($path === ':memory:' || !str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new DatabaseUnavailableException('Invalid SQLITE_PATH.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new DatabaseUnavailableException('Cannot create SQLite directory.');
        }
        $pdo = new JametulhodaSqlitePDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        // WAL + مهلت ��وتاه قفل: چند اتصال هم‌زمان (نشست، ژورنال آپلود) روی یک
        // فایل SQLite بدون خطای «database is locked» کار می‌کنند.
        try { $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA busy_timeout=5000'); } catch (Throwable $e) { }
        return $pdo;
    }
    if (databaseDriver() === 'mysql') {
        $host = env_value('DB_HOST');
        $port = (int)env_value('DB_PORT', '3306');
        $name = env_value('DB_NAME');
        $user = env_value('DB_USER');
        $pass = env_value('DB_PASS');

        if ($host === '' || $name === '' || $user === '' || $pass === '') {
            throw new DatabaseUnavailableException('MySQL connection settings (DB_HOST/DB_NAME/DB_USER/DB_PASS) are not configured.');
        }
        if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host) || $port < 1 || $port > 65535) {
            throw new DatabaseUnavailableException('Invalid MySQL connection settings.');
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . rawurlencode($name) . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Native prepares by default: required for the RETURNING-emulation
            // and typed LIMIT/OFFSET binding in JametulhodaMySqlStatement.
            // DB_EMULATE_PREPARES=1 is an escape hatch for MySQL-compatible
            // servers with broken server-side prepares; integer LIMIT/OFFSET
            // values stay unquoted because they are bound/typed as ints.
            PDO::ATTR_EMULATE_PREPARES => env_value('DB_EMULATE_PREPARES') === '1',
            PDO::ATTR_PERSISTENT => false,
        ];
        // Do not hang the worker when the MySQL host is unreachable (e.g. a
        // shared host that only allows connections from its own web servers).
        if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 8;
        return new JametulhodaMySqlPDO($dsn, $user, $pass, $options);
    }

    $databaseUrl = postgresDatabaseUrl();
    if ($databaseUrl === '') {
        // Explicit, non-guessing diagnosis (never silently fall back to the
        // MySQL host of another deployment).
        throw new DatabaseUnavailableException(
            env_value('VERCEL') !== ''
                ? 'DATABASE_URL is not set on Vercel: configure the Supabase PostgreSQL connection string in the Production environment.'
                : 'DATABASE_URL is not set.'
        );
    }
    $url = parse_url($databaseUrl);
    if (!$url || !in_array($url['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
        throw new DatabaseUnavailableException('DATABASE_URL must be a PostgreSQL URL.');
    }
    parse_str($url['query'] ?? '', $options);
    // Supabase and Neon commonly provide sslmode=require. Keep that mode:
    // it encrypts the connection without assuming a provider-specific CA
    // bundle is present on the Vercel PHP runtime.
    $ssl = $options['sslmode'] ?? 'require';
    if (!in_array($ssl, ['require', 'verify-ca', 'verify-full'], true)) {
        throw new DatabaseUnavailableException('Unsupported sslmode in DATABASE_URL. Use sslmode=require for Supabase/Neon.');
    }

    $host = strtolower((string)($url['host'] ?? ''));
    $port = (int)($url['port'] ?? 5432);
    $name = rawurldecode(ltrim($url['path'] ?? '', '/'));
    $user = rawurldecode($url['user'] ?? '');
    $pass = rawurldecode($url['pass'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9.:-]+$/', $host) || !preg_match('/^[\w-]+$/', $name)) {
        throw new DatabaseUnavailableException('Invalid database host or name.');
    }
    if ($user === '') {
        throw new DatabaseUnavailableException('DATABASE_URL must include a PostgreSQL username.');
    }

    // Vercel Production is IPv4-only for this deployment. Supabase direct
    // db.<project-ref>.supabase.co is IPv6 by default, so Production must use
    // the shared Session Pooler on port 5432. The pooler cluster index cannot
    // be derived safely from the project region; the host must come from the
    // Supabase Connect dialog and be stored in DATABASE_URL.
    if (env_value('VERCEL') !== '' && APP_ENV === 'production') {
        if ($port !== 5432 || !preg_match('/^aws-\d+-[a-z0-9-]+\.pooler\.supabase\.com$/', $host)) {
            throw new DatabaseUnavailableException(
                'Vercel Production requires the Supabase Session Pooler: aws-[INDEX]-[REGION].pooler.supabase.com:5432.'
            );
        }

        $supabaseUrlHost = strtolower((string)parse_url(env_value('SUPABASE_URL'), PHP_URL_HOST));
        $projectRef = '';
        if (preg_match('/^([a-z0-9]+)\.supabase\.co$/', $supabaseUrlHost, $m)) {
            $projectRef = $m[1];
        }
        if ($projectRef !== '' && $user !== 'postgres.' . $projectRef) {
            throw new DatabaseUnavailableException(
                'Vercel Production DATABASE_URL must use the Supabase Session Pooler username postgres.' . $projectRef . '.'
            );
        }
        if (strtolower($name) !== 'postgres') {
            throw new DatabaseUnavailableException('Vercel Production DATABASE_URL must use database postgres.');
        }
    }

    // Keep the database password out of DATABASE_URL when Vercel supplies it
    // as the integration-owned Secret POSTGRES_PASSWORD. A full password in
    // DATABASE_URL remains supported for local/legacy deployments.
    if ($pass === '') {
        $pass = env_value('DATABASE_PASSWORD', env_value('POSTGRES_PASSWORD'));
    }

    // The Supabase Vercel integration also exposes the canonical PostgreSQL
    // connection as a sensitive POSTGRES_URL. Never use its host as the
    // production endpoint; DATABASE_URL remains authoritative for host/port/db.
    // We only borrow the password when the integration URL identifies the same
    // PostgreSQL user and database as DATABASE_URL.
    if ($pass === '') {
        $integrationUrls = [
            env_value('POSTGRES_URL'),
            env_value('POSTGRES_PRISMA_URL'),
            env_value('POSTGRES_URL_NON_POOLING'),
        ];
        foreach ($integrationUrls as $integrationUrl) {
            if (trim($integrationUrl) === '') continue;
            $integration = parse_url($integrationUrl);
            if (!$integration) continue;
            $integrationUser = rawurldecode((string)($integration['user'] ?? ''));
            $integrationDb = rawurldecode(ltrim((string)($integration['path'] ?? ''), '/'));
            $integrationPass = rawurldecode((string)($integration['pass'] ?? ''));
            if ($integrationUser === $user && $integrationDb === $name && $integrationPass !== '') {
                $pass = $integrationPass;
                break;
            }
        }
    }

    if ($pass === '') {
        throw new DatabaseUnavailableException(
            'DATABASE_URL has no password and no DATABASE_PASSWORD/POSTGRES_PASSWORD or matching Vercel POSTGRES_URL secret is configured.'
        );
    }

    $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';sslmode=' . $ssl . ';connect_timeout=10';
    // Neon requires SNI/endpoint routing; the endpoint option keeps pooled and
    // direct hostnames working with older libpq builds.
    if (isset($options['options']) && is_string($options['options']) && $options['options'] !== '') {
        $dsn .= ';options=' . $options['options'];
    }
    if ($ssl === 'verify-full' || $ssl === 'verify-ca') {
        $ca = '/etc/ssl/certs/ca-certificates.crt';
        if (is_file($ca)) $dsn .= ';sslrootcert=' . $ca;
    }
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $pdo->exec("SET TIME ZONE 'Asia/Kabul'");
    return $pdo;
}

/**
 * Shared connection for the current request.
 *
 * A failed connection is remembered for the rest of the request: without that,
 * every helper on a page would re-dial an unreachable host and burn the whole
 * function execution budget on connection timeouts.
 */
/**
 * Stable fingerprint of the effective database settings for this request.
 *
 * The installer is loaded in the same PHP request that serves the initial
 * installer page. Its CSRF/session bootstrap can call getDB() before the
 * submitted MySQL settings are available. Later, install.php stores the
 * submitted values in APP_LOCAL_CONFIG and must be able to retry with those
 * new effective settings. Include every value that can change the selected
 * database endpoint/credentials, but never log the fingerprint or its inputs.
 */
function databaseConfigFingerprint(): string {
    return hash('sha256', implode("\0", [
        env_value('VERCEL'),
        env_value('DB_DRIVER'),
        env_value('DB_HOST'),
        env_value('DB_PORT', '3306'),
        env_value('DB_NAME'),
        env_value('DB_USER'),
        env_value('DB_PASS'),
        env_value('DATABASE_URL'),
        env_value('SQLITE_PATH'),
    ]));
}

/**
 * Shared connection for the current request.
 *
 * A failed connection is remembered for the current effective configuration.
 * If APP_LOCAL_CONFIG or the environment changes the database settings later
 * in the same request (as the browser installer does), the old PDO/failure
 * cache is discarded and a fresh connection is attempted.
 */
function getDB(): PDO {
    static $pdo = null;
    static $failure = null;
    static $fingerprint = null;

    $currentFingerprint = databaseConfigFingerprint();
    if ($fingerprint !== $currentFingerprint) {
        $pdo = null;
        $failure = null;
        $fingerprint = $currentFingerprint;
    }

    if ($pdo instanceof PDO) return $pdo;
    if ($failure instanceof Throwable) {
        throw new DatabaseUnavailableException($failure->getMessage(), 0, $failure);
    }
    try {
        return $pdo = newDatabaseConnection();
    } catch (Throwable $e) {
        $failure = $e;
        error_log('Database unavailable: ' . get_class($e) . ' — ' . $e->getMessage());
        throw $e instanceof DatabaseUnavailableException
            ? $e
            : new DatabaseUnavailableException($e->getMessage(), 0, $e);
    }
}

/** Connection or null — for code paths that must keep rendering without a database. */
function tryGetDB(): ?PDO {
    try {
        return getDB();
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Human-readable state of the database for diagnostics pages and the degraded
 * notice in the site header. Nothing is hidden: the reason is reported.
 *
 * @return array{ok:bool,configured:bool,driver:string,reason:string}
 */
function databaseStatus(): array {
    static $status = null;
    if ($status !== null) return $status;
    $driver = '';
    try { $driver = databaseDriver(); } catch (Throwable $e) { }
    $configured = databaseConfigured();
    try {
        getDB()->query('SELECT 1');
        return $status = ['ok' => true, 'configured' => true, 'driver' => $driver, 'reason' => ''];
    } catch (Throwable $e) {
        return $status = ['ok' => false, 'configured' => $configured, 'driver' => $driver, 'reason' => $e->getMessage()];
    }
}
