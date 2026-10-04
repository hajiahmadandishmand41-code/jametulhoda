<?php
/**
 * Temporary Preview-only integration test. It is never usable on Production.
 * It exercises the same server storage primitives used by the application:
 * Supabase DB connection -> signed TUS upload -> object verification ->
 * PostgreSQL metadata registration -> delete verification.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if ((string)env_value('VERCEL_ENV') !== 'preview'
    || (string)env_value('VERCEL_GIT_COMMIT_REF') !== 'supabase-production-storage-migration') {
    http_response_code(404);
    echo json_encode(['ok'=>false], JSON_UNESCAPED_UNICODE);
    exit;
}

$checks = [];
$pass = static function(string $name, string $detail = '') use (&$checks): void {
    $checks[] = ['name'=>$name,'ok'=>true,'detail'=>$detail];
};
$fail = static function(string $name, string $detail) use (&$checks): void {
    $checks[] = ['name'=>$name,'ok'=>false,'detail'=>$detail];
};

$key = '';
$finalUrl = '';
try {
    $db = getDB();
    $runtime = $db->query('SELECT current_database() AS db_name, current_user AS db_user')->fetch(PDO::FETCH_ASSOC);
    $expectedUser = 'jhd_runtime_caf1b3d2f6';
    if (!is_array($runtime) || (string)($runtime['db_name'] ?? '') !== 'postgres' || (string)($runtime['db_user'] ?? '') !== $expectedUser) {
        $fail('Vercel runtime DB', 'unexpected runtime identity');
        throw new RuntimeException('runtime db identity mismatch');
    }
    $pass('Vercel runtime DB', 'Supabase PostgreSQL runtime role verified');

    if (storageDriver() !== 'supabase') {
        $fail('Supabase storage driver', 'driver=' . storageDriver());
        throw new RuntimeException('storage driver mismatch');
    }
    $pass('Supabase storage driver', supabaseStorageBucket());

    $key = 'staging/integration/' . bin2hex(random_bytes(18)) . '.bin';
    $payload = random_bytes(7 * 1024 * 1024);
    $grant = supabaseCreateSignedUpload($key);
    if (empty($grant['ok'])) throw new RuntimeException((string)($grant['error'] ?? 'signed upload failed'));
    $pass('signed TUS grant');

    $endpoint = supabaseTusEndpoint();
    $meta = 'bucketName ' . base64_encode(supabaseStorageBucket())
        . ',objectName ' . base64_encode($key)
        . ',contentType ' . base64_encode('application/octet-stream');

    $createHeaders = [];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Tus-Resumable: 1.0.0',
            'Upload-Length: ' . strlen($payload),
            'Upload-Metadata: ' . $meta,
            'x-signature: ' . $grant['token'],
            'x-upsert: false',
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function($ch, string $line) use (&$createHeaders): int {
            $trim = trim($line);
            if (str_contains($trim, ':')) {
                [$k,$v] = array_map('trim', explode(':',$trim,2));
                $createHeaders[strtolower($k)] = $v;
            }
            return strlen($line);
        },
    ]);
    curl_exec($ch);
    $createStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $createError = curl_error($ch);
    curl_close($ch);
    $location = (string)($createHeaders['location'] ?? '');
    if ($location !== '' && str_starts_with($location, '/')) {
        $parts = parse_url($endpoint);
        $location = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $location;
    }
    if ($createStatus !== 201 || $location === '') {
        throw new RuntimeException('TUS create HTTP ' . $createStatus . ($createError !== '' ? ' ' . $createError : ''));
    }
    $pass('TUS resumable session');

    $offset = 0;
    $total = strlen($payload);
    $chunks = 0;
    $chunkSize = 6 * 1024 * 1024;
    while ($offset < $total) {
        $length = min($chunkSize, $total - $offset);
        $chunk = substr($payload, $offset, $length);
        $ch = curl_init($location);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => $chunk,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Tus-Resumable: 1.0.0',
                'Upload-Offset: ' . $offset,
                'Content-Type: application/offset+octet-stream',
                'Content-Length: ' . $length,
            ],
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $nextOffset = (int)(curl_getinfo($ch, CURLINFO_SIZE_UPLOAD_T) ?: 0);
        curl_close($ch);
        if ($status !== 204) throw new RuntimeException('TUS PATCH HTTP ' . $status);
        $offset += $length;
        $chunks++;
    }
    if ($offset !== $total) throw new RuntimeException('TUS final offset mismatch');
    $pass('TUS chunk upload', $chunks . ' chunks / ' . $total . ' bytes');

    $info = supabaseStorageObjectInfo($key);
    if (empty($info['ok']) || (int)($info['size'] ?? 0) !== $total) {
        throw new RuntimeException('Storage object verification failed');
    }
    $pass('Supabase Storage object verification', $total . ' bytes');

    $pending = $db->prepare("INSERT INTO pending_uploads (reference, not_before) VALUES (?, NOW()+INTERVAL '24 hours') ON CONFLICT DO NOTHING");
    $pending->execute([storageUrl($key)]);
    $finalUrl = adoptDirectUpload($key, 'image', 'media');
    if ($finalUrl === '') throw new RuntimeException('application adoption failed');
    $metaStmt = $db->prepare('SELECT file_key,url,mime,size FROM stored_files WHERE url=? LIMIT 1');
    $metaStmt->execute([$finalUrl]);
    $row = $metaStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['size'] !== $total || (string)$row['url'] !== $finalUrl) {
        throw new RuntimeException('stored_files metadata mismatch');
    }
    $pass('PostgreSQL metadata registration', 'stored_files verified');

    if (!deleteStoredFile($finalUrl)) throw new RuntimeException('deleteStoredFile returned false');
    $leftMeta = $db->prepare('SELECT count(*) FROM stored_files WHERE url=?');
    $leftMeta->execute([$finalUrl]);
    $objectAfter = supabaseStorageObjectInfo((string)$row['file_key']);
    if ((int)$leftMeta->fetchColumn() !== 0 || !empty($objectAfter['ok'])) {
        throw new RuntimeException('delete verification failed');
    }
    $pass('Delete + orphan cleanup path', 'metadata and object both removed');
} catch (Throwable $e) {
    error_log('temporary Supabase integration probe failed: ' . get_class($e));
    $safeMessage = preg_replace('/postgres(?:ql)?:\\/\\/[^\\s]+/i', 'postgresql://[redacted]', (string)$e->getMessage()) ?? '';
    $safeMessage = preg_replace('/(password|passwd|pwd)=([^&\\s]+)/i', '$1=[redacted]', $safeMessage) ?? $safeMessage;
    $fail('integration exception', get_class($e) . ($safeMessage !== '' ? ': ' . substr($safeMessage, 0, 220) : ''));
    if ($finalUrl !== '') {
        try { deleteStoredFile($finalUrl); } catch (Throwable) {}
    } elseif ($key !== '') {
        try { supabaseStorageDeleteObject($key); } catch (Throwable) {}
    }
}

$failed = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok']));
echo json_encode([
    'ok'=>count($failed) === 0,
    'checks'=>$checks,
    'secret_exposed'=>false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
