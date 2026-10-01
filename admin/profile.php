<?php
/**
 * admin/profile.php — پروفایل مدیر
 *
 * مدیر می‌تواند نام، ایمیل، تصویر پروفایل و رمز عبور خود را تغییر دهد.
 * تغییر رمز فقط با تأیید رمز فعلی (password_verify) انجام می‌شود و پس از آن
 * auth_version بالا می‌رود؛ بنابراین رمز قدیمی دیگر کار نمی‌کند و نشست‌های
 * دیگر باطل می‌شوند.
 *
 * این صفحه همچنین مقصد «تغییر اجباری رمز» پس از نصب اولیه است.
 */
declare(strict_types=1);

$adminTitle = 'پروفایل من';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/storage.php';

$me = currentUser();
$forced = !empty($_GET['force']) || (int)$me['must_change_password'] === 1;
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    beginContentUploadScope();
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی. صفحه را رفرش کنید.';
    } else {
        $action = (string)($_POST['action'] ?? 'profile');

        if ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $next = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if ($next === '' || $confirm === '') {
                $error = 'رمز جدید و تکرار آن را وارد کنید.';
            } else {
                $result = jhd_change_password((int)$me['id'], $current, $next, $confirm, true);
                if (!empty($result['ok'])) {
                    jhd_keep_current_session_after_password_change((int)$me['id']);
                    $_SESSION['flash_msg'] = 'رمز عبور تغییر کرد. از این پس رمز جدید لازم است.';
                    $_SESSION['flash_type'] = 'success';
                    redirect(adminProfileUrl());
                }
                $error = (string)($result['error'] ?? 'رمز عبور فعلی نادرست است.');
            }
        } else {
            $avatar = $me['avatar'];
            if (!empty($_FILES['avatar']['name'])) {
                $uploaded = uploadContentImage($_FILES['avatar'], 'avatar', (int)$me['id']);
                if ($uploaded === '') {
                    $error = 'بارگذاری تصویر ناموفق بود. فقط تصویر JPG/PNG/WebP/GIF با حجم مجاز پذیرفته می‌شود.';
                } else {
                    $old = (string)$me['avatar'];
                    $avatar = $uploaded;
                    if ($old !== '' && $old !== $avatar) scheduleFileDeletion($old);
                }
            }
            if ($error === '') {
                $result = jhd_update_profile((int)$me['id'], [
                    'full_name' => $_POST['full_name'] ?? $me['full_name'],
                    'email' => $_POST['email'] ?? $me['email'],
                    'phone' => $_POST['phone'] ?? $me['phone'],
                    'phone_normalized' => $me['phone_normalized'],
                    'avatar' => $avatar,
                ]);
                if (!empty($result['ok'])) {
                    $_SESSION['flash_msg'] = 'اطلاعات پروفایل ذخیره شد.';
                    $_SESSION['flash_type'] = 'success';
                    redirect(adminProfileUrl());
                }
                $error = (string)($result['error'] ?? 'ذخیرهٔ اطلاعات انجام نشد.');
            }
        }
    }
}

$user = currentUser();
$capabilities = jhd_role_permission_summary($user['role']);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0">پروفایل من</h1>
    <span class="badge bg-secondary"><?= sanitize(jhd_role_label($user['role'])) ?></span>
</div>

<?php if ($forced): ?>
<div class="alert alert-warning">
    <i class="bi bi-shield-exclamation ms-2"></i>
    برای امنیت حساب، ابتدا رمز عبور خود را تغییر دهید. تا آن زمان دسترسی به بقیهٔ بخش‌های پنل بسته است.
</div>
<?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="admin-card mb-4">
            <div class="admin-card-header"><i class="bi bi-person-badge ms-2"></i>اطلاعات حساب</div>
            <div class="admin-card-body">
                <form method="post" enctype="multipart/form-data" class="admin-form" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="full_name">نام و نام خانوادگی</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" required minlength="2" maxlength="120" value="<?= sanitize($user['full_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email">ایمیل (برای ورود استفاده می‌شود)</label>
                            <input type="email" id="email" name="email" class="form-control" maxlength="180" value="<?= sanitize($user['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="phone">شمارهٔ تلفن</label>
                            <input type="tel" id="phone" name="phone" class="form-control" maxlength="32" value="<?= sanitize($user['phone']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="username-display">نام کاربری</label>
                            <input type="text" id="username-display" class="form-control" value="<?= sanitize($user['username'] !== '' ? $user['username'] : '—') ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label for="avatar">تصویر پروفایل</label>
                            <input type="file" id="avatar" name="avatar" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">تصویر جدید جایگزین تصویر قبلی می‌شود (حداکثر <?= (int)(MAX_FILE_SIZE / 1024 / 1024) ?> مگابایت).</div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <?php if ($user['avatar'] !== ''): ?><img src="<?= sanitize(imgUrl($user['avatar'])) ?>" alt="تصویر پروفایل" class="jhd-admin-avatar-preview" width="72" height="72"><?php else: ?><span class="text-muted small">تصویری ثبت نشده است.</span><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-success" type="submit"><i class="bi bi-check-circle ms-1"></i>ذخیرهٔ اطلاعات</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header"><i class="bi bi-key ms-2"></i>تغییر رمز عبور</div>
            <div class="admin-card-body">
                <form method="post" class="admin-form" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="current_password">رمز عبور فعلی</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="col-md-6">
                            <label for="new_password">رمز عبور جدید</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required minlength="<?= (int)JHD_MIN_ADMIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                            <div class="form-text">حداقل <?= (int)JHD_MIN_ADMIN_PASSWORD_LENGTH ?> نویسه.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password">تکرار رمز جدید</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="<?= (int)JHD_MIN_ADMIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-shield-lock ms-1"></i>تغییر رمز عبور</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="admin-card mb-4">
            <div class="admin-card-header"><i class="bi bi-info-circle ms-2"></i>خلاصهٔ حساب</div>
            <div class="admin-card-body">
                <ul class="list-unstyled mb-0 jhd-profile-summary">
                    <li><span>نام کاربری:</span><strong><?= sanitize($user['username'] !== '' ? $user['username'] : '—') ?></strong></li>
                    <li><span>نقش:</span><strong><?= sanitize(jhd_role_label($user['role'])) ?></strong></li>
                    <li><span>دسترسی‌ها:</span><strong><?= sanitize($capabilities) ?></strong></li>
                    <li><span>آخرین ورود:</span><strong><?= sanitize($user['last_login'] !== '' ? persianDate($user['last_login']) : '—') ?></strong></li>
                    <li><span>عضویت:</span><strong><?= sanitize($user['created_at'] !== '' ? persianDate($user['created_at']) : '—') ?></strong></li>
                </ul>
            </div>
        </div>
        <div class="admin-card">
            <div class="admin-card-body">
                <p class="text-muted mb-2" style="font-size:.86rem">خروج از حساب در همهٔ دستگاه‌ها با تغییر رمز انجام می‌شود.</p>
                <a href="<?= adminLogoutUrl() ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right ms-1"></i>خروج از حساب</a>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
