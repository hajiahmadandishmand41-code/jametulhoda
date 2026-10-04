<?php
/**
 * media.php — مدیریت فایل‌های رسانه‌ای (صوت + ویدیو) برای پست‌ها
 * این فایل باید بعد از functions.php بارگذاری شود.
 */

/**
 * ایجاد جدول media_files اگر وجود نداشته باشد
 * اگر قبلاً در functions.php تعریف شده باشد، دوباره تعریف نمی‌شود.
 */
if (!function_exists('ensureMediaTable')) {
    function ensureMediaTable(): void {
        // Schema managed by bin/migrate.php.
    }
}

/**
 * دریافت تمام فایل‌های رسانه‌ای برای یک آیتم
 */
function getMediaFor(string $refType, int $refId, string $kind): array {
    ensureMediaTable();
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT m.*, f.size FROM media_files m
             LEFT JOIN stored_files f ON f.url=m.file_path
             WHERE m.ref_type = ? AND m.ref_id = ? AND m.kind = ?
             ORDER BY m.sort_order ASC, m.id ASC"
        );
        $stmt->execute([$refType, $refId, $kind]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Detail-page resolver: one media row by id, only when its kind matches
 * the route (/video/{id} vs /audio/{id}). Parent visibility is checked
 * by the caller (draft parents must not leak media).
 */
function getMediaById(int $id, string $kind): ?array {
    if ($id < 1 || ($kind !== 'video' && $kind !== 'audio')) return null;
    try {
        $stmt = getDB()->prepare('SELECT * FROM media_files WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || ($row['kind'] ?? '') !== $kind) return null;
        return $row;
    } catch (PDOException $e) { return null; }
}

/**
 * آپلود چند فایل صوتی یا ویدیویی و ذخیره در دیتابیس
 */
/**
 * Store a batch of editor attachments and report every supplied-file failure.
 *
 * A completely empty multi-file input is successful (and returns no errors),
 * while transport errors, malformed PHP file arrays, rejected MIME/size checks
 * and database failures are visible to the caller.  This distinction matters:
 * an edit that changes only text must not fail merely because no attachment was
 * selected, but a submitted attachment must never disappear silently.
 *
 * @return array{provided:int,uploaded:int,errors:list<string>,stored:list<array{ref_type:string,ref_id:int,kind:string,path:string>}
 */
function handleMediaUploads(string $refType, int $refId, array $files, string $kind, array $context = []): array {
    ensureMediaTable();
    if ($refId < 1 || !in_array($refType, ['post', 'lesson', 'book'], true) || !in_array($kind, ['audio', 'video', 'document'], true)) {
        throw new InvalidArgumentException('Invalid media upload target.');
    }

    $asList = static function (mixed $value): array {
        return is_array($value) ? $value : [$value];
    };
    $names     = $asList($files['name'] ?? '');
    $errors    = $asList($files['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmpNames  = $asList($files['tmp_name'] ?? '');
    $fileTypes = $asList($files['type'] ?? '');
    $sizes     = $asList($files['size'] ?? 0);
    $result = ['provided' => 0, 'uploaded' => 0, 'errors' => [], 'stored' => []];

    // Large files are removed from multipart by the browser bridge and arrive
    // as signed Supabase staging keys. Infer the matching field kind so every
    // existing controller automatically supports the direct path.
    $direct = $_POST['jhd_direct'] ?? [];
    if (is_array($direct)) {
        $directPaths = [];
        foreach ($direct as $field => $paths) {
            if (!is_string($field)) continue;
            $fieldKind = str_contains($field, 'audio') ? 'audio'
                : (str_contains($field, 'video') ? 'video'
                : ((str_contains($field, 'document') || str_contains($field, 'attachment') || ($field === 'files' && strtolower((string)($_POST['kind'] ?? '')) === 'document')) ? 'document' : ''));
            if ($fieldKind !== $kind) continue;
            $list = is_array($paths) ? $paths : [$paths];
            foreach ($list as $path) if (is_string($path) && $path !== '') $directPaths[] = $path;
        }
        if ($directPaths) {
            $directResult = handleDirectMediaUploads($refType, $refId, array_values(array_unique($directPaths)), $kind, $context);
            $result['provided'] += (int)$directResult['provided'];
            $result['uploaded'] += (int)$directResult['uploaded'];
            $result['errors'] = array_merge($result['errors'], (array)$directResult['errors']);
            $result['stored'] = array_merge($result['stored'], (array)$directResult['stored']);
        }
    }

    $uploadError = static function (int $code): string {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'اندازهٔ فایل از حد مجاز سرور بیشتر است.',
            UPLOAD_ERR_PARTIAL => 'فایل به‌طور کامل دریافت نشد.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت آپلود در دسترس نیست.',
            UPLOAD_ERR_CANT_WRITE => 'ذخیرهٔ فایل روی سرور انجام نشد.',
            UPLOAD_ERR_EXTENSION => 'یک افزونهٔ سرور آپلود فایل را متوقف کرد.',
            default => 'خطای نامشخص هنگام دریافت فایل.',
        };
    };

    $keys = array_values(array_unique(array_merge(array_keys($names), array_keys($errors), array_keys($tmpNames), array_keys($fileTypes), array_keys($sizes))));
    foreach ($keys as $key) {
        $rawName = $names[$key] ?? '';
        $rawError = $errors[$key] ?? UPLOAD_ERR_NO_FILE;
        $rawTmpName = $tmpNames[$key] ?? '';
        $rawSize = $sizes[$key] ?? 0;
        $malformed = !is_string($rawName) || !is_scalar($rawError) || !is_string($rawTmpName) || !is_scalar($rawSize);
        $name = is_string($rawName) ? trim($rawName) : '';
        $error = is_numeric($rawError) ? (int)$rawError : UPLOAD_ERR_NO_FILE;
        $tmpName = is_string($rawTmpName) ? $rawTmpName : '';
        $size = is_numeric($rawSize) ? (int)$rawSize : 0;
        // Native no-file entries are expected for untouched multiple inputs.
        if (!$malformed && $name === '' && $error === UPLOAD_ERR_NO_FILE && $tmpName === '' && $size === 0) continue;

        $result['provided']++;
        $position = $result['provided'];
        if ($malformed) {
            $result['errors'][] = "فایل پیوست شمارهٔ {$position} ساختار معتبر ندارد.";
            continue;
        }
        if ($name === '' || $error === UPLOAD_ERR_NO_FILE) {
            $result['errors'][] = "فایل پیوست شمارهٔ {$position} ساختار معتبر ندارد.";
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $result['errors'][] = "فایل «{$name}»: " . $uploadError($error);
            continue;
        }
        if ($tmpName === '' || $size < 1 || !is_uploaded_file($tmpName)) {
            $result['errors'][] = "فایل «{$name}» به‌درستی از مرورگر دریافت نشد.";
            continue;
        }

        $file = [
            'name'     => $name,
            'type'     => isset($fileTypes[$key]) && is_string($fileTypes[$key]) ? $fileTypes[$key] : '',
            'tmp_name' => $tmpName,
            'error'    => $error,
            'size'     => $size,
        ];
        $entity = $refType === 'post' ? (string)($context['post_type'] ?? 'post') : $refType;
        if (!in_array($entity, ['report', 'article', 'research'], true)) $entity = $refType === 'post' ? 'post' : $entity;
        $path = $kind === 'audio'
            ? uploadContentAudio($file, $entity, $refId, $context)
            : ($kind === 'video'
                ? uploadContentVideo($file, $entity, $refId, $context)
                : uploadContentDocument($file, $entity, $refId, in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['doc', 'docx'], true) ? 'word' : 'pdf', $context));
        if ($path === '') {
            $result['errors'][] = "فایل «{$name}» معتبر نیست یا ذخیره نشد." . storageFailureHint();
            continue;
        }

        try {
            // Original client names are display-only. The stored key is random,
            // so duplicate names can never overwrite one another.
            $title = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 280);
            getDB()->prepare(
                "INSERT INTO media_files (ref_type, ref_id, kind, file_path, title, sort_order, created_at)
                 VALUES (?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order)+1 FROM media_files x WHERE x.ref_type=? AND x.ref_id=? AND x.kind=?), 0), NOW())"
            )->execute([$refType, $refId, $kind, $path, $title, $refType, $refId, $kind]);
            $result['uploaded']++;
            $result['stored'][] = ['ref_type' => $refType, 'ref_id' => $refId, 'kind' => $kind, 'path' => $path];
        } catch (Throwable) {
            scheduleFileDeletion($path);
            $result['errors'][] = "ثبت فایل «{$name}» در آرشیو رسانه انجام نشد.";
        }
    }
    return $result;
}

