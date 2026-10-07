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
    'about_long' => 12000,
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
    'about_long' => '',
    'address' => SITE_ADDRESS,
    'phone' => SITE_PHONE,
    'email' => SITE_EMAIL,
    'social_telegram' => '',
    'social_youtube' => '',
    'social_instagram' => '',
];
$homepageSectionLabels = [
    'hero' => 'مطلب شاخص',
    'editor_picks' => 'برگزیدهٔ سردبیر',
    'latest' => 'تازه‌ترین مطالب',
    'news' => 'اخبار',
    'articles' => 'مقالات',
    'reports' => 'گزارش‌ها',
    'research' => 'پژوهش‌ها',
    'topics' => 'موضوعات',
    'events' => 'رویدادها و برنامه‌ها',
    'books' => 'کتابخانه دیجیتال',
    'lessons' => 'دروس',
    'media' => 'رسانه',
];
$homepageCfgRaw = trim((string)($sets['homepage_config'] ?? ''));
$homepageCfg = json_decode($homepageCfgRaw, true);
if (!is_array($homepageCfg)) $homepageCfg = [];
$homepageEnabled = [];
foreach ($homepageSectionLabels as $sectionKey => $_label) {
    $homepageEnabled[$sectionKey] = array_key_exists($sectionKey, $homepageCfg['sections'] ?? [])
        ? (bool)$homepageCfg['sections'][$sectionKey] : true;
}
$homepageHeroId = max(0, (int)($homepageCfg['hero_post_id'] ?? 0));

