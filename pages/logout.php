<?php
/**
 * logout.php — خروج از حساب (عضو، مدیر و مدیر ارشد)
 *
 * سامانه یک نشست واحد دارد؛ خروج، نشست را به‌طور کامل پایان می‌دهد و همهٔ
 * کلیدهای هویتی پاک می‌شوند. خروج همیشه با POST و توکن CSRF انجام می‌شود تا
 * یک لینک ساده یا تصویر بیرونی نتواند کاربر را خارج کند.
 *
 * همین کنترلر از /admin/logout هم اجرا می‌شود (فقط مقصد بازگشت تفاوت دارد).
 */
declare(strict_types=1);

$jhdLogoutInAdminContext = !empty($jhdLogoutInAdminContext);
$jhdLogoutRoot = dirname(__DIR__);

require_once $jhdLogoutRoot . '/config/config.php';
require_once $jhdLogoutRoot . '/includes/functions.php';
require_once $jhdLogoutRoot . '/includes/auth.php';
require_once $jhdLogoutRoot . '/includes/member-auth.php';
startSecureSession();

$wasStaff = isLoggedIn();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requirePostCsrf();
    jhd_logout();
    redirect($wasStaff ? loginUrl() : url());
}

$pageTitle = 'خروج از حساب';
$pageDesc = 'خروج از حساب کاربری جامعة‌الهدی.';
$canonicalOverride = logoutUrl();
$noindexSeo = true;
require_once $jhdLogoutRoot . '/includes/header.php';
?>
<section class="jhd-auth-page">
    <div class="container">
        <div class="jhd-auth-card jhd-login-card">
            <p class="jhd-kicker">پایان نشست</p>
            <h1>خروج از حساب کاربری</h1>
            <p class="jhd-auth-lead">آیا می‌خواهید از حساب خود خارج شوید؟ برای ورود دوباره باید شناسه و رمز عبور را وارد کنید.</p>
            <form method="post" action="<?= $jhdLogoutInAdminContext ? adminLogoutUrl() : logoutUrl() ?>" class="jhd-auth-form">
                <?= csrfField() ?>
                <button type="submit" class="jhd-button jhd-button-block"><i class="bi bi-box-arrow-right ms-2"></i>خروج</button>
                <a class="jhd-button jhd-button-ghost jhd-button-block" href="<?= $wasStaff ? adminUrl('dashboard') : url() ?>">انصراف</a>
            </form>
            <p class="jhd-login-foot">جامعه‌الهدی | مرکز علمی، آموزشی و پژوهشی</p>
        </div>
    </div>
</section>
<?php require_once $jhdLogoutRoot . '/includes/footer.php'; ?>
