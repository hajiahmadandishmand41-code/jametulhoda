<?php
/**
 * admin/posts/gallery.php — نقطهٔ پایانی JSON برای مدیریت گالری تصاویر مطلب
 *
 * مسیرها: /admin/content/gallery (اجرا از config/routes.php)
 * متد: POST با توکن CSRF همان فرم ویرایش مطلب.
 * عملیات‌ها: reorder | set_primary | delete | alt | replace
 * خروجی: JSON — هر پاسخ کد وضعیت HTTP درست دارد و پیام فارسی می‌دهد.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/media.php';
require_once __DIR__ . '/../../includes/post-gallery.php';
require_once __DIR__ . '/../../includes/content-draft.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$fail = static function (string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $fail('فقط درخواست POST پذیرفته می‌شود.', 405);
}
if (!isLoggedIn()) {
    $fail('برای این عملیات باید وارد پنل مدیریت شوید.', 403);
}
if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    $fail('نشست شما منقضی شده است؛ صفحه را دوباره بارگذاری کنید.', 403);
}

$postId = (int)($_POST['post_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));

// ── ۱) شناسهٔ واقعی، پیش از هر عملیات رسانه‌ای ──────────────────────────────
// صفحهٔ «مطلب جدید» هنوز رکوردی ندارد؛ به‌جای رد کردن درخواست، همان‌جا یک
// پیش‌نویس واقعی ساخته می‌شود تا رسانه‌ها هرگز بدون مالک نمانند.
// پیش‌نویس خودکار فقط وقتی ساخته می‌شود که فراخوان صریحاً بخواهد
// (action=ensure_draft یا allow_draft=1 از فرم «مطلب جدید»). هر درخواست دیگری
// با شناسهٔ نامعتبر، مثل قبل، پاسخ خطای روشن می‌گیرد — نه رکورد ناخواسته.
$mayCreateDraft = $action === 'ensure_draft' || !empty($_POST['allow_draft']);
if ($mayCreateDraft) {
    $draft = jhd_ensure_auto_draft((string)($_POST['post_type'] ?? 'article'), $postId);
    if (empty($draft['ok'])) $fail((string)($draft['error'] ?? 'ایجاد پیش‌نویس اولیه انجام نشد.'), 409);
    $postId = (int)$draft['post_id'];
    if ($action === 'ensure_draft') {
        echo json_encode(['ok' => true, 'post_id' => $postId, 'created' => !empty($draft['created'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if ($postId < 1) $fail('شناسهٔ مطلب در این درخواست معتبر نیست؛ برای دریافت نسخهٔ به‌روز، صفحهٔ ویرایش را یک‌بار رفرش کنید.');

$db = getDB();
$owner = $db->prepare('SELECT id, post_type FROM posts WHERE id = ? LIMIT 1');
$owner->execute([$postId]);
$post = $owner->fetch();
if (!$post) $fail('مطلب مورد نظر یافت نشد.', 404);
$entity = in_array((string)$post['post_type'], ['report', 'article', 'research'], true) ? (string)$post['post_type'] : 'post';

/** وضعیت کامل و تازهٔ گالری — پایهٔ همهٔ پاسخ‌ها تا رابط کاربری هرگز کهنه نشود. */
$galleryState = static function (int $postId): array {
    $featured = array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedImages($postId));
    return array_values(array_map(static fn(array $row): array => [
        'id'       => (int)$row['id'],
        'path'     => imgUrl((string)$row['image_path']),
        'raw'      => (string)$row['image_path'],
        'alt'      => (string)($row['alt_text'] ?? ''),
        'sort'     => (int)($row['sort_order'] ?? 0),
        'featured' => in_array((int)$row['id'], $featured, true),
    ], getPostImages($postId)));
};

