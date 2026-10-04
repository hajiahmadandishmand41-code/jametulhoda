<?php
/**
 * post-gallery.php — مدیریت گالری تصاویر و رسانهٔ متنی مطلب (حقیقی و متصل به دیتابیس)
 *
 * این لایه بین پنل مدیریت و دیتابیس نشسته و هر عملیات را با تراکنش، بررسی
 * مالکیت رکورد و CSRF (در کنترلرها) انجام می‌دهد:
 *
 *   • getPostImages()            → فهرست مرتب‌شدهٔ تصاویر گالری یک مطلب
 *   • addPostImage()             → افزودن تصویر تازه به گالری
 *   • setPostImagesOrder()       → تغییر ترتیب (Drag & Drop در پنل)
 *   • setPostPrimaryImage()      → انتخاب تصویر اصلی (posts.featured_image)
 *   • updatePostImageAlt()       → ویرایش متن جانشین (alt) تصویر
 *   • deletePostImage()          → حذف تصویر از دیتابیس و زمان‌بندی حذف فایل
 *   • replacePostImage()         → جایگزینی فایل یک تصویر موجود
 *   • setMediaOrder()            → ترتیب ویدیو/صوت/مستندات مطلب
 *   • renameMediaFile()          → تغییر عنوان رسانه
 *   • setPostFeaturedVideo()     → انتخاب ویدیوی شاخص مطلب
 *
 * ستون sort_order جدول post_images در database/database.{postgres,mysql}.sql
 * و tests/fixtures/schema.sqlite.sql تعریف شده است؛ نصبی که هنوز مهاجرت را
 * اجرا نکرده، پیام خطای صریح می‌گیرد (هیچ تغییر جدولی از درخواست وب انجام
 * نمی‌شود — مطابق سیاست schema این پروژه).
 */

if (!function_exists('jhd_upload_error_message')) {
    /** پیام فارسی و مشخص برای خطاهای سطح PHP هنگام آپلود (حجم، موقت، و …). */
    function jhd_upload_error_message(int $code): string {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل از حد مجاز سرور بیشتر است؛ تصویر را کوچک‌تر کنید یا با مدیر hosting سقف آپلود را افزایش دهید.',
            UPLOAD_ERR_PARTIAL => 'فایل به‌طور کامل از مرورگر ارسال نشد؛ دوباره تلاش کنید.',
            UPLOAD_ERR_NO_FILE => 'فایلی انتخاب نشده است.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت آپلود روی سرور در دسترس نیست.',
            UPLOAD_ERR_CANT_WRITE => 'ذخیرهٔ فایل روی دیسک سرور انجام نشد.',
            UPLOAD_ERR_EXTENSION => 'یک افزونهٔ سرور آپلود این فایل را متوقف کرد.',
            default => 'خطای نامشخص هنگام دریافت فایل.',
        };
    }
}

/** آیا ستون sort_order روی post_images موجود است؟ (یک‌بار در هر درخواست) */
function post_images_sort_column_exists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $db = getDB();
        if (databaseDriver() === 'sqlite') {
            $stmt = $db->query("PRAGMA table_info(post_images)");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (($column['name'] ?? '') === 'sort_order') return $exists = true;
            }
            return $exists = false;
        }
        if (databaseDriver() === 'mysql') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_images' AND COLUMN_NAME = 'sort_order'");
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'post_images' AND column_name = 'sort_order'");
        }
        $stmt->execute();
        return $exists = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return $exists = false;
    }
}

