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

if (storageDriver() !== 'supabase') $fail('آپلود مستقیم نیازمند فعال‌بودن درایور Supabase است؛ فایل ارسال نشد.',409);

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

        $mimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
            'mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav','m4a'=>'audio/mp4',
            'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','mkv'=>'video/x-matroska',
            'pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $mime=$mimes[$ext];
        foreach ($_SESSION['direct_uploads'] ?? [] as $oldKey => $claim) {
            if (($claim['expires'] ?? 0) < time()) unset($_SESSION['direct_uploads'][$oldKey]);
        }
        if(count($_SESSION['direct_uploads'] ?? []) >= 200) $fail('تعداد فایل‌های موقت این نشست زیاد است؛ ابتدا عملیات قبلی را تکمیل کنید.',429);
        $key = 'staging/' . bin2hex(random_bytes(24)) . '.' . $ext;
        $signed = supabaseCreateSignedUpload($key);
        if (empty($signed['ok'])) $fail((string)($signed['error'] ?? 'ایجاد مجوز آپلود مستقیم انجام نشد.'), 502);

        // Keep an explicit one-day cleanup lease. Once the final form adopts the
        // object, the lease is removed; abandoned/interrupted objects are cleaned
        // by the storage GC worker.
        $public = storageUrl($key);
        getDB()->prepare("INSERT INTO pending_uploads (reference, not_before) VALUES (?, NOW()+INTERVAL '24 hours') ON CONFLICT DO NOTHING")
            ->execute([$public]);

        $_SESSION['direct_uploads'][$key] = ['size'=>$size, 'kind'=>$kind, 'mime'=>$mime, 'expires'=>time()+7200, 'verified'=>false];
        echo json_encode([
            'request_id' => basename($key),
            'mime' => $mime,
            'ok' => true,
            'path' => $key,
            'token' => (string)$signed['token'],
            'tus_endpoint' => supabaseTusEndpoint(),
            // Direct signed PUT fallback: when the resumable route is not
            // reachable the browser can still hand the file to Storage in one
            // request, straight from the browser to Storage — never through
            // the Vercel function, so the request-body limit cannot bite.
            'signed_url' => (string)($signed['signed_url'] ?? ''),
            'bucket' => supabaseStorageBucket(),
            'public_url' => $public,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $key = storageKey((string)($_POST['path'] ?? ''));
    $size = (int)($_POST['size'] ?? 0);
    if ($key === '' || !str_starts_with($key, 'staging/') || $size < 1) $fail('فایل مستقیم معتبر نیست.');

    $claim = $_SESSION['direct_uploads'][$key] ?? null;
    if (!is_array($claim) || ($claim['expires'] ?? 0) < time() || (int)$claim['size'] !== $size) {
        $fail('مجوز این فایل متعلق به نشست جاری نیست یا منقضی شده است.', 403);
    }
    $info = supabaseStorageObjectInfo($key);
    if (empty($info['ok'])) $fail((string)($info['error'] ?? 'فایل مستقیم در Storage پیدا نشد.'), 404);

    $remoteSize = (int)($info['size'] ?? 0);
    if ((string)($info['mime'] ?? '') !== (string)$claim['mime']) $fail('نوع فایل ذخیره‌شده با مجوز آپلود برابر نیست.',422);
    if ($remoteSize !== $size) $fail('حجم فایل ذخیره‌شده با فایل اصلی برابر نیست.', 422);

    if (!storageUrlIsPublic(storageUrl($key))) $fail('فایل ذخیره شده، اما دسترسی عمومی آن تأیید نشد؛ نیاز به بررسی مجدد دارد.', 422);
    $_SESSION['direct_uploads'][$key]['verified'] = true;
    echo json_encode(['state'=>'uploaded','ok'=>true,'path'=>$key,'size'=>$remoteSize], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('direct storage endpoint failed: ' . get_class($e));
    $fail('خطای غیرمنتظره در Storage مستقیم.', 500);
}
?>