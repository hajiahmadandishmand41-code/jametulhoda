<?php
/**
 * bin/create-admin.php — ساخت یا بازیابی حساب مدیر ارشد از خط فرمان
 *
 * نام کاربری می‌تواند از DEFAULT_ADMIN_USERNAME بیاید؛ رمز باید به‌صورت
 * ADMIN_PASSWORD یا DEFAULT_ADMIN_PASSWORD از محیط محرمانه تعیین شود.
 *
 * رمز فقط با password_hash() ذخیره می‌شود. اگر حساب از قبل وجود داشته باشد،
 * رمز آن بازنشانی و با افزایش auth_version همه نشست‌های فعال باطل می‌شوند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/identity.php';

// ساختار یکپارچهٔ هویت را تضمین می‌کند (نصب‌های قدیمی: members → users، نقش‌های تازه).
$schema = jhd_ensure_identity_schema();
if (empty($schema['ok'])) {
    fwrite(STDERR, "Identity schema could not be prepared: " . ($schema['error'] ?? 'unknown') . "\n");
    exit(1);
}

$username = env_value('ADMIN_USERNAME', DEFAULT_ADMIN_USERNAME);
$customPassword = env_value('ADMIN_PASSWORD');
$password = $customPassword !== '' ? $customPassword : DEFAULT_ADMIN_PASSWORD;

if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username) || strlen($password) < 14) {
    fwrite(STDERR, "Set ADMIN_USERNAME and a unique ADMIN_PASSWORD of at least 14 characters in your environment.\n");
    exit(1);
}

$db = getDB();
$stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$existingId = (int)$stmt->fetchColumn();
$hash = password_hash($password, PASSWORD_DEFAULT);

if ($existingId) {
    $db->prepare('UPDATE users SET password = ?, is_active = 1, must_change_password = 0, auth_version = COALESCE(auth_version, 1) + 1 WHERE id = ?')
       ->execute([$hash, $existingId]);
    echo "Administrator '{$username}' already existed: password reset and existing sessions invalidated.\n";
} else {
    $db->prepare("INSERT INTO users (username, password, email, full_name, role, is_active, auth_version) VALUES (?, ?, ?, ?, 'super_admin', 1, 1)")
       ->execute([$username, $hash, env_value('ADMIN_EMAIL'), env_value('ADMIN_NAME', $username)]);
    echo "Administrator '{$username}' created; the password is stored as a password_hash() digest only.\n";
}
