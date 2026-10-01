<?php
/**
 * Regression test for shared-host upgrades.
 *
 * Builds an intentionally old MySQL/MariaDB users table:
 * - missing modern identity columns such as must_change_password
 * - old role CHECK constraint
 *
 * The real identity migrator must upgrade it without deleting rows, then a
 * super_admin account must be insertable and verifiable through application code.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/identity.php';

$db = getDB();

// Use a dedicated legacy table definition, not the canonical schema.
$db->exec('DROP TABLE IF EXISTS users');
$db->exec("CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL,
    email VARCHAR(180) NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL DEFAULT '',
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    is_active SMALLINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (role IN ('superadmin','admin','editor'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$oldHash = password_hash('LegacyPass!12345', PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO users (username,email,password,full_name,role,is_active) VALUES (?,?,?,?,?,1)");
$stmt->execute(['legacy-owner', 'legacy@example.test', $oldHash, 'Legacy Owner', 'superadmin']);

$result = jhd_ensure_identity_schema(true);
if (empty($result['ok'])) {
    fwrite(STDERR, "Identity migration failed: " . ($result['error'] ?? 'unknown') . PHP_EOL);
    exit(1);
}

$required = [
    'username','email','phone','phone_normalized','country','country_code',
    'password','full_name','role','is_active','must_change_password',
    'agreed_terms','avatar','last_login','created_at','updated_at','auth_version',
];
$columns = [];
$q = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'");
foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $column) $columns[(string)$column] = true;

$missing = array_values(array_filter($required, static fn(string $name): bool => !isset($columns[$name])));
if ($missing) {
    fwrite(STDERR, "Missing identity columns: " . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$checkCount = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND CONSTRAINT_TYPE='CHECK'")->fetchColumn();
if ($checkCount !== 0) {
    fwrite(STDERR, "Legacy role CHECK constraint was not removed." . PHP_EOL);
    exit(1);
}

$legacy = $db->query("SELECT id, role FROM users WHERE username='legacy-owner' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$legacy || (string)$legacy['role'] !== 'super_admin') {
    fwrite(STDERR, "Legacy account was not preserved/normalized." . PHP_EOL);
    exit(1);
}

$adminHash = password_hash('FreshAdmin!123456', PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users (username,email,password,full_name,role,is_active,must_change_password,auth_version)
              VALUES (?,?,?,?,?,?,?,?)")
   ->execute(['ci-owner', 'ci-owner@example.test', $adminHash, 'CI Owner', 'super_admin', 1, 0, 1]);

$verified = jhd_verify_credentials('ci-owner', 'FreshAdmin!123456');
if (empty($verified['ok']) || ($verified['user']['role'] ?? '') !== 'super_admin') {
    fwrite(STDERR, "Application-level admin credential verification failed." . PHP_EOL);
    exit(1);
}

echo "Legacy MariaDB identity migration: PASS
";
echo "Added columns: " . count($result['columns']) . "
";
echo "Legacy roles normalized: PASS
";
echo "super_admin password verification: PASS
";
