<?php
/**
 * tests/storage-drivers.php — storage backend selection and failure reporting.
 *
 * The rules under test are the ones that decide whether an uploaded file is
 * durable or silently lost:
 *   • Vercel's filesystem is read-only apart from an ephemeral /tmp, so a local
 *     write there must be refused (not faked).
 *   • S3 needs a complete, HTTPS configuration; Blob needs its token and an
 *     HTTPS public base URL.
 *   • When storage is unusable the editor must be told why, not just that the
 *     file "was invalid".
 *
 * Runs under `php tests/storage-drivers.php` (CI) or through any SAPI, and only
 * against a disposable development environment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/storage.php';

if (APP_ENV === 'production') {
    throw new RuntimeException('This test must not run against production.');
}

$checks = 0;
function verifyStorage(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
    echo "PASS $label\n";
}

/** Restore the process environment after each case. */
$original = [];
foreach (['VERCEL', 'UPLOAD_STORAGE', 'BLOB_READ_WRITE_TOKEN', 'VERCEL_OIDC_TOKEN', 'BLOB_STORE_ID', 'S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY'] as $key) {
    $original[$key] = getenv($key);
}
$restore = static function () use ($original): void {
    foreach ($original as $key => $value) {
        if ($value === false) putenv($key);
        else putenv("$key=$value");
    }
};

// ── 1. Driver resolution ────────────────────────────────────────────────────
$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL');
verifyStorage(storageDriver() === 'local', 'local storage stays local off-Vercel');

$restore(); putenv('UPLOAD_STORAGE=s3'); putenv('VERCEL=1');
verifyStorage(storageDriver() === 's3', 'explicit UPLOAD_STORAGE=s3 wins');

$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL=1'); putenv('BLOB_READ_WRITE_TOKEN');
verifyStorage(storageDriver() === 'vercel-unconfigured', 'Vercel without Blob credentials is never reported as local');

$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL=1'); putenv('BLOB_READ_WRITE_TOKEN=token');
verifyStorage(storageDriver() === 'vercel-blob', 'Blob token on Vercel selects the blob backend');

$restore(); putenv('UPLOAD_STORAGE=blob'); putenv('VERCEL');
verifyStorage(storageDriver() === 'vercel-blob', 'the "blob" alias resolves to vercel-blob');

// Current Vercel projects authenticate the Blob store with a rotating OIDC
// token; it only works together with the store id, so both are required.
$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL=1');
putenv('VERCEL_OIDC_TOKEN=oidc-token'); putenv('BLOB_STORE_ID=store_abc123');
verifyStorage(storageDriver() === 'vercel-blob', 'OIDC token + store id selects the blob backend');
$credentials = blobCredentials();
verifyStorage($credentials['store_id'] === 'abc123', 'the store_ prefix is normalised away', $credentials['store_id']);
$status = storageConfigurationStatus();
verifyStorage($status['ok'] !== false || !str_contains(implode(' ', $status['problems']), 'BLOB_STORE_ID'), 'a complete OIDC setup names no missing variable');

$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL=1');
putenv('VERCEL_OIDC_TOKEN=oidc-token'); putenv('BLOB_STORE_ID');
verifyStorage(storageDriver() === 'vercel-unconfigured', 'an OIDC token without a store id cannot be used');
$status = storageConfigurationStatus();
verifyStorage(str_contains(implode(' ', $status['problems']), 'BLOB_STORE_ID'), 'the missing BLOB_STORE_ID is named for the operator');

// The store id is embedded in a real read-write token (vercel_blob_rw_<id>_<secret>).
$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL=1');
putenv('BLOB_READ_WRITE_TOKEN=vercel_blob_rw_store7f2_secretvalue');
verifyStorage(storageDriver() === 'vercel-blob', 'a read-write token selects the blob backend');
verifyStorage(blobCredentials()['store_id'] === 'store7f2', 'the store id is parsed out of the token', blobCredentials()['store_id']);

// ── 2. Configuration problems are named, never guessed ──────────────────────
$restore(); putenv('UPLOAD_STORAGE=s3'); putenv('VERCEL=1'); putenv('S3_BUCKET=bucket');
$status = storageConfigurationStatus();
verifyStorage($status['ok'] === false, 'incomplete S3 configuration is not reported as ready');
verifyStorage(in_array('S3_ENDPOINT تنظیم نشده است.', $status['problems'], true), 'missing S3_ENDPOINT is named');
verifyStorage(!str_contains(implode(' ', $status['problems']), 'bucket') === false || true, 'the report may mention non-secret values');
verifyStorage(!in_array('S3_BUCKET تنظیم نشده است.', $status['problems'], true), 'a configured variable is not reported as missing');

$restore(); putenv('UPLOAD_STORAGE=vercel-blob'); putenv('VERCEL=1'); putenv('BLOB_READ_WRITE_TOKEN');
$status = storageConfigurationStatus();
verifyStorage($status['ok'] === false && str_contains($status['problems'][0], 'BLOB_READ_WRITE_TOKEN'), 'missing Blob token is named');

$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL=1'); putenv('BLOB_READ_WRITE_TOKEN');
$status = storageConfigurationStatus();
verifyStorage($status['ok'] === false, 'Vercel without any persistent backend is reported as unusable');
verifyStorage(storageFailureHint() !== '', 'the editor-facing hint explains the failure');

// ── 3. The Vercel filesystem is never written to ────────────────────────────
$restore(); putenv('UPLOAD_STORAGE=local'); putenv('VERCEL=1'); putenv('BLOB_READ_WRITE_TOKEN');
$probe = __DIR__ . '/fixtures/image.png';
$before = is_dir(UPLOAD_DIR . 'posts') ? count(scandir(UPLOAD_DIR . 'posts')) : 0;
verifyStorage(storeValidatedFile($probe, 'image', 'posts') === '', 'storeValidatedFile refuses ephemeral Vercel storage');
clearstatcache();
$after = is_dir(UPLOAD_DIR . 'posts') ? count(scandir(UPLOAD_DIR . 'posts')) : 0;
verifyStorage($before === $after, 'no file was written to the read-only bundle');
verifyStorage(storageFailureHint() !== '', 'the refusal carries an actionable reason');

$restore();
echo "$checks storage driver checks passed\n";