/**
 * Convert batch failures into one safe, editor-facing error.  When several
 * batches belong to a single save (audio, video, documents), remove any rows
 * that were created before a later batch failed.  The upload scope then cleans
 * their unreferenced physical objects, so a failed edit never leaves a partial
 * attachment set behind.
 */
function requireMediaUploads(array ...$results): void {
    $errors = [];
    foreach ($results as $result) {
        foreach ((array)($result['errors'] ?? []) as $error) if (is_string($error) && $error !== '') $errors[] = $error;
    }
    if (!$errors) return;
    foreach ($results as $result) {
        foreach ((array)($result['stored'] ?? []) as $stored) {
            if (!is_array($stored) || empty($stored['path'])) continue;
            try {
                getDB()->prepare('DELETE FROM media_files WHERE ref_type=? AND ref_id=? AND kind=? AND file_path=?')->execute([
                    (string)($stored['ref_type'] ?? ''), (int)($stored['ref_id'] ?? 0), (string)($stored['kind'] ?? ''), (string)$stored['path'],
                ]);
                scheduleFileDeletion((string)$stored['path']);
            } catch (Throwable) {
                // The pending-upload journal remains the recovery path if the
                // database is unavailable while reporting the original error.
            }
        }
    }
    throw new RuntimeException(implode(' ', array_slice($errors, 0, 3)));
}

