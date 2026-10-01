<?php
/**
 * home-intro.php — هیروی محتوامحور صفحه اصلی (نه بنر تبلیغاتی).
 * - بدون اسلایدر، بدون تزئینات، با حداکثر یک CTA.
 * - همه‌چیز از داده واقعی ($heroPost)؛ اگر مطلبی نباشد، معرفی بسیار ساده سایت.
 * Vars: $heroPost (?array), $heroTopic (?array), $siteName, $siteSlogan.
 */
?>
<section class="jhd-hero jhd-hero--short"><div class="container"><div class="jhd-hero-copy">
<?php if(!empty($heroPost)): ?>
<span class="jhd-eyebrow"><?= sanitize($heroTopic['name'] ?? postTypeLabel($heroPost['post_type'] ?? 'article')) ?></span>
<h1><?= sanitize($heroPost['title']) ?></h1>
<?php if(!empty($heroPost['summary'])): ?><p><?= sanitize(excerpt($heroPost['summary'],160)) ?></p><?php endif; ?>
<p class="text-muted small mb-0"><i class="bi bi-calendar3 ms-1"></i><?= persianDate($heroPost['published_at'] ?? $heroPost['created_at']) ?></p>
<div class="jhd-hero-cta"><a class="jhd-button" href="<?= postUrl($heroPost) ?>">مطالعه مطلب <i class="bi bi-arrow-left"></i></a></div>
<?php else: ?>
<span class="jhd-eyebrow"><?= sanitize($siteName ?? 'جامعه‌الهدی') ?></span>
<h1><?= sanitize($siteSlogan ?? '') ?></h1>
<div class="jhd-hero-cta"><a class="jhd-button" href="<?= siteUrl('topics') ?>">مشاهده موضوعات <i class="bi bi-arrow-left"></i></a></div>
<?php endif; ?>
</div></div></section>
