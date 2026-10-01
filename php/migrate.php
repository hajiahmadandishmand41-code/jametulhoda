<?php
/**
 * php/migrate.php — token-protected schema bootstrap for hosts without a shell.
 *
 * Route: /php/migrate?token=…  (also accepts the X-Migration-Token header)
 *
 * Why it exists: on Vercel there is no way to run `php bin/migrate.php`, so the
 * PostgreSQL/Neon schema needs a deliberate, reproducible and repeatable entry
 * point instead of tables appearing as a side effect of some random request.
 *
 * Safety:
 *   • Disabled unless MIGRATION_TOKEN is configured (404 otherwise).
 *   • Constant-time token comparison; no output reveals credentials.
 *   • Idempotent: it only runs the canonical schema file for the active driver.
 *   • It never deletes content and never bypasses authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/schema-migrator.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$expected = env_value('MIGRATION_TOKEN');
if ($expected === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'migration endpoint disabled (MIGRATION_TOKEN is not set)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$supplied = (string)($_GET['token'] ?? $_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? '');
if ($supplied === '' || !hash_equals($expected, $supplied)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid migration token'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = jhd_run_schema_migration();
    // Creating the first administrator is opt-in through DEFAULT_ADMIN_PASSWORD
    // and only happens while the installation has no administrator at all.
    $result['admin'] = jhd_bootstrap_admin();
    $result['ok'] = true;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Migration endpoint failed: ' . get_class($e) . ' — ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'driver' => (function (): string { try { return databaseDriver(); } catch (Throwable $e) { return 'unknown'; } })(),
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
