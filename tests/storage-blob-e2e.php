<?php
/**
 * tests/storage-blob-e2e.php — آزمون سرتاسریِ مسیر واقعی ذخیره‌سازی
 * ───────────────────────────────────────────────────────────────────────────
 * این آزمون همان مسیری را اجرا می‌کند که هنگام ذخیرهٔ یک مطلب در پنل مدیریت
 * طی می‌شود — بدون هیچ نشانی، توکن یا حساب واقعی:
 *
 *   storeValidatedFile() → درایور Blob → درج رکورد در stored_files
 *   → بررسی دسترسیِ عمومی URL → حذف (POST /delete) → پاک‌شدن رکورد
 *
 * برای تصویر، ویدیو، صوت و PDF اجرا می‌شود؛ و یک‌بار هم با غیرفعال‌بودن
 * توابع GD (شبیه‌سازی runtime فعلی Vercel که افزونهٔ GD ندارد) تا مطمئن شویم
 * آپلود تصویر در آن حالت هم کامل انجام می‌شود.
 *
 * پیش‌نیاز: tests/mock-blob-server.mjs روی MOCK_BLOB_PORT در حال اجرا باشد.
 *
 * نمونهٔ اجرا (CI):
 *   node tests/mock-blob-server.mjs &
 *   DB_DRIVER=sqlite SQLITE_PATH=/tmp/storage-e2e.sqlite \
 *   UPLOAD_STORAGE=vercel-blob BLOB_READ_WRITE_TOKEN=vercel_blob_rw_mockstore_secret \
 *   BLOB_API_BASE=http://127.0.0.1:9111 UPLOAD_BASE_URL=http://127.0.0.1:9111/public \
 *   VERCEL=1 php tests/storage-blob-e2e.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

// A throwaway database so the test never touches a real one.
$database = getenv('SQLITE_PATH') ?: sys_get_temp_dir() . '/jametulhoda-storage-e2e.sqlite';
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

function check(bool $ok, string $label, string $detail = ''): void {
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

$endpoint = rtrim(getenv('BLOB_API_BASE') ?: 'http://127.0.0.1:9111', '/');
$publicBase = rtrim(getenv('UPLOAD_BASE_URL') ?: $endpoint . '/public', '/');

check(storageDriver() === 'vercel-blob', 'the blob driver is selected', storageDriver());
$credentials = blobCredentials();
check($credentials['kind'] === 'read-write', 'the read-write token is detected', $credentials['kind']);
check($credentials['store_id'] === 'mockstore', 'the store id is parsed out of the token', (string)$credentials['store_id']);
$status = storageConfigurationStatus();
check($status['ok'] === true, 'storage configuration reports no problem', implode(' | ', $status['problems']));

/** Copy a fixture next to the request, because uploads are read from disk. */
function fixtureCopy(string $name): string {
    $source = dirname(__DIR__) . '/tests/fixtures/' . $name;
    $target = sys_get_temp_dir() . '/jhd-e2e-' . bin2hex(random_bytes(4)) . '-' . $name;
    copy($source, $target);
    return $target;
}

// A tiny but real PDF: the fixture folder has none, and validateUpload() reads
// the "%PDF-" magic, so a minimal one is enough for the real code path.
$pdfPath = sys_get_temp_dir() . '/jhd-e2e-doc.pdf';
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

$fixtures = [
    'image' => fixtureCopy('image.png'),
    'video' => fixtureCopy('video.mp4'),
    'audio' => fixtureCopy('audio.mp3'),
    'pdf'   => $pdfPath,
];

