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
    if (str_starts_with($value, UPLOAD_BASE_URL . '/')) $value = substr($value, strlen(UPLOAD_BASE_URL) + 1);
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
    $folders = ['posts','reports','articles','research','lessons','books','book-covers','topics','site','media','images','documents','avatars','banners',

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
    if ($entity === 'lesson') return 'lessons/' . $id;
    $prefix = match ($entity) {
        'report' => 'report-',
        'article' => 'article-',
        'research' => 'research-',
        'book' => 'book-',
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
    return in_array($root, ['posts','reports','articles','research','lessons','books','book-covers','topics','site','media','images','documents','avatars','banners','audio','video','audios','videos'], true);
}

function storageUrl(string $key): string {
    if (!storageKey($key) || $key !== storageKey($key)) throw new InvalidArgumentException('Invalid storage key');
    return UPLOAD_BASE_URL . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
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
    $info = validateUpload($path, $kind);
    if (!$info) return '';
    $temporary = null;
    try {
        // Decode/re-encode images: remove metadata and trailing executable/polyglot data.
        if ($kind === 'image') {
            $image = @imagecreatefromstring(file_get_contents($path));
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
        if (UPLOAD_STORAGE === 's3') {
            storageClient()->putObject([
                'Bucket'=>env_value('S3_BUCKET'),'Key'=>$key,'SourceFile'=>$path,
                'ContentType'=>$info['mime'], 'CacheControl'=>'public,max-age=31536000,immutable',
                'ContentDisposition'=>in_array($kind, ['pdf','word'], true) ? 'attachment' : 'inline',
            ]);
            storageClient()->headObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
            $head=curl_init($url);
            curl_setopt_array($head,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
            curl_exec($head); $status=curl_getinfo($head,CURLINFO_HTTP_CODE); curl_close($head);
            if($status!==200) {
                storageClient()->deleteObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
                throw new RuntimeException('Public storage URL is not accessible.');
            }
        } elseif (UPLOAD_STORAGE === 'local' && !env_value('VERCEL')) {
            // Local disk is durable on classic/shared hosts (e.g. InfinityFree);
            // only Vercel's ephemeral filesystem is refused here.
            $dir = UPLOAD_DIR . $folder;
            if (!is_dir($dir) && !mkdir($dir,0755,true)) return '';
            if (!copy($path, UPLOAD_DIR . $key)) return '';
            chmod(UPLOAD_DIR . $key,0644);
        } else throw new RuntimeException('Persistent S3 storage required in production.');
        try {
            getDB()->prepare('INSERT INTO stored_files (file_key,url,mime,size) VALUES (?,?,?,?)')->execute([$key,$url,$info['mime'],$info['size']]);
        } catch (Throwable $e) {
            if (UPLOAD_STORAGE === 's3') storageClient()->deleteObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
            else @unlink(UPLOAD_DIR . $key);
            throw $e;
        }
        if ($scope['active']) $scope['completed'][] = $url;
        return $url;
    } catch (Throwable $e) {
        error_log('Upload failed: ' . get_class($e) . ' — ' . $e->getMessage());
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
    if (UPLOAD_STORAGE === 's3') {
        storageClient()->deleteObject(['Bucket'=>env_value('S3_BUCKET'),'Key'=>$key]);
    } elseif (UPLOAD_STORAGE === 'local' && !env_value('VERCEL')) {
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
