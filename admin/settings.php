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
/*
 * مدیریت صفحهٔ اصلی. برچسب‌ها همان کلیدهایی‌اند که
 * jhd_homepage_settings() می‌شناسد و صفحهٔ نخست (index.php) مستقیماً از
 * آن‌ها می‌خواند؛ پس هر تیک اینجا بلافاصله در صفحهٔ نخست اثر می‌گذارد.
 * کلیدهای قدیمی (گزارش‌ها، رویدادها، پرسش‌وپاسخ و …) در همان تابع به این
 * شش نگاشت می‌شوند، پس تنظیماتِ ذخیره‌شدهٔ نصب‌های پیشین بی‌صدا از دست
 * نمی‌رود.
 */
$homepageSectionLabels = jhd_home_section_labels();
$homepageCfg = jhd_homepage_settings();
$homepageEnabled = $homepageCfg['sections'];
$homepageHeroId = $homepageCfg['hero_post_id'];
// ریل‌های انتهای مطلب: کلیدهای تازه در همان جدول settings (بدون migration).
$railsCfg = jhd_content_rails_settings();
$homepageCategories = $db->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$homepagePosts = [];
try {
    $homepagePostsStmt = $db->query(
        "SELECT id, title, post_type, published_at
         FROM posts
         WHERE status='published'
         ORDER BY published_at DESC, id DESC
"
    );
    $homepagePosts = $homepagePostsStmt->fetchAll();
} catch (Throwable) {
    $homepagePosts = [];
}

