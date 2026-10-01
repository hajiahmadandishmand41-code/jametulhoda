<?php
/**
 * admin/settings.php — تنظیمات امن و قابل‌اعتبارسنجی سایت
 */
$adminTitle = 'تنظیمات سایت';
require_once __DIR__ . '/includes/header.php';

$db = getDB();
$rows = $db->query('SELECT setting_key, value FROM settings')->fetchAll();
$sets = array_column($rows, 'value', 'setting_key');

/** Portable settings upsert: `setting_key` avoids MySQL's reserved `key`. */
function upsertSetting(PDO $db, string $key, string $value): void {
    if (databaseDriver() === 'mysql') {
        $db->prepare('INSERT INTO settings (setting_key,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$key, $value]);
        return;
    }
    $db->prepare('INSERT INTO settings (setting_key,value) VALUES (?,?) ON CONFLICT (setting_key) DO UPDATE SET value=EXCLUDED.value')->execute([$key, $value]);
}

$fieldLimits = [
    'site_name' => 100,
    'site_slogan' => 200,
    'about_short' => 1200,
    'address' => 300,
    'phone' => 50,
    'email' => 254,
    'social_telegram' => 2048,
    'social_youtube' => 2048,
    'social_instagram' => 2048,
];
$defaults = [
    'site_name' => SITE_NAME,
    'site_slogan' => SITE_SLOGAN,
    'about_short' => '',
    'address' => SITE_ADDRESS,
    'phone' => SITE_PHONE,
    'email' => SITE_EMAIL,
    'social_telegram' => '',
    'social_youtube' => '',
    'social_instagram' => '',
];
$formValues = [];
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    beginContentUploadScope();
    $csrf = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!is_string($csrf) || !verifyCsrfToken($csrf)) {
        http_response_code(403);
        $error = 'درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش نمایید.';
    } else {
        foreach ($fieldLimits as $field => $maxLength) {
            $raw = $_POST[$field] ?? ($sets[$field] ?? $defaults[$field]);
            if (!is_string($raw)) {
                $error = 'یکی از مقادیر فرم معتبر نیست.';
                continue;
            }
            $value = trim($raw);
            $formValues[$field] = $value;
            if (mb_strlen($value, 'UTF-8') > $maxLength) {
                $error = 'طول یکی از مقادیر واردشده بیش از حد مجاز است.';
            }
        }

        if (($formValues['site_name'] ?? '') === '') {
            $error = 'نام سایت نمی‌تواند خالی باشد.';
        }
        $email = $formValues['email'] ?? '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'نشانی ایمیل معتبر نیست.';
        }
        $phone = $formValues['phone'] ?? '';
        if ($phone !== '' && preg_match('/^[\p{N}\s+().,#*;\-]+$/u', $phone) !== 1) {
            $error = 'شماره تلفن فقط می‌تواند شامل عدد و نشانه‌های رایج تلفن باشد.';
        }
        foreach (['social_telegram' => 'تلگرام', 'social_youtube' => 'یوتیوب', 'social_instagram' => 'اینستاگرام'] as $field => $label) {
            $value = $formValues[$field] ?? '';
            if ($value !== '' && safeExternalUrl($value) === '') {
                $error = 'پیوند ' . $label . ' باید یک نشانی معتبر HTTPS باشد.';
            }
        }

        $newLogo = '';
        if ($error === '' && isset($_FILES['logo'])) {
            $file = $_FILES['logo'];
            if (!is_array($file) || !is_string($file['name'] ?? null)) {
                $error = 'فایل لوگو معتبر نیست.';
            } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newLogo = uploadImage($file, 'site');
                if ($newLogo === '') {
                    $error = 'بارگذاری لوگو ناموفق بود. فقط تصویر معتبر با حجم مجاز پذیرفته می‌شود.';
                }
            }
        }

        if ($error === '') {
            $oldLogo = (string)($sets['site_logo'] ?? '');
            try {
                $db->beginTransaction();
                foreach ($fieldLimits as $field => $_maxLength) {
                    upsertSetting($db, $field, $formValues[$field] ?? '');
                }
                if ($newLogo !== '') upsertSetting($db, 'site_logo', $newLogo);
                $db->commit();

                clearSettingCache();
                if ($newLogo !== '' && $oldLogo !== '' && $oldLogo !== $newLogo) {
                    scheduleFileDeletion($oldLogo);
                }
                $_SESSION['flash_msg'] = 'تنظیمات سایت با موفقیت ذخیره شد.';
                $_SESSION['flash_type'] = 'success';
                redirect(url('admin/settings'));
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Admin settings save failed: ' . get_class($e));
                $error = 'ذخیره تنظیمات انجام نشد. دوباره تلاش کنید.';
            }
        }
    }
}

