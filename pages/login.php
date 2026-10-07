<?php
/**
 * login.php — تنها صفحهٔ ورود سامانه (کاربر، مدیر و مدیر ارشد)
 *
 * همه با همین صفحه وارد می‌شوند؛ نقش از دیتابیس خوانده می‌شود و مقصد را
 * تعیین می‌کند:
 *   admin / super_admin → /admin/dashboard
 *   user                → /account
 *
 * حساب مدیریتی جداگانه‌ای وجود ندارد: /admin/login هم همین کنترلر را اجرا
 * می‌کند تا هر دو نشانی دقیقاً یک رفتار داشته باشند.
 */
declare(strict_types=1);

// این فایل هم از مسیر /login و هم از /admin/login اجرا می‌شود.
$jhdLoginInAdminContext = !empty($jhdLoginInAdminContext);
$jhdLoginRoot = dirname(__DIR__);

require_once $jhdLoginRoot . '/config/config.php';
require_once $jhdLoginRoot . '/includes/functions.php';
require_once $jhdLoginRoot . '/includes/auth.php';
require_once $jhdLoginRoot . '/includes/member-auth.php';
startSecureSession();

$alreadyUser = currentUser();
// مقصد بازگشت فقط از POST (اولویت) یا GET خوانده می‌شود و در jhd_safe_redirect_target
// اعتبارسنجی می‌شود؛ هیچ آدرس بیرونی پذیرفته نمی‌شود.
$requestedRedirect = jhd_safe_redirect_target($_POST['redirect'] ?? $_GET['redirect'] ?? null, '');
$roleHome = $alreadyUser['id'] > 0 ? jhd_home_url_for_role($alreadyUser['role']) : url();

// کاربر واردشده دوباره فرم ورود نمی‌بیند.
if ($alreadyUser['id'] > 0) {
    if ((int)$alreadyUser['must_change_password'] === 1 && !$requestedRedirect) {
        redirect($alreadyUser['role'] === JHD_ROLE_USER ? url('password-change') : adminUrl('profile') . '?force=1');
    }
    redirect($requestedRedirect !== '' ? $requestedRedirect : $roleHome);
}

$error = '';
$identifierValue = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'نشست شما منقضی شده است. لطفاً صفحه را دوباره بارگذاری کنید.';
    } else {
        $identifier = trim(jhd_string_field($_POST, 'identifier'));
        $password = jhd_string_field($_POST, 'password');
        $remember = !empty($_POST['remember']);
        $identifierValue = $identifier;
        $requestedRedirect = jhd_safe_redirect_target($_POST['redirect'] ?? null, '');

        if ($identifier === '' || $password === '') {
            $error = 'شناسه و رمز عبور را وارد کنید.';
        } else {
            $result = jhd_login($identifier, $password, $remember);
            if (!empty($result['ok'])) {
                $user = $result['user'];
                if ((int)$user['must_change_password'] === 1) {
                    $_SESSION['flash_msg'] = 'برای ادامه، رمز عبور خود را تغییر دهید.';
                    $_SESSION['flash_type'] = 'warning';
                    redirect($user['role'] === JHD_ROLE_USER ? url('password-change') : adminUrl('profile') . '?force=1');
                }
                redirect($requestedRedirect !== '' ? $requestedRedirect : jhd_home_url_for_role($user['role']));
            }
            $error = (string)($result['error'] ?? 'شناسه یا رمز عبور نادرست است.');
        }
    }
}

$pageTitle = 'ورود به حساب کاربری';
$pageDesc = 'ورود اعضا، مدیران و مدیر ارشد مدرسه جامعه‌الهدی از یک صفحهٔ واحد و امن.';
$canonicalOverride = loginUrl();
$noindexSeo = true;
require_once $jhdLoginRoot . '/includes/header.php';
?>
<section class="jhd-auth-page">
    <div class="container">
        <div class="jhd-auth-card jhd-login-card">
            <div class="jhd-login-head">
                <img src="<?= imgUrl(SITE_LOGO_PATH) ?>" width="64" height="64" alt="<?= sanitize($siteName) ?>" class="jhd-login-logo">
                <p class="jhd-kicker">حساب کاربری</p>
                <h1>ورود به حساب کاربری</h1>
                <p class="jhd-auth-lead">با ایمیل، شمارهٔ تلفن یا نام کاربری خود وارد شوید. مدیریت محتوا و پنل مدیران نیز از همین صفحه انجام می‌شود.</p>
            </div>

            <?php if ($requestedRedirect !== ''): ?>
            <p class="jhd-login-note"><i class="bi bi-info-circle ms-1"></i>برای دیدن صفحهٔ درخواستی، ابتدا وارد شوید.</p>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert" aria-live="polite"><i class="bi bi-exclamation-triangle ms-2"></i><?= sanitize($error) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= $jhdLoginInAdminContext ? adminLoginUrl() : loginUrl() ?>" class="jhd-auth-form" autocomplete="on" novalidate>
                <?= csrfField() ?>
                <?php if ($requestedRedirect !== ''): ?>
                <input type="hidden" name="redirect" value="<?= sanitize($requestedRedirect) ?>">
                <?php endif; ?>

                <label for="identifier">ایمیل، شمارهٔ تلفن یا نام کاربری</label>
                <div class="jhd-input-wrap">
                    <i class="bi bi-person" aria-hidden="true"></i>
                    <input type="text" id="identifier" name="identifier" class="form-control" required maxlength="190"
                           value="<?= sanitize($identifierValue) ?>" autocomplete="username" inputmode="email"
                           placeholder="مثلاً: name@example.com یا 0798228441" autofocus>
                </div>

                <label for="password">رمز عبور</label>
                <div class="jhd-input-wrap">
                    <i class="bi bi-lock" aria-hidden="true"></i>
                    <input type="password" id="password" name="password" class="form-control" required
                           autocomplete="current-password" placeholder="رمز عبور خود را وارد کنید">
                    <button type="button" class="jhd-input-action" data-password-toggle="password"
                            aria-label="نمایش یا پنهان‌کردن رمز عبور" aria-pressed="false" tabindex="0">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="jhd-login-row">
                    <label class="jhd-check jhd-remember">
                        <input type="checkbox" name="remember" value="1">
                        <span>مرا به خاطر بسپار</span>
                    </label>
                    <a class="jhd-login-help" href="<?= url('contact') ?>">
                        <i class="bi bi-question-circle ms-1"></i>رمز را فراموش کرده‌اید؟ تماس با مدیریت
                    </a>
                </div>

                <button type="submit" class="jhd-button jhd-button-block">
                    <i class="bi bi-box-arrow-in-left ms-2"></i>ورود
                </button>
            </form>

            <div class="jhd-auth-links jhd-login-links">
                <span>حساب کاربری ندارید؟</span>
                <a href="<?= registerUrl() ?>">ثبت‌نام</a>
            </div>

            <p class="jhd-login-foot">مدرسه جامعه‌الهدی | مرکز علمی، آموزشی و پژوهشی</p>
        </div>
    </div>
</section>
<?php require_once $jhdLoginRoot . '/includes/footer.php'; ?>