$homepageSources = ['post' => $homepagePosts];
foreach (['book' => 'books', 'lesson' => 'lessons'] as $kind => $table) {
    $homepageSources[$kind] = $db->query("SELECT id,title FROM $table WHERE status='published' ORDER BY created_at DESC,id DESC")->fetchAll();
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

        /*
         * مدیریت صفحهٔ اصلی فقط وقتی بازنویسی می‌شود که فرم واقعاً این بخش را
         * هم فرستاده باشد. یک فرم HTML، تیک‌های خالی را اصلاً نمی‌فرستد، پس
         * «بدون کلید» می‌تواند یا یعنی کاربر همه را برداشته، یا یعنی درخواست
         * از جای دیگری آمده است (مثلاً یک اسکریپت قدیمی که فقط نام سایت را
         * می‌فرستد). نشانگر پنهانِ فرم این دو را از هم جدا می‌کند: بدون آن،
         * چیدمانِ ذخیره‌شده دست‌نخورده می‌ماند و با یک درخواست ناقص به‌طور
         * تصادفی خالی نمی‌شود.
         */
        $homepagePanelPosted = !empty($_POST['homepage_sections_present']);
        if ($homepagePanelPosted) {
            foreach (['homepage_hero_post_id', 'homepage_hero_mode', 'homepage_hero_kind', 'homepage_other_id', 'homepage_feature_ref', 'homepage_density', 'homepage_thumb'] as $field) {
                if (isset($_POST[$field]) && !is_string($_POST[$field])) {
                    http_response_code(400); exit('Invalid homepage setting');
                }
            }
            $postedLayout = $_POST['homepage_layout'] ?? [];
            if (!is_array($postedLayout)) { http_response_code(400); exit('Invalid layout'); }
            foreach ($postedLayout as $item) {
                if (!is_array($item)) { http_response_code(400); exit('Invalid layout'); }
                foreach ($item as $value) {
                    if (!is_string($value)) { http_response_code(400); exit('Invalid layout'); }
                }
            }
            $postedSections = $_POST['homepage_sections'] ?? [];
            if (!is_array($postedSections)) $postedSections = [];
            $postedSections = array_values(array_filter(array_map(
                static fn($value) => is_string($value) ? $value : '',
                $postedSections
            )));
            foreach ($homepageSectionLabels as $sectionKey => $_label) {
                $homepageEnabled[$sectionKey] = in_array($sectionKey, $postedSections, true);
            }
            $homepageHeroId = max(0, (int)($_POST['homepage_hero_post_id'] ?? 0));
            $homepageCfg['hero_mode'] = (string)($_POST['homepage_hero_mode'] ?? ($homepageHeroId ? 'manual' : 'auto'));
            $homepageCfg['hero_kind'] = (string)($_POST['homepage_hero_kind'] ?? 'post');
            if ($homepageCfg['hero_kind'] !== 'post') $homepageHeroId = max(0, (int)($_POST['homepage_other_id'] ?? 0));
            if (isset($_POST['homepage_feature_ref'])) {
                $ref = (string)$_POST['homepage_feature_ref'];
                if (preg_match('/^(post|book|lesson):([0-9]+)$/D', $ref, $match)) {
                    $homepageCfg['hero_kind'] = $match[1];
                    $homepageHeroId = (int)$match[2];
                } else { $homepageHeroId = 0; }
            }
            $homepageCfg['layout'] = is_array($_POST['homepage_layout'] ?? null) ? $_POST['homepage_layout'] : $homepageCfg['layout'];
            // تراکم چیدمان و اندازهٔ بندانگشتی — jhd_normalize_home_config
            // مقدارها را به گزینه‌های مجاز محدود می‌کند.
            $homepageCfg['density'] = (string)($_POST['homepage_density'] ?? 'compact');
            $homepageCfg['thumb'] = (string)($_POST['homepage_thumb'] ?? 'sm');
        }
        if ($homepagePanelPosted) {
            $homepageCfg['sections'] = array_replace($homepageCfg['sections'], $homepageEnabled);
            $homepageCfg['hero_post_id'] = $homepageHeroId;
        }
        /*
         * ریل‌های انتهای مطلب هم مانند بخش‌های صفحهٔ اصلی با نشانگر پنهان
         * از «درخواست ناقص» جدا می‌شوند؛ بدون نشانگر، تنظیمات ذخیره‌شده
         * دست‌نخورده می‌ماند.
         */
        $railsPanelPosted = !empty($_POST['content_rails_present']);
        if ($railsPanelPosted) {
            foreach (['rails_latest_count', 'rails_popular_count', 'rails_min_views'] as $field) {
                if (isset($_POST[$field]) && !is_string($_POST[$field])) {
                    http_response_code(400); exit('Invalid rails setting');
                }
            }
            $postedRails = $_POST['content_rails'] ?? [];
            if (!is_array($postedRails)) $postedRails = [];
            $postedRails = array_values(array_filter(array_map(
                static fn($value) => is_string($value) ? $value : '',
                $postedRails
            )));
            $railsCfg['latest_enabled'] = in_array('latest', $postedRails, true);
            $railsCfg['popular_enabled'] = in_array('popular', $postedRails, true);
            $railsCfg['latest_count'] = (int)($_POST['rails_latest_count'] ?? 4);
            $railsCfg['popular_count'] = (int)($_POST['rails_popular_count'] ?? 4);
            $railsCfg['min_views'] = (int)($_POST['rails_min_views'] ?? 50);
        }
        $railsCfg = jhd_normalize_rails_config($railsCfg);
        $railsConfigValue = json_encode($railsCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $homepageCfg = jhd_normalize_home_config($homepageCfg);
        if ($homepagePanelPosted && $homepageCfg['hero_mode'] === 'manual' && !jhd_home_feature($homepageCfg)) {
            $error = 'مطلب شاخص معتبر نیست؛ یک محتوای منتشرشده انتخاب کنید.';
        }
        $homepageConfigValue = json_encode($homepageCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
                upsertSetting($db, 'content_rails_config', $railsConfigValue);
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
                <div class="admin-card-header" id="admin-homepage-heading">مدیریت صفحهٔ اصلی</div>
                <div class="admin-card-body">
                    <?php if (APP_ENV === 'production' && env_value('VIEW_HASH_KEY', '') === ''): ?>
                    <div class="alert alert-warning">ثبت بازدید جدید تا تنظیم VIEW_HASH_KEY در محیط استقرار غیرفعال است؛ آمار قبلی حفظ شده است. پیش از فعال‌سازی، migration شمارهٔ ۰۰۰۳ را اجرا کنید.</div>
                    <?php endif; ?>
                    <p class="admin-settings-help">هر تیک، یکی از بخش‌های صفحهٔ اصلی را روشن یا خاموش می‌کند و نتیجه <strong>بلافاصله</strong> در صفحهٔ نخست دیده می‌شود. خاموش کردن یک بخش، آن را از سایت حذف نمی‌کند: آرشیو، ناوبری «بیشتر»، فوتر و نقشهٔ سایت همیشه پابرجا می‌مانند.</p>
                    <div class="mb-4">
                        <label class="form-label fw-bold">بخش‌های قابل نمایش در صفحهٔ اصلی</label>
                        <?php /* نشانگر پنهان: بدون آن، «تیکِ خالی» با «درخواستِ ناقص» فرقی ندارد. */ ?>
                        <input type="hidden" name="homepage_sections_present" value="1">
                        <div class="row g-2">
                            <?php foreach ($homepageSectionLabels as $sectionKey => $sectionLabel): ?>
                            <div class="col-12 col-sm-6">
                                <label class="form-check border rounded-3 p-2 d-flex gap-2 align-items-center">
                                    <input class="form-check-input m-0" type="checkbox" name="homepage_sections[]" value="<?= sanitize($sectionKey) ?>" <?= !empty($homepageEnabled[$sectionKey]) ? 'checked' : '' ?>>
                                    <span><?= sanitize($sectionLabel) ?></span>
                                </label>
                                <label class="form-label">عنوان نمایشی
                                  <input class="form-control" name="homepage_layout[<?= $sectionKey ?>][title]" maxlength="100" value="<?= sanitize($homepageCfg['layout'][$sectionKey]['title']) ?>">
                                </label>
                                <div class="d-flex gap-2">
                                  <label>ترتیب (کوچک‌تر، بالاتر)<input class="form-control" type="number" min="0" max="99" name="homepage_layout[<?= $sectionKey ?>][order]" value="<?= $homepageCfg['layout'][$sectionKey]['order'] ?>"></label>
                                  <label>تعداد<input class="form-control" type="number" min="1" max="<?= in_array($sectionKey, ['latest','popular'], true) ? 3 : 12 ?>" name="homepage_layout[<?= $sectionKey ?>][limit]" value="<?= $homepageCfg['layout'][$sectionKey]['limit'] ?>"></label>
                                </div>
                                <?php if ($sectionKey !== 'feature'): ?>
                                <label class="form-label">حالت کارت‌ها
                                  <select class="form-select" name="homepage_layout[<?= $sectionKey ?>][mode]">
                                    <?php foreach (['auto' => 'خودکار: ردیف فشرده در موبایل، کاشی کوچک در دسکتاپ', 'rows' => 'همیشه ردیف افقی فشرده', 'grid' => 'کاشی بزرگ شبکه‌ای'] as $modeValue => $modeLabel): ?>
                                    <option value="<?= $modeValue ?>" <?= $homepageCfg['layout'][$sectionKey]['mode'] === $modeValue ? 'selected' : '' ?>><?= $modeLabel ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </label>
                                <?php endif; ?>
                                <?php if (in_array($sectionKey, ['popular','latest','news','writing','research'], true)): ?>
                                <label class="form-label">دسته‌بندی
                                  <select class="form-select" name="homepage_layout[<?= $sectionKey ?>][category]">
                                    <option value="0">همهٔ دسته‌ها</option>
                                    <?php foreach ($homepageCategories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= $homepageCfg['layout'][$sectionKey]['category'] === (int)$cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </label>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">روش انتخاب مطلب شاخص
                          <select class="form-select" name="homepage_hero_mode">
                            <?php foreach (['auto' => 'خودکار: جدیدترین مطلب منتشرشده', 'manual' => 'انتخاب دستی', 'off' => 'غیرفعال'] as $mode => $label): ?>
                            <option value="<?= $mode ?>" <?= $homepageCfg['hero_mode'] === $mode ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label for="homepage-hero-post" class="form-label fw-bold">انتخاب از همهٔ محتوای منتشرشده</label>
                        <select id="homepage-hero-post" name="homepage_feature_ref" class="form-select">
                          <option value="">انتخاب محتوا</option>
                          <?php foreach ($homepageSources as $kind => $items): ?>
                          <optgroup label="<?= $kind === 'post' ? 'مطالب' : ($kind === 'book' ? 'کتاب‌ها' : 'درس‌ها') ?>">
                            <?php foreach ($items as $hp): $ref = $kind . ':' . (int)$hp['id']; ?>
                            <option value="<?= $ref ?>" <?= $homepageCfg['hero_kind'] === $kind && $homepageHeroId === (int)$hp['id'] ? 'selected' : '' ?>><?= sanitize(postTypeLabel($kind === 'post' ? $hp['post_type'] : $kind)) ?> — <?= sanitize($hp['title']) ?></option>
                            <?php endforeach; ?>
                          </optgroup>
                          <?php endforeach; ?>
                        </select>
                        <div class="form-text">این مطلب با تصویر در یک طرف و تیتر و خلاصه در طرف دیگر، به‌شکل افقی و کنترل‌شده بالای صفحهٔ اصلی می‌نشیند؛ در موبایل هم افقی می‌ماند.</div>
                        <div class="row g-2 mt-1">
                          <div class="col-12 col-sm-6">
                            <label class="form-label">تراکم چیدمان صفحهٔ اصلی
                              <select class="form-select" name="homepage_density">
                                <?php foreach (['compact' => 'فشرده (اسکرول کمتر، مطالب بیشتر)', 'cozy' => 'راحت (فاصلهٔ بیشتر بین بخش‌ها)'] as $densityValue => $densityLabel): ?>
                                <option value="<?= $densityValue ?>" <?= $homepageCfg['density'] === $densityValue ? 'selected' : '' ?>><?= $densityLabel ?></option>
                                <?php endforeach; ?>
                              </select>
                            </label>
                          </div>
                          <div class="col-12 col-sm-6">
                            <label class="form-label">اندازهٔ بندانگشتی کارت‌های فشرده
                              <select class="form-select" name="homepage_thumb">
                                <?php foreach (['sm' => 'کوچک (پیش‌فرض)', 'md' => 'متوسط'] as $thumbValue => $thumbLabel): ?>
                                <option value="<?= $thumbValue ?>" <?= $homepageCfg['thumb'] === $thumbValue ? 'selected' : '' ?>><?= $thumbLabel ?></option>
                                <?php endforeach; ?>
                              </select>
                            </label>
                          </div>
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small">
                        تیک «برگزیدهٔ سردبیر» را هنگام ایجاد یا ویرایش هر مطلب فعال کنید تا در فهرست‌های دیگر هم بالا بیاید. در حالت خودکار، جدیدترین مطلب منتشرشده (با ترتیب تاریخ و سپس شناسه) شاخص می‌شود؛ حالت دستی فقط همان محتوای انتخاب‌شده را نمایش می‌دهد.
                    </div>
                </div>
            </section>

            <section class="admin-card mb-4" aria-labelledby="admin-rails-heading">
                <div class="admin-card-header" id="admin-rails-heading">انتهای مطلب: تازه‌ترین‌ها و پربازدیدترین‌ها</div>
                <div class="admin-card-body">
                    <p class="admin-settings-help">دو فهرست کوچک و افقی که پس از متن هر خبر/مقاله و پیش از فوتر می‌آیند: «تازه‌ترین‌های هم‌نوع» با تاریخ واقعی انتشار و «پربازدیدترین‌های هم‌نوع» با شمارندهٔ واقعی بازدید. اگر مطلب واجد شرایطی نباشد، بخش مربوطه نمایش داده نمی‌شود و هیچ محتوای ساختگی جای آن را نمی‌گیرد.</p>
                    <?php /* نشانگر پنهان: تیک‌های خالی از درخواست ناقص جدا می‌شوند. */ ?>
                    <input type="hidden" name="content_rails_present" value="1">
                    <div class="row g-2">
                        <div class="col-12 col-sm-6">
                            <label class="form-check border rounded-3 p-2 d-flex gap-2 align-items-center">
                                <input class="form-check-input m-0" type="checkbox" name="content_rails[]" value="latest" <?= $railsCfg['latest_enabled'] ? 'checked' : '' ?>>
                                <span>نمایش «تازه‌ترین‌های هم‌نوع»</span>
                            </label>
                            <label class="form-label">تعداد موارد تازه‌ترین‌ها
                                <input class="form-control" type="number" min="1" max="8" name="rails_latest_count" value="<?= (int)$railsCfg['latest_count'] ?>">
                            </label>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-check border rounded-3 p-2 d-flex gap-2 align-items-center">
                                <input class="form-check-input m-0" type="checkbox" name="content_rails[]" value="popular" <?= $railsCfg['popular_enabled'] ? 'checked' : '' ?>>
                                <span>نمایش «پربازدیدترین‌های هم‌نوع»</span>
                            </label>
                            <label class="form-label">تعداد موارد پربازدیدترین‌ها
                                <input class="form-control" type="number" min="1" max="8" name="rails_popular_count" value="<?= (int)$railsCfg['popular_count'] ?>">
                            </label>
                            <label class="form-label">آستانهٔ پربازدیدشدن (حداقل بازدید واقعی)
                                <input class="form-control" type="number" min="1" max="100000" name="rails_min_views" value="<?= (int)$railsCfg['min_views'] ?>">
                            </label>
                            <div class="form-text">این آستانه برای بخش «پربازدیدترین‌ها» در صفحهٔ اصلی هم اعمال می‌شود.</div>
                        </div>
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
