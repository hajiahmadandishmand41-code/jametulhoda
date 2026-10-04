<?php
require_once __DIR__ . '/../config/database.php';

/** Request-local tracking; standalone media-library uploads deliberately do not opt in. */
function &contentUploadScope(): array {
    static $scope = ['active'=>false, 'completed'=>[]];
    return $scope;
}
/**
 * Upload-time journal connection. It stays separate (autocommit) so a later
 * content rollback cannot erase the cleanup intent recorded during upload.
 * The shutdown-time journal UPDATE below deliberately reuses getDB(): at
 * request end the app connection may still hold open cursors (e.g. an
 * INSERT ... RETURNING awaiting close) that keep the database locked, and a
 * second connection writing then would block on that lock. Uploads always
 * run before any content write, so the upload-time journal INSERT here is
 * never contended.
 */
function uploadJournalDB(): PDO {
    static $db;
    return $db ??= newDatabaseConnection();
}
function beginContentUploadScope(): void {
    $scope =& contentUploadScope();
    if ($scope['active']) return;
    $scope['active'] = true;
    register_shutdown_function(function(): void {
        $scope =& contentUploadScope();
        if (!$scope['completed']) return;
        try {
            // An unfinished transaction cannot be a successful save at request end.
            if (getDB()->inTransaction()) getDB()->rollBack();
            // Same-connection journal write (see uploadJournalDB note): a
            // second connection could block forever on locks held by still-
            // open request cursors. The rollback above already released any
            // transaction lock, so this UPDATE commits standalone.
            $ready = getDB()->prepare('UPDATE pending_uploads SET not_before=NOW() WHERE reference=?');
            foreach ($scope['completed'] as $url) $ready->execute([$url]);
            require_once __DIR__.'/content-delete.php';
            processStorageDeletions();
        } catch (Throwable $e) {
            error_log('New upload cleanup queued for retry with bin/storage-gc.php.');
        }
    });
}

/** Only generated keys/legacy upload references from our own origin can be deleted. */
function storageKey(string $value): string {
    $root = dirname(__DIR__) . '/';
    if (str_starts_with($value, $root)) $value = substr($value, strlen($root));
    if (str_starts_with($value, UPLOAD_DIR)) $value = 'uploads/' . substr($value, strlen(UPLOAD_DIR));
    $supabasePublicBase = supabaseStoragePublicBaseUrl();
    if ($supabasePublicBase !== '' && str_starts_with($value, $supabasePublicBase . '/')) $value = substr($value, strlen($supabasePublicBase) + 1);
    elseif (str_starts_with($value, UPLOAD_BASE_URL . '/')) $value = substr($value, strlen(UPLOAD_BASE_URL) + 1);
    elseif (str_starts_with($value, BASE_PATH . '/uploads/')) $value = substr($value, strlen(BASE_PATH . '/uploads/'));
    elseif (str_starts_with($value, '/uploads/')) $value = substr($value, 9);
    elseif (str_starts_with($value, 'uploads/')) $value = substr($value, 8);
    // Legacy folder names (audio/video) stay valid: files uploaded before the
    // rename to audios/videos must keep resolving.
    // Current content uses a deterministic entity directory, for example
    // `articles/article-42/…` or `lessons/collection-3/volume-1/lesson-9/…`.
    // Legacy single-directory uploads remain readable.  Do not weaken this
    // allowlist: it is the boundary that prevents a database value from being
    // turned into an arbitrary local/S3 key.
    $folders = ['staging','posts','reports','articles','research','lessons','books','book-covers','topics','site','media','images','documents','avatars','banners',

                'audio','video','audios','videos',
                UPLOAD_IMAGES,UPLOAD_AUDIO,UPLOAD_VIDEO,UPLOAD_DOCUMENTS];
    if (!in_array(explode('/', $value)[0], $folders, true)) return '';
    if (!preg_match('~^(?:[a-zA-Z0-9_-]+/)+[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|gif|webp|mp3|ogg|wav|m4a|mp4|webm|mov|mkv|pdf|doc|docx)$~D', $value) || str_contains($value, '..')) return '';
    return $value;
}

/**
 * Return the one safe, deterministic media folder for a concrete content row.
 * IDs (rather than mutable titles/slugs or course membership) keep media URLs
 * stable after an editor changes a title or moves a lesson between volumes.
 */
function contentStorageFolder(string $entity, int $id, array $context = []): string {
    if ($id < 1) throw new InvalidArgumentException('A persisted content id is required for storage.');
    $root = match ($entity) {
        'report' => 'reports',
        'article' => 'articles',
        'research' => 'research',
        'book' => 'books',
        'topic' => 'topics',
        'banner' => 'banners',
        'avatar' => 'avatars',
        'lesson' => 'lessons',
        default => 'posts',
    };
    if ($entity === 'lesson_collection') {
        return 'lessons/collection-' . $id;
    }
    if ($entity === 'lesson') {
        $collId = (int)($context['collection_id'] ?? 0);
        if ($collId > 0) {
            return 'lessons/collection-' . $collId . '/lesson-' . $id;
        }
        return 'lessons/lesson-' . $id;
    }
    if ($entity === 'book') {
        $catId = (int)($context['category_id'] ?? $context['topic_id'] ?? 0);
        if ($catId > 0) {
            return 'books/category-' . $catId . '/book-' . $id;
        }
        return 'books/book-' . $id;
    }
    $prefix = match ($entity) {
        'report' => 'report-',
        'article' => 'article-',
        'research' => 'research-',
        'topic' => 'topic-',
        'banner' => 'banner-',
        'avatar' => 'user-',
        default => 'post-',
    };
    return $root . '/' . $prefix . $id;
}