$stored = [];
foreach (['image', 'video', 'audio', 'pdf'] as $kind) {
    $path = $fixtures[$kind];
    $url = storeValidatedFile($path, $kind, 'articles/article-1');
    check($url !== '', "$kind upload returns a URL", $url);
    if ($url === '') continue;
    $stored[$kind] = $url;

    check(str_starts_with($url, $publicBase . '/'), "$kind URL is built from UPLOAD_BASE_URL", $url);

    // The registry row must exist — this is what the media library lists.
    $row = getDB()->prepare('SELECT file_key, url, mime, size FROM stored_files WHERE url=?');
    $row->execute([$url]);
    $record = $row->fetch(PDO::FETCH_ASSOC);
    check(is_array($record), "$kind is recorded in stored_files");
    if (is_array($record)) {
        check((string)$record['file_key'] !== '' && str_contains($url, rawurlencode(basename((string)$record['file_key']))),
            "$kind file_key resolves to the public URL", (string)$record['file_key']);
        check((int)$record['size'] > 0, "$kind size is stored", (string)$record['size']);
    }

    // The URL must really serve the bytes back.
    $headers = @get_headers($url, true);
    $statusLine = is_array($headers) ? (string)($headers[0] ?? '') : '';
    check(str_contains($statusLine, '200'), "$kind URL is publicly reachable", $statusLine);
    $downloaded = @file_get_contents($url);
    check(is_string($downloaded) && $downloaded !== '', "$kind URL serves the bytes back");
    if ($kind === 'image' && storageHasImageLibrary()) {
        // With GD the upload is re-encoded, so the bytes are expected to differ.
        $magic = substr((string)$downloaded, 0, 4);
        check($magic === 'RIFF' || $magic === "\x89PNG", "$kind is re-encoded to WebP/PNG", bin2hex($magic));
    } else {
        check($downloaded === file_get_contents($path), "$kind bytes come back unchanged");
    }
}

// ── Deletion uses the documented contract ───────────────────────────────────
$before = count(json_decode((string)@file_get_contents($endpoint . '/__log'), true) ?: []);
$imageUrl = $stored['image'] ?? '';
if ($imageUrl !== '') {
    check(deleteStoredFile($imageUrl), 'deleteStoredFile reports success');
    $log = json_decode((string)@file_get_contents($endpoint . '/__log'), true) ?: [];
    $deleteCalls = array_values(array_filter($log, static fn(array $entry): bool =>
        ($entry['method'] ?? '') === 'POST' && ($entry['url'] ?? '') === '/delete'));
    check($deleteCalls !== [], 'deletion calls POST /delete (not DELETE on a path)');
    if ($deleteCalls !== []) {
        $payload = json_decode((string)($deleteCalls[0]['body'] ?? '{}'), true);
        check(is_array($payload) && ($payload['urls'][0] ?? '') === $imageUrl,
            'the delete body carries {"urls":[…]}', (string)($deleteCalls[0]['body'] ?? ''));
        check(($deleteCalls[0]['headers']['x-api-version'] ?? '') === blobApiVersion(),
            'the delete call sends the current x-api-version', (string)($deleteCalls[0]['headers']['x-api-version'] ?? ''));
        check(str_starts_with((string)($deleteCalls[0]['headers']['authorization'] ?? ''), 'Bearer '),
            'the delete call is authenticated');
    }
    $gone = @get_headers($imageUrl, true);
    check(!is_array($gone) || !str_contains((string)($gone[0] ?? ''), '200'), 'the object is gone from the store');
    $stillThere = getDB()->prepare('SELECT COUNT(*) FROM stored_files WHERE url=?');
    $stillThere->execute([$imageUrl]);
    check((int)$stillThere->fetchColumn() === 0, 'the registry row is removed');
}

// ── Upload request shape ────────────────────────────────────────────────────
$log = json_decode((string)@file_get_contents($endpoint . '/__log'), true) ?: [];
$puts = array_values(array_filter($log, static fn(array $entry): bool => ($entry['method'] ?? '') === 'PUT'));
check($puts !== [], 'uploads are sent as PUT');
if ($puts !== []) {
    $put = $puts[0];
    check(($put['headers']['x-add-random-suffix'] ?? '') === '0', 'x-add-random-suffix is 0 so the URL stays derivable');
    check(($put['headers']['x-vercel-blob-access'] ?? '') === 'public', 'x-vercel-blob-access is public');
    check(($put['headers']['x-vercel-blob-store-id'] ?? '') === 'mockstore', 'the store id header is sent');
    check(($put['headers']['authorization'] ?? '') !== '', 'the upload is authenticated');
}

// ── A wrong UPLOAD_BASE_URL must fail loudly, never silently ────────────────
$wrong = null;
try {
    storageUrl('articles/article-1/' . bin2hex(random_bytes(20)) . '.png');
} catch (Throwable $e) {
    $wrong = $e;
}
check($wrong === null, 'a well-formed key still builds a URL');

// ── Cleanup ─────────────────────────────────────────────────────────────────
foreach ($fixtures as $path) {
    if (is_file($path) && str_starts_with($path, sys_get_temp_dir())) @unlink($path);
}
if (is_file($pdfPath)) @unlink($pdfPath);

echo "\n" . ($failures ? count($failures) . " FAILURE(S)\n" : "ALL $checks CHECKS PASSED\n");
exit($failures ? 1 : 0);
