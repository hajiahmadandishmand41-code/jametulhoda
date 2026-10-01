<?php
/**
 * contact.php — فرم تماس با مدیر
 * اصلاح‌شده: ذخیره در دیتابیس با is_read، CSRF، اعتبارسنجی کامل
 */
$pageTitle = 'تماس با ما';
$pageDesc = 'راه‌های ارتباط با جامعة‌الهدی در کابل، نشانی، شماره تماس و فرم ارسال پیام به مجموعه.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startSecureSession();

// ─── اطمینان از وجود جدول پیام‌ها (سازگار با is_read) ────────────────────────
function ensureContactTable(): void {
    // Schema managed by bin/migrate.php.
}

ensureContactTable();

$success = false;
$errors  = [];
$formData = [];

// ─── پردازش فرم ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // بررسی CSRF
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'خطای امنیتی. لطفاً صفحه را رفرش کرده و دوباره تلاش کنید.';
    } else {
        $name    = trim(jhd_string_field($_POST, 'name'));
        $email   = trim(jhd_string_field($_POST, 'email'));
        $phone   = trim(jhd_string_field($_POST, 'phone'));
        $subject = trim(jhd_string_field($_POST, 'subject'));
        $message = trim(jhd_string_field($_POST, 'message'));

        $formData = compact('name','email','phone','subject','message');

        // اعتبارسنجی
        if (mb_strlen($name, 'UTF-8') < 2) {
            $errors[] = 'نام باید حداقل ۲ حرف باشد.';
        }
        if (!$subject) {
            $errors[] = 'موضوع پیام الزامی است.';
        }
        if (mb_strlen($message, 'UTF-8') < 10) {
            $errors[] = 'متن پیام باید حداقل ۱۰ کاراکتر باشد.';
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'آدرس ایمیل معتبر نیست.';
        }

        // دریافت IP
        $ip = clientIp();

        // محدودیت نرخ: حداکثر ۳ پیام در ۳۰ دقیقه از یک IP
        if (empty($errors) && $ip) {
            try {
                $db     = getDB();
                // بررسی وجود ستون ip_address قبل از کوئری
                $rstmt  = $db->prepare(
                    "SELECT COUNT(*) FROM contact_messages
                     WHERE ip_address = ? AND created_at > (NOW() - INTERVAL '30 minutes')"
                );
                $rstmt->execute([$ip]);
                if ((int)$rstmt->fetchColumn() >= 3) {
                    $errors[] = 'تعداد پیام‌های ارسالی بیش از حد مجاز است. لطفاً ۳۰ دقیقه دیگر تلاش کنید.';
                }
            } catch (PDOException $e) {
                // اگر DB مشکل داشت، ادامه بده
                error_log('Rate limit check error: ' . get_class($e));
            }
        }

        if (empty($errors)) {
            try {
                $db   = getDB();
                $stmt = $db->prepare(
                    "INSERT INTO contact_messages (name, email, phone, subject, message, ip_address, is_read, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
                );
                $stmt->execute([
                    $name,
                    $email   ?: null,
                    $phone   ?: null,
                    $subject ?: null,
                    $message,
                    $ip      ?: null,
                ]);
                $success  = true;
                $formData = []; // پاک کردن فرم
                // ابطال CSRF token برای جلوگیری از ارسال مجدد
                unset($_SESSION[CSRF_TOKEN_NAME]);
            } catch (PDOException $e) {
                error_log('Contact form save error: ' . get_class($e));
                $errors[] = 'خطا در ذخیره پیام. لطفاً دوباره تلاش کنید.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-bar">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= siteUrl() ?>">صفحه اصلی</a></li>
                <li class="breadcrumb-item active">تماس با ما</li>
            </ol>
        </nav>
    </div>
</div>

<div class="jhd-section">
    <div class="container">
        <div class="row g-4">
            <!-- فرم تماس -->
            <div class="col-lg-7">
                <?= jhd_page_head([
                    'eyebrow' => 'پل ارتباطی شما با مدرسه',
                    'icon' => 'bi-envelope',
                    'title' => 'تماس با مدیریت',
                    'lead' => 'برای ارسال پیام، پرسش یا پیشنهاد فرم زیر را پر کنید؛ در اسرع وقت پاسخ خواهیم داد.',
                ]) ?>

                <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div>
                        <strong>پیام شما با موفقیت ارسال شد!</strong><br>
                        <span class="small">با تشکر از توجه شما. ما به زودی پاسخ خواهیم داد.</span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill ms-2"></i>
                    <strong>لطفاً خطاهای زیر را برطرف کنید:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $e): ?>
                        <li><?= sanitize($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="post" id="contactForm" class="jhd-contact-card" novalidate>
                    <?= csrfField() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="cf_name">
                                نام و نام خانوادگی <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="cf_name" name="name" class="form-control <?= (!empty($errors) && mb_strlen($formData['name']??'','UTF-8') < 2) ? 'is-invalid' : '' ?>"
                                value="<?= sanitize($formData['name'] ?? '') ?>"
                                placeholder="نام کامل شما"
                                required minlength="2" maxlength="200">
                            <div class="invalid-feedback">نام باید حداقل ۲ حرف باشد.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="cf_phone">شماره تماس</label>
                            <input type="tel" id="cf_phone" name="phone" class="form-control"
                                value="<?= sanitize($formData['phone'] ?? '') ?>"
                                placeholder="شماره تلفن (اختیاری)"
                                maxlength="30">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold" for="cf_email">ایمیل</label>
                            <input type="email" id="cf_email" name="email" class="form-control"
                                value="<?= sanitize($formData['email'] ?? '') ?>"
                                placeholder="آدرس ایمیل (اختیاری)"
                                maxlength="200">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold" for="cf_subject">
                                موضوع <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="cf_subject" name="subject" class="form-control <?= (!empty($errors) && empty($formData['subject'])) ? 'is-invalid' : '' ?>"
                                value="<?= sanitize($formData['subject'] ?? '') ?>"
                                placeholder="موضوع پیام خود را بنویسید"
                                required maxlength="300">
                            <div class="invalid-feedback">موضوع پیام الزامی است.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold" for="cf_message">
                                متن پیام <span class="text-danger">*</span>
                            </label>
                            <textarea id="cf_message" name="message" rows="6"
                                class="form-control <?= (!empty($errors) && mb_strlen($formData['message']??'','UTF-8') < 10) ? 'is-invalid' : '' ?>"
                                placeholder="پیام خود را اینجا بنویسید..."
                                required minlength="10"><?= sanitize($formData['message'] ?? '') ?></textarea>
                            <div class="invalid-feedback">متن پیام باید حداقل ۱۰ کاراکتر باشد.</div>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                                <i class="bi bi-send-fill ms-1"></i>ارسال پیام
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- اطلاعات تماس -->
            <div class="col-lg-5">
                <div class="contact-info-box p-4 bg-soft rounded-xl h-100">
                    <h4 class="fw-bold mb-4"><i class="bi bi-info-circle ms-2 text-gold"></i>اطلاعات تماس</h4>

                    <div class="contact-item d-flex align-items-start gap-3 mb-4">
                        <div class="contact-icon-box">
                            <i class="bi bi-geo-alt-fill text-primary fs-4"></i>
                        </div>
                        <div>
                            <div class="fw-bold mb-1">آدرس</div>
                            <div class="text-muted"><?= sanitize(getSetting('address', SITE_ADDRESS)) ?></div>
                        </div>
                    </div>

                    <div class="contact-item d-flex align-items-start gap-3 mb-4">
                        <div class="contact-icon-box">
                            <i class="bi bi-telephone-fill text-success fs-4"></i>
                        </div>
                        <div>
                            <div class="fw-bold mb-1">تلفن</div>
                            <a href="tel:<?= sanitize(getSetting('phone', SITE_PHONE)) ?>" class="text-decoration-none text-muted">
                                <?= sanitize(getSetting('phone', SITE_PHONE)) ?>
                            </a>
                        </div>
                    </div>

                    <div class="contact-item d-flex align-items-start gap-3 mb-4">
                        <div class="contact-icon-box">
                            <i class="bi bi-envelope-fill text-warning fs-4"></i>
                        </div>
                        <div>
                            <div class="fw-bold mb-1">ایمیل</div>
                            <a href="mailto:<?= sanitize(getSetting('email', SITE_EMAIL)) ?>" class="text-decoration-none text-muted">
                                <?= sanitize(getSetting('email', SITE_EMAIL)) ?>
                            </a>
                        </div>
                    </div>

                    <div class="contact-item d-flex align-items-start gap-3">
                        <div class="contact-icon-box">
                            <i class="bi bi-clock-fill text-info fs-4"></i>
                        </div>
                        <div>
                            <div class="fw-bold mb-1">ساعات کاری</div>
                            <div class="text-muted">شنبه تا چهارشنبه: ۸ صبح تا ۵ عصر</div>
                        </div>
                    </div>

                    <!-- شبکه‌های اجتماعی -->
                    <?php
                    $telegram = safeExternalUrl((string)getSetting('social_telegram'));
                    $youtube = safeExternalUrl((string)getSetting('social_youtube'));
                    ?>
                    <?php if ($telegram || $youtube): ?>
                    <hr>
                    <div class="social-links d-flex gap-3 mt-3">
                        <?php if ($telegram): ?>
                        <a href="<?= sanitize($telegram) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-telegram ms-1"></i>تلگرام
                        </a>
                        <?php endif; ?>
                        <?php if ($youtube): ?>
                        <a href="<?= sanitize($youtube) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-youtube ms-1"></i>یوتیوب
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// اعتبارسنجی سمت کلاینت
(function() {
    var form = document.getElementById('contactForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        form.classList.add('was-validated');
        // غیرفعال کردن دکمه برای جلوگیری از ارسال مجدد
        var btn = document.getElementById('submitBtn');
        if (btn && form.checkValidity()) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm ms-2" role="status"></span>در حال ارسال...';
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