/** Validate a folder assembled by the application before writing a file. */
function storageFolderIsAllowed(string $folder): bool {
    if (!preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $folder) || str_contains($folder, '..')) return false;
    $root = explode('/', $folder)[0] ?? '';
    return in_array($root, ['staging','posts','reports','articles','research','lessons','books','book-covers','topics','site','media','images','documents','avatars','banners','audio','video','audios','videos'], true);
}

function supabaseStoragePublicBaseUrl(): string {
    $url = rtrim(trim(env_value('SUPABASE_URL')), '/');
    $bucket = trim(env_value('SUPABASE_STORAGE_BUCKET', 'site-media'));
    if ($url === '' || $bucket === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    return $url . '/storage/v1/object/public/' . rawurlencode($bucket);
}

function supabaseStorageApiBase(): string {
    $url = rtrim(trim(env_value('SUPABASE_URL')), '/');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    return $url . '/storage/v1';
}

function supabaseStorageBucket(): string {
    return trim(env_value('SUPABASE_STORAGE_BUCKET', 'site-media'));
}

function supabaseTusEndpoint(): string {
    $configured = rtrim(trim(env_value('SUPABASE_TUS_ENDPOINT')), '/');
    if ($configured !== '') return $configured;
    $api = supabaseStorageApiBase();
    if ($api === '') return '';
    $host = (string)parse_url($api, PHP_URL_HOST);
    if (preg_match('/^([A-Za-z0-9-]+)\\.supabase\\.co$/i', $host, $m)) {
        return 'https://' . $m[1] . '.storage.supabase.co/storage/v1/upload/resumable';
    }
    throw new RuntimeException('برای این Supabase URL، SUPABASE_TUS_ENDPOINT باید تنظیم شود.');
}

/** Authenticated server-to-Supabase Storage REST request; the service key never reaches the browser. */
function supabaseStorageRequest(string $method, string $path, array $headers = [], ?string $body = null): array {
    $base = supabaseStorageApiBase();
    $serviceKey = trim(env_value('SUPABASE_SERVICE_ROLE_KEY'));
    if ($base === '' || $serviceKey === '') return [0, '', 'Supabase Storage credentials are not configured.'];
    $url = $base . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $http = ['apikey: ' . $serviceKey, 'Authorization: Bearer ' . $serviceKey];
    foreach ($headers as $k => $v) $http[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $http,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$status, $response === false ? '' : (string)$response, $error];
}

function supabaseStoragePath(string $key): string {
    $safe = storageKey($key);
    if ($safe === '') throw new InvalidArgumentException('Invalid Supabase storage key.');
    return rawurlencode(supabaseStorageBucket()) . '/' . implode('/', array_map('rawurlencode', explode('/', $safe)));
}

function supabaseCreateSignedUpload(string $key): array {
    $safe = storageKey($key);
    if ($safe === '' || !str_starts_with($safe, 'staging/')) return ['ok'=>false,'error'=>'Invalid staging key.'];
    [$status, $body, $error] = supabaseStorageRequest(
        'POST',
        'object/upload/sign/' . rawurlencode(supabaseStorageBucket()) . '/' . implode('/', array_map('rawurlencode', explode('/', $safe))),
        ['Content-Type'=>'application/json'],
        json_encode(['upsert'=>false], JSON_UNESCAPED_SLASHES)
    );
    if ($status < 200 || $status >= 300) return ['ok'=>false,'error'=>'Supabase signed upload failed (HTTP '.$status.').'.($error!==''?' '.$error:'')];
    $data = json_decode($body, true);
    $signed = is_array($data) ? (string)($data['signedURL'] ?? $data['url'] ?? '') : '';
    $token = is_array($data) ? (string)($data['token'] ?? '') : '';
    if ($signed !== '' && $token === '') {
        $query = (string)parse_url($signed, PHP_URL_QUERY);
        parse_str($query, $params);
        $token = (string)($params['token'] ?? '');
    }
    if ($token === '') return ['ok'=>false,'error'=>'Supabase did not return a signed upload token.'];
    return ['ok'=>true,'token'=>$token,'signed_url'=>$signed];
}

function supabaseStorageUploadObject(string $key, string $filePath, string $mime): bool {
    $safe = storageKey($key);
    if ($safe === '' || !is_file($filePath)) return false;
    $payload = @file_get_contents($filePath);
    if ($payload === false) return false;
    [$status, , $error] = supabaseStorageRequest(
        'POST',
        'object/' . rawurlencode(supabaseStorageBucket()) . '/' . implode('/', array_map('rawurlencode', explode('/', $safe))),
        ['Content-Type'=>$mime, 'x-upsert'=>'false', 'Cache-Control'=>'public, max-age=31536000, immutable'],
        $payload
    );
    if ($status < 200 || $status >= 300) {
        error_log('Supabase Storage upload failed with HTTP '.$status.($error!==''?' '.$error:''));
        return false;
    }
    return !empty(supabaseStorageObjectInfo($safe)['ok']);
}

function supabaseStorageDeleteObject(string $key): bool {
    $safe = storageKey($key);
    if ($safe === '') return false;
    [$status] = supabaseStorageRequest(
        'DELETE',
        'object/' . rawurlencode(supabaseStorageBucket()) . '/' . implode('/', array_map('rawurlencode', explode('/', $safe)))
    );
    return $status === 200 || $status === 204;
}

function supabaseStorageObjectInfo(string $key): array {
    $safe = storageKey($key);
    if ($safe === '') return ['ok'=>false,'error'=>'Invalid key.'];
    [$status, $body, $error] = supabaseStorageRequest(
        'GET',
        'object/info/' . rawurlencode(supabaseStorageBucket()) . '/' . implode('/', array_map('rawurlencode', explode('/', $safe)))
    );
    if ($status !== 200) return ['ok'=>false,'error'=>'Supabase object lookup failed (HTTP '.$status.').'.($error!==''?' '.$error:'')];
    $data = json_decode($body, true);
    if (!is_array($data)) return ['ok'=>false,'error'=>'Invalid Supabase object metadata.'];
    $size = (int)($data['size'] ?? $data['metadata']['size'] ?? 0);
    return ['ok'=>true,'size'=>$size,'mime'=>(string)($data['mimetype'] ?? $data['metadata']['mimetype'] ?? $data['contentType'] ?? '')];
}

function supabaseStorageMoveObject(string $sourceKey, string $destinationKey): bool {
    $source = storageKey($sourceKey); $destination = storageKey($destinationKey);
    if ($source === '' || $destination === '') return false;
    [$status, $body] = supabaseStorageRequest(
        'POST',
        'object/move',
        ['Content-Type'=>'application/json'],
        json_encode([
            'bucketId'=>supabaseStorageBucket(),
            'sourceKey'=>$source,
            'destinationKey'=>$destination,
        ], JSON_UNESCAPED_SLASHES)
    );
    return $status >= 200 && $status < 300 && $body !== '';
}

function adoptDirectUpload(string $stagingReference, string $kind, string $folder, string $originalName = ''): string {
    $staging = storageKey($stagingReference);
    if ($staging === '' || !str_starts_with($staging, 'staging/') || !in_array($kind, ['image','audio','video','pdf','word'], true)) return '';
    if (!storageFolderIsAllowed($folder)) return '';
    $info = supabaseStorageObjectInfo($staging);
    if (empty($info['ok'])) return '';

    $ext = strtolower(pathinfo($staging, PATHINFO_EXTENSION));
    $allowed = [
        'image'=>['jpg','jpeg','png','gif','webp'],
        'audio'=>['mp3','ogg','wav','m4a'],
        'video'=>['mp4','webm','mov','mkv'],
        'pdf'=>['pdf'],
        'word'=>['doc','docx'],
    ];
    if (!in_array($ext, $allowed[$kind], true)) return '';
    $mime = (string)($info['mime'] ?? '');
    if ($mime === '') $mime = match($kind) {
        'image'=>'image/jpeg','audio'=>'audio/mpeg','video'=>'video/mp4','pdf'=>'application/pdf','word'=>'application/octet-stream'
    };

    $finalKey = $folder . '/' . bin2hex(random_bytes(20)) . '.' . $ext;
    if (!supabaseStorageMoveObject($staging, $finalKey)) return '';
    $url = storageUrl($finalKey);
    try {
        getDB()->prepare('INSERT INTO stored_files (file_key,url,mime,size) VALUES (?,?,?,?)')->execute([$finalKey,$url,$mime,(int)$info['size']]);
        getDB()->prepare('DELETE FROM pending_uploads WHERE reference=?')->execute([storageUrl($staging)]);
        $scope =& contentUploadScope();
        if ($scope['active']) $scope['completed'][] = $url;
        return $url;
    } catch (Throwable $e) {
        // The final object is now unreferenced; queue it for the ordinary storage GC.
        try { getDB()->prepare('INSERT INTO storage_deletions (reference) VALUES (?) ON CONFLICT DO NOTHING')->execute([$url]); } catch (Throwable) {}
        return '';
    }
}

function jhdDirectUploadPaths(string $field): array {
    $all = $_POST['jhd_direct'] ?? [];
    $values = is_array($all) ? ($all[$field] ?? []) : [];
    if (!is_array($values)) $values = [$values];
    $out = [];
    foreach ($values as $value) {
        if (!is_string($value)) continue;
        $key = storageKey($value);
        if ($key !== '' && str_starts_with($key, 'staging/')) $out[] = $key;
    }
    return array_values(array_unique($out));
}

function jhdDirectUploadPath(string $field): string {
    return jhdDirectUploadPaths($field)[0] ?? '';
}

function storageUrl(string $key): string {
    $safe = storageKey($key);
    if (!$safe || $key !== $safe) throw new InvalidArgumentException('Invalid storage key');
    if (storageDriver() === 'supabase') {
        return supabaseStoragePublicBaseUrl() . '/' . implode('/', array_map('rawurlencode', explode('/', $safe)));
    }
    return UPLOAD_BASE_URL . '/' . implode('/', array_map('rawurlencode', explode('/', $safe)));
}

/**
 * The persistent storage backend that is actually usable in this deployment.
 *
 * `local` is only durable on a classic host (InfinityFree, a VPS with Apache).
 * On Vercel the filesystem is read-only apart from an ephemeral `/tmp`, so a
 * local write would vanish with the function instance; when a usable Vercel
 * Blob store is connected the blob backend is selected automatically,
 * otherwise the caller gets an explicit "no persistent storage" failure
 * instead of a file that silently disappears.
 */
function storageDriver(): string {
    $configured = strtolower(trim(env_value('UPLOAD_STORAGE', 'local')));
    if (in_array($configured, ['supabase','s3','vercel-blob','blob'], true)) {
        return $configured === 'blob' ? 'vercel-blob' : $configured;
    }
    if (env_value('VERCEL') !== '') {
        if (strtolower(trim(env_value('APP_ENV'))) === 'production' || trim(env_value('SUPABASE_URL')) !== '') {
            if (trim(env_value('SUPABASE_URL')) !== '' && trim(env_value('SUPABASE_SERVICE_ROLE_KEY')) !== '') return 'supabase';
        }
        // Never report local storage on Vercel: the filesystem is ephemeral and
        // a local-looking status hides a missing platform credential.
        return blobIsUsable() ? 'vercel-blob' : 'vercel-unconfigured';
    }
    return 'local';
}

/**
 * Credentials Vercel injects when a Blob store is connected to the project.
 *
 * Two shapes exist and both must keep working:
 *   • `BLOB_READ_WRITE_TOKEN` — the long-lived static token. Its own value
 *     carries the store id (`vercel_blob_rw_<storeId>_<secret>`).
 *   • `VERCEL_OIDC_TOKEN` + `BLOB_STORE_ID` — the short-lived OIDC token that
 *     current Vercel projects get by default. Sending the store id header is
 *     mandatory with this shape, so a token alone is not enough.
 *
 * Never log or render the returned token; only the store id (an identifier)
 * and the credential kind may be displayed to an administrator.
 */
function blobCredentials(): array {
    // `BLOB_STORE_ID` may be written as `store_<id>` or as the bare id; the API
    // header always expects the bare form (the SDK normalises the same way).
    $storeId = preg_replace('~^store_~', '', trim(env_value('BLOB_STORE_ID')));
    $token   = trim(env_value('BLOB_READ_WRITE_TOKEN'));
    if ($token !== '') {
        if ($storeId === '' && preg_match('~^vercel_blob_rw_([A-Za-z0-9_-]+)_~', $token, $match) === 1) {
            $storeId = $match[1];
        }
        return ['token' => $token, 'store_id' => (string)$storeId, 'kind' => 'read-write'];
    }
    $oidc = trim(env_value('VERCEL_OIDC_TOKEN'));
    if ($oidc !== '') return ['token' => $oidc, 'store_id' => (string)$storeId, 'kind' => 'oidc'];
    return ['token' => '', 'store_id' => (string)$storeId, 'kind' => 'none'];
}

/** Is the connected Blob store callable with the credentials present here? */
function blobIsUsable(): bool {
    $credentials = blobCredentials();
    if ($credentials['token'] === '') return false;
    // An OIDC request without the store id header is rejected by the API.
    return $credentials['kind'] === 'read-write' || $credentials['store_id'] !== '';
}

/** Blob store REST endpoint (documented, stable; overridable for testing). */
function blobEndpoint(): string {
    return rtrim(env_value('BLOB_API_BASE', 'https://blob.vercel-storage.com'), '/');
}

/**
 * Must stored media be served over https?
 *
 * Yes in production and on Vercel — a media URL that downgrades to http is
 * blocked by the browser and breaks every card image. A development or test
 * environment may legitimately talk to a local simulator over plain http, so
 * the rule is derived from the environment and never hard-coded.
 */
function storageRequiresHttps(): bool {
    return APP_ENV === 'production' || env_value('VERCEL') !== '';
}

/** Accept a store URL: https always, http only where the environment allows it. */
function storageUrlSchemeAllowed(string $url): bool {
    if ($url === '') return false;
    if (str_starts_with($url, 'https://')) return true;
    return !storageRequiresHttps() && str_starts_with($url, 'http://');
}

/** Blob API version. Matches the current @vercel/blob SDK default. */
function blobApiVersion(): string {
    $version = trim(env_value('BLOB_API_VERSION'));
    return $version !== '' ? $version : '12';
}

/**
 * One signed Blob API call. Returns [httpStatus, body]. Failures never throw:
 * the caller decides what a non-2xx answer means for this upload.
 */
function blobRequest(string $method, string $url, array $headers, ?string $body = null): array {
    $credentials = blobCredentials();
    $token = $credentials['token'];
    if ($token === '') return [0, ''];
    $handle = curl_init($url);
    $lines = [
        'authorization: Bearer ' . $token,
        'x-api-version: ' . blobApiVersion(),
    ];
    // Required with OIDC tokens; harmless (and correct) with a read-write token.
    if ($credentials['store_id'] !== '') $lines[] = 'x-vercel-blob-store-id: ' . $credentials['store_id'];
    foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($response === false) return [0, $error];
    return [$status, (string)$response];
}

/**
 * Upload one object to the Blob store and return its public URL.
 * `x-add-random-suffix: 0` keeps the URL equal to `UPLOAD_BASE_URL . '/' . key`,
 * which is what storageUrl()/storageKey() assume for the whole application.
 */
function blobPutObject(string $key, string $path, string $mime, string $kind): string {
    $payload = @file_get_contents($path);
    if ($payload === false) return '';
    [$status, $body] = blobRequest('PUT', blobEndpoint() . '/' . implode('/', array_map('rawurlencode', explode('/', $key))), [
        // `x-vercel-blob-access` is the current header; `access` is the legacy
        // spelling still accepted by the API. Both carry the same value.
        'x-vercel-blob-access' => 'public',
        'access' => 'public',
        'content-type' => $mime,
        'x-content-type' => $mime,
        'x-add-random-suffix' => '0',
        'x-cache-control-max-age' => '31536000',
    ], $payload);
    if ($status !== 200 && $status !== 201) {
        error_log('Blob upload failed with HTTP ' . $status);
        return '';
    }
    $decoded = json_decode($body, true);
    $url = is_array($decoded) ? (string)($decoded['url'] ?? '') : '';
    if ($url === '' && $body !== '' && preg_match('~^https?://~', $body) === 1) $url = $body;
    if (!storageUrlSchemeAllowed($url)) return '';
    if (in_array($kind, ['pdf', 'word'], true)) {
        // Documents are offered as downloads; the token URL adds ?download=1.
        return $url;
    }
    return $url;
}

/**
 * Delete objects from the Blob store.
 *
 * The REST contract is `POST /delete` with a JSON body `{ "urls": [...] }` —
 * not a DELETE against a path. A wrong contract here silently leaves orphaned
 * objects behind whenever an editor replaces or removes media.
 */
function blobDeleteObject(string $url): bool {
    if (!storageUrlSchemeAllowed($url)) return false;
    [$status] = blobRequest('POST', blobEndpoint() . '/delete', [
        'content-type' => 'application/json',
        'x-content-type' => 'application/json',
    ], json_encode(['urls' => [$url]], JSON_UNESCAPED_SLASHES));
    return $status === 200 || $status === 204;
}

/**
 * Public origin of the connected Blob store, as reported by the store itself.
 *
 * Returns '' when it cannot be determined (no credentials, or a brand-new
 * empty store with nothing to list yet). Used only to tell the administrator
 * the exact value `UPLOAD_BASE_URL` must have — never as a stored setting.
 */
function blobPublicOrigin(): string {
    if (!blobIsUsable()) return '';
    [$status, $body] = blobRequest('GET', blobEndpoint() . '/?limit=1', []);
    if ($status !== 200) return '';
    $decoded = json_decode($body, true);
    $first = is_array($decoded) ? (string)($decoded['blobs'][0]['url'] ?? '') : '';
    if (!storageUrlSchemeAllowed($first)) return '';
    return (string)preg_replace('~^https?://([^/]+).*$~', '$1', $first);
}

/** Is the object publicly reachable? A store that cannot be read back is not usable. */
function storageUrlIsPublic(string $url): bool {
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return $status === 200;
}

/**
 * Machine- and human-readable state of the persistent storage configuration.
 *
 * Never contains a secret value — only the *names* of variables that are
 * missing, so it is safe to render on /admin/diagnostics.
 *
 * @return array{driver:string,ok:bool,problems:list<string>,limits:array<string,string>}
 */
function storageConfigurationStatus(): array {
    $driver = storageDriver();
    $problems = [];

    if ($driver === 'supabase') {
        foreach (['SUPABASE_URL','SUPABASE_SERVICE_ROLE_KEY','SUPABASE_STORAGE_BUCKET'] as $variable) {
            if (env_value($variable) === '') $problems[] = "$variable تنظیم نشده است.";
        }
        if ($problems === [] && !str_starts_with(trim(env_value('SUPABASE_URL')), 'https://')) {
            $problems[] = 'SUPABASE_URL باید با https:// شروع شود.';
        }
    } elseif ($driver === 's3') {
        foreach (['S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY'] as $variable) {
            if (env_value($variable) === '') $problems[] = "$variable تنظیم نشده است.";
        }
        if (env_value('S3_ENDPOINT') !== '' && !str_starts_with(env_value('S3_ENDPOINT'), 'https://')) {
            $problems[] = 'S3_ENDPOINT باید با https:// شروع شود.';
        }
    } elseif ($driver === 'vercel-blob') {
        $credentials = blobCredentials();
        if ($credentials['kind'] === 'none') {
            $problems[] = 'هیچ اعتبارنامه‌ای برای Blob یافت نشد (BLOB_READ_WRITE_TOKEN یا VERCEL_OIDC_TOKEN). در داشبورد Vercel یک Blob Store به پروژه متصل کنید تا خودکار تزریق شوند.';
        } elseif ($credentials['kind'] === 'oidc' && $credentials['store_id'] === '') {
            $problems[] = 'BLOB_STORE_ID تنظیم نشده است؛ استفاده از VERCEL_OIDC_TOKEN بدون شناسهٔ فروشگاه پذیرفته نمی‌شود.';
        }
    } elseif ($driver === 'vercel-unconfigured') {
        $problems[] = 'هیچ اعتبارنامهٔ قابل استفاده‌ای برای Blob در Runtime فعلی پیدا نشد. Vercel Blob باید BLOB_READ_WRITE_TOKEN را تزریق کند؛ در الگوی OIDC نیز VERCEL_OIDC_TOKEN به‌همراه BLOB_STORE_ID لازم است. اتصال Storage را برای همین پروژه و Production بررسی کنید.';
    } elseif (env_value('VERCEL') !== '') {
        $credentials = blobCredentials();
        if ($credentials['kind'] === 'oidc' && $credentials['store_id'] === '') {
            // Nearly configured: the store is connected (an OIDC token exists)
            // but the store id is missing, so the API would reject every call.
            $problems[] = 'اتصال Blob ناقص است: VERCEL_OIDC_TOKEN وجود دارد ولی BLOB_STORE_ID تنظیم نشده است. در بخش Storage پروژهٔ Vercel، فروشگاه را دوباره به پروژه متصل کنید (یا UPLOAD_STORAGE=vercel-blob را همراه BLOB_STORE_ID تنظیم کنید).';
        } else {
            $problems[] = 'روی Vercel فایل‌سیستم فقط‌خواندنی و /tmp موقتی است؛ UPLOAD_STORAGE=s3 یا یک Vercel Blob Store لازم است.';
        }
    }

    // HTTPS is mandatory in production (and on Vercel). A development or test
    // environment may legitimately point at a local simulator over http.
    if ($driver !== 'local' && storageRequiresHttps() && !str_starts_with(UPLOAD_BASE_URL, 'https://')) {
        $problems[] = 'UPLOAD_BASE_URL باید نشانی https عمومی فضای ذخیره‌سازی باشد (مقدار فعلی: ' . UPLOAD_BASE_URL . ').';
    }

    $onVercel = env_value('VERCEL') !== '';
    $bodyCap = $onVercel ? '4.5MB (سقف غیرقابل تغییر پلتفرم Vercel)' : ini_get('post_max_size');
    $credentials = blobCredentials();
    return [
        'driver' => $driver,
        'ok' => $problems === [],
        'problems' => $problems,
        'limits' => [
            'public_base_url' => $driver === 'supabase' ? supabaseStoragePublicBaseUrl() : UPLOAD_BASE_URL,
            'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            'post_max_size' => (string)ini_get('post_max_size'),
            'max_file_uploads' => (string)ini_get('max_file_uploads'),
            'request_body_cap' => $bodyCap,
            'app_image_limit' => (string)MAX_FILE_SIZE,
            'app_video_limit' => (string)MAX_VIDEO_SIZE,
            'temp_dir_writable' => is_writable(sys_get_temp_dir()) ? 'yes' : 'no',
            // Without GD the stored image is the original validated upload
            // (no re-encode, no downscale); uploads keep working either way.
            'image_reencoding' => storageHasImageLibrary()
                ? 'فعال (WebP/PNG بازکدگذاری و کوچک‌سازی)'
                : 'غیرفعال — افزونهٔ GD روی این میزبان در دسترس نیست؛ تصویر پس از بررسی نوع واقعی همان‌گونه ذخیره می‌شود.',
        ],
        // Identifiers only: never a token, and never rendered anywhere public.
        'blob' => [
            'auth' => $credentials['kind'],
            'store_id' => $credentials['store_id'],
            'usable' => blobIsUsable(),
            'suggested_base_url' => $driver === 'vercel-blob' && !str_starts_with(UPLOAD_BASE_URL, 'https://')
                ? blobPublicOrigin()
                : '',
        ],
    ];
}

/** Is the image re-encoding pipeline available? (Vercel's PHP runtime ships without GD.) */
function storageHasImageLibrary(): bool {
    return function_exists('imagecreatefromstring') && (function_exists('imagewebp') || function_exists('imagepng'));
}

/** Editor-facing explanation of why an upload could not be stored ('' = storage is fine). */
function storageFailureHint(): string {
    $status = storageConfigurationStatus();
    $log =& storageFailureLog();
    $reasons = [];
    if (!$status['ok']) $reasons = array_slice($status['problems'], 0, 2);
    if ($log) $reasons[] = (string)end($log);
    $reasons = array_slice(array_values(array_filter(array_unique($reasons))), 0, 2);
    if (!$reasons) return '';
    return ' دلیل ذخیره‌نشدن فایل: ' . implode(' | ', $reasons);
}

/** Request-scoped record of storage failures, so editors see the real cause. */
function &storageFailureLog(): array {
    static $log = [];
    return $log;
}
function storageClient(): \Aws\S3\S3Client {
    static $client;
    if (!is_file(__DIR__ . '/../vendor/autoload.php')) throw new RuntimeException('Run composer install.');
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!str_starts_with(env_value('S3_ENDPOINT'), 'https://') || !str_starts_with(UPLOAD_BASE_URL, 'https://')) throw new RuntimeException('S3 and public storage require HTTPS.');
    return $client ??= new \Aws\S3\S3Client([
        'version'=>'latest', 'region'=>env_value('S3_REGION', 'auto'),
        'endpoint'=>env_value('S3_ENDPOINT'),
        'use_path_style_endpoint'=>env_value('S3_PATH_STYLE', 'true') === 'true',
        'credentials'=>['key'=>env_value('S3_ACCESS_KEY_ID'), 'secret'=>env_value('S3_SECRET_ACCESS_KEY')],
        'http'=>['connect_timeout'=>10, 'timeout'=>60],
    ]);
}
function validateUpload(string $path, string $kind): ?array {
    $maps = [
        'image'=>['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'],
        'audio'=>['audio/mpeg'=>'mp3','audio/ogg'=>'ogg','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a'],
        'video'=>['video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogg','video/quicktime'=>'mov','video/x-matroska'=>'mkv'],
        'pdf'=>['application/pdf'=>'pdf'],
        'word'=>['application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx'],
    ];
    if (!isset($maps[$kind]) || !is_file($path)) return null;
    $size = filesize($path);
    if (!$size || $size > ($kind === 'video' ? MAX_VIDEO_SIZE : MAX_FILE_SIZE)) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    // ZIP containers must be genuine DOCX, not arbitrary archives or macro-enabled files.
    if ($kind === 'word' && in_array($mime, ['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return null;
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
        for ($i=0; $i<$zip->numFiles; $i++) {
            if (preg_match('~(?:vbaProject|\.exe$|\.php$|\.js$|\.bin$)~i', $zip->getNameIndex($i))) $valid = false;
        }
        $zip->close();
        if (!$valid) return null;
        $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    if (!isset($maps[$kind][$mime])) return null;
    if ($kind === 'image') {
        $info = @getimagesize($path);
        if (!$info || $info[0]*$info[1] > 20000000) return null;
    }
    if ($kind === 'pdf') {
        $f=fopen($path, 'rb'); $magic=fread($f,5); fclose($f);
        if ($magic !== '%PDF-') return null;
    }
    return ['mime'=>$mime,'extension'=>$maps[$kind][$mime],'size'=>$size];
}
function storeValidatedFile(string $path, string $kind, string $folder): string {
    // Folders are generated from entity IDs by contentStorageFolder().  Nested
    // folders are accepted only after strict segment/root validation; client
    // file names never participate in this path.
    if (!storageFolderIsAllowed($folder)) return '';
    $driver = storageDriver();
    if (in_array($driver, ['local', 'vercel-unconfigured'], true) && env_value('VERCEL') !== '') {
        // Never pretend an ephemeral /tmp write is a stored file: it disappears
        // with the function instance and would leave a dead URL in the database.
        $log =& storageFailureLog();
        $log[] = 'روی Vercel فضای ذخیره‌سازی پایدار پیکربندی نشده است. اتصال Vercel Blob یا S3 را تنظیم کنید.';
        return '';
    }
    $info = validateUpload($path, $kind);
    if (!$info) return '';
    $temporary = null;
    try {
        // Decode/re-encode images: remove metadata and trailing executable/polyglot data.
        // The Vercel PHP runtime has no GD, so this is best-effort: when the
        // image library is missing the original bytes are stored instead of
        // rejecting the upload. validateUpload() has already proven the real
        // MIME type (finfo) and that the bytes really decode as an image.
        if ($kind === 'image' && storageHasImageLibrary()) {
            $raw = @file_get_contents($path);
            $image = $raw === false ? false : @imagecreatefromstring($raw);
            if (!$image) return '';
            // Cap the stored resolution. A phone photo is often 4000px wide, but
            // the widest slot in the design system is ~1600px, so anything above
            // IMAGE_MAX_EDGE is pure bandwidth: it slows every card grid, every
            // listing page and mobile browsing as the archive grows.
            $maxEdge = (int)(env_value('IMAGE_MAX_EDGE') ?: 2000);
            $srcW = imagesx($image);
            $srcH = imagesy($image);
            if ($maxEdge >= 320 && ($srcW > $maxEdge || $srcH > $maxEdge)) {
                $newW = $srcW >= $srcH ? $maxEdge : (int)max(1, round($srcW * $maxEdge / $srcH));
                $newH = $srcW >= $srcH ? (int)max(1, round($srcH * $maxEdge / $srcW)) : $maxEdge;
                $scaled = @imagescale($image, $newW, $newH, IMG_BICUBIC);
                if ($scaled) { unset($image); $image = $scaled; }
            }
            $temporary = tempnam(sys_get_temp_dir(), 'jhd-');
            $webp = function_exists('imagewebp');
            // Keep PNG transparency intact when WebP is unavailable.
            if (!$webp) { imagealphablending($image, false); imagesavealpha($image, true); }
            $encoded = $webp ? imagewebp($image, $temporary, 85) : imagepng($image, $temporary, 8);
            // PHP 8.5 deprecates imagedestroy(); dropping the reference lets
            // the GdImage object release its native resources automatically.
            unset($image);
            if (!$encoded || !filesize($temporary)) return '';
            $path = $temporary; $info = ['mime'=>$webp ? 'image/webp' : 'image/png','extension'=>$webp ? 'webp' : 'png','size'=>filesize($path)];
        }
        $key = $folder . '/' . bin2hex(random_bytes(20)) . '.' . $info['extension'];
        $url = storageUrl($key);
        $scope =& contentUploadScope();
        if ($scope['active']) {
            // Write ahead of physical storage. A crash/timeout retains a durable job.
            // Give in-flight requests a full day before a background worker may act.
            uploadJournalDB()->prepare("INSERT INTO pending_uploads (reference,not_before) VALUES (?,NOW()+INTERVAL '24 hours') ON CONFLICT DO NOTHING")->execute([$url]);
        }
        if ($driver === 's3') {
            storageClient()->putObject([
                'Bucket'=>env_value('S3_BUCKET'),'Key'=>$key,'SourceFile'=>$path,
                'ContentType'=>$info['mime'], 'CacheControl'=>'public,max-age=31536000,immutable',
                'ContentDisposition'=>in_array($kind, ['pdf','word'], true) ? 'attachment' : 'inline',
            ]);
            storageClient()->headObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
            if (!storageUrlIsPublic($url)) {
                storageClient()->deleteObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
                throw new RuntimeException('Public storage URL is not accessible.');
            }
        } elseif ($driver === 'vercel-blob') {
            $stored = blobPutObject($key, $path, $info['mime'], $kind);
            if ($stored === '') {
                $credentials = blobCredentials();
                $suffix = $credentials['kind'] === 'oidc'
                    ? 'اعتبارنامهٔ OIDC پذیرفته نشد (اتصال Store به پروژه و متغیر BLOB_STORE_ID را بررسی کنید).'
                    : 'BLOB_READ_WRITE_TOKEN پذیرفته نشد (در تنظیمات پروژهٔ Vercel بررسی شود).';
                throw new RuntimeException('Vercel Blob فایل را نپذیرفت. ' . $suffix);
            }
            if (rtrim($stored, '/') !== rtrim($url, '/')) {
                // The public URL must stay derivable from the key, otherwise the
                // deletion/registry paths (storageKey) cannot resolve the object.
                blobDeleteObject($stored);
                $origin = (string)preg_replace('~^https?://([^/]+).*$~', '$1', $stored);
                throw new RuntimeException(
                    'UPLOAD_BASE_URL باید دقیقاً برابر مبدأ Blob Store باشد. مقدار لازم: ' . $origin
                    . ' (مقدار فعلی: ' . UPLOAD_BASE_URL . ')'
                );
            }
            if (!storageUrlIsPublic($url)) {
                blobDeleteObject($url);
                throw new RuntimeException('نشانی عمومی فایل ذخیره‌شده در دسترس نیست.');
            }
        } elseif ($driver === 'supabase') {
            if (!supabaseStorageUploadObject($key, $path, $info['mime'])) {
                throw new RuntimeException('Supabase Storage فایل را نپذیرفت.');
            }
            if (!storageUrlIsPublic($url)) {
                supabaseStorageDeleteObject($key);
                throw new RuntimeException('نشانی عمومی فایل Supabase در دسترس نیست.');
            }
        } elseif ($driver === 'local') {
            // Local disk is durable on classic/shared hosts (e.g. InfinityFree);
            // Vercel's ephemeral filesystem never reaches this branch.
            $dir = UPLOAD_DIR . $folder;
            if (!is_dir($dir) && !mkdir($dir,0755,true)) return '';
            if (!copy($path, UPLOAD_DIR . $key)) return '';
            chmod(UPLOAD_DIR . $key,0644);
        } else throw new RuntimeException('Persistent S3 storage required in production.');
        try {
            getDB()->prepare('INSERT INTO stored_files (file_key,url,mime,size) VALUES (?,?,?,?)')->execute([$key,$url,$info['mime'],$info['size']]);
        } catch (Throwable $e) {
            if ($driver === 's3') storageClient()->deleteObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
            elseif ($driver === 'vercel-blob') blobDeleteObject($url);
            elseif ($driver === 'supabase') supabaseStorageDeleteObject($key);
            else @unlink(UPLOAD_DIR . $key);
            throw $e;
        }
        if ($scope['active']) $scope['completed'][] = $url;
        return $url;
    } catch (Throwable $e) {
        error_log('Upload failed: ' . get_class($e) . ' — ' . $e->getMessage());
        $log =& storageFailureLog();
        $log[] = $e->getMessage();
        return '';
    } finally { if ($temporary && is_file($temporary)) unlink($temporary); }
}
function uploadFile(array $file, string $kind, string $folder): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return '';
    return storeValidatedFile($file['tmp_name'], $kind, $folder);
}
function deleteStoredFile(string $reference): bool {
    $key = storageKey($reference);
    if (!$key) return false;
    $driver = storageDriver();
    if ($driver === 's3') {
        storageClient()->deleteObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
    } elseif ($driver === 'vercel-blob') {
        $blobUrl = storageUrlSchemeAllowed($reference) ? $reference : storageUrl($key);
        if (!blobDeleteObject($blobUrl)) return false;
    } elseif ($driver === 'supabase') {
        if (!supabaseStorageDeleteObject($key)) return false;
    } elseif ($driver === 'local') {
        $path = realpath(UPLOAD_DIR . $key);
        $base = realpath(UPLOAD_DIR);
        if ($path && (!$base || !str_starts_with($path, $base . '/') || !is_file($path))) return false;
        if ($path && !unlink($path)) return false;
    } else return false;
    getDB()->prepare('DELETE FROM stored_files WHERE file_key=?')->execute([$key]);
    return true;
}

/** Registry deletion must not invalidate files still used by content or the site logo. */
function storedFileIsReferenced(string $reference): bool {
    $key=storageKey($reference);
    if(!$key) return true;
    $values=array_values(array_unique([$reference,$key,'uploads/'.$key,BASE_PATH.'/uploads/'.$key,storageUrl($key)]));
    $ph=implode(',',array_fill(0,count($values),'?'));
    $checks=[];$params=[];
    foreach([
        'posts'=>['featured_image','featured_video'],
        'lessons'=>['featured_image','audio_file','video_file','pdf_file'],
        'books'=>['cover_image','pdf_file','word_file'],
        'topics'=>['cover_image'], 'lesson_collections'=>['cover_image'],
        'featured_banners'=>['image'],
        'post_images'=>['image_path'], 'media_files'=>['file_path'], 'settings'=>['value'],
    ] as $table=>$columns) {
        $where=[];
        foreach($columns as $column){$where[]="$column IN ($ph)";$params=array_merge($params,$values);}
        $checks[]='EXISTS (SELECT 1 FROM '.$table.' WHERE '.implode(' OR ',$where).')';
    }
    $stmt=getDB()->prepare('SELECT '.implode(' OR ',$checks));$stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}
/** An unsuccessful edit leaves the old reference intact, so the worker cancels its deletion. */
function scheduleFileDeletion(string $reference): bool {
    if(!storageKey($reference)) return false;
    getDB()->prepare('INSERT INTO storage_deletions (reference) VALUES (?) ON CONFLICT DO NOTHING')->execute([$reference]);
    static $registered=false;
    if(!$registered){
        $registered=true;
        register_shutdown_function(function():void{
            try {
                if(!getDB()->inTransaction()) {
                    require_once __DIR__.'/content-delete.php';
                    processStorageDeletions();
                }
            } catch(Throwable $e){error_log('Storage cleanup queued for retry.');}
        });
    }
    return true;
}
