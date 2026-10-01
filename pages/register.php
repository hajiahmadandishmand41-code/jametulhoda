<?php
/**
 * ثبت‌نام کاربران عمومی
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member-auth.php';
startSecureSession();

if (isMemberLoggedIn()) {
    redirect(accountUrl());
}

$error = '';
$countries = jhd_countries();
$postString = static fn(string $key, string $default = ''): string => jhd_string_field($_POST, $key, $default);
$selectedCountry = $postString('country', 'AF');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی. صفحه را رفرش کنید.';
    } else {
        $memberInput = $_POST;
        foreach (['full_name', 'country', 'phone', 'email', 'password', 'password_confirm', 'agreed_terms'] as $field) {
            $memberInput[$field] = $postString($field);
        }
        $result = registerMember($memberInput);
        if (!empty($result['ok'])) {
            redirect(accountUrl());
        }
        $error = $result['error'] ?? 'ثبت‌نام انجام نشد.';
    }
}

$pageTitle = 'ثبت‌نام';
$pageDesc = 'عضویت در جامعة‌الهدی برای دسترسی به حساب کاربری و پیگیری مطالب علمی، آموزشی و پژوهشی.';
$canonicalOverride = registerUrl();
$noindexSeo = true;
require_once __DIR__ . '/../includes/header.php';
?>
<section class="jhd-auth-page">
    <div class="container">
        <div class="jhd-auth-card jhd-auth-card-wide">
            <p class="jhd-kicker">عضویت</p>
            <h1>ثبت‌نام در جامعة‌الهدی</h1>
            <p class="jhd-auth-lead">حساب کاربری عمومی برای پیگیری مطالب است و هیچ دسترسی مدیریتی ایجاد نمی‌کند.</p>
            <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
            <form method="post" action="<?= registerUrl() ?>" autocomplete="on" class="jhd-auth-form">
                <?= csrfField() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="full_name">نام و نام خانوادگی</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" required minlength="2" maxlength="120" value="<?= sanitize($postString('full_name')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="country">کشور</label>
                        <select id="country" name="country" class="form-select" required>
                            <?php foreach ($countries as $code => $row): ?>
                            <option value="<?= sanitize($code) ?>" <?= $selectedCountry === $code ? 'selected' : '' ?>><?= sanitize($row['name']) ?><?= $row['dial'] !== '' ? ' (+' . sanitize($row['dial']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="phone">شماره تلفن</label>
                        <input type="tel" id="phone" name="phone" class="form-control" required maxlength="32" value="<?= sanitize($postString('phone')) ?>" autocomplete="tel">
                    </div>
                    <div class="col-md-6">
                        <label for="email">ایمیل (اختیاری)</label>
                        <input type="email" id="email" name="email" class="form-control" maxlength="180" value="<?= sanitize($postString('email')) ?>" autocomplete="email">
                    </div>
                    <div class="col-md-6">
                        <label for="password">رمز عبور</label>
                        <input type="password" id="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="col-md-6">
                        <label for="password_confirm">تکرار رمز عبور</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="col-12">
                        <label class="jhd-check">
                            <input type="checkbox" name="agreed_terms" value="1" required>
                            <span>قوانین استفاده از سایت را خوانده‌ام و می‌پذیرم.</span>
                        </label>
                    </div>
                </div>
                <button type="submit" class="jhd-button jhd-button-block mt-3">ایجاد حساب</button>
            </form>
            <div class="jhd-auth-links">
                <a href="<?= loginUrl() ?>">حساب دارید؟ وارد شوید</a>
                <a href="<?= url('about') ?>">درباره جامعه‌الهدی</a>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
