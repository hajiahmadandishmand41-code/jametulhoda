<?php
/**
 * admin/members/index.php — اعضای عمومی سایت
 *
 * اعضا و مدیران یک جدول مشترک دارند؛ این صفحه فقط حساب‌های با نقش «عضو سایت»
 * را نشان می‌دهد. غیرفعال‌کردن عضو، auth_version را بالا می‌برد تا نشست‌های
 * فعال او باطل شوند.
 */
$adminTitle = 'اعضای سایت';
require_once __DIR__ . '/../includes/header.php';
requireCapability('users');

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requirePostCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $target = $id > 0 ? jhd_user_by_id($id) : null;
    if ($target === null || $target['role'] !== JHD_ROLE_USER) {
        $error = 'عضو معتبر نیست.';
    } else {
        $active = isset($_POST['is_active']) ? 1 : 0;
        try {
            getDB()->prepare('UPDATE users SET is_active=?, auth_version=COALESCE(auth_version,1)+1 WHERE id=? AND role=?')
               ->execute([$active, $id, JHD_ROLE_USER]);
            $_SESSION['flash_msg'] = 'وضعیت عضو به‌روز شد.';
            $_SESSION['flash_type'] = 'success';
            redirect(adminUrl('members'));
        } catch (Throwable $e) {
            $error = 'به‌روزرسانی انجام نشد.';
        }
    }
}

$members = jhd_list_users(JHD_ROLE_USER, 300);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0">اعضای عمومی سایت</h1>
    <a href="<?= url('admin/users') ?>" class="btn btn-sm btn-outline-primary">مدیریت همهٔ کاربران</a>
</div>
<p class="text-muted" style="font-size:.9rem">این اعضا با ثبت‌نام عمومی ساخته شده‌اند و فقط به حساب کاربری خود دسترسی دارند؛ هرگز به پنل مدیریت راه پیدا نمی‌کنند.</p>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<div class="table-responsive admin-card">
<table class="table admin-table mb-0">
<thead><tr><th>نام</th><th>کشور</th><th>تلفن</th><th>ایمیل</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr></thead>
<tbody>
<?php if (!$members): ?>
<tr><td colspan="7" class="text-center text-muted py-4">هنوز عضو عمومی ثبت نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($members as $m): ?>
<tr>
    <td><?= sanitize($m['full_name'] !== '' ? $m['full_name'] : '—') ?></td>
    <td><?= sanitize($m['country'] !== '' ? $m['country'] : '—') ?></td>
    <td><?= sanitize($m['phone'] !== '' ? $m['phone'] : '—') ?></td>
    <td><?= sanitize($m['email'] !== '' ? $m['email'] : '—') ?></td>
    <td><?= (int)$m['is_active'] === 1 ? '<span class="text-success">فعال</span>' : '<span class="text-danger">غیرفعال</span>' ?></td>
    <td class="text-muted" style="font-size:.8rem"><?= sanitize($m['last_login'] !== '' ? persianDate($m['last_login']) : '—') ?></td>
    <td>
        <form method="post" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <?php if ((int)$m['is_active'] === 1): ?>
            <button class="btn btn-sm btn-outline-danger">غیرفعال</button>
            <?php else: ?>
            <input type="hidden" name="is_active" value="1">
            <button class="btn btn-sm btn-outline-success">فعال‌سازی</button>
            <?php endif; ?>
        </form>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/users', ['edit' => $m['id']]) ?>">ویرایش</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
