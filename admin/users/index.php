<?php
/**
 * admin/users/index.php — مدیریت کاربران (مدیران، اعضا و کارکنان)
 *
 * همهٔ حساب‌ها در جدول `users` هستند. اعضای عمومی (نقش user) هم در همین فهرست
 * دیده می‌شوند تا مدیریت یکپارچه باشد. ساخت/ویرایش حساب فقط برای مدیر ارشد
 * (قابلیت users) مجاز است و نقش فقط از فهرست مجاز انتخاب می‌شود.
 */
$adminTitle = 'کاربران و اعضا';
require_once __DIR__ . '/../includes/header.php';
requireCapability('users');

$db = getDB();
$error = '';
$success = '';
$edit = null;
$filter = (string)($_GET['role'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requirePostCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $role = jhd_normalize_role((string)($_POST['role'] ?? JHD_ROLE_USER));
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($username !== '' && !preg_match('/^[a-zA-Z0-9_.-]{3,80}$/D', $username)) $error = 'نام کاربری باید ۳ تا ۸۰ نویسهٔ لاتین باشد.';
    elseif (mb_strlen($name) > 120) $error = 'نام بیش از حد بلند است.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'ایمیل معتبر نیست.';
    elseif (!in_array($role, jhd_role_values(), true)) $error = 'نقش معتبر نیست.';
    elseif ((!$id || $password !== '') && strlen($password) < JHD_MIN_ADMIN_PASSWORD_LENGTH) $error = 'رمز عبور باید حداقل ' . JHD_MIN_ADMIN_PASSWORD_LENGTH . ' نویسه باشد.';
    elseif ($id === (int)$admin['id'] && (!$active || $role !== JHD_ROLE_SUPER_ADMIN)) $error = 'غیرفعال‌سازی یا کاهش دسترسی حساب فعلی مجاز نیست.';
    else {
        try {
            if ($id) {
                $db->prepare('UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=? WHERE id=?')
                   ->execute([$username !== '' ? $username : null, $name, $email !== '' ? $email : null, $role, $active, $id]);
                if ($password !== '') {
                    $db->prepare('UPDATE users SET password=?, must_change_password=0, auth_version=COALESCE(auth_version,1)+1 WHERE id=?')
                       ->execute([jhd_hash_password($password), $id]);
                } else {
                    $db->prepare('UPDATE users SET auth_version=COALESCE(auth_version,1)+1 WHERE id=? AND is_active=0')->execute([$id]);
                }
            } else {
                $created = jhd_create_user([
                    'username' => $username,
                    'full_name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'role' => $role,
                    'is_active' => $active,
                ]);
                if (empty($created['ok'])) throw new RuntimeException((string)($created['error'] ?? 'ذخیره انجام نشد.'));
            }
            $_SESSION['flash_msg'] = 'اطلاعات کاربر ذخیره شد.';
            $_SESSION['flash_type'] = 'success';
            redirect(adminUrl('users'));
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'ذخیره انجام نشد؛ نام کاربری و ایمیل باید یکتا باشند.';
        }
    }
}

if (!empty($_GET['edit'])) {
    $edit = jhd_user_by_id((int)$_GET['edit']);
}

$users = jhd_list_users($filter !== '' ? $filter : null, 300);
$counts = jhd_count_users();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0">کاربران و اعضا</h1>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= url('admin/users') ?>" class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline-primary' ?>">همه (<?= array_sum($counts) ?>)</a>
        <a href="<?= url('admin/users', ['role' => JHD_ROLE_SUPER_ADMIN]) ?>" class="btn btn-sm <?= $filter === JHD_ROLE_SUPER_ADMIN ? 'btn-primary' : 'btn-outline-primary' ?>">مدیر ارشد (<?= $counts[JHD_ROLE_SUPER_ADMIN] ?>)</a>
        <a href="<?= url('admin/users', ['role' => JHD_ROLE_ADMIN]) ?>" class="btn btn-sm <?= $filter === JHD_ROLE_ADMIN ? 'btn-primary' : 'btn-outline-primary' ?>">مدیران (<?= $counts[JHD_ROLE_ADMIN] ?>)</a>
        <a href="<?= url('admin/users', ['role' => JHD_ROLE_USER]) ?>" class="btn btn-sm <?= $filter === JHD_ROLE_USER ? 'btn-primary' : 'btn-outline-primary' ?>">اعضا (<?= $counts[JHD_ROLE_USER] ?>)</a>
    </div>
</div>
<p class="text-muted" style="font-size:.9rem">همهٔ حساب‌ها (مدیر، مدیر ارشد و عضو سایت) در یک جدول مدیریت می‌شوند و نقش، سطح دسترسی را تعیین می‌کند. حذف اطلاعات انجام نمی‌شود؛ دسترسی را غیرفعال کنید.</p>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>

<div class="admin-card mb-4">
    <div class="admin-card-header"><?= $edit ? 'ویرایش حساب «' . sanitize($edit['full_name'] !== '' ? $edit['full_name'] : ($edit['username'] !== '' ? $edit['username'] : $edit['email'])) . '»' : 'ساخت حساب تازه' ?></div>
    <div class="admin-card-body">
        <form method="post" class="admin-form" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="row g-3">
                <div class="col-md-4"><label for="user-username">نام کاربری (لاتین)</label><input id="user-username" class="form-control" name="username" value="<?= sanitize((string)($edit['username'] ?? '')) ?>" placeholder="برای اعضای عمومی اختیاری است"></div>
                <div class="col-md-4"><label for="user-full_name">نام کامل</label><input id="user-full_name" class="form-control" name="full_name" value="<?= sanitize((string)($edit['full_name'] ?? '')) ?>"></div>
                <div class="col-md-4"><label for="user-email">ایمیل</label><input id="user-email" class="form-control" type="email" name="email" value="<?= sanitize((string)($edit['email'] ?? '')) ?>"></div>
                <div class="col-md-4">
                    <label for="user-password">رمز عبور <?= $edit ? '(خالی = تغییر نکند)' : '' ?></label>
                    <input id="user-password" class="form-control" type="password" name="password" minlength="<?= (int)JHD_MIN_ADMIN_PASSWORD_LENGTH ?>" autocomplete="new-password" <?= $edit ? '' : 'required' ?>>
                </div>
                <div class="col-md-4">
                    <label for="user-role">نقش</label>
                    <select id="user-role" class="form-select" name="role">
                        <?php foreach (jhd_role_labels() as $value => $label): ?>
                        <option value="<?= sanitize($value) ?>" <?= ($edit['role'] ?? JHD_ROLE_USER) === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">عضو سایت فقط حساب کاربری؛ مدیر محتوا و مدیر ارشد پنل مدیریت.</div>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-3">
                    <label class="jhd-check mb-2"><input type="checkbox" name="is_active" <?= (int)($edit['is_active'] ?? 1) ? 'checked' : '' ?>> حساب فعال است</label>
                    <button class="btn btn-primary mb-2" type="submit">ذخیره</button>
                    <?php if ($edit): ?><a href="<?= url('admin/users') ?>" class="btn btn-outline-secondary mb-2">انصراف</a><?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="table-responsive admin-card">
<table class="table admin-table mb-0">
<thead><tr><th>نام</th><th>نام کاربری</th><th>ایمیل / تلفن</th><th>نقش</th><th>دسترسی‌ها</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr></thead>
<tbody>
<?php if (!$users): ?>
<tr><td colspan="8" class="text-center text-muted py-4">حسابی با این فیلتر پیدا نشد.</td></tr>
<?php endif; ?>
<?php foreach ($users as $user): ?>
<tr>
    <td><?= sanitize($user['full_name'] !== '' ? $user['full_name'] : '—') ?></td>
    <td><?= sanitize($user['username'] !== '' ? $user['username'] : '—') ?></td>
    <td><?= sanitize($user['email'] !== '' ? $user['email'] : ($user['phone'] !== '' ? $user['phone'] : '—')) ?></td>
    <td><span class="badge <?= $user['role'] === JHD_ROLE_SUPER_ADMIN ? 'bg-dark' : ($user['role'] === JHD_ROLE_ADMIN ? 'bg-primary' : 'bg-secondary') ?>"><?= sanitize(jhd_role_label($user['role'])) ?></span></td>
    <td class="text-muted" style="font-size:.78rem"><?= sanitize(jhd_role_permission_summary($user['role'])) ?></td>
    <td><?= (int)$user['is_active'] === 1 ? '<span class="text-success">فعال</span>' : '<span class="text-danger">غیرفعال</span>' ?></td>
    <td class="text-muted" style="font-size:.8rem"><?= sanitize($user['last_login'] !== '' ? persianDate($user['last_login']) : '—') ?></td>
    <td><a href="<?= url('admin/users', ['edit' => $user['id']]) ?>" class="btn btn-sm btn-outline-primary py-0 px-2">ویرایش</a></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
