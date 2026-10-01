<?php
/**
 * password-change.php — تغییر رمز عبور حساب کاربری (عضو و مدیر)
 *
 * رمز فعلی همیشه با password_verify() بررسی می‌شود، سپس رمز جدید با
 * password_hash() ذخیره و auth_version بالا می‌رود تا بقیهٔ نشست‌ها باطل شوند.
 * پس از تغییر، دیگر رمز قدیمی پذیرفته نمی‌شود.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member-auth.php';
startSecureSession();
requireMember();

$user = currentUser();
$forced = !empty($_GET['force']) || (int)$user['must_change_password'] === 1;
$minLength = jhd_role_is_staff($user['role']) ? JHD_MIN_ADMIN_PASSWORD_LENGTH : 8;
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی. صفحه را رفرش کنید.';
    } else {
        $current = jhd_string_field($_POST, 'current_password');
        $next = jhd_string_field($_POST, 'new_password');
        $confirm = jhd_string_field($_POST, 'new_password_confirm');
        if ($next === '' || $confirm === '') {
            $error = 'رمز جدید و تکرار آن را وارد کنید.';
        } else {
            $result = jhd_change_password((int)$user['id'], $current, $next, $confirm, true);
            if (!empty($result['ok'])) {
                // نشست جاری با نسخهٔ تازه هم‌گام می‌شود تا کاربر بیرون نیفتد.
                jhd_keep_current_session_after_password_change((int)$user['id']);
                $_SESSION['flash_msg'] = 'رمز عبور با موفقیت تغییر کرد.';
                $_SESSION['flash_type'] = 'success';
                redirect(jhd_role_is_staff($user['role']) ? adminDashboardUrl() : accountUrl());
            }
            $error = (string)($result['error'] ?? 'رمز فعلی نادرست است.');
        }
    }
}

$pageTitle = 'تغییر رمز عبور';
$pageDesc = 'تغییر رمز عبور حساب کاربری جامعة‌الهدی.';
$canonicalOverride = url('password-change');
$noindexSeo = true;
require_once __DIR__ . '/../includes/header.php';
?>
<section class="jhd-auth-page">
    <div class="container">
        <div class="jhd-auth-card">
            <p class="jhd-kicker">امنیت حساب</p>
            <h1>تغییر رمز عبور</h1>
            <?php if ($forced): ?>
            <div class="alert alert-warning" role="alert">برای ادامهٔ کار، رمز عبور خود را تغییر دهید.</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
            <form method="post" action="<?= url('password-change') ?>" class="jhd-auth-form" autocomplete="off">
                <?= csrfField() ?>
                <label for="current_password">رمز فعلی</label>
                <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
                <label for="new_password">رمز جدید</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required minlength="<?= $minLength ?>" autocomplete="new-password">
                <div class="jhd-login-help mb-2">حداقل <?= $minLength ?> نویسه؛ ترکیبی از حرف، رقم و نشانه امن‌تر است.</div>
                <label for="new_password_confirm">تکرار رمز جدید</label>
                <input type="password" id="new_password_confirm" name="new_password_confirm" class="form-control" required minlength="<?= $minLength ?>" autocomplete="new-password">
                <button type="submit" class="jhd-button jhd-button-block">ذخیرهٔ رمز جدید</button>
            </form>
            <div class="jhd-auth-links"><a href="<?= accountUrl() ?>">بازگشت به حساب</a></div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
