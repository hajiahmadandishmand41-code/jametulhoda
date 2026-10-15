<?php
/**
 * tests/storage-tus-endpoint.php — the signed resumable-upload contract.
 *
 * Regression test for the reported
 *
 *     403 Unauthorized — {"error":"Invalid Compact JWS"}
 *
 * Supabase Storage exposes two resumable (TUS) routes:
 *
 *   /storage/v1/upload/resumable        → expects a USER jwt (a login token)
 *   /storage/v1/upload/resumable/sign   → expects a scoped SIGNED upload token
 *
 * This application never gives the browser a user JWT: the browser only ever
 * receives a short-lived, path-scoped signed token that the server issued. The
 * plain route ignores the `x-signature` header and tries to parse that signed
 * token as a user JWT — which is exactly what fails with "Invalid Compact
 * JWS". The signed route is therefore mandatory.
 *
 * See supabase/supabase#44918 ("developers must add /sign at the end of the
 * upload URL so the TUS server knows to look for the x-signature header and
 * thus avoid the (unhelpful) error of \"Invalid Compact JWS\"") and Supabase's
 * own signed-Uppy example, which posts to `/storage/v1/upload/resumable/sign`.
 *
 * Runs under `php tests/storage-tus-endpoint.php` and only against a
 * disposable development environment.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/storage.php';

if (APP_ENV === 'production') {
    throw new RuntimeException('This test must not run against production.');
}

$checks = 0;
function verifyTus(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
    echo "PASS $label\n";
}

// ── 1. The built-in default is the SIGNED route ─────────────────────────────
$original = [];
foreach (['SUPABASE_URL', 'SUPABASE_TUS_ENDPOINT', 'SUPABASE_STORAGE_BUCKET'] as $key) {
    $original[$key] = getenv($key);
}
$restore = static function () use ($original): void {
    foreach ($original as $key => $value) {
        if ($value === false) putenv($key);
        else putenv("$key=$value");
    }
};

$restore();
putenv('SUPABASE_URL=https://abcdefghijklmnop.supabase.co');
putenv('SUPABASE_TUS_ENDPOINT');
$endpoint = supabaseTusEndpoint();
verifyTus(str_ends_with($endpoint, '/storage/v1/upload/resumable/sign'),
    'the default resumable endpoint is the signed route');
verifyTus(str_contains($endpoint, '.storage.supabase.co'),
    'the default resumable endpoint uses the direct Storage hostname');
verifyTus(!str_contains($endpoint, '/resumable/resumable'),
    'the default resumable endpoint has no duplicated path segment');

// ── 2. An operator-supplied endpoint is normalised, never rejected ──────────
$restore();
putenv('SUPABASE_URL=https://abcdefghijklmnop.supabase.co');
putenv('SUPABASE_TUS_ENDPOINT=https://abcdefghijklmnop.storage.supabase.co/storage/v1/upload/resumable');
$configured = supabaseTusEndpoint();
verifyTus(str_ends_with($configured, '/sign'),
    'a configured plain resumable endpoint is upgraded to the signed route');
verifyTus(substr_count($configured, '/sign') === 1,
    'the signed suffix is added exactly once');

$restore();
putenv('SUPABASE_TUS_ENDPOINT=https://abcdefghijklmnop.storage.supabase.co/storage/v1/upload/resumable/sign/');
$alreadySigned = supabaseTusEndpoint();
verifyTus($alreadySigned === 'https://abcdefghijklmnop.storage.supabase.co/storage/v1/upload/resumable/sign',
    'a configured signed endpoint is left untouched (no double suffix)');

$restore();
putenv('SUPABASE_TUS_ENDPOINT=https://example.test/custom/tus');
$custom = supabaseTusEndpoint();
verifyTus($custom === 'https://example.test/custom/tus/sign',
    'a custom endpoint also receives the signed suffix');

$restore();

// ── 3. The signed token must be a compact JWS ───────────────────────────────
// Handing a non-JWT to the browser only reproduces the opaque 403, so the
// server refuses to pass such a token on.
$jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk';
verifyTus(supabaseTokenIsCompactJws($jwt), 'a real compact JWS is accepted');
verifyTus(!supabaseTokenIsCompactJws(''), 'an empty token is rejected');
verifyTus(!supabaseTokenIsCompactJws('not-a-jwt'), 'an opaque token is rejected');
verifyTus(!supabaseTokenIsCompactJws('a.b'), 'a two-segment token is rejected');
verifyTus(!supabaseTokenIsCompactJws('a.b.c.d'), 'a four-segment token is rejected');
verifyTus(!supabaseTokenIsCompactJws('a..c'), 'an empty segment is rejected');
verifyTus(!supabaseTokenIsCompactJws('a.b.c!'), 'a non base64url character is rejected');
verifyTus(!supabaseTokenIsCompactJws('<html>error</html>'), 'an HTML error page is rejected');

// ── 4. The normalisation helper on its own ──────────────────────────────────
verifyTus(supabaseTusSignedEndpoint('https://x.test/upload/resumable') === 'https://x.test/upload/resumable/sign',
    'supabaseTusSignedEndpoint() appends /sign');
verifyTus(supabaseTusSignedEndpoint('https://x.test/upload/resumable/sign') === 'https://x.test/upload/resumable/sign',
    'supabaseTusSignedEndpoint() is idempotent');
verifyTus(supabaseTusSignedEndpoint('  https://x.test/upload/resumable/  ') === 'https://x.test/upload/resumable/sign',
    'supabaseTusSignedEndpoint() trims and drops the trailing slash');
verifyTus(supabaseTusSignedEndpoint('') === '', 'an empty endpoint stays empty');

echo "\n$checks resumable-upload contract checks passed\n";
