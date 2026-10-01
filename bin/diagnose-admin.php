<?php
/**
 * CLI diagnostic for administrator accounts. Never prints passwords or hashes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$username = $argv[1] ?? env_value('ADMIN_USERNAME', DEFAULT_ADMIN_USERNAME);
echo "Jametulhoda admin diagnostic\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "APP_ENV: " . APP_ENV . "\n";
echo "Pretty URLs: " . (JHD_PRETTY_URLS ? 'on' : 'off') . "\n";
echo "Session driver: " . env_value('SESSION_DRIVER', 'database') . "\n";

try {
    $db = getDB();
    echo "Database: " . databaseDriver() . " connected\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . get_class($e) . "\n");
    exit(1);
}

foreach (['users', 'app_sessions', 'login_limits', 'members'] as $table) {
    try {
        $n = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        echo "Table {$table}: ok ({$n} rows)\n";
    } catch (Throwable $e) {
        echo "Table {$table}: MISSING\n";
    }
}

if ($username !== '') {
    $stmt = $db->prepare('SELECT id, username, role, is_active, auth_version, last_login, password FROM users WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) {
        echo "Account '{$username}': not found\n";
        exit(0);
    }
    $info = password_get_info((string)$row['password']);
    echo "Account id: {$row['id']}\n";
    echo "Role: {$row['role']}\n";
    echo "Active: " . ((int)$row['is_active'] === 1 ? 'yes' : 'no') . "\n";
    echo "Auth version: {$row['auth_version']}\n";
    echo "Last login: " . ($row['last_login'] ?: 'never') . "\n";
    echo "Password is hash: " . (!empty($info['algo']) ? 'yes' : 'NO') . "\n";
    echo "Hash algorithm: " . ($info['algoName'] ?: 'invalid') . "\n";
}
