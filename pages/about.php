<?php
/**
 * about.php — درباره مدرسه
 */
$pageTitle = 'درباره ما';
$pageDesc = 'با جامعة‌الهدی، مرکز علمی، آموزشی و پژوهشی علوم اسلامی در کابل آشنا شوید؛ اهداف آموزشی، پژوهشی و راه‌های ارتباط با مجموعه.';
require_once __DIR__ . '/../includes/header.php';

$aboutPhone = getSetting('phone', SITE_PHONE);
$aboutPhoneHref = preg_replace('/[^\p{N}+.(),;*#\s-]/u', '', $aboutPhone) ?? '';
$aboutEmail = getSetting('email', SITE_EMAIL);
$aboutEmailHref = filter_var($aboutEmail, FILTER_VALIDATE_EMAIL) ? $aboutEmail : '';
$aboutTelegram = safeExternalUrl(getSetting('social_telegram'));
$aboutYoutube = safeExternalUrl(getSetting('social_youtube'));
$aboutInstagram = safeExternalUrl(getSetting('social_instagram'));
?>
<div class="breadcrumb-bar">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= siteUrl() ?>">صفحه اصلی</a></li>
                <li class="breadcrumb-item active">درباره ما</li>
            </ol>
        </nav>
    </div>
</div>
<div class="jhd-section">
    <div class="container">
        <?= jhd_page_head([
            'eyebrow' => 'آشنایی با مدرسه',
            'icon' => 'bi-info-circle',
            'title' => 'درباره مدرسه علمیه جامعه‌الهدی',
            'lead' => 'مرکزی علمی، آموزشی و پژوهشی در پرتو قرآن و عترت؛ سرآمد مقصدی برای جویندگان معارف اسلامی.',
        ]) ?>
        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <div class="jhd-prose">
                    <h2 class="about-card-title"><i class="bi bi-star" aria-hidden="true"></i>معرفی مدرسه</h2>
                    <p><?= sanitize(getSetting('about_short', 'مدرسه علمیه جامعه‌الهدی یکی از مراکز علوم دینی در افغانستان است.')) ?></p>
                    <p>این مدرسه با هدف آموزش علوم اسلامی، تربیت طلاب، و ترویج فرهنگ قرآنی و اهل بیت (ع) تأسیس گردیده است. در این مدرسه علوم مختلف اسلامی از جمله فقه، اصول، تفسیر قرآن، حدیث، کلام، فلسفه و ادبیات عرب تدریس می‌شود.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-star" aria-hidden="true"></i>اهداف مدرسه</h2>
                    <ul class="about-list">
                        <li>تربیت طلاب متعهد و آگاه به علوم اسلامی</li>
                        <li>ترویج فرهنگ قرآنی و ارزش‌های اهل بیت (ع)</li>
                        <li>ایجاد بستر مناسب برای تحقیق و پژوهش دینی</li>
                        <li>برگزاری برنامه‌های فرهنگی و مذهبی</li>
                        <li>تقویت هویت اسلامی در جامعه</li>
                    </ul>

                    <h2 class="about-card-title mt-4"><i class="bi bi-star" aria-hidden="true"></i>رشته‌های تحصیلی</h2>
                    <div class="row g-3">
                        <?php
                        $subjects = [
                            ['فقه و اصول','bi bi-book','text-primary'],
                            ['تفسیر قرآن','bi bi-journal-text','text-success'],
                            ['حدیث شناسی','bi bi-chat-quote','text-info'],
                            ['کلام و فلسفه','bi bi-lightbulb','text-warning'],
                            ['ادبیات عرب','bi bi-translate','text-danger'],
                            ['تاریخ اسلام','bi bi-clock-history','text-secondary'],
                        ];
                        foreach ($subjects as [$name, $icon, $color]):
                        ?>
                        <div class="col-6 col-md-4">
                            <div class="subject-box">
                                <i class="<?= $icon ?> <?= $color ?> mb-2 d-block" style="font-size:1.25rem;color:var(--jhd-gold)"></i>
                                <span><?= $name ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="about-sidebar">
                    <div class="about-info-card mb-4">
                        <h5 class="about-card-title"><i class="bi bi-person-fill ms-2 text-gold"></i>مؤسس مدرسه</h5>
                        <p class="mb-0">آیت‌الله محمدحسین حلیمی</p>
                    </div>
                    <div class="contact-info-card">
                        <h5 class="about-card-title"><i class="bi bi-geo-alt-fill ms-2 text-gold"></i>اطلاعات تماس</h5>
                        <ul class="contact-info-list">
                            <li><i class="bi bi-geo-alt text-primary"></i><span><?= sanitize(getSetting('address', SITE_ADDRESS)) ?></span></li>
                            <li><i class="bi bi-telephone text-primary"></i><?php if ($aboutPhoneHref !== ''): ?><a href="tel:<?= sanitize($aboutPhoneHref) ?>" dir="ltr"><?= sanitize($aboutPhone) ?></a><?php else: ?><span><?= sanitize($aboutPhone) ?></span><?php endif; ?></li>
                            <li><i class="bi bi-envelope text-primary"></i><?php if ($aboutEmailHref !== ''): ?><a href="mailto:<?= sanitize($aboutEmailHref) ?>" dir="ltr"><?= sanitize($aboutEmail) ?></a><?php else: ?><span><?= sanitize($aboutEmail) ?></span><?php endif; ?></li>
                        </ul>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <?php if ($aboutTelegram !== ''): ?><a href="<?= sanitize($aboutTelegram) ?>" class="btn btn-sm btn-outline-info" target="_blank" rel="noopener noreferrer"><i class="bi bi-telegram ms-1"></i>تلگرام</a><?php endif; ?>
                            <?php if ($aboutYoutube !== ''): ?><a href="<?= sanitize($aboutYoutube) ?>" class="btn btn-sm btn-outline-danger" target="_blank" rel="noopener noreferrer"><i class="bi bi-youtube ms-1"></i>یوتیوب</a><?php endif; ?>
                            <?php if ($aboutInstagram !== ''): ?><a href="<?= sanitize($aboutInstagram) ?>" class="btn btn-sm btn-outline-warning" target="_blank" rel="noopener noreferrer"><i class="bi bi-instagram ms-1"></i>اینستاگرام</a><?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="<?= siteUrl('contact') ?>" class="btn btn-primary w-100"><i class="bi bi-envelope ms-1"></i>تماس با ما</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