$homepagePosts = [];
try {
    $homepagePostsStmt = $db->query(
        "SELECT id, title, post_type, published_at
         FROM posts
         WHERE status='published'
         ORDER BY published_at DESC, id DESC
         LIMIT 120"
    );
    $homepagePosts = $homepagePostsStmt->fetchAll();
} catch (Throwable) {
    $homepagePosts = [];
}

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

        $postedSections = $_POST['homepage_sections'] ?? [];
        if (!is_array($postedSections)) $postedSections = [];
        foreach ($homepageSectionLabels as $sectionKey => $_label) {
            $homepageEnabled[$sectionKey] = in_array($sectionKey, $postedSections, true);
        }
        $homepageHeroId = max(0, (int)($_POST['homepage_hero_post_id'] ?? 0));
        if ($homepageHeroId > 0) {
            $heroExists = false;
            foreach ($homepagePosts as $hp) {
                if ((int)$hp['id'] === $homepageHeroId) { $heroExists = true; break; }
            }
            if (!$heroExists) {
                $homepageHeroId = 0;
                $error = 'مطلب شاخص صفحه اصلی معتبر نیست؛ یک مطلب منتشرشده انتخاب کنید.';
            }
        }
        $homepageConfigValue = json_encode([
            'sections' => $homepageEnabled,
            'hero_post_id' => $homepageHeroId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $newLogo = '';
        if ($error === '' && (isset($_FILES['logo']) || jhdDirectUploadPath('logo') !== '')) {
            $directLogo = jhdDirectUploadPath('logo');
            if ($directLogo !== '') {
                $newLogo = adoptDirectUpload($directLogo, 'image', 'site');
                if ($newLogo === '') {
                    $error = 'بارگذاری لوگو ناموفق بود. فایل مستقیم در Storage ثبت نشد.';
                }
            } else {
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
        }

        if ($error === '') {
            $oldLogo = (string)($sets['site_logo'] ?? '');
            try {
                $db->beginTransaction();
                foreach ($fieldLimits as $field => $_maxLength) {
                    upsertSetting($db, $field, $formValues[$field] ?? '');
                }
                upsertSetting($db, 'homepage_config', $homepageConfigValue);
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
                    <div class="mb-3">
                        <label for="setting-about-short">معرفی کوتاه</label>
                        <textarea id="setting-about-short" name="about_short" class="form-control" rows="4" maxlength="1200" aria-describedby="about-short-help"><?= sanitize($sets['about_short'] ?? '') ?></textarea>
                        <div class="form-text" id="about-short-help">این متن در صفحهٔ معرفی و بخش‌های عمومی سایت استفاده می‌شود.</div>
                    </div>
                    <div class="mt-3">
                        <label for="setting-about-long">معرفی کامل سایت</label>
                        <textarea id="setting-about-long" name="about_long" class="form-control" rows="12" maxlength="12000" aria-describedby="about-long-help"><?= sanitize($sets['about_long'] ?? '') ?></textarea>
                        <div class="form-text" id="about-long-help">برای متن کامل معرفی مدرسه، سابقه، اهداف، فعالیت‌های آموزشی، پژوهشی، فرهنگی و اطلاعات تکمیلی استفاده کنید. متن شما در صفحهٔ «درباره ما» نمایش داده می‌شود.</div>
                    </div>
                </div>
            </section>

            <section class="admin-card mb-4" aria-labelledby="admin-homepage-heading">
                <div class="admin-card-header" id="admin-homepage-heading">چیدمان صفحهٔ اصلی</div>
                <div class="admin-card-body">
                    <p class="admin-settings-help">مدیر می‌تواند بخش‌های ویترین صفحهٔ اصلی را روشن/خاموش کند و مطلب شاخص را جداگانه انتخاب کند. ترتیب نمایش بخش‌ها در سایت ثابت و ویرایشی است.</p>
                    <div class="mb-4">
                        <label class="form-label fw-bold">بخش‌های قابل نمایش در صفحهٔ اصلی</label>
                        <div class="row g-2">
                            <?php foreach ($homepageSectionLabels as $sectionKey => $sectionLabel): ?>
                            <div class="col-12 col-sm-6">
                                <label class="form-check border rounded-3 p-2 d-flex gap-2 align-items-center">
                                    <input class="form-check-input m-0" type="checkbox" name="homepage_sections[]" value="<?= sanitize($sectionKey) ?>" <?= !empty($homepageEnabled[$sectionKey]) ? 'checked' : '' ?>>
                                    <span><?= sanitize($sectionLabel) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label for="homepage-hero-post" class="form-label fw-bold">مطلب شاخص صفحهٔ اصلی</label>
                        <select id="homepage-hero-post" name="homepage_hero_post_id" class="form-select">
                            <option value="0">بدون انتخاب دستی — استفاده از برگزیدهٔ منتشرشده</option>
                            <?php foreach ($homepagePosts as $hp): ?>
                            <option value="<?= (int)$hp['id'] ?>" <?= $homepageHeroId === (int)$hp['id'] ? 'selected' : '' ?>>
                                <?= sanitize(postTypeLabel((string)$hp['post_type'])) ?> — <?= sanitize($hp['title']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">این انتخاب فقط مطلب شاخص بزرگ ابتدای صفحه را کنترل می‌کند.</div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small">
                        برای <strong>برگزیدهٔ سردبیر</strong>، هنگام ایجاد یا ویرایش هر مطلب تیک «برگزیدهٔ سردبیر» را فعال کنید؛ این بخش می‌تواند مستقل از مطلب شاخص روشن/خاموش شود.
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
                <section class="admin-card mb-4" aria-labelledby="admin-media-heading">
                    <div class="admin-card-header" id="admin-media-heading">مدیریت رسانه</div>
                    <div class="admin-card-body">
                        <p class="admin-settings-help mb-3">دسترسی سریع به کتابخانه رسانه، ویدیوها و صوت‌ها از همین بخش.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-sm btn-outline-primary" href="<?= adminUrl('media') ?>"><i class="bi bi-images ms-1"></i>کتابخانه رسانه</a>
                            <a class="btn btn-sm btn-outline-primary" href="<?= adminUrl('videos') ?>"><i class="bi bi-camera-video ms-1"></i>ویدیوها</a>
                            <a class="btn btn-sm btn-outline-primary" href="<?= adminUrl('audios') ?>"><i class="bi bi-music-note-beamed ms-1"></i>صوت‌ها</a>
                        </div>
                        <div class="form-text mt-3">محدودیت فعلی برنامه: تصویر تا 20MB و ویدیو تا 150MB برای هر فایل؛ ویدیوهای بزرگ‌تر از سقف درخواست Vercel با آپلود مستقیم و resumable به Storage فرستاده می‌شوند.</div>
                    </div>
                </section>
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
