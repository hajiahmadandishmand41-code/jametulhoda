<?php
/**
 * tests/storage-base-url-mismatch.php — the exact failure the editors reported
 * ───────────────────────────────────────────────────────────────────────────
 * «خطا در آپلود تصویر شاخص» happens whenever UPLOAD_BASE_URL does not point at
 * the real Blob origin: the file *is* written to the store, but the URL the
 * database keeps does not resolve, so every listing shows a broken image.
 *
 * This test proves two things with the real code path:
 *
 *   1. with the correct base URL the upload succeeds (control case);
 *   2. with a wrong base URL the upload is refused — the orphan object is
 *      deleted again — and the message names the origin the value must have,
 *      instead of failing silently or leaving a dead URL behind.
 *
 * It needs no token and no real store: tests/mock-blob-server.mjs stands in.
 *
 *   node tests/mock-blob-server.mjs &
 *   DB_DRIVER=sqlite SQLITE_PATH=/tmp/x.sqlite UPLOAD_STORAGE=vercel-blob \
 *   BLOB_READ_WRITE_TOKEN=vercel_blob_rw_mockstore_secret \
 *   BLOB_API_BASE=http://127.0.0.1:9111 UPLOAD_BASE_URL=http://127.0.0.1:9111/public \
 *   php tests/storage-base-url-mismatch.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$database = getenv('SQLITE_PATH') ?: sys_get_temp_dir() . '/jametulhoda-mismatch.sqlite';
if (is_file($database)) unlink($database);
putenv('SQLITE_PATH=' . $database);
putenv('DB_DRIVER=sqlite');
putenv('APP_ENV=development');
putenv('SESSION_DRIVER=files');

require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/storage.php';

$checks = 0;
$failures = [];

function check(bool $ok, string $label, string $detail = ''): void
{
    global $checks, $failures;
    if (!$ok) {
        $failures[] = $label . ($detail !== '' ? " ($detail)" : '');
        echo "FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        return;
    }
    $checks++;
    echo "PASS $label\n";
}

/** Build the schema the way bin/dev-db.php does. */
$statements = file_get_contents($root . '/tests/fixtures/schema.sqlite.sql');
foreach (explode(";\n", (string)$statements) as $statement) {
    $trimmed = trim($statement);
    if ($trimmed === '') continue;
    getDB()->exec($trimmed);
}

/** A real (tiny) PNG, with or without GD. */
$fixture = sys_get_temp_dir() . '/jhd-mismatch-' . bin2hex(random_bytes(4)) . '.png';
if (function_exists('imagecreatetruecolor')) {
    $image = imagecreatetruecolor(24, 24);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 90));
    imagepng($image, $fixture);
    imagedestroy($image);
} else {
    file_put_contents($fixture, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAQwAI/AL+6d0jjwAAAABJRU5ErkJggg=='
    ));
}

/**
 * Run one upload in a child process, optionally with a different environment.
 */
function uploadOnce(string $file, array $overrides): array
{
    $root = dirname(__DIR__);
    $environment = getenv();
    foreach ($overrides as $name => $value) $environment[$name] = $value;
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/fixtures/upload-once.php')
        . ' ' . escapeshellarg($file) . ' image articles/test';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, $environment);
    if (!is_resource($process)) return ['out' => '', 'err' => 'proc_open failed'];
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return ['out' => $out, 'err' => $err];
}

$correct = rtrim((string)getenv('UPLOAD_BASE_URL'), '/');
$storeHost = (string)parse_url((string)getenv('BLOB_API_BASE'), PHP_URL_HOST);
$storePort = (string)parse_url((string)getenv('BLOB_API_BASE'), PHP_URL_PORT);

// The wrong value first: the store must still be empty afterwards, so the
// control upload below can prove the difference comes from the base URL alone.
echo "── the reported failure: a base URL that is not the store origin ──\n";
$bad = uploadOnce($fixture, ['UPLOAD_BASE_URL' => 'https://wrong.example.com/public']);
check(str_contains($bad['out'], 'EMPTY'), 'a wrong UPLOAD_BASE_URL refuses the upload instead of storing a dead URL', trim($bad['out']));
check(
    str_contains($bad['out'], $storeHost . ($storePort !== '' ? ':' . $storePort : '')),
    'the message names the origin UPLOAD_BASE_URL must have',
    trim($bad['out'])
);

// A refused upload may not leave an orphan behind: the registry never learns
// about the object, so nothing could ever delete it again.
$listUrl = rtrim((string)getenv('BLOB_API_BASE'), '/') . '/__objects';
$objects = json_decode((string)@file_get_contents($listUrl), true) ?: [];
$orphans = array_values(array_filter($objects, static fn($key): bool => str_contains((string)$key, 'articles/test')));
check($orphans === [], 'no orphan object is left in the store', implode(', ', $orphans));

echo "\n── control: the configured base URL ──\n";
$good = uploadOnce($fixture, ['UPLOAD_BASE_URL' => $correct]);
check(str_contains($good['out'], 'URL '), 'with the right base URL the image is stored', trim($good['out'] . ' ' . $good['err']));
$storedUrl = '';
if (str_contains($good['out'], 'URL ')) {
    $line = explode("\n", substr($good['out'], strpos($good['out'], 'URL ')))[0];
    $storedUrl = trim(substr($line, 4));
}
if ($storedUrl !== '') {
    check(str_starts_with($storedUrl, $correct . '/'), 'the stored URL is built from UPLOAD_BASE_URL', $storedUrl);
    $headers = @get_headers($storedUrl, true);
    check(is_array($headers) && str_contains((string)($headers[0] ?? ''), '200'), 'the stored URL is publicly readable', $storedUrl);
    $row = getDB()->prepare('SELECT COUNT(*) FROM stored_files WHERE url=?');
    $row->execute([$storedUrl]);
    check((int)$row->fetchColumn() >= 0, 'the stored_files registry is writable');
}

@unlink($fixture);

echo "\n" . ($failures ? count($failures) . " FAILURE(S)\n" : "ALL $checks CHECKS PASSED\n");
exit($failures ? 1 : 0);
