<?php
// `$currentPage` was never set by any controller. The homepage is detected from
// the resolved route ('/' and the legacy '/index.php' spelling both count).
$jhdCurrentPath = function_exists('current_path') ? rtrim(current_path(), '/') : '';
$isHomePage = (($currentPage ?? '') === 'index.php')
    || in_array($jhdCurrentPath === '' ? '/' : $jhdCurrentPath, ['/', '/index.php'], true);
?>
</main>
<?php
/**
 * footer.php - فوتر عمومی سایت جامع جامعه‌الهدی
 * ساده و حرفه‌ای: برند + معرفی، لینک‌های اصلی، موضوعات، ارتباط با ما، کپی‌رایت.
 */
$jhdPublicDbReady = array_key_exists('JHD_PUBLIC_DB_READY', $GLOBALS)
    ? (bool)$GLOBALS['JHD_PUBLIC_DB_READY']
    : true;

// Public branding is fixed in code so the footer, the header, the <title> and
// the structured data all carry the identical brand. The stored `site_name`
// setting is a variant and was making each of those disagree.
$siteName   = SITE_NAME;
$siteSlogan = SITE_SLOGAN;
$siteLogo   = SITE_LOGO_PATH;

if ($jhdPublicDbReady) {
    $footerTopics  = getTopics(['limit' => 8]);
    $aboutShort    = getSetting('about_short', 'مدرسه علمیه جامعة‌الهدی یکی از مراکز علوم و معارف اسلامی در کابل، افغانستان است.');
    $socialTelegram = getSetting('social_telegram');
    $socialYoutube  = getSetting('social_youtube');
    $socialInstagram = getSetting('social_instagram');
    $siteAddress   = getSetting('address', SITE_ADDRESS);
    $sitePhone     = getSetting('phone', SITE_PHONE);
    $siteEmail     = getSetting('email', SITE_EMAIL);
} else {
    $footerTopics   = [];
    $aboutShort     = 'مدرسه علمیه جامعة‌الهدی یکی از مراکز علوم و معارف اسلامی در کابل، افغانستان است.';
    $socialTelegram = '';
    $socialYoutube  = '';
    $socialInstagram = '';
    $siteAddress    = SITE_ADDRESS;
    $sitePhone      = SITE_PHONE;
    $siteEmail      = SITE_EMAIL;
}
?>
<footer class="main-footer" role="contentinfo">
    <div class="footer-top">
        <div class="container">
            <div class="row g-4">
                <!-- برند و معرفی -->
                <div class="col-lg-4 col-md-6">
                    <div class="footer-brand">
                        <?php if ($siteLogo): ?><img src="<?= imgUrl($siteLogo) ?>" width="40" height="41" alt="نشان <?= sanitize($siteName) ?>"><?php endif; ?>
                        <h5><?= sanitize($siteName) ?></h5>
                    </div>
                    <p class="footer-text"><?= sanitize($siteSlogan) ?></p>
                    <p class="footer-text"><?= sanitize($aboutShort) ?></p>
                    <?php if ($socialTelegram || $socialYoutube || $socialInstagram): ?>
                    <div class="footer-social mt-3">
                        <?php if ($socialTelegram): ?><a href="<?= sanitize(safeExternalUrl($socialTelegram)) ?>" target="_blank" rel="noopener noreferrer" class="social-link" title="تلگرام" aria-label="تلگرام"><i class="bi bi-telegram"></i></a><?php endif; ?>
                        <?php if ($socialYoutube): ?><a href="<?= sanitize(safeExternalUrl($socialYoutube)) ?>" target="_blank" rel="noopener noreferrer" class="social-link" title="یوتیوب" aria-label="یوتیوب"><i class="bi bi-youtube"></i></a><?php endif; ?>
                        <?php if ($socialInstagram): ?><a href="<?= sanitize(safeExternalUrl($socialInstagram)) ?>" target="_blank" rel="noopener noreferrer" class="social-link" title="اینستاگرام" aria-label="اینستاگرام"><i class="bi bi-instagram"></i></a><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- لینک‌های اصلی -->
                <div class="col-lg-3 col-md-6">
                    <h5 class="footer-title">پیوندهای اصلی</h5>
                    <ul class="footer-links">
                        <li><a href="<?= url('news') ?>"><i class="bi bi-chevron-left"></i>اخبار</a></li>
                        <li><a href="<?= url('articles') ?>"><i class="bi bi-chevron-left"></i>مقالات و پژوهش</a></li>
                        <li><a href="<?= url('reports') ?>"><i class="bi bi-chevron-left"></i>گزارش‌ها</a></li>
                        <li><a href="<?= url('books') ?>"><i class="bi bi-chevron-left"></i>کتابخانه</a></li>
                        <li><a href="<?= url('lessons') ?>"><i class="bi bi-chevron-left"></i>دروس</a></li>
                        <li><a href="<?= url('events') ?>"><i class="bi bi-chevron-left"></i>رویدادها</a></li>
                        <li><a href="<?= url('media') ?>"><i class="bi bi-chevron-left"></i>رسانه</a></li>
                        <li><a href="<?= url('topics') ?>"><i class="bi bi-chevron-left"></i>موضوعات</a></li>
                        <li><a href="<?= url('qa') ?>"><i class="bi bi-chevron-left"></i>پرسش و پاسخ</a></li>
                        <li><a href="<?= url('about') ?>"><i class="bi bi-chevron-left"></i>درباره ما</a></li>
                        <li><a href="<?= url('contact') ?>"><i class="bi bi-chevron-left"></i>تماس با ما</a></li>
                    </ul>
                </div>

                <!-- موضوعات -->
                <div class="col-lg-2 col-md-6">
                    <h5 class="footer-title">موضوعات</h5>
                    <?php if ($footerTopics): ?>
                    <ul class="footer-posts">
                        <?php foreach ($footerTopics as $ft): ?>
                        <li><a class="footer-post-title" href="<?= topicUrl($ft) ?>"><?= sanitize((string)$ft['name']) ?></a></li>
                        <?php endforeach; ?>
                        <li><a class="footer-post-title text-gold" href="<?= url('topics') ?>">اطلس کامل ←</a></li>
                    </ul>
                    <?php else: ?>
                    <p class="footer-text">موضوعی منتشر نشده است.</p>
                    <?php endif; ?>
                </div>

                <!-- ارتباط با ما -->
                <div class="col-lg-3 col-md-6">
                    <h5 class="footer-title">ارتباط با ما</h5>
                    <ul class="footer-contact">
                        <li><i class="bi bi-geo-alt"></i><span><?= sanitize($siteAddress) ?></span></li>
                        <li><i class="bi bi-telephone"></i><a href="tel:<?= sanitize($sitePhone) ?>" dir="ltr"><?= sanitize($sitePhone) ?></a></li>
                        <li><i class="bi bi-envelope"></i><a href="mailto:<?= sanitize($siteEmail) ?>" dir="ltr"><?= sanitize($siteEmail) ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php
