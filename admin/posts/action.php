<?php
/**
 * admin/posts/action.php — عملیات وضعیت و حذف محتوا
 * پشتیبانی از مسیرهای:
 *   /admin/content/{id}/publish
 *   /admin/content/{id}/unpublish
 *   /admin/content/{id}/archive
 *   /admin/content/{id}/delete
 */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/content-delete.php';

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$action = strtolower(trim($_GET['action'] ?? ($_POST['action'] ?? '')));

if ($id < 1 || !in_array($action, ['publish', 'unpublish', 'archive', 'delete'], true)) {
    http_response_code(404);
    echo '<div class="admin-card p-4 text-center"><h5>درخواست یا عملیات نامعتبر است.</h5><a href="' . siteUrl('admin/content') . '" class="btn btn-primary mt-3">بازگشت به محتوا</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, title, status, post_type FROM posts WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    echo '<div class="admin-card p-4 text-center"><h5>مطلب مورد نظر یافت نشد.</h5><a href="' . siteUrl('admin/content') . '" class="btn btn-primary mt-3">بازگشت به محتوا</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$actionLabels = [
    'publish'   => 'انتشار مطلب',
    'unpublish' => 'انتقال به پیش‌نویس',
    'archive'   => 'آرشیو مطلب',
    'delete'    => 'حذف مطلب',
];

// اگر متد GET است، فرم تأیید امن عملیات همراه با CSRF نمایش داده شود
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="admin-card text-center p-4">
                <div class="mb-3 text-warning" style="font-size:3rem">
                    <i class="bi <?= $action === 'delete' ? 'bi-trash text-danger' : 'bi-question-circle' ?>"></i>
                </div>
                <h4 class="mb-2">تأیید <?= $actionLabels[$action] ?></h4>
                <p class="text-muted mb-4">
                    آیا از <?= $actionLabels[$action] ?> «<strong><?= sanitize($post['title']) ?></strong>» اطمینان دارید؟
                </p>
                <form method="post" action="<?= contentActionUrl($id, $action) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="<?= sanitize($action) ?>">
                    <div class="d-flex justify-content-center gap-2">
                        <a href="<?= siteUrl('admin/content') ?>" class="btn btn-outline-secondary">انصراف</a>
                        <button type="submit" class="btn <?= $action === 'delete' ? 'btn-danger' : 'btn-primary' ?>">
                            بله، انجام شود
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// متد POST — بررسی CSRF
requirePostCsrf();

switch ($action) {
    case 'publish':
        $u = $db->prepare("UPDATE posts SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_at = NOW() WHERE id = ?");
        $u->execute([$id]);
        $_SESSION['flash_msg'] = 'مطلب با موفقیت منتشر شد.';
        $_SESSION['flash_type'] = 'success';
        break;

    case 'unpublish':
        $u = $db->prepare("UPDATE posts SET status = 'draft', updated_at = NOW() WHERE id = ?");
        $u->execute([$id]);
        $_SESSION['flash_msg'] = 'مطلب به حالت پیش‌نویس تغییر یافت.';
        $_SESSION['flash_type'] = 'warning';
        break;

    case 'archive':
        $u = $db->prepare("UPDATE posts SET status = 'draft', updated_at = NOW() WHERE id = ?");
        $u->execute([$id]);
        $_SESSION['flash_msg'] = 'مطلب با موفقیت آرشیو شد.';
        $_SESSION['flash_type'] = 'secondary';
        break;

    case 'delete':
        if (!deleteContentRecord('posts', $id, null)) {
            http_response_code(500);
            exit('خطا در حذف مطلب.');
        }
        $_SESSION['flash_msg'] = 'مطلب با موفقیت حذف شد.';
        $_SESSION['flash_type'] = 'success';
        break;
}

redirect(siteUrl('admin/content'));
