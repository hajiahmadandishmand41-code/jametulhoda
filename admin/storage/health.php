<?php
/**
 * Temporary one-time migration verification endpoint.
 * Protected by a Vercel-sensitive env token and removed after production verification.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$allowedCommit = '9c503db62b29374d96bb33ccce58cb5514f412d4';
if (env_value('VERCEL_ENV') !== 'preview' || env_value('VERCEL_GIT_COMMIT_SHA') !== $allowedCommit) {
    http_response_code(404);
    echo json_encode(['ok'=>false], JSON_UNESCAPED_UNICODE);
    exit;
}

$checks = [];
$fail = static function(string $name, string $detail) use (&$checks): void {
    $checks[] = ['name'=>$name,'ok'=>false,'detail'=>$detail];
};
$pass = static function(string $name, string $detail = '') use (&$checks): void {
    $checks[] = ['name'=>$name,'ok'=>true,'detail'=>$detail];
};

$testKey = 'staging/migration-check/' . bin2hex(random_bytes(16)) . '.png';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
if ($png === false) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'fixture'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDB();
    $db->query('SELECT 1')->fetchColumn();
    $driver = databaseDriver();
    $pass('database connection', 'driver=' . $driver);

    $required = ['users','categories','topics','posts','post_images','post_topics',
        'lesson_collections','lesson_volumes','lessons','lesson_topics','contact_messages',
        'media_files','settings','books','book_topics','site_banners','featured_banners',
        'app_sessions','login_limits','stored_files','storage_deletions','pending_uploads'];
    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $q = $db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name IN ($placeholders)");
    $q->execute($required);
    $seen = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($required, array_keys($seen)));
    if ($missing) $fail('required production tables', implode(',', $missing));
    else $pass('required production tables', count($required) . ' present');

    $bucket = supabaseStorageBucket();
    if ($bucket !== 'site-media') $fail('site-media bucket selected', 'selected=' . $bucket);
    else $pass('site-media bucket selected');

    if (storageDriver() !== 'supabase') $fail('Supabase storage driver', 'driver=' . storageDriver());
    else $pass('Supabase storage driver');

    $bucketStmt = $db->prepare('SELECT id, public FROM storage.buckets WHERE id=?');
    $bucketStmt->execute([$bucket]);
    $bucketRow = $bucketStmt->fetch(PDO::FETCH_ASSOC);
    if (!$bucketRow) $fail('site-media bucket exists', 'missing');
    else $pass('site-media bucket exists', 'public=' . ((int)$bucketRow['public'] === 1 ? 'true' : 'false'));

    $grant = supabaseCreateSignedUpload($testKey);
    if (empty($grant['ok'])) {
        $fail('signed upload grant', (string)($grant['error'] ?? 'failed'));
    } else {
        $pass('signed upload grant');

        $endpoint = supabaseTusEndpoint();
        $bucketB64 = base64_encode($bucket);
        $keyB64 = base64_encode($testKey);
        $mimeB64 = base64_encode('image/png');
        $metadata = "bucketName {$bucketB64},objectName {$keyB64},contentType {$mimeB64}";

        $location = '';
        $responseHeaders = [];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => [
                'Tus-Resumable: 1.0.0',
                'Upload-Length: ' . strlen($png),
                'Upload-Metadata: ' . $metadata,
                'x-signature: ' . $grant['token'],
                'x-upsert: false',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function($ch, string $line) use (&$location, &$responseHeaders): int {
                $trimmed = trim($line);
                if (stripos($trimmed, 'location:') === 0) $location = trim(substr($trimmed, 9));
                if (str_contains($trimmed, ':')) {
                    [$k,$v] = array_map('trim', explode(':',$trimmed,2));
                    $responseHeaders[strtolower($k)] = $v;
                }
                return strlen($line);
            },
        ]);
        curl_exec($ch);
        $createStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $createError = curl_error($ch);
        curl_close($ch);

        if (!$location && !empty($responseHeaders['location'])) $location = $responseHeaders['location'];
        if (is_string($location) && str_starts_with($location, '/')) {
            $parts = parse_url($endpoint);
            $location = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $location;
        }

        if ($createStatus !== 201 || $location === '') {
            $fail('TUS upload session', 'http=' . $createStatus . ($createError !== '' ? ' error' : ''));
        } else {
            $ch = curl_init($location);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => $png,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Tus-Resumable: 1.0.0',
                    'Upload-Offset: 0',
                    'Content-Type: application/offset+octet-stream',
                    'Content-Length: ' . strlen($png),
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            curl_exec($ch);
            $patchStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($patchStatus !== 204) {
                $fail('TUS chunk upload', 'http=' . $patchStatus);
            } else {
                $info = supabaseStorageObjectInfo($testKey);
                if (empty($info['ok']) || (int)$info['size'] !== strlen($png)) {
                    $fail('Storage object verification', 'size=' . (int)($info['size'] ?? 0));
                } else {
                    $pass('Storage object verification', 'size=' . (int)$info['size']);

                    $pending = $db->prepare('INSERT INTO pending_uploads (reference, not_before) VALUES (?, NOW()+INTERVAL \'24 hours\') ON CONFLICT DO NOTHING');
                    $pending->execute([storageUrl($testKey)]);
                    $finalUrl = adoptDirectUpload($testKey, 'image', 'media');
                    if ($finalUrl === '') {
                        $fail('metadata registration + adoption', 'adoptDirectUpload failed');
                    } else {
                        $metaStmt = $db->prepare('SELECT file_key,url,mime,size FROM stored_files WHERE url=?');
                        $metaStmt->execute([$finalUrl]);
                        $metaRow = $metaStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$metaRow) {
                            $fail('PostgreSQL file metadata', 'stored_files row missing');
                        } else {
                            $pass('PostgreSQL file metadata', 'mime=' . (string)$metaRow['mime'] . ' size=' . (int)$metaRow['size']);
                        }
                        if (!deleteStoredFile($finalUrl)) $fail('Storage delete', 'deleteStoredFile returned false');
                        else $pass('Storage delete');

                        $left = $db->prepare('SELECT count(*) FROM stored_files WHERE url=?');
                        $left->execute([$finalUrl]);
                        $remainingMeta = (int)$left->fetchColumn();
                        $objectInfo = supabaseStorageObjectInfo((string)$metaRow['file_key']);
                        if ($remainingMeta === 0 && empty($objectInfo['ok'])) $pass('delete verification');
                        else $fail('delete verification', 'metadata=' . $remainingMeta . ' object_present=' . (!empty($objectInfo['ok']) ? 'yes' : 'no'));
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('migration health failed: ' . get_class($e));
    $fail('exception', 'runtime verification failed');
}

$ok = !array_filter($checks, static fn(array $c): bool => !$c['ok']);
echo json_encode([
    'ok'=>$ok,
    'checks'=>$checks,
    'secret_exposed'=>false,
    'tested_key_redacted'=>true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