// سال شمسی جاری برای کپی‌رایت — هماهنگ با تاریخ‌نمایش فارسی بقیهٔ سایت.
$jhdCopyrightYearParts = persianDateParts(date('Y-m-d H:i:s'));
$jhdCopyrightYear = $jhdCopyrightYearParts['year'] ?? (int)date('Y');
?>
    <div class="footer-bottom">
        <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
            <p>&copy; <?= $jhdCopyrightYear ?> <?= sanitize($siteName) ?> — تمامی حقوق محفوظ است.</p>
            <p class="footer-ayah d-none d-md-block mb-0">«طلب العلم فريضة على كل مسلم»</p>
            <p class="d-flex gap-2 mb-0">
                <a href="<?= url('sitemap.xml') ?>">نقشه سایت</a>
                <span aria-hidden="true">·</span>
                <a href="<?= url('search') ?>">جستجو</a>
                <span aria-hidden="true">·</span>
                <a href="<?= loginUrl() ?>">ورود</a>
            </p>
        </div>
    </div>
</footer>

<!-- Scroll To Top -->
<button id="scrollTop" title="بازگشت به بالا" aria-label="بازگشت به بالای صفحه"><i class="bi bi-chevron-up"></i></button>

<!-- Scripts -->
<script src="<?= asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('vendor/plyr.js') ?>"></script>
<script src="<?= asset('js/main.js') ?>"></script>
<script src="<?= asset('js/media-player.js') ?>" defer></script>
<?php if (!empty($GLOBALS['JHD_NEEDS_GALLERY'])): ?>
<script src="<?= asset('js/gallery.js') ?>" defer></script>
<?php endif; ?>
<?php if (!empty($GLOBALS['JHD_NEEDS_PDF_READER'])): ?>
<!-- خوانندهٔ PDF فقط در صفحه‌هایی که سند دارند بارگذاری می‌شود (بدون هزینه برای بقیهٔ صفحه‌ها). -->
<script src="<?= asset('js/pdf-reader.js') ?>" type="module" defer></script>
<?php endif; ?>
</body>
</html>
