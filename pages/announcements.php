<?php
$pageTitle = 'اطلاعیه‌ها و اعلانات';
$pageDesc = 'اطلاعیه‌های رسمی، برنامه‌های آموزشی و خبرهای ثبت‌شدهٔ جامعة‌الهدی را در این بخش دنبال کنید.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 12;
$opts   = ['type' => 'announcement', 'limit' => $limit, 'offset' => ($page - 1) * $limit];
if ($search) $opts['search'] = $search;

$posts = getPosts($opts);
$total = countPosts(array_merge(['type' => 'announcement'], $search ? ['search' => $search] : []));
$pages = (int)ceil($total / $limit);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar"><div class="container"><nav><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= url() ?>">صفحه اصلی</a></li>
    <li class="breadcrumb-item active">اطلاعیه‌ها</li>
</ol></nav></div></div>
<div class="jhd-section"><div class="container">
    <div class="page-header mb-4">
        <h1 class="page-title"><i class="bi bi-megaphone ms-2 text-gold"></i>اطلاعیه‌ها و اعلانات</h1>
        <div class="section-divider"></div>
        <p class="text-muted mt-2">اعلانات رسمی، بخشنامه‌های آموزشی و اطلاعیه‌های ثبت‌نام مدرسه علمیه جامعه‌الهدی</p>
    </div>
    <?php if (empty($posts)): ?>
    <div class="jhd-empty-state"><i class="bi bi-megaphone" aria-hidden="true"></i><p>اطلاعیه‌ای یافت نشد</p></div>
    <?php else: ?>
    <?= renderCategoryChips(['announcement'], url('announcements'), 'همه اطلاعیه‌ها') ?>
    <?php jhd_preload_post_topics($posts); ?>
    <?= jhd_grid_open() ?>
        <?php foreach ($posts as $p):
            echo renderPostCard($p, ['cta' => 'ادامه مطلب و جزییات', 'excerpt' => 130]);
        endforeach; ?>
    </div>
    <?php if ($pages > 1): ?><div class="mt-5"><?= paginate($total, $limit, $page, url('announcements', ['page' => '%d'])) ?></div><?php endif; ?>
    <?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