try {
    switch ($action) {
        case 'add':
            // Accept a single file (`image`) or a batch (`images[]`) exactly
            // like the create/edit forms do, so every upload path shares one
            // validation + storage stack.
            $batch = $_FILES['images'] ?? null;
            // Normalize PHP's multidimensional $_FILES (images[] → sub-arrays)
            // into a flat list of per-slot file records.
            $slots = null;
            if (is_array($batch) && array_is_list($batch) && isset($batch[0]['name'])) {
                $slots = $batch; // already normalized (single-file form)
            } elseif (is_array($batch) && isset($batch['name'])) {
                $names = is_array($batch['name']) ? $batch['name'] : [$batch['name']];
                $slots = [];
                foreach (array_keys($names) as $key) {
                    $slots[$key] = [
                        'name'     => (string)($batch['name'][$key] ?? ''),
                        'type'     => (string)($batch['type'][$key] ?? ''),
                        'tmp_name' => (string)($batch['tmp_name'][$key] ?? ''),
                        'error'    => (int)($batch['error'][$key] ?? UPLOAD_ERR_NO_FILE),
                        'size'     => (int)($batch['size'][$key] ?? 0),
                    ];
                }
            }
            if ($slots === null && is_array($_FILES['image'] ?? null)) {
                $single = $_FILES['image'];
                $slots = ['0' => ['name' => (string)$single['name'], 'type' => (string)($single['type'] ?? ''), 'tmp_name' => (string)$single['tmp_name'], 'error' => (int)($single['error'] ?? UPLOAD_ERR_NO_FILE), 'size' => (int)($single['size'] ?? 0)]];
            }
            if (empty($slots)) $fail('فایلی برای افزودن به گالری ارسال نشده است.');
            $alts = is_array($_POST['alts'] ?? null) ? $_POST['alts'] : [];
            $added = 0;
            $addedIds = [];
            $errors = [];
            foreach ($slots as $key => $entry) {
                if (trim($entry['name']) === '' && $entry['error'] === UPLOAD_ERR_NO_FILE) continue; // untouched empty slot
                $file = [
                    'name'     => trim($entry['name']),
                    'type'     => $entry['type'],
                    'tmp_name' => $entry['tmp_name'],
                    'error'    => $entry['error'],
                    'size'     => $entry['size'],
                ];
                if ($file['error'] !== UPLOAD_ERR_OK || $file['name'] === '') {
                    $errors[] = jhd_upload_error_message($file['error']);
                    continue;
                }
                if (!is_uploaded_file($file['tmp_name'])) {
                    $errors[] = 'یکی از تصاویر به‌درستی از مرورگر دریافت نشد.';
                    continue;
                }
                $imgPath = uploadContentImage($file, $entity, $postId);
                if ($imgPath === '') {
                    $errors[] = 'یکی از تصاویر معتبر نیست (فرمت JPG/PNG/GIF/WebP و حداکثر ۲۰ مگابایت).';
                    continue;
                }
                try {
                    // افزودن بدون امکان رکورد تکراری؛ پرچم شاخص جداگانه تعیین می‌شود.
                    $addedIds[] = addPostImageUnique($postId, $imgPath, (string)($alts[$key] ?? ''), false);
                    $added++;
                } catch (Throwable) {
                    scheduleFileDeletion($imgPath);
                    $errors[] = 'ثبت تصویر در گالری انجام نشد.';
                }
            }
            // Batch upload is all-or-nothing; no partial gallery survives a rejected file.
            if ($errors) {
                foreach ($addedIds as $addedId) deletePostImage($postId, (int)$addedId);
                $added = 0;
            }
            $images = $galleryState($postId);
            if (!$errors && !empty($_POST['feature_added'])) {
                // «افزودن به‌عنوان رسانهٔ شاخص» در یک رفت‌وبرگشت.
                $featuredNow = array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedImages($postId));
                setPostFeaturedImages($postId, array_merge($featuredNow, array_map('intval', $addedIds)));
                $images = $galleryState($postId);
            }
            if ($errors) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => implode(' ', array_slice(array_unique($errors), 0, 3)), 'images' => $images, 'added' => $added], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['ok' => true, 'images' => $images, 'added' => $added], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'reorder':
            $order = $_POST['order'] ?? [];
            if (!is_array($order) || !$order) $fail('فهرست ترتیب ارسال نشده است.');
            if (!setPostImagesOrder($postId, $order)) $fail('ترتیب نامعتبر است؛ فقط تصاویر همین مطلب و به‌صورت کامل.');
            $images = $galleryState($postId);
            echo json_encode(['ok' => true, 'images' => $images], JSON_UNESCAPED_UNICODE);
            break;

        case 'set_primary':
            $imageId = (int)($_POST['image_id'] ?? 0);
            if (!setPostPrimaryImage($postId, $imageId)) $fail('تصویر موردنظر در گالری این مطلب یافت نشد.', 404);
            // تصویر اصلی همان «نخستین تصویر شاخص» است تا هر دو مسیر یک معنی بدهند.
            $existingFeatured = array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedImages($postId));
            setPostFeaturedImages($postId, array_merge([$imageId], array_filter($existingFeatured, static fn(int $id): bool => $id !== $imageId)));
            $featured = $db->prepare('SELECT featured_image FROM posts WHERE id = ?');
            $featured->execute([$postId]);
            echo json_encode(['ok' => true, 'featured_image' => imgUrl((string)$featured->fetchColumn())], JSON_UNESCAPED_UNICODE);
            break;

        case 'alt':
            $imageId = (int)($_POST['image_id'] ?? 0);
            $alt = (string)($_POST['alt'] ?? '');
            if (!updatePostImageAlt($postId, $imageId, $alt)) $fail('ویرایش توضیح تصویر انجام نشد.');
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            $imageId = (int)($_POST['image_id'] ?? 0);
            $result = deletePostImage($postId, $imageId);
            if (empty($result['ok'])) $fail((string)($result['error'] ?? 'حذف تصویر انجام نشد.'));
            $images = $galleryState($postId);
            echo json_encode(['ok' => true, 'images' => $images], JSON_UNESCAPED_UNICODE);
            break;

        case 'replace':
            $imageId = (int)($_POST['image_id'] ?? 0);
            $file = $_FILES['image'] ?? null;
            if (!is_array($file)) $fail('فایلی ارسال نشده است.');
            $result = replacePostImage($postId, $imageId, $file, $entity);
            if (empty($result['ok'])) $fail((string)($result['error'] ?? 'جایگزینی تصویر انجام نشد.'));
            echo json_encode(['ok' => true, 'path' => imgUrl((string)($result['path'] ?? ''))], JSON_UNESCAPED_UNICODE);
            break;

        case 'feature':
        case 'unfeature':
            $imageId = (int)($_POST['image_id'] ?? 0);
            $result = togglePostFeaturedImage($postId, $imageId, $action === 'feature');
            if (empty($result['ok'])) $fail((string)($result['error'] ?? 'تغییر وضعیت تصویر شاخص انجام نشد.'));
            $featured = $db->prepare('SELECT featured_image FROM posts WHERE id = ?');
            $featured->execute([$postId]);
            echo json_encode([
                'ok' => true,
                'images' => $galleryState($postId),
                'featured_image' => imgUrl((string)$featured->fetchColumn()),
                'featured_ids' => $result['ids'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'set_featured_images':
            $list = $_POST['featured'] ?? [];
            if (!is_array($list)) $list = $list === '' ? [] : [$list];
            $result = setPostFeaturedImages($postId, $list);
            if (empty($result['ok'])) $fail((string)($result['error'] ?? 'ثبت تصاویر شاخص انجام نشد.'));
            $featured = $db->prepare('SELECT featured_image FROM posts WHERE id = ?');
            $featured->execute([$postId]);
            echo json_encode([
                'ok' => true,
                'images' => $galleryState($postId),
                'featured_image' => imgUrl((string)$featured->fetchColumn()),
                'featured_ids' => $result['ids'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'state':
            $featured = $db->prepare('SELECT featured_image FROM posts WHERE id = ?');
            $featured->execute([$postId]);
            echo json_encode([
                'ok' => true,
                'post_id' => $postId,
                'images' => $galleryState($postId),
                'featured_image' => imgUrl((string)$featured->fetchColumn()),
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            $fail('عملیات نامعتبر است.', 404);
    }
} catch (Throwable $e) {
    error_log('gallery endpoint failed: ' . get_class($e));
    $fail($e instanceof RuntimeException ? $e->getMessage() : 'خطای غیرمنتظره در مدیریت گالری.', 500);
}
