<?php
/**
 * account.php — حساب کاربری عضو سایت
 *
 * مدیران و مدیر ارشد همان حساب را دارند؛ برای آن‌ها دکمهٔ ورود به پنل هم
 * نمایش داده می‌شود. این صفحه فقط اطلاعات خودِ کاربر را نشان می‌دهد و هیچ
 * داده حساسی (هش رمز، نسخهٔ احراز هویت) را بیرون نمی‌دهد.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member-auth.php';
startSecureSession();
requireMember();

$user = currentUser();
$isStaff = jhd_role_is_staff($user['role']);

$pageTitle = 'حساب کاربری';
$pageDesc = 'مدیریت حساب کاربری در جامعة‌الهدی.';
$canonicalOverride = accountUrl();
$noindexSeo = true;
require_once __DIR__ . '/../includes/header.php';
?>
<section class="jhd-auth-page">
    <div class="container">
        <div class="jhd-auth-card jhd-auth-card-wide">
            <p class="jhd-kicker">پروفایل</p>
            <h1>حساب کاربری</h1>
            <p class="jhd-auth-lead">
                <?php if ($isStaff): ?>
                این حساب دسترسی مدیریتی دارد. برای مدیریت محتوا وارد پنل شوید.
                <?php else: ?>
                این حساب مخصوص اعضای سایت است و به پنل مدیریت دسترسی ندارد.
                <?php endif; ?>
            </p>
            <dl class="jhd-account-dl">
                <div><dt>نام</dt><dd><?= sanitize($user['full_name'] !== '' ? $user['full_name'] : '—') ?></dd></div>
                <div><dt>نقش</dt><dd><?= sanitize(jhd_role_label($user['role'])) ?></dd></div>
                <div><dt>کشور</dt><dd><?= sanitize($user['country'] !== '' ? $user['country'] : '—') ?></dd></div>
                <div><dt>تلفن</dt><dd><?= sanitize($user['phone'] !== '' ? $user['phone'] : '—') ?></dd></div>
                <div><dt>ایمیل</dt><dd><?= sanitize($user['email'] !== '' ? $user['email'] : '—') ?></dd></div>
                <div><dt>آخرین ورود</dt><dd><?= sanitize($user['last_login'] !== '' ? persianDate($user['last_login']) : '—') ?></dd></div>
            </dl>
            <div class="jhd-auth-links">
                <?php if ($isStaff): ?>
                <a class="jhd-button" href="<?= adminDashboardUrl() ?>"><i class="bi bi-speedometer2 ms-2"></i>پنل مدیریت</a>
                <a class="jhd-button jhd-button-ghost" href="<?= adminProfileUrl() ?>">ویرایش پروفایل</a>
                <?php endif; ?>
                <a class="jhd-button jhd-button-ghost" href="<?= url('password-change') ?>">تغییر رمز عبور</a>
                <a class="jhd-button jhd-button-ghost" href="<?= logoutUrl() ?>">خروج</a>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