/**
 * حذف یک فایل رسانه‌ای از دیتابیس و دیسک
 */
/**
 * Keep a primary audio/video column discoverable in the shared media archive.
 * `sort_order=-1000000` is reserved for that single generated relation so it
 * can be safely replaced without touching editor-added media files.
 */
function syncPrimaryMediaFile(string $refType, int $refId, string $kind, string $path, string $title = ''): void {
    if ($refId < 1 || !in_array($refType, ['post','lesson'], true) || !in_array($kind, ['audio','video'], true)) return;
    $db = getDB();
    if ($path === '') {
        // No primary any more: the generated relation disappears with it.
        $db->prepare('DELETE FROM media_files WHERE ref_type=? AND ref_id=? AND kind=? AND sort_order=-1000000')->execute([$refType,$refId,$kind]);
        return;
    }
    // Only *stale* generated rows are dropped. Deleting and re-inserting the row
    // for the current path would silently destroy its is_featured/featured_order
    // flags (the featured video is usually exactly this row).
    $db->prepare('DELETE FROM media_files WHERE ref_type=? AND ref_id=? AND kind=? AND sort_order=-1000000 AND file_path <> ?')
       ->execute([$refType,$refId,$kind,$path]);
    // A selected gallery video is already archived; never create a second row.
    $existing = $db->prepare('SELECT id FROM media_files WHERE ref_type=? AND ref_id=? AND kind=? AND file_path=? LIMIT 1');
    $existing->execute([$refType, $refId, $kind, $path]);
    $exists = $existing->fetchColumn();
    $existing->closeCursor();
    if ($exists) return;
    $db->prepare('INSERT INTO media_files (ref_type,ref_id,kind,file_path,title,sort_order,created_at) VALUES (?,?,?,?,?,-1000000,NOW())')->execute([$refType,$refId,$kind,$path,mb_substr($title,0,280)]);
}

/** Adopt browser-direct uploads that already exist in the Supabase staging area. */
function handleDirectMediaUploads(string $refType, int $refId, array $paths, string $kind, array $context = []): array {
    ensureMediaTable();
    if ($refId < 1 || !in_array($refType, ['post','lesson','book'], true) || !in_array($kind, ['audio','video','document'], true)) {
        throw new InvalidArgumentException('Invalid direct media upload target.');
    }
    $result = ['provided'=>count($paths),'uploaded'=>0,'errors'=>[],'stored'=>[]];
    if (!$paths) return $result;
    $folderEntity = $refType === 'post' ? ((string)($context['post_type'] ?? 'post')) : $refType;
    $folder = contentStorageFolder($folderEntity, $refId, $context);
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') continue;
        $url = adoptDirectUpload($path, $kind === 'document' ? 'pdf' : $kind, $folder);
        if ($url === '') {
            // A direct Word attachment can arrive here through the document input;
            // infer it from the staging extension when the requested kind is document.
            $ext = strtolower(pathinfo(storageKey($path), PATHINFO_EXTENSION));
            if ($kind === 'document' && in_array($ext,['doc','docx'],true)) {
                $url = adoptDirectUpload($path, 'word', $folder);
            }
        }
        if ($url === '') { $result['errors'][]='یکی از فایل‌های مستقیم در Storage قابل ثبت نبود.'; continue; }
        $title = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        try {
            $stmt = getDB()->prepare('INSERT INTO media_files (ref_type,ref_id,kind,file_path,title,sort_order,created_at)
                 VALUES (?,?,?,?,?,COALESCE((SELECT MAX(sort_order)+1 FROM media_files x WHERE x.ref_type=? AND x.ref_id=? AND x.kind=?),0),NOW()) RETURNING id');
            $stmt->execute([$refType,$refId,$kind,$url,$title,$refType,$refId,$kind]);
            $mediaId=(int)$stmt->fetchColumn(); $stmt->closeCursor();
            if ($mediaId<1) throw new RuntimeException('Media row id was not created.');
            $result['uploaded']++;
            $result['stored'][]=['ref_type'=>$refType,'ref_id'=>$refId,'kind'=>$kind,'path'=>$url];
        } catch (Throwable $e) {
            scheduleFileDeletion($url);
            $result['errors'][]='ثبت متادیتای یکی از فایل‌های مستقیم انجام نشد.';
        }
    }
    return $result;
}

