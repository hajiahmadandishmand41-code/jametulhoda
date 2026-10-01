<?php
/**
 * schema-migrator.php — one reproducible, idempotent schema bootstrap.
 *
 * Used by:
 *   • bin/migrate.php      (CLI, any environment)
 *   • php/migrate.php      (token-protected HTTP endpoint, used on Vercel where
 *                           there is no shell access to the deployment)
 *
 * The canonical SQL files stay the single source of truth:
 *   database/database.mysql.sql     → MySQL/MariaDB (InfinityFree)
 *   database/database.postgres.sql  → PostgreSQL/Neon (Vercel)
 *
 * Running it repeatedly is safe: every statement is CREATE/ALTER … IF [NOT]
 * EXISTS or an INSERT … ON CONFLICT/IGNORE, and "already exists" errors are
 * treated as satisfied preconditions on MySQL (which lacks IF NOT EXISTS for
 * indexes). No data is deleted.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (!function_exists('splitSqlStatements')) {
    function splitSqlStatements(string $sql): array {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $out = []; $buffer = ''; $quote = null; $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i]; $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === $quote) {
                    if ($next === $quote) { $buffer .= $next; $i++; }
                    elseif ($i === 0 || $sql[$i - 1] !== '\\') $quote = null;
                }
                continue;
            }
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
            if ($ch === "'" || $ch === '"' || $ch === chr(96)) { $quote = $ch; $buffer .= $ch; continue; }
            if ($ch === ';') { $statement = trim($buffer); if ($statement !== '') $out[] = $statement; $buffer = ''; continue; }
            $buffer .= $ch;
        }
        $statement = trim($buffer); if ($statement !== '') $out[] = $statement;
        return $out;
    }
}

if (!function_exists('applyDatabaseSchema')) {
    function applyDatabaseSchema(PDO $db, string $schemaPath): int {
        if (!is_file($schemaPath)) throw new RuntimeException('Schema file not found.');
        $schema = file_get_contents($schemaPath);
        if ($schema === false) throw new RuntimeException('Unable to read schema file.');
        // PostgreSQL keeps the file as one transactional script (BEGIN/COMMIT
        // and DO $$ … $$ blocks); MySQL needs statement-by-statement execution.
        $statements = databaseDriver() === 'mysql' ? splitSqlStatements($schema) : [$schema];
        $applied = 0;
        foreach ($statements as $statement) {
            if (trim($statement) === '') continue;
            try {
                $db->exec($statement); $applied++;
            } catch (PDOException $e) {
                if (databaseDriver() !== 'mysql') throw $e;
                $message = $e->getMessage();
                if (preg_match('/already exists|duplicate key name|duplicate entry|duplicate column/i', $message)) continue;
                throw $e;
            }
        }
        return $applied;
    }
}

/** Canonical schema file for the active driver. */
function jhd_schema_file(): string {
    return dirname(__DIR__) . '/database/'
        . (databaseDriver() === 'mysql' ? 'database.mysql.sql' : 'database.postgres.sql');
}

/**
 * Apply the schema + reconcile the identity schema. Idempotent.
 *
 * @return array{driver:string,statements:int,identity:bool,tables:array<string,bool>}
 */
function jhd_run_schema_migration(): array {
    $driver = databaseDriver();
    $db = getDB();
    $applied = applyDatabaseSchema($db, jhd_schema_file());

    // CREATE TABLE IF NOT EXISTS cannot repair a table created by an older
    // release; reconcile the unified identity schema explicitly.
    require_once __DIR__ . '/identity.php';
    $identity = jhd_ensure_identity_schema(true);
    if (empty($identity['ok'])) {
        throw new RuntimeException((string)($identity['error'] ?? 'Identity schema migration failed.'));
    }

    require_once __DIR__ . '/functions.php';
    $tables = [];
    foreach (JHD_CORE_TABLES as $table) {
        $tables[$table] = jhd_core_table_exists($db, $table);
    }
    // Session + rate-limit tables used by the shared authentication stack.
    foreach (['app_sessions', 'login_limits'] as $table) {
        $tables[$table] = jhd_core_table_exists($db, $table);
    }

    return [
        'driver' => $driver,
        'statements' => $applied,
        'identity' => true,
        'tables' => $tables,
    ];
}

/**
 * Create the first administrator when the installation has none.
 * Never touches an existing account and never invents a password: the password
 * must be supplied through DEFAULT_ADMIN_PASSWORD.
 *
 * @return array{created:bool,reason:string}
 */
function jhd_bootstrap_admin(): array {
    require_once __DIR__ . '/identity.php';
    $db = getDB();
    $existing = (int)$db->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin')")->fetchColumn();
    if ($existing > 0) return ['created' => false, 'reason' => 'administrator already exists'];

    $password = DEFAULT_ADMIN_PASSWORD;
    if ($password === '') return ['created' => false, 'reason' => 'DEFAULT_ADMIN_PASSWORD is not set'];

    $result = jhd_create_user([
        'username' => DEFAULT_ADMIN_USERNAME,
        'email' => SITE_EMAIL,
        'password' => $password,
        'full_name' => 'مدیر سامانه',
        'role' => 'super_admin',
        'is_active' => 1,
        'must_change_password' => 1,
    ]);
    if (empty($result['ok'])) {
        return ['created' => false, 'reason' => (string)($result['error'] ?? 'could not create administrator')];
    }
    return ['created' => true, 'reason' => 'administrator created with DEFAULT_ADMIN_PASSWORD (must be changed at first login)'];
}
