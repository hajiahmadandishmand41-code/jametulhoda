<?php
/**
 * category.php — صفحه دسته‌بندی
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$slug = trim($_GET['slug'] ?? '');
$db = jhd_db();
if ($db === null) {
    jhd_render_content_unavailable('دسته‌بندی', url('topics'), 'مشاهده موضوعات');
}
$cat = null;
try {
    $stmt = $db->prepare("SELECT * FROM categories WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $cat = $stmt->fetch() ?: null;
} catch (PDOException $e) { $cat = null; }

if (!$cat) {
    if ($slug === '') { header('Location: ' . siteUrl('topics')); exit; }
    http_response_code(404);
    $pageTitle = 'دسته‌بندی یافت نشد';
    $pageDesc = 'دسته‌بندی مورد نظر یافت نشد';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-5 text-center"><h1>دسته‌بندی مورد نظر یافت نشد.</h1><a href="'.siteUrl('topics').'" class="btn btn-primary mt-3">مشاهده موضوعات</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = POSTS_PER_PAGE;
$posts  = getPosts(['cat' => $cat['id'], 'limit' => $limit, 'offset' => ($page - 1) * $limit]);
// category شامل همه نوع‌ها می‌شه (به جز پیش‌نویس) — section فیلتر نمی‌کنه چون دسته‌بندی مستقل از صفحه‌ست
$total  = countPosts(['cat' => $cat['id']]);
$pages  = (int)ceil($total / $limit);
// One category = one URL: /category/<slug>. The legacy ?slug= form keeps
// resolving and forwards to it.
jhd_redirect_to_canonical(categoryUrl($cat), jhd_route_path('category', ['slug' => (string)$cat['slug']]));

$pageTitle = $cat['name'];
$pageDesc = trim((string)($cat['description'] ?? '')) !== ''
    ? excerpt((string)$cat['description'], 160)
    : 'مطالب دسته‌بندی ' . $cat['name'] . ' در مدرسه جامعه‌الهدی — مقالات، اخبار، پژوهش‌ها و گزارش‌های مرتبط.';
$canonicalOverride = categoryUrl($cat);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar"><div class="container"><nav><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= siteUrl() ?>">صفحه اصلی</a></li>
    <li class="breadcrumb-item active"><?= sanitize($cat['name']) ?></li>
</ol></nav></div></div>
<div class="jhd-section"><div class="container">
    <div class="page-header mb-4">
        <h1 class="page-title"><i class="bi bi-grid ms-2 text-gold"></i><?= sanitize($cat['name']) ?></h1>
        <?php if ($cat['description']): ?><p class="text-mid"><?= sanitize($cat['description']) ?></p><?php endif; ?>
        <div class="section-divider"></div>
        <p class="text-muted small"><?= number_format($total) ?> مطلب در این دسته‌بندی</p>
    </div>
    <?php if (empty($posts)): ?>
    <div class="jhd-empty-state"><i class="bi bi-folder" aria-hidden="true"></i><p>مطلبی یافت نشد</p></div>
    <?php else: ?>
    <?php jhd_preload_post_topics($posts); ?>
    <?= jhd_grid_open() ?>
        <?php foreach ($posts as $k => $p):
            echo renderPostCard($p, ['featured' => $k === 0, 'cta' => 'ادامه مطلب', 'excerpt' => 120]);
        endforeach; ?>
    </div>
    <?php if ($pages > 1): ?><div class="mt-5"><?= paginate($total, $limit, $page, categoryUrl($slug) . '&page=%d') ?></div><?php endif; ?>
    <?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
