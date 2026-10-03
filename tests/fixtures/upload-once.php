<?php
/**
 * tests/fixtures/upload-once.php — helper for the storage tests
 * ───────────────────────────────────────────────────────────────────────────
 * Runs exactly one real storeValidatedFile() call in a fresh PHP process and
 * prints what the editor would see:
 *
 *   php upload-once.php <file> <kind> <folder>
 *   → "URL <url>"   when the upload was stored
 *   → "EMPTY"       when it was refused, followed by the reason(s)
 *
 * A separate process is the only way to test how a *different* environment
 * (a wrong UPLOAD_BASE_URL, for example) behaves: the constants are fixed
 * when config/config.php is loaded.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/storage.php';

[$script, $path, $kind, $folder] = $argv + [1 => '', 2 => 'image', 3 => 'articles/test'];

$url = storeValidatedFile($path, $kind, $folder);
echo $url === '' ? "EMPTY\n" : "URL $url\n";
foreach (storageFailureLog() as $reason) {
    echo "REASON $reason\n";
}