$sets = array_merge($sets, $formValues);
?>
<div class="admin-page-heading">
    <div>
        <h1 class="h4 mb-1">تنظیمات سایت</h1>
        <p class="text-muted mb-0">اطلاعات عمومی و راه‌های ارتباطی که در صفحه‌های عمومی نمایش داده می‌شوند.</p>
    </div>
</div>

<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="admin-form" autocomplete="off">
    <?= csrfField() ?>
    <div class="row g-4 admin-settings-layout">
        <div class="col-lg-8">
            <section class="admin-card mb-4" aria-labelledby="admin-site-info-heading">
                <div class="admin-card-header" id="admin-site-info-heading">اطلاعات سایت</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label for="setting-site-name">نام سایت</label>
                        <input id="setting-site-name" type="text" name="site_name" class="form-control" maxlength="100" required value="<?= sanitize($sets['site_name'] ?? SITE_NAME) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="setting-site-slogan">شعار سایت</label>
                        <input id="setting-site-slogan" type="text" name="site_slogan" class="form-control" maxlength="200" value="<?= sanitize($sets['site_slogan'] ?? SITE_SLOGAN) ?>">
                    </div>
                    <div>
                        <label for="setting-about-short">معرفی کوتاه</label>
                        <textarea id="setting-about-short" name="about_short" class="form-control" rows="4" maxlength="1200" aria-describedby="about-short-help"><?= sanitize($sets['about_short'] ?? '') ?></textarea>
                        <div class="form-text" id="about-short-help">این متن در صفحهٔ معرفی و بخش‌های عمومی سایت استفاده می‌شود.</div>
                    </div>
                </div>
            </section>

            <section class="admin-card mb-4" aria-labelledby="admin-contact-heading">
                <div class="admin-card-header" id="admin-contact-heading">اطلاعات تماس</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label for="setting-address">نشانی</label>
                        <input id="setting-address" type="text" name="address" class="form-control" maxlength="300" value="<?= sanitize($sets['address'] ?? SITE_ADDRESS) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="setting-phone">شماره تلفن</label>
                        <input id="setting-phone" type="tel" name="phone" class="form-control" maxlength="50" inputmode="tel" value="<?= sanitize($sets['phone'] ?? SITE_PHONE) ?>">
                    </div>
                    <div>
                        <label for="setting-email">ایمیل</label>
                        <input id="setting-email" type="email" name="email" class="form-control" maxlength="254" autocomplete="email" value="<?= sanitize($sets['email'] ?? SITE_EMAIL) ?>">
                    </div>
                </div>
            </section>

            <section class="admin-card" aria-labelledby="admin-social-heading">
                <div class="admin-card-header" id="admin-social-heading">شبکه‌های اجتماعی</div>
                <div class="admin-card-body">
                    <p class="admin-settings-help">پیوندها باید با <code>https://</code> شروع شوند. موارد خالی در سایت نمایش داده نمی‌شوند.</p>
                    <div class="mb-3">
                        <label for="setting-telegram"><i class="bi bi-telegram ms-2 text-info"></i>تلگرام</label>
                        <input id="setting-telegram" type="url" name="social_telegram" class="form-control" maxlength="2048" inputmode="url" placeholder="https://t.me/..." value="<?= sanitize($sets['social_telegram'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="setting-youtube"><i class="bi bi-youtube ms-2 text-danger"></i>یوتیوب</label>
                        <input id="setting-youtube" type="url" name="social_youtube" class="form-control" maxlength="2048" inputmode="url" placeholder="https://youtube.com/..." value="<?= sanitize($sets['social_youtube'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="setting-instagram"><i class="bi bi-instagram ms-2 text-warning"></i>اینستاگرام</label>
                        <input id="setting-instagram" type="url" name="social_instagram" class="form-control" maxlength="2048" inputmode="url" placeholder="https://instagram.com/..." value="<?= sanitize($sets['social_instagram'] ?? '') ?>">
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <aside class="admin-settings-aside">
                <section class="admin-card mb-4" aria-labelledby="admin-logo-heading">
                    <div class="admin-card-header" id="admin-logo-heading">نشان سایت</div>
                    <div class="admin-card-body">
                        <?php $logoDisplay = (string)($sets['site_logo'] ?? 'assets/img/logo.png'); ?>
                        <img class="admin-settings-logo" src="<?= sanitize(imgUrl($logoDisplay)) ?>" alt="نشان فعلی سایت" loading="lazy" decoding="async">
                        <label for="setting-logo" class="mt-3">بارگذاری نشان تازه</label>
                        <input id="setting-logo" type="file" name="logo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="form-text">تصویر معتبر با حجم مجاز انتخاب کنید؛ فایل قبلی پس از ذخیره پاک‌سازی می‌شود.</div>
                    </div>
                </section>
                <div class="admin-card admin-settings-save">
                    <div class="admin-card-body">
                        <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-circle ms-1" aria-hidden="true"></i>ذخیره تنظیمات</button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</form>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