function deleteMediaFile(int $mediaId, string $refType, int $refId): bool {
    if ($mediaId < 1 || $refId < 1 || !in_array($refType, ['post', 'lesson', 'book'], true)) return false;
    try {
        $db = getDB();
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT file_path, kind FROM media_files WHERE id=? AND ref_type=? AND ref_id=?');
        $stmt->execute([$mediaId, $refType, $refId]);
        $row = $stmt->fetch();
        if (!$row) { $db->rollBack(); return false; }
        // Clear a selected primary before removing its gallery entry.
        $column = ($row['kind'] === 'video') ? 'featured_video' : null;
        if ($refType === 'post' && $column) {
            $db->prepare("UPDATE posts SET featured_video=NULL WHERE id=? AND featured_video=?")->execute([$refId, $row['file_path']]);
        }
        if ($refType === 'lesson') {
            $field = match ($row['kind']) { 'audio' => 'audio_file', 'video' => 'video_file', 'document' => 'pdf_file', default => null };
            if ($field) $db->prepare("UPDATE lessons SET $field=NULL WHERE id=? AND $field=?")->execute([$refId, $row['file_path']]);
        }
        $db->prepare('DELETE FROM media_files WHERE id=? AND ref_type=? AND ref_id=?')->execute([$mediaId, $refType, $refId]);
        if (!scheduleFileDeletion((string)$row['file_path'])) throw new RuntimeException('Cannot queue storage cleanup.');
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        return false;
    }
}

/**
 * دریافت تعداد فایل‌های رسانه‌ای یک آیتم
 */

/**
 * نسخهٔ «بدون پرتاب استثنا» از requireMediaUploads برای نقاط پایانی JSON.
 * ردیف‌های ساخته‌شده در همان دسته را برمی‌گرداند و فایل‌ها را به صف حذف
 * می‌فرستد، تا یک آپلود ناموفق هیچ رکورد ناقص یا فایل یتیمی جا نگذارد.
 */
function requireMediaUploadsSafely(array ...$results): void {
    try {
        requireMediaUploads(...$results);
    } catch (Throwable) {
        // پیام خطا را فراخوان (نقطهٔ پایانی JSON) خودش برمی‌گرداند.
    }
}

/**
 * شمارش رسانه‌های یک محتوا به تفکیک نوع — برای نشانه‌های کارت و صفحهٔ مدیریت.
 * (countMediaFor() در functions.php شمارش تک‌عددی می‌دهد؛ این تابع تفکیکی است.)
 *
 * @return array{audio:int,video:int,document:int}
 */
function mediaKindCounts(string $refType, int $refId): array {
    $out = ['audio' => 0, 'video' => 0, 'document' => 0];
    if ($refId < 1) return $out;
    try {
        $stmt = getDB()->prepare(
            'SELECT kind, COUNT(*) AS total FROM media_files WHERE ref_type = ? AND ref_id = ? GROUP BY kind'
        );
        $stmt->execute([$refType, $refId]);
        foreach ($stmt->fetchAll() as $row) {
            $kind = (string)($row['kind'] ?? '');
            if (isset($out[$kind])) $out[$kind] = (int)$row['total'];
        }
    } catch (Throwable) {
        // شمارش نمایشی است؛ نبودنش نباید صفحه را از کار بیندازد.
    }
    return $out;
}
