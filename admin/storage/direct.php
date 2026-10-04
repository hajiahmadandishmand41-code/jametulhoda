<?php
/**
 * /admin/storage/direct — issue a short-lived Supabase Storage upload grant.
 *
 * Only authenticated administrators with a valid CSRF token may request a grant.
 * The service-role key never leaves PHP. The browser receives only a signed
 * upload token scoped to one generated staging object.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$fail = static function(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $fail('فقط درخواست POST پذیرفته می‌شود.', 405);
}
if (!isLoggedIn()) $fail('ابتدا وارد پنل مدیریت شوید.', 403);
if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    $fail('نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.', 403);
}

$action = strtolower(trim((string)($_POST['action'] ?? 'sign')));
if (!in_array($action, ['sign','finalize'], true)) $fail('عملیات نامعتبر است.');

try {
    if ($action === 'sign') {
        $name = trim((string)($_POST['name'] ?? ''));
        $size = (int)($_POST['size'] ?? 0);
        $mime = trim((string)($_POST['mime'] ?? 'application/octet-stream'));
        $kind = strtolower(trim((string)($_POST['kind'] ?? '')));

        if ($name === '' || $size < 1 || mb_strlen($name) > 255) $fail('نام یا اندازهٔ فایل معتبر نیست.');
        if (!in_array($kind, ['image','audio','video','pdf','word'], true)) $fail('نوع فایل معتبر نیست.');
        $max = $kind === 'video' ? MAX_VIDEO_SIZE : MAX_FILE_SIZE;
        if ($size > $max) $fail('حجم فایل از سقف مجاز این نوع فایل بیشتر است.', 413);

        // Validate the extension/mime pair without trusting the browser MIME.
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = [
            'image' => ['jpg','jpeg','png','gif','webp'],
            'audio' => ['mp3','ogg','wav','m4a'],
            'video' => ['mp4','webm','mov','mkv'],
            'pdf'   => ['pdf'],
            'word'  => ['doc','docx'],
        ];
        if (!in_array($ext, $allowed[$kind], true)) $fail('پسوند فایل برای این نوع رسانه مجاز نیست.');
        if ($mime === '' || mb_strlen($mime) > 120) $fail('نوع MIME نامعتبر است.');

        $key = 'staging/' . bin2hex(random_bytes(24)) . '.' . $ext;
        $signed = supabaseCreateSignedUpload($key);
        if (empty($signed['ok'])) $fail((string)($signed['error'] ?? 'ایجاد مجوز آپلود مستقیم انجام نشد.'), 502);

        // Keep an explicit one-day cleanup lease. Once the final form adopts the
        // object, the lease is removed; abandoned/interrupted objects are cleaned
        // by the storage GC worker.
        $public = storageUrl($key);
        getDB()->prepare("INSERT INTO pending_uploads (reference, not_before) VALUES (?, NOW()+INTERVAL '24 hours') ON CONFLICT DO NOTHING")
            ->execute([$public]);

        echo json_encode([
            'ok' => true,
            'path' => $key,
            'token' => (string)$signed['token'],
            'tus_endpoint' => supabaseTusEndpoint(),
            'bucket' => supabaseStorageBucket(),
            'public_url' => $public,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $key = storageKey((string)($_POST['path'] ?? ''));
    $size = (int)($_POST['size'] ?? 0);
    if ($key === '' || !str_starts_with($key, 'staging/') || $size < 1) $fail('فایل مستقیم معتبر نیست.');

    $info = supabaseStorageObjectInfo($key);
    if (empty($info['ok'])) $fail((string)($info['error'] ?? 'فایل مستقیم در Storage پیدا نشد.'), 404);

    $remoteSize = (int)($info['size'] ?? 0);
    if ($remoteSize !== $size) $fail('حجم فایل ذخیره‌شده با فایل اصلی برابر نیست.', 422);

    echo json_encode(['ok'=>true,'path'=>$key,'size'=>$remoteSize], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('direct storage endpoint failed: ' . get_class($e));
    $fail('خطای غیرمنتظره در Storage مستقیم.', 500);
}
?>