/** فهرست مرتب‌شدهٔ تصاویر گالری یک مطلب (قدیمی‌ترین → جدیدترین با ترتیب دستی). */
function getPostImages(int $postId): array {
    if ($postId < 1) return [];
    try {
        $order = post_images_sort_column_exists() ? 'sort_order ASC, id ASC' : 'id ASC';
        $stmt = getDB()->prepare("SELECT * FROM post_images WHERE post_id = ? ORDER BY $order");
        $stmt->execute([$postId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getPostImages failed: ' . get_class($e));
        return [];
    }
}

/** افزودن تصویر به گالری و بازگرداندن شناسهٔ رکورد تازه. */
function addPostImage(int $postId, string $imagePath, string $alt = ''): int {
    if ($postId < 1 || $imagePath === '') return 0;
    if (!post_images_sort_column_exists()) {
        throw new RuntimeException('ستون ترتیب گالری (post_images.sort_order) موجود نیست؛ لطفاً php bin/migrate.php را اجرا کنید.');
    }
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO post_images (post_id, image_path, alt_text, sort_order, created_at)
         VALUES (?, ?, ?, COALESCE((SELECT MAX(sort_order)+1 FROM post_images x WHERE x.post_id = ?), 0), NOW()) RETURNING id"
    );
    $stmt->execute([$postId, $imagePath, $alt !== '' ? mb_substr($alt, 0, 200) : null, $postId]);
    return (int)$stmt->fetchColumn();
}

/**
 * تغییر ترتیب گالری. فقط شناسه‌های متعلق به همین مطلب پذیرفته می‌شوند و
 * فهرست ناقص/نامعتبر باعث خطا می‌شود، نه بی‌صدایی.
 */
function setPostImagesOrder(int $postId, array $orderedIds): bool {
    if ($postId < 1 || !$orderedIds) return false;
    if (!post_images_sort_column_exists()) {
        throw new RuntimeException('ستون ترتیب گالری (post_images.sort_order) موجود نیست؛ لطفاً php bin/migrate.php را اجرا کنید.');
    }
    $ids = array_values(array_filter(array_map('intval', $orderedIds), static fn(int $id): bool => $id > 0));
    if (count($ids) !== count($orderedIds)) return false;
    $db = getDB();
    $owned = $db->prepare('SELECT id FROM post_images WHERE post_id = ?');
    $owned->execute([$postId]);
    $ownedIds = array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN));
    sort($ownedIds);
    $sorted = $ids;
    sort($sorted);
    if ($sorted !== $ownedIds) return false; // هیچ رکورد بی‌مالک یا جاافتاده مجاز نیست

    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE post_images SET sort_order = ? WHERE id = ? AND post_id = ?');
        foreach ($ids as $index => $imageId) $update->execute([$index, $imageId, $postId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** انتخاب یک تصویر از گالری به‌عنوان تصویر اصلی مطلب. */
function setPostPrimaryImage(int $postId, int $imageId): bool {
    if ($postId < 1 || $imageId < 1) return false;
    $db = getDB();
    $stmt = $db->prepare('SELECT image_path FROM post_images WHERE id = ? AND post_id = ?');
    $stmt->execute([$imageId, $postId]);
    $path = $stmt->fetchColumn();
    if (!$path) return false;
    $db->prepare('UPDATE posts SET featured_image = ?, updated_at = NOW() WHERE id = ?')->execute([(string)$path, $postId]);
    return true;
}

/** ویرایش متن جانشین (alt) یک تصویر گالری. */
function updatePostImageAlt(int $postId, int $imageId, string $alt): bool {
    if ($postId < 1 || $imageId < 1) return false;
    $stmt = getDB()->prepare('UPDATE post_images SET alt_text = ? WHERE id = ? AND post_id = ?');
    $stmt->execute([$alt !== '' ? mb_substr(trim($alt), 0, 200) : null, $imageId, $postId]);
    return $stmt->rowCount() > 0;
}

/**
 * حذف تصویر از گالری؛ اگر تصویر شاخص باشد ارجاع شاخص نیز پاک می‌شود.
 */
function deletePostImage(int $postId, int $imageId): array {
    if ($postId < 1 || $imageId < 1) return ['ok' => false, 'error' => 'درخواست نامعتبر است.'];
    $db = getDB();
    $stmt = $db->prepare('SELECT image_path FROM post_images WHERE id = ? AND post_id = ?');
    $stmt->execute([$imageId, $postId]);
    $path = (string)$stmt->fetchColumn();
    if ($path === '') return ['ok' => false, 'error' => 'تصویر در گالری این مطلب یافت نشد.'];

    $post = $db->prepare('SELECT featured_image FROM posts WHERE id = ?');
    $post->execute([$postId]);
    $featured = (string)$post->fetchColumn();

    $db->beginTransaction();
    try {
        if ($path === $featured) {
            $db->prepare('UPDATE posts SET featured_image=NULL, updated_at=NOW() WHERE id=? AND featured_image=?')->execute([$postId, $path]);
        }
        $db->prepare('DELETE FROM post_images WHERE id = ? AND post_id = ?')->execute([$imageId, $postId]);
        // ترتیب‌ها را فشرده می‌کنیم تا گالری بینابیش نداشته باشد.
        if (post_images_sort_column_exists()) {
            $remaining = getPostImages($postId);
            $compact = $db->prepare('UPDATE post_images SET sort_order = ? WHERE id = ? AND post_id = ?');
            foreach ($remaining as $index => $row) $compact->execute([$index, (int)$row['id'], $postId]);
        }
        if (!scheduleFileDeletion($path)) throw new RuntimeException('Cannot queue image cleanup.');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'حذف تصویر از دیتابیس انجام نشد.'];
    }
    return ['ok' => true];
}

/**
 * جایگزینی فایل یک تصویر گالری با فایل تازه (بدون تغییر جایگاه در گالری).
 */
function replacePostImage(int $postId, int $imageId, array $file, string $entity): array {
    if ($postId < 1 || $imageId < 1) return ['ok' => false, 'error' => 'درخواست نامعتبر است.'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['ok' => false, 'error' => jhd_upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))];
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT image_path FROM post_images WHERE id = ? AND post_id = ?');
    $stmt->execute([$imageId, $postId]);
    $oldPath = (string)$stmt->fetchColumn();
    if ($oldPath === '') return ['ok' => false, 'error' => 'تصویر در گالری این مطلب یافت نشد.'];

    $newPath = uploadContentImage($file, $entity, $postId);
    if ($newPath === '') return ['ok' => false, 'error' => 'فایل تازه معتبر نیست یا ذخیره نشد (فرمت JPG/PNG/GIF/WebP و حداکثر ۲۰ مگابایت).'];

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE post_images SET image_path = ? WHERE id = ? AND post_id = ?')->execute([$newPath, $imageId, $postId]);
        // اگر تصویر اصلی به فایل قدیمی اشاره می‌کرد، به فایل تازه ارتقا می‌یابد.
        $post = $db->prepare('SELECT featured_image FROM posts WHERE id = ?');
        $post->execute([$postId]);
        if ((string)$post->fetchColumn() === $oldPath) {
            $db->prepare('UPDATE posts SET featured_image = ? WHERE id = ?')->execute([$newPath, $postId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        scheduleFileDeletion($newPath);
        return ['ok' => false, 'error' => 'جایگزینی تصویر در دیتابیس انجام نشد.'];
    }
    scheduleFileDeletion($oldPath);
    return ['ok' => true, 'path' => $newPath];
}

/** جایگزینی یک تصویر گالری از فایل مستقیم Supabase. */
function replacePostImageDirect(int $postId, int $imageId, string $stagingReference, string $entity): array {
    if ($postId < 1 || $imageId < 1) return ['ok'=>false,'error'=>'درخواست نامعتبر است.'];
    $db = getDB();
    $stmt = $db->prepare('SELECT image_path FROM post_images WHERE id=? AND post_id=?');
    $stmt->execute([$imageId,$postId]);
    $oldPath=(string)$stmt->fetchColumn();
    if ($oldPath==='') return ['ok'=>false,'error'=>'تصویر در گالری این مطلب یافت نشد.'];
    $newPath=adoptDirectUpload($stagingReference,'image',contentStorageFolder($entity,$postId));
    if ($newPath==='') return ['ok'=>false,'error'=>'فایل تازهٔ مستقیم معتبر نیست یا در Storage ثبت نشد.'];
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE post_images SET image_path=? WHERE id=? AND post_id=?')->execute([$newPath,$imageId,$postId]);
        $post=$db->prepare('SELECT featured_image FROM posts WHERE id=?'); $post->execute([$postId]);
        if ((string)$post->fetchColumn()===$oldPath) $db->prepare('UPDATE posts SET featured_image=? WHERE id=?')->execute([$newPath,$postId]);
        $db->commit();
    } catch(Throwable) {
        if($db->inTransaction())$db->rollBack();
        scheduleFileDeletion($newPath);
        return ['ok'=>false,'error'=>'جایگزینی تصویر مستقیم در دیتابیس انجام نشد.'];
    }
    scheduleFileDeletion($oldPath);
    return ['ok'=>true,'path'=>$newPath];
}

/** تغییر ترتیب رسانه‌های یک مطلب (ویدیو/صوت/مستندات). */
function setMediaOrder(int $refId, array $orderedIds, string $kind, string $refType = 'post'): bool {
    if (!in_array($refType, ['post', 'lesson', 'book'], true)) return false;
    if ($refId < 1 || !$orderedIds) return false;
    if (!in_array($kind, ['audio', 'video', 'document'], true)) return false;
    $ids = array_values(array_filter(array_map('intval', $orderedIds), static fn(int $id): bool => $id > 0));
    if (count($ids) !== count($orderedIds)) return false;
    $db = getDB();
    $primaryFilter = $refType === 'lesson' ? ' AND sort_order <> -1000000' : '';
    $owned = $db->prepare('SELECT id FROM media_files WHERE ref_type = ? AND ref_id = ? AND kind = ?' . $primaryFilter);
    $owned->execute([$refType, $refId, $kind]);
    $ownedIds = array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN));
    sort($ownedIds);
    $sorted = $ids;
    sort($sorted);
    if ($sorted !== $ownedIds) return false;

    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE media_files SET sort_order = ? WHERE id = ? AND ref_type = ? AND ref_id = ? AND kind = ? AND sort_order <> -1000000');
        foreach ($ids as $index => $mediaId) $update->execute([$index, $mediaId, $refType, $refId, $kind]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** تغییر عنوان (نام نمایشی) یک رسانهٔ مطلب. */
function renameMediaFile(int $mediaId, int $refId, string $title): bool {
    if ($mediaId < 1 || $refId < 1) return false;
    $title = trim($title);
    if (mb_strlen($title) > 280) $title = mb_substr($title, 0, 280);
    $stmt = getDB()->prepare('UPDATE media_files SET title = ? WHERE id = ? AND ref_type = ? AND ref_id = ?');
    $stmt->execute([$title !== '' ? $title : null, $mediaId, 'post', $refId]);
    return $stmt->rowCount() > 0;
}

/** انتخاب یک ویدیو از رسانه‌های مطلب به‌عنوان «ویدیو شاخص» صفحهٔ مطلب. */
function setPostFeaturedVideo(int $postId, int $mediaId): array {
    if ($postId < 1 || $mediaId < 1) return ['ok' => false, 'error' => 'درخواست نامعتبر است.'];
    $db = getDB();
    $stmt = $db->prepare("SELECT file_path FROM media_files WHERE id = ? AND ref_type = 'post' AND ref_id = ? AND kind = 'video'");
    $stmt->execute([$mediaId, $postId]);
    $path = (string)$stmt->fetchColumn();
    $stmt->closeCursor();
    if ($path === '') return ['ok' => false, 'error' => 'ویدیوی موردنظر در رسانه‌های این مطلب یافت نشد.'];
    // Single-selection API kept for older callers: it now also raises the
    // is_featured flag so the multi-featured layer and the legacy pointer can
    // never disagree (a later sync would otherwise clear the pointer again).
    if (jhd_featured_columns_exist('media_files')) {
        $result = setPostFeaturedVideos($postId, [$mediaId]);
        if (empty($result['ok'])) return $result;
        return ['ok' => true, 'path' => $path];
    }
    $db->prepare('UPDATE posts SET featured_video = ?, updated_at = NOW() WHERE id = ?')->execute([$path, $postId]);
    require_once __DIR__ . '/media.php';
    syncPrimaryMediaFile('post', $postId, 'video', $path);
    return ['ok' => true, 'path' => $path];
}

/** حذف «ویدیو شاخص» بدون حذف فایل از گالری ویدیوها. */
function clearPostFeaturedVideo(int $postId): void {
    if ($postId < 1) return;
    $db = getDB();
    $db->prepare("UPDATE posts SET featured_video = NULL, updated_at = NOW() WHERE id = ?")->execute([$postId]);
    // Clearing is explicit and order-independent: the flags go down with the
    // pointer, so no later synchronisation can resurrect the old selection.
    if (jhd_featured_columns_exist('media_files')) {
        $db->prepare("UPDATE media_files SET is_featured = 0, featured_order = 0 WHERE ref_type = 'post' AND ref_id = ? AND kind = 'video'")
           ->execute([$postId]);
    }
    require_once __DIR__ . '/media.php';
    syncPrimaryMediaFile('post', $postId, 'video', '');
}

/* ══════════════════════════════════════════════════════════════════════════
 * رسانهٔ شاخصِ چندگانه — چند تصویر شاخص و چند ویدیوی شاخص برای هر محتوا
 * ──────────────────────────────────────────────────────────────────────────
 * قاعده‌های ثابت این لایه:
 *   • هیچ رکورد تازه‌ای برای «شاخص کردن» ساخته نمی‌شود؛ فقط پرچم is_featured
 *     روی همان ردیف گالری/آرشیو رسانه روشن می‌شود ⇒ رکورد تکراری غیرممکن است.
 *   • posts.featured_image و posts.featured_video همیشه با نخستین رسانهٔ
 *     شاخص هم‌گام می‌شوند تا تمام کارت‌ها و صفحه‌های موجود بدون تغییر کار کنند.
 *   • هر عملیات فقط روی رکوردهای متعلق به همان مطلب اثر دارد (بررسی مالکیت).
 * ═══════════════════════════════════════════════════════════════════════════ */

/** آیا ستون‌های رسانهٔ شاخص روی جدول موجودند؟ (یک‌بار در هر درخواست) */
function jhd_featured_columns_exist(string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    if (!in_array($table, ['post_images', 'media_files'], true)) return $cache[$table] = false;
    try {
        $db = getDB();
        $driver = databaseDriver();
        if ($driver === 'sqlite') {
            foreach ($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (($column['name'] ?? '') === 'is_featured') return $cache[$table] = true;
            }
            return $cache[$table] = false;
        }
        $sql = $driver === 'mysql'
            ? "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'is_featured'"
            : "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = ? AND column_name = 'is_featured'";
        $stmt = $db->prepare($sql);
        $stmt->execute([$table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

/** تصاویر شاخص یک مطلب، به ترتیب انتخاب مدیر. */
function getPostFeaturedImages(int $postId): array {
    if ($postId < 1) return [];
    if (!jhd_featured_columns_exist('post_images')) {
        // نصب قدیمی: تصویر شاخصِ تکی همچنان کار می‌کند.
        try {
            $stmt = getDB()->prepare('SELECT featured_image FROM posts WHERE id = ?');
            $stmt->execute([$postId]);
            $path = (string)$stmt->fetchColumn();
            return $path !== '' ? [['id' => 0, 'image_path' => $path, 'alt_text' => '', 'sort_order' => 0]] : [];
        } catch (Throwable) { return []; }
    }
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM post_images WHERE post_id = ? AND is_featured = 1 ORDER BY featured_order ASC, sort_order ASC, id ASC'
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getPostFeaturedImages failed: ' . get_class($e));
        return [];
    }
}

/** ویدیوهای شاخص یک مطلب، به ترتیب انتخاب مدیر. */
function getPostFeaturedVideos(int $postId): array {
    if ($postId < 1) return [];
    if (!jhd_featured_columns_exist('media_files')) {
        try {
            $stmt = getDB()->prepare('SELECT featured_video FROM posts WHERE id = ?');
            $stmt->execute([$postId]);
            $path = (string)$stmt->fetchColumn();
            return $path !== '' ? [['id' => 0, 'file_path' => $path, 'title' => '', 'kind' => 'video']] : [];
        } catch (Throwable) { return []; }
    }
    try {
        $stmt = getDB()->prepare(
            "SELECT * FROM media_files WHERE ref_type = 'post' AND ref_id = ? AND kind = 'video' AND is_featured = 1
             ORDER BY featured_order ASC, sort_order ASC, id ASC"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('getPostFeaturedVideos failed: ' . get_class($e));
        return [];
    }
}

/**
 * هم‌گام‌سازی اشاره‌گرهای قدیمی (posts.featured_image / featured_video) با
 * نخستین رسانهٔ شاخص. پس از هر تغییرِ شاخص فراخوانی می‌شود تا کارت‌ها، فید
 * و متادیتای اشتراک‌گذاری همان لحظه درست باشند.
 */
function syncPostFeaturedPointers(int $postId): void {
    if ($postId < 1) return;
    $db = getDB();
    if (jhd_featured_columns_exist('post_images')) {
        $images = getPostFeaturedImages($postId);
        $primary = $images[0]['image_path'] ?? null;
        if ($primary === null) {
            // بدون تصویر شاخصِ صریح، نخستین تصویر گالری معیار است (رفتار قبلی).
            $gallery = getPostImages($postId);
            $primary = $gallery[0]['image_path'] ?? null;
        }
        $db->prepare('UPDATE posts SET featured_image = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$primary !== null && $primary !== '' ? $primary : null, $postId]);
    }
    if (jhd_featured_columns_exist('media_files')) {
        $videos = getPostFeaturedVideos($postId);
        if (!$videos) {
            // Content published before the featured columns existed keeps its
            // pointer: adopt that row instead of dropping the video silently.
            $legacy = $db->prepare("SELECT m.id FROM media_files m JOIN posts p ON p.id = m.ref_id
                                    WHERE m.ref_type = 'post' AND m.ref_id = ? AND m.kind = 'video'
                                      AND p.featured_video IS NOT NULL AND p.featured_video <> ''
                                      AND m.file_path = p.featured_video LIMIT 1");
            $legacy->execute([$postId]);
            $legacyId = (int)$legacy->fetchColumn();
            $legacy->closeCursor();
            if ($legacyId > 0) {
                $db->prepare("UPDATE media_files SET is_featured = 1, featured_order = 0 WHERE id = ?")->execute([$legacyId]);
                $videos = getPostFeaturedVideos($postId);
            }
        }
        $primaryVideo = $videos[0]['file_path'] ?? null;
        $db->prepare('UPDATE posts SET featured_video = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$primaryVideo !== null && $primaryVideo !== '' ? $primaryVideo : null, $postId]);
        require_once __DIR__ . '/media.php';
        syncPrimaryMediaFile('post', $postId, 'video', (string)($primaryVideo ?? ''));
    }
}

/**
 * انتخاب مجموعهٔ تصاویر شاخص (چندتایی و مرتب).
 * فهرست خالی یعنی «هیچ تصویر شاخصی» — خطا نیست.
 */
function setPostFeaturedImages(int $postId, array $imageIds): array {
    if ($postId < 1) return ['ok' => false, 'error' => 'شناسهٔ مطلب معتبر نیست.'];
    if (!jhd_featured_columns_exist('post_images')) {
        return ['ok' => false, 'error' => 'ستون رسانهٔ شاخص هنوز ساخته نشده است؛ لطفاً php bin/migrate.php را اجرا کنید.'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn(int $id): bool => $id > 0)));
    $db = getDB();
    $owned = $db->prepare('SELECT id FROM post_images WHERE post_id = ?');
    $owned->execute([$postId]);
    $ownedIds = array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ids as $id) {
        if (!in_array($id, $ownedIds, true)) return ['ok' => false, 'error' => 'یکی از تصاویر انتخاب‌شده متعلق به این مطلب نیست.'];
    }
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE post_images SET is_featured = 0, featured_order = 0 WHERE post_id = ?')->execute([$postId]);
        $mark = $db->prepare('UPDATE post_images SET is_featured = 1, featured_order = ? WHERE id = ? AND post_id = ?');
        foreach ($ids as $index => $id) $mark->execute([$index, $id, $postId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('setPostFeaturedImages failed: ' . get_class($e));
        return ['ok' => false, 'error' => 'ثبت تصاویر شاخص انجام نشد.'];
    }
    syncPostFeaturedPointers($postId);
    return ['ok' => true, 'ids' => $ids];
}

/** روشن/خاموش کردن پرچم شاخص برای یک تصویر (بدون به‌هم‌ریختن بقیه). */
function togglePostFeaturedImage(int $postId, int $imageId, bool $featured): array {
    $current = array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedImages($postId));
    $current = array_values(array_filter($current, static fn(int $id): bool => $id > 0));
    if ($featured) {
        if (!in_array($imageId, $current, true)) $current[] = $imageId;
    } else {
        $current = array_values(array_filter($current, static fn(int $id): bool => $id !== $imageId));
    }
    return setPostFeaturedImages($postId, $current);
}

/** انتخاب مجموعهٔ ویدیوهای شاخص (چندتایی و مرتب). */
function setPostFeaturedVideos(int $postId, array $mediaIds): array {
    if ($postId < 1) return ['ok' => false, 'error' => 'شناسهٔ مطلب معتبر نیست.'];
    if (!jhd_featured_columns_exist('media_files')) {
        return ['ok' => false, 'error' => 'ستون رسانهٔ شاخص هنوز ساخته نشده است؛ لطفاً php bin/migrate.php را اجرا کنید.'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn(int $id): bool => $id > 0)));
    $db = getDB();
    $owned = $db->prepare("SELECT id FROM media_files WHERE ref_type = 'post' AND ref_id = ? AND kind = 'video'");
    $owned->execute([$postId]);
    $ownedIds = array_map('intval', $owned->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ids as $id) {
        if (!in_array($id, $ownedIds, true)) return ['ok' => false, 'error' => 'یکی از ویدیوهای انتخاب‌شده متعلق به این مطلب نیست.'];
    }
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE media_files SET is_featured = 0, featured_order = 0 WHERE ref_type = 'post' AND ref_id = ? AND kind = 'video'")->execute([$postId]);
        $mark = $db->prepare("UPDATE media_files SET is_featured = 1, featured_order = ? WHERE id = ? AND ref_type = 'post' AND ref_id = ?");
        foreach ($ids as $index => $id) $mark->execute([$index, $id, $postId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('setPostFeaturedVideos failed: ' . get_class($e));
        return ['ok' => false, 'error' => 'ثبت ویدیوهای شاخص انجام نشد.'];
    }
    syncPostFeaturedPointers($postId);
    return ['ok' => true, 'ids' => $ids];
}

/** روشن/خاموش کردن پرچم شاخص برای یک ویدیو. */
function togglePostFeaturedVideo(int $postId, int $mediaId, bool $featured): array {
    $current = array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedVideos($postId));
    $current = array_values(array_filter($current, static fn(int $id): bool => $id > 0));
    if ($featured) {
        if (!in_array($mediaId, $current, true)) $current[] = $mediaId;
    } else {
        $current = array_values(array_filter($current, static fn(int $id): bool => $id !== $mediaId));
    }
    return setPostFeaturedVideos($postId, $current);
}

/**
 * ثبت یک تصویر در گالری با جلوگیری از رکورد تکراری.
 * اگر همان مسیر قبلاً برای همین مطلب ثبت شده باشد، شناسهٔ موجود برمی‌گردد.
 */
function addPostImageUnique(int $postId, string $imagePath, string $alt = '', bool $featured = false): int {
    if ($postId < 1 || $imagePath === '') return 0;
    $stmt = getDB()->prepare('SELECT id FROM post_images WHERE post_id = ? AND image_path = ? LIMIT 1');
    $stmt->execute([$postId, $imagePath]);
    $existing = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $imageId = $existing > 0 ? $existing : addPostImage($postId, $imagePath, $alt);
    if ($featured && $imageId > 0) togglePostFeaturedImage($postId, $imageId, true);
    return $imageId;
}

/**
 * نمای واحد «رسانهٔ شاخص» برای صفحهٔ محتوا: تصاویر + ویدیوها در یک فهرست
 * مرتب، آمادهٔ نمایش در اسلایدر/نگارخانهٔ بالای مطلب.
 *
 * @return list<array{type:string,src:string,alt:string,poster:string,id:int}>
 */
function getPostFeaturedMedia(int $postId): array {
    $out = [];
    foreach (getPostFeaturedImages($postId) as $image) {
        $path = (string)($image['image_path'] ?? '');
        if ($path === '') continue;
        $out[] = ['type' => 'image', 'id' => (int)($image['id'] ?? 0), 'src' => $path, 'alt' => (string)($image['alt_text'] ?? ''), 'poster' => ''];
    }
    foreach (getPostFeaturedVideos($postId) as $video) {
        $path = (string)($video['file_path'] ?? '');
        if ($path === '') continue;
        $out[] = ['type' => 'video', 'id' => (int)($video['id'] ?? 0), 'src' => $path, 'alt' => (string)($video['title'] ?? ''), 'poster' => ''];
    }
    return $out;
}
