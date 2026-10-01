<?php
/**
 * admin/posts/media-manage.php — نقطهٔ پایانی JSON برای مدیریت رسانهٔ مطلب
 *
 * مسیر: /admin/content/media (از config/routes.php)
 * متد: POST با CSRF. عملیات‌ها:
 *   reorder        → تغییر ترتیب ویدیو/صوت/مستندات
 *   rename         → تغییر عنوان نمایشی رسانه
 *   delete         → حذف رسانه (دیتابیس + دیسک)
 *   set_featured   → انتخاب یک ویدیو به‌عنوان «ویدیو شاخص» مطلب
 *   clear_featured → حذف ویدیو شاخص
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
if (!isLoggedIn()) $fail('برای این عملیات باید وارد پنل مدیریت شوید.', 403);
if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    $fail('نشست شما منقضی شده است؛ صفحه را دوباره بارگذاری کنید.', 403);
}

$postId = (int)($_POST['post_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));

// شناسهٔ واقعی همیشه پیش از پیوست رسانه تأمین می‌شود (هم‌رفتار با گالری).
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
$ownerRow = $owner->fetch();
if (!$ownerRow) $fail('مطلب مورد نظر یافت نشد.', 404);
$postType = (string)($ownerRow['post_type'] ?? 'article');

/** وضعیت تازهٔ رسانه‌های یک نوع — پایهٔ همهٔ پاسخ‌ها. */
$mediaState = static function (int $postId, string $kind): array {
    $featured = $kind === 'video'
        ? array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedVideos($postId))
        : [];
    return array_values(array_map(static fn(array $row): array => [
        'id'       => (int)$row['id'],
        'path'     => imgUrl((string)$row['file_path']),
        'raw'      => (string)$row['file_path'],
        'title'    => (string)($row['title'] ?? ''),
        'sort'     => (int)($row['sort_order'] ?? 0),
        'featured' => in_array((int)$row['id'], $featured, true),
    ], array_filter(getMediaFor('post', $postId, $kind), static fn(array $row): bool => (int)($row['sort_order'] ?? 0) !== -1000000)));
};

try {
    switch ($action) {
        case 'reorder':
            $kind = strtolower(trim((string)($_POST['kind'] ?? '')));
            $order = $_POST['order'] ?? [];
            if (!in_array($kind, ['audio', 'video', 'document'], true)) $fail('نوع رسانه نامعتبر است.');
            if (!is_array($order) || !$order) $fail('فهرست ترتیب ارسال نشده است.');
            if (!setMediaOrder($postId, $order, $kind)) $fail('ترتیب نامعتبر است؛ فقط رسانه‌های همین مطلب و به‌صورت کامل.');
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'rename':
            $mediaId = (int)($_POST['media_id'] ?? 0);
            $title = (string)($_POST['title'] ?? '');
            if (!renameMediaFile($mediaId, $postId, $title)) $fail('تغییر عنوان رسانه انجام نشد.');
            echo json_encode(['ok' => true, 'title' => mb_substr(trim($title), 0, 280)], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            $mediaId = (int)($_POST['media_id'] ?? 0);
            if (!deleteMediaFile($mediaId, 'post', $postId)) $fail('حذف رسانه انجام نشد.');
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'set_featured':
            // سازگاری با رفتار قبلی: همین ویدیو «نخستین ویدیوی شاخص» می‌شود.
            $mediaId = (int)($_POST['media_id'] ?? 0);
            $others = array_filter(
                array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedVideos($postId)),
                static fn(int $id): bool => $id !== $mediaId && $id > 0
            );
            $result = setPostFeaturedVideos($postId, array_merge([$mediaId], $others));
            if (empty($result['ok'])) $fail((string)($result['error'] ?? 'انتخاب ویدیو شاخص انجام نشد.'));
            echo json_encode(['ok' => true, 'videos' => $mediaState($postId, 'video'), 'featured_ids' => $result['ids'] ?? []], JSON_UNESCAPED_UNICODE);
            break;

        case 'feature':
        case 'unfeature':
            $mediaId = (int)($_POST['media_id'] ?? 0);
            $result = togglePostFeaturedVideo($postId, $mediaId, $action === 'feature');
            if (empty($result['ok'])) $fail((string)($result['error'] ?? 'تغییر وضعیت ویدیوی شاخص انجام نشد.'));
            echo json_encode(['ok' => true, 'videos' => $mediaState($postId, 'video'), 'featured_ids' => $result['ids'] ?? []], JSON_UNESCAPED_UNICODE);
            break;

        case 'set_featured_videos':
            $list = $_POST['featured'] ?? [];
            if (!is_array($list)) $list = $list === '' ? [] : [$list];
            $result = setPostFeaturedVideos($postId, $list);
            if (empty($result['ok'])) $fail((string)($result['error'] ?? 'ثبت ویدیوهای شاخص انجام نشد.'));
            echo json_encode(['ok' => true, 'videos' => $mediaState($postId, 'video'), 'featured_ids' => $result['ids'] ?? []], JSON_UNESCAPED_UNICODE);
            break;

        case 'clear_featured':
            setPostFeaturedVideos($postId, []);
            clearPostFeaturedVideo($postId);
            echo json_encode(['ok' => true, 'videos' => $mediaState($postId, 'video')], JSON_UNESCAPED_UNICODE);
            break;

        case 'add':
            // آپلود چندتایی بدون نیاز به ذخیرهٔ فرم؛ همان استک اعتبارسنجی فرم.
            $kind = strtolower(trim((string)($_POST['kind'] ?? '')));
            if (!in_array($kind, ['audio', 'video', 'document'], true)) $fail('نوع رسانه نامعتبر است.');
            $field = $_FILES['files'] ?? null;
            if (!is_array($field)) $fail('فایلی برای افزودن ارسال نشده است.');
            beginContentUploadScope();
            $upload = handleMediaUploads('post', $postId, $field, $kind, ['post_type' => $postType]);
            if (!empty($upload['errors'])) {
                requireMediaUploadsSafely($upload);
                $fail(implode(' ', array_slice($upload['errors'], 0, 3)), 422);
            }
            if (!empty($_POST['feature_added']) && $kind === 'video') {
                $ids = array_map(static fn(array $row): int => (int)$row['id'], $mediaState($postId, 'video'));
                setPostFeaturedVideos($postId, $ids);
            }
            echo json_encode([
                'ok' => true,
                'added' => (int)($upload['uploaded'] ?? 0),
                'items' => $mediaState($postId, $kind),
                'post_id' => $postId,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'state':
            $kind = strtolower(trim((string)($_POST['kind'] ?? 'video')));
            if (!in_array($kind, ['audio', 'video', 'document'], true)) $kind = 'video';
            echo json_encode(['ok' => true, 'post_id' => $postId, 'items' => $mediaState($postId, $kind)], JSON_UNESCAPED_UNICODE);
            break;

        default:
            $fail('عملیات نامعتبر است.', 404);
    }
} catch (Throwable $e) {
    error_log('media endpoint failed: ' . get_class($e));
    $fail('خطای غیرمنتظره در مدیریت رسانه.', 500);
}
