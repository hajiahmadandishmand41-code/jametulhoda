<?php
/**
 * content-draft.php — «پیش‌نویس خودکار» برای حل ریشه‌ای خطای «شناسه رد شد»
 * ───────────────────────────────────────────────────────────────────────────
 * ترتیب درست انتشار در این سامانه:
 *
 *     ۱) ثبت محتوا  →  ۲) دریافت شناسهٔ واقعی  →  ۳) ثبت همهٔ رسانه‌ها با همان
 *     شناسه  →  ۴) تعیین رسانهٔ شاخص  →  ۵) انتشار کامل
 *
 * پیش از این، صفحهٔ «مطلب جدید» هنوز شناسه‌ای نداشت؛ بنابراین هر عملیات
 * رسانه‌ای پیش از نخستین ذخیره با پیام «شناسهٔ مطلب معتبر نیست» رد می‌شد.
 * راه‌حل ریشه‌ای: به‌محض آنکه مدیر نخستین فایل را انتخاب کند، یک ردیف واقعی
 * «پیش‌نویس خودکار» ساخته می‌شود و شناسهٔ همان ردیف به فرم برمی‌گردد. از آن
 * لحظه همهٔ رسانه‌ها مستقیماً به شناسهٔ نهایی متصل می‌شوند و هنگام ثبت فرم،
 * همان ردیف به‌روزرسانی می‌شود — نه یک ردیف تازه. در نتیجه:
 *
 *   • هیچ رسانه‌ای بدون مالک (یتیم) باقی نمی‌ماند،
 *   • هیچ مطلب تکراری ساخته نمی‌شود،
 *   • مسیر فایل‌ها از همان ابتدا با شناسهٔ نهایی ساخته می‌شود.
 *
 * پیش‌نویس‌های خودکاری که هرگز ثبت نشوند با jhd_purge_stale_auto_drafts()
 * (در همان نشست) پاک می‌شوند و فایل‌هایشان به صف حذف storage می‌روند.
 */

/** کلید نشست برای شناسه‌های پیش‌نویس خودکارِ همین مدیر. */
const JHD_AUTO_DRAFT_SESSION_KEY = 'jhd_auto_drafts';

/** فهرست پیش‌نویس‌های خودکار این نشست. */
function jhd_auto_draft_ids(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) return [];
    $ids = $_SESSION[JHD_AUTO_DRAFT_SESSION_KEY] ?? [];
    return is_array($ids) ? array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)) : [];
}

/** آیا این شناسه یک پیش‌نویس خودکار متعلق به همین نشست است؟ */
function jhd_is_auto_draft(int $postId): bool {
    return $postId > 0 && in_array($postId, jhd_auto_draft_ids(), true);
}

/** ثبت/حذف شناسه در فهرست نشست. */
function jhd_remember_auto_draft(int $postId): void {
    if ($postId < 1 || session_status() !== PHP_SESSION_ACTIVE) return;
    $ids = jhd_auto_draft_ids();
    if (!in_array($postId, $ids, true)) $ids[] = $postId;
    $_SESSION[JHD_AUTO_DRAFT_SESSION_KEY] = array_slice($ids, -20);
}

function jhd_forget_auto_draft(int $postId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION[JHD_AUTO_DRAFT_SESSION_KEY] = array_values(array_filter(
        jhd_auto_draft_ids(),
        static fn(int $id): bool => $id !== $postId
    ));
}

/**
 * شناسهٔ واقعی برای پیوست رسانه پیش از نخستین ذخیره.
 *
 * اگر $existingId معتبر و متعلق به همین نشست باشد همان برمی‌گردد؛ در غیر این
 * صورت یک ردیف پیش‌نویس واقعی ساخته می‌شود. عنوان و اسلاگ موقت‌اند و هنگام
 * ثبت نهایی با مقدارهای واقعی جایگزین می‌شوند.
 *
 * @return array{ok:bool,post_id:int,error?:string,created?:bool}
 */
function jhd_ensure_auto_draft(string $postType, int $existingId = 0): array {
    $allowed = ['report','article','research','qa','announcement','speech','news','program','religious'];
    if (!in_array($postType, $allowed, true)) $postType = 'article';

    if ($existingId > 0 && jhd_is_auto_draft($existingId)) {
        try {
            $stmt = getDB()->prepare('SELECT id FROM posts WHERE id = ? LIMIT 1');
            $stmt->execute([$existingId]);
            if ((int)$stmt->fetchColumn() === $existingId) {
                return ['ok' => true, 'post_id' => $existingId, 'created' => false];
            }
        } catch (Throwable) {
            // ردیف دیگر وجود ندارد؛ پیش‌نویس تازه‌ای می‌سازیم.
        }
        jhd_forget_auto_draft($existingId);
    }

    $admin = function_exists('currentAdmin') ? currentAdmin() : null;
    $authorId = (int)($admin['id'] ?? 0);
    if ($authorId < 1) return ['ok' => false, 'post_id' => 0, 'error' => 'برای این عملیات باید وارد پنل مدیریت شوید.'];

    try {
        $db = getDB();
        $title = 'پیش‌نویس بدون عنوان';
        $slug = uniqueSlug('posts', 'draft-' . bin2hex(random_bytes(6)));
        $stmt = $db->prepare(
            "INSERT INTO posts (title, slug, post_type, page_section, author_id, status, is_featured, published_at, created_at, updated_at)
             VALUES (?, ?, ?, 'other', ?, 'draft', 0, NOW(), NOW(), NOW()) RETURNING id"
        );
        $stmt->execute([$title, $slug, $postType, $authorId]);
        $postId = (int)$stmt->fetchColumn();
        $stmt->closeCursor();
        if ($postId < 1) throw new RuntimeException('draft id missing');
        jhd_remember_auto_draft($postId);
        return ['ok' => true, 'post_id' => $postId, 'created' => true];
    } catch (Throwable $e) {
        error_log('auto draft creation failed: ' . get_class($e));
        return ['ok' => false, 'post_id' => 0, 'error' => 'ایجاد پیش‌نویس اولیه انجام نشد؛ لطفاً دوباره تلاش کنید.'];
    }
}

/**
 * پاک‌سازی پیش‌نویس‌های خودکارِ رهاشده در همین نشست.
 * فقط ردیف‌هایی حذف می‌شوند که هنوز «پیش‌نویس بدون عنوان» و بدون محتوا باشند
 * و شناسه‌شان با پیش‌نویسِ در حال ویرایش برابر نباشد.
 */
function jhd_purge_stale_auto_drafts(int $keepId = 0): void {
    $ids = array_values(array_filter(jhd_auto_draft_ids(), static fn(int $id): bool => $id !== $keepId));
    if (!$ids) return;
    require_once __DIR__ . '/content-delete.php';
    foreach ($ids as $id) {
        try {
            $stmt = getDB()->prepare("SELECT title, status, content FROM posts WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) { jhd_forget_auto_draft($id); continue; }
            $isUntouched = (string)$row['status'] === 'draft'
                && (string)$row['title'] === 'پیش‌نویس بدون عنوان'
                && trim((string)($row['content'] ?? '')) === '';
            if ($isUntouched) deleteContentRecord('posts', $id);
            jhd_forget_auto_draft($id);
        } catch (Throwable) {
            jhd_forget_auto_draft($id);
        }
    }
}
