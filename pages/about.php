<?php
/**
 * about.php — درباره مدرسه
 */
$pageTitle = 'مدرسه علمیه جامعه‌الهدی در کابل';
$pageDesc = 'معرفی مدرسه علمیه جامعه‌الهدی در کابل؛ مرکز علمی، آموزشی و پژوهشی علوم اسلامی با تمرکز بر آموزش، پژوهش و معارف قرآن و عترت.';
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
            'title' => 'مدرسه علمیه جامعه‌الهدی در کابل',
            'lead' => 'مرکز علمی، آموزشی و پژوهشی علوم اسلامی در پرتو قرآن و عترت؛ با محوریت آموزش، پژوهش و ترویج معارف اسلامی.',
        ]) ?>

        <?php
        $organizationName = 'مدرسه علمیه جامعه‌الهدی';
        $organizationDescription = 'مرکز علمی، آموزشی و پژوهشی علوم اسلامی در کابل، افغانستان؛ با تمرکز بر آموزش علوم اسلامی، تربیت طلاب، پژوهش دینی و ترویج فرهنگ قرآنی و اهل‌بیت (ع).';
        $organizationSameAs = [];
        foreach ([
            safeExternalUrl(getSetting('social_telegram')),
            safeExternalUrl(getSetting('social_youtube')),
            safeExternalUrl(getSetting('social_instagram')),
        ] as $sameAsUrl) {
            if ($sameAsUrl !== '') $organizationSameAs[] = $sameAsUrl;
        }
        $organizationJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'EducationalOrganization',
            '@id' => rtrim(SITE_URL, '/') . '/#organization',
            'name' => $organizationName,
            'alternateName' => ['جامعة‌الهدی', 'مدرسه علمیه جامعه‌الهدی'],
            'url' => rtrim(SITE_URL, '/') . '/about',
            'logo' => canonicalUrl('assets/img/logo.png'),
            'description' => $organizationDescription,
            'areaServed' => [
                '@type' => 'Country',
                'name' => 'Afghanistan',
            ],
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'کابل',
                'addressCountry' => 'AF',
            ],
            'founder' => [
                '@type' => 'Person',
                'name' => 'آیت‌الله محمدحسین حلیمی',
            ],
            'knowsAbout' => [
                'فقه و اصول',
                'تفسیر قرآن',
                'حدیث شناسی',
                'کلام و فلسفه',
                'ادبیات عرب',
                'تاریخ اسلام',
            ],
        ];
        $aboutPageJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'AboutPage',
            '@id' => rtrim(SITE_URL, '/') . '/about#webpage',
            'url' => rtrim(SITE_URL, '/') . '/about',
            'name' => 'مدرسه علمیه جامعه‌الهدی در کابل',
            'description' => $pageDesc,
            'mainEntity' => ['@id' => rtrim(SITE_URL, '/') . '/#organization'],
        ];
        if ($aboutEmailHref !== '') $organizationJsonLd['email'] = 'mailto:' . $aboutEmailHref;
        if ($aboutPhoneHref !== '') $organizationJsonLd['telephone'] = $aboutPhone;
        if ($organizationSameAs) $organizationJsonLd['sameAs'] = $organizationSameAs;
        ?>
        <script type="application/ld+json"><?= json_encode($organizationJsonLd, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
        <script type="application/ld+json"><?= json_encode($aboutPageJsonLd, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
        <div class="row g-4 align-items-start">
            <div class="col-lg-8">
                <article class="jhd-prose" aria-labelledby="about-title">
                    <h2 id="about-title" class="about-card-title"><i class="bi bi-building ms-2" aria-hidden="true"></i>معرفی مدرسه علمیه جامعه‌الهدی در کابل</h2>
                    <p>مدرسه علمیه جامعه‌الهدی در کابل، یک مرکز آموزشی و علمی در حوزه علوم اسلامی است که محتوای این وب‌سایت برای معرفی فعالیت‌های آموزشی، پژوهشی، فرهنگی و تربیتی آن و دسترسی آسان‌تر به محتوای مرتبط سامان یافته است.</p>
                    <p>جامعه‌الهدی در کنار آموزش دینی و علوم اسلامی، به مطالعه، پژوهش و عرضه محتوای علمی توجه دارد و می‌کوشد محیطی منظم و مناسب برای یادگیری، اندیشه‌ورزی و رشد علمی و اخلاقی طلاب و علاقه‌مندان فراهم کند.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-book ms-2" aria-hidden="true"></i>آموزش علوم دینی و اسلامی</h2>
                    <p>آموزش علوم اسلامی از محورهای اصلی محتوای مدرسه است. در بخش‌های آموزشی و علمی سایت، موضوعاتی مانند فقه و اصول، تفسیر قرآن، حدیث‌شناسی، کلام و فلسفه، ادبیات عرب و تاریخ اسلام بازتاب یافته است. هدف از ارائه این محتوا، فراهم‌کردن زمینه‌ای برای مطالعه منظم و تقویت فهم علمی و دینی است.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-lightbulb ms-2" aria-hidden="true"></i>رشد علمی و فکری</h2>
                    <p>یادگیری تنها به دریافت مطالب محدود نمی‌شود؛ مطالعه دقیق، پرسش‌گری، تحلیل و توجه به منابع علمی نیز بخشی از مسیر رشد فکری است. جامعه‌الهدی از طریق درس‌ها، کتاب‌ها، مقالات، پژوهش‌ها و موضوعات آموزشی، بستری برای پیگیری این مسیر فراهم می‌کند.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-journal-text ms-2" aria-hidden="true"></i>پژوهش و تولید محتوای علمی</h2>
                    <p>پژوهش، مطالعه و تولید محتوای علمی از بخش‌های مهم فعالیت محتوایی جامعه‌الهدی است. این وب‌سایت امکان عرضه و دسترسی به مقاله‌ها، پژوهش‌ها، گزارش‌ها، کتاب‌ها، درس‌ها و دیگر مطالب آموزشی مرتبط را فراهم می‌کند تا محتوای علمی با نظم بهتر در اختیار مخاطبان قرار گیرد.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-people ms-2" aria-hidden="true"></i>فعالیت‌های فرهنگی و تربیتی</h2>
                    <p>در کنار آموزش و پژوهش، فعالیت‌های فرهنگی و تربیتی نیز در معرفی جامعه‌الهدی جایگاه دارد. توجه به اخلاق، مسئولیت‌پذیری، نظم، هویت دینی و حضور آگاهانه در جامعه، زمینه‌ای برای رشد متوازن علمی، اخلاقی و اجتماعی نسل جوان فراهم می‌کند.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-collection ms-2" aria-hidden="true"></i>کتاب، درس و محتوای آموزشی</h2>
                    <p>بخش‌های مختلف سایت برای دسترسی ساختاریافته به کتاب‌ها، درس‌ها، مقاله‌ها، گزارش‌ها، پژوهش‌ها، اخبار و دیگر محتوای آموزشی و علمی طراحی شده‌اند. این ساختار به مخاطبان کمک می‌کند مطالب مورد نیاز خود را بر اساس موضوع و نوع محتوا آسان‌تر پیدا و مطالعه کنند.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-phone ms-2" aria-hidden="true"></i>آموزش و دسترسی دیجیتال</h2>
                    <p>فناوری و انتشار دیجیتال، امکان دسترسی آسان‌تر به محتوای آموزشی و علمی را فراهم می‌کند. وب‌سایت جامعه‌الهدی با ارائه محتوای متنی و رسانه‌ای، زمینه دسترسی مخاطبان به مطالب آموزشی، پژوهشی و فرهنگی را در بستر وب فراهم ساخته است.</p>

                    <h2 class="about-card-title mt-4"><i class="bi bi-mortarboard ms-2" aria-hidden="true"></i>محیطی برای یادگیری و رشد</h2>
                    <p>جامعه‌الهدی تلاش می‌کند آموزش، مطالعه، پژوهش و فعالیت‌های فرهنگی و تربیتی را در یک مسیر منظم کنار هم قرار دهد؛ مسیری که در آن رشد علمی با پرورش اخلاق، مسئولیت‌پذیری و آمادگی برای نقش‌آفرینی سازنده در جامعه همراه باشد.</p>
                </article>
            </div>
            <div class="col-lg-4">
                <div class="about-sidebar">
                    <div class="about-info-card mb-4">
                        <h2 class="about-card-title"><i class="bi bi-diagram-3 ms-2 text-gold" aria-hidden="true"></i>محورهای فعالیت</h2>
                        <ul class="about-list mb-0">
                            <li>آموزش علوم دینی و اسلامی</li>
                            <li>مطالعه و پژوهش</li>
                            <li>تولید و عرضه محتوای علمی</li>
                            <li>فعالیت‌های فرهنگی و تربیتی</li>
                            <li>کتاب، درس، مقاله و گزارش</li>
                            <li>دسترسی دیجیتال به محتوای آموزشی</li>
                        </ul>
                    </div>
                    <div class="contact-info-card">
                        <h2 class="about-card-title"><i class="bi bi-geo-alt-fill ms-2 text-gold" aria-hidden="true"></i>اطلاعات تماس</h2>
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
