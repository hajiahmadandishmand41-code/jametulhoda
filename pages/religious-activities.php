<?php
$pageTitle = 'فعالیت‌های مذهبی';
$pageDesc = 'گزارش فعالیت‌ها، مراسم و برنامه‌های مذهبی ثبت‌شدهٔ جامعة‌الهدی.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startSecureSession();

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$posts = getPosts(['type' => 'religious', 'limit' => $limit, 'offset' => ($page - 1) * $limit]);
$total = countPosts(['type' => 'religious']);
$pages = (int)ceil($total / $limit);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar"><div class="container"><nav><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= url() ?>">صفحه اصلی</a></li>
    <li class="breadcrumb-item active">فعالیت‌های مذهبی</li>
</ol></nav></div></div>
<div class="jhd-section"><div class="container">
    <div class="page-header mb-4">
        <h1 class="page-title"><i class="bi bi-moon-stars ms-2 text-gold"></i>فعالیت‌های مذهبی</h1>
        <div class="section-divider"></div>
        <p class="text-muted mt-2">مراسم، مناسبت‌ها، محافل قرآنی و برنامه‌های مذهبی جامعه‌الهدی</p>
    </div>
    <?php if (empty($posts)): ?>
    <div class="jhd-empty-state"><i class="bi bi-moon-stars" aria-hidden="true"></i><p>موردی یافت نشد</p></div>
    <?php else: ?>
    <?= renderCategoryChips(['religious'], url('religious-activities'), 'همه فعالیت‌ها') ?>
    <?php jhd_preload_post_topics($posts); ?>
    <?= jhd_grid_open() ?>
        <?php foreach ($posts as $p):
            echo renderPostCard($p, ['cta' => 'ادامه مطلب', 'excerpt' => 120]);
        endforeach; ?>
    </div>
    <?php if ($pages > 1): ?><div class="mt-5"><?= paginate($total, $limit, $page, url('religious-activities', ['page' => '%d'])) ?></div><?php endif; ?>
    <?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
