<?php
/**
 * bin/migrate.php — apply the canonical schema for the active driver (CLI).
 * The implementation is shared with the HTTP bootstrap (php/migrate.php) so
 * both paths stay identical and idempotent.
 */
require_once __DIR__ . '/../includes/schema-migrator.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$result = jhd_run_schema_migration();
$missing = array_keys(array_filter($result['tables'], static fn(bool $ok): bool => !$ok));
echo ($result['driver'] === 'mysql' ? 'MySQL' : 'PostgreSQL')
    . " schema applied: {$result['statements']} statement(s); identity schema reconciled. Existing content is not deleted.\n";
echo $missing === [] ? "All required tables are present.\n" : ('Missing tables: ' . implode(', ', $missing) . "\n");
if (in_array('--with-admin', $argv, true)) {
    $admin = jhd_bootstrap_admin();
    echo 'Administrator bootstrap: ' . ($admin['created'] ? 'created' : 'skipped') . ' — ' . $admin['reason'] . "\n";
}