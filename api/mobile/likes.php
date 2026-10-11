<?php
/**
 * api/mobile/likes.php — لایک محتوا (عضو واردشده)
 *
 * طراحی ضد جعل و ضد تکرار:
 *   • جدول content_likes قید یکتایی (user_id, ref_type, ref_id) دارد؛ ثبت تکراری
 *     حتی با درخواست هم‌زمان، یک ردیف می‌سازد.
 *   • شمارنده همیشه از COUNT(*) دیتابیس می‌آید؛ هیچ شمارندهٔ سمت کلاینت پذیرفته
 *     نمی‌شود و هیچ فیلد like_count در POST خوانده نمی‌شود.
 *   • like/unlike عملیات idempotent است: تکرار آن‌ها خطا نمی‌سازد و شماره را
 *     دستکاری نمی‌کند.
 *   • فقط محتوای منتشرشده قابل لایک است؛ نقش‌ها رعایت می‌شود و کاربر غیرفعال
 *     نمی‌تواند عملیات انجام دهد.
 */
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' || $method === 'HEAD') {
    $refType = (string)($_GET['ref_type'] ?? '');
    $refId = jhd_api_int($_GET, 'ref_id', 0);
    if (!in_array($refType, JHD_API_LIKE_TYPES, true) || $refId < 1) {
        jhd_api_error('invalid_request', 'نوع و شناسهٔ محتوا لازم است.', 422);
    }
    if (!jhd_api_content_exists($refType, $refId)) {
        jhd_api_error('not_found', 'محتوای درخواستی پیدا نشد.', 404);
    }
    $viewer = jhd_api_user(); // اختیاری: ناشناس فقط شمارنده را می‌بیند
    jhd_api_data([
        'ref_type' => $refType,
        'ref_id' => $refId,
        'like_count' => jhd_api_like_count($refType, $refId),
        'liked' => jhd_api_viewer_liked($refType, $refId, $viewer),
    ]);
}

if ($method === 'POST') {
    jhd_api_method(['POST']);
    $user = jhd_api_user(true);
    $body = jhd_api_body();
    $refType = jhd_api_str($body, 'ref_type', 20);
    $refId = jhd_api_int($body, 'ref_id', 0);
    $action = jhd_api_str($body, 'action', 10);
    if (!in_array($refType, JHD_API_LIKE_TYPES, true) || $refId < 1) {
        jhd_api_error('invalid_request', 'نوع و شناسهٔ محتوا لازم است.', 422);
    }
    if (!in_array($action, ['like', 'unlike'], true)) {
        jhd_api_error('invalid_action', 'عملیات like یا unlike مجاز است.', 422);
    }
    if (!jhd_api_content_exists($refType, $refId)) {
        jhd_api_error('not_found', 'محتوای درخواستی پیدا نشد.', 404);
    }
    // محدودیت نرم نرخ عملیات هر کاربر؛ حفاظت در برابر اسپم دکمه.
    try {
        $db = getDB();
        $key = hash('sha256', 'like:' . (int)$user['id']);
        if (recordLoginAttempt($db, $key) > 120) {
            jhd_api_error('rate_limited', 'تعداد عملیات بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 429);
        }
    } catch (Throwable $e) {
        error_log('Like limiter unavailable: ' . get_class($e));
    }

    try {
        $db = getDB();
        if ($action === 'like') {
            // Idempotent: درج تکراری با قید یکتایی بی‌اثر است (بدون شمارش دوباره).
            if (databaseDriver() === 'mysql') {
                $db->prepare('INSERT IGNORE INTO content_likes (user_id, ref_type, ref_id) VALUES (?, ?, ?)')
                    ->execute([(int)$user['id'], $refType, $refId]);
            } elseif (databaseDriver() === 'sqlite') {
                $db->prepare('INSERT OR IGNORE INTO content_likes (user_id, ref_type, ref_id) VALUES (?, ?, ?)')
                    ->execute([(int)$user['id'], $refType, $refId]);
            } else {
                $db->prepare('INSERT INTO content_likes (user_id, ref_type, ref_id) VALUES (?, ?, ?) ON CONFLICT (user_id, ref_type, ref_id) DO NOTHING')
                    ->execute([(int)$user['id'], $refType, $refId]);
            }
        } else {
            $db->prepare('DELETE FROM content_likes WHERE user_id = ? AND ref_type = ? AND ref_id = ?')
                ->execute([(int)$user['id'], $refType, $refId]);
        }
    } catch (Throwable $e) {
        error_log('Like write failed: ' . get_class($e));
        jhd_api_error('unavailable', 'ثبت لایک در این لحظه ممکن نیست. بعداً دوباره تلاش کنید.', 503);
    }

    jhd_api_data([
        'ref_type' => $refType,
        'ref_id' => $refId,
        'action' => $action,
        'like_count' => jhd_api_like_count($refType, $refId),
        'liked' => $action === 'like',
    ]);
}

header('Allow: GET, HEAD, POST, OPTIONS');
jhd_api_error('method_not_allowed', 'روش درخواست مجاز نیست.', 405);
