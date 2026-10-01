<?php
/**
 * admin/index.php — داشبورد مدیریتی با نمای تفکیک‌شدهٔ محتوا، آموزش و سامانه
 */
$adminTitle = 'داشبورد';
require_once __DIR__ . '/includes/header.php';
$db = getDB();

$count = static function (string $sql): int {
    try { return (int)getDB()->query($sql)->fetchColumn(); } catch (Throwable) { return 0; }
};

$totalPosts       = $count("SELECT COUNT(*) FROM posts WHERE status='published'");
$totalDrafts      = $count("SELECT COUNT(*) FROM posts WHERE status='draft'");
$totalTopics      = $count("SELECT COUNT(*) FROM topics WHERE is_active=1");
$bookStatusCounts = [];
try {
    foreach ($db->query("SELECT status, COUNT(*) AS cnt FROM books GROUP BY status")->fetchAll() as $bookStatusRow) {
        $bookStatusCounts[(string)$bookStatusRow['status']] = (int)$bookStatusRow['cnt'];
    }
} catch (Throwable) { $bookStatusCounts = []; }
$totalBooks     = array_sum($bookStatusCounts);
$totalBooksLive = $bookStatusCounts['published'] ?? 0;
$totalLessons   = $count("SELECT COUNT(*) FROM lessons WHERE status='published'");
$totalCollections = $count("SELECT COUNT(*) FROM lesson_collections WHERE is_active=1");
$totalMedia       = $count("SELECT COUNT(*) FROM media_files");
$totalMsgs        = $count("SELECT COUNT(*) FROM contact_messages");
$unreadMsgs       = $count("SELECT COUNT(*) FROM contact_messages WHERE is_read=0");
$userCounts       = jhd_count_users();
$totalUsers       = array_sum($userCounts);

$typeCounts = [];
try {
    $typeCountsStmt = $db->query("SELECT post_type, COUNT(*) AS cnt FROM posts WHERE status='published' GROUP BY post_type");
    foreach ($typeCountsStmt->fetchAll() as $row) $typeCounts[$row['post_type']] = (int)$row['cnt'];
} catch (Throwable) {
    $typeCounts = [];
}
$totalResearch = $typeCounts['research'] ?? 0;
// فقط رکوردهای منتشرشده در شمار کل محتوای منتشرشده حساب می‌شوند.
$totalPublishedContent = $totalPosts + $totalBooksLive + $totalLessons;

$recentPosts = [];
try {
    $recentPosts = $db->query("SELECT p.id,p.title,p.post_type,p.status,p.created_at,p.slug,c.name AS cat_name FROM posts p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.created_at DESC LIMIT 7")->fetchAll();
} catch (Throwable) { $recentPosts = []; }
$recentMsgs = [];
try {
    $recentMsgs = $db->query("SELECT id,name,subject,is_read,created_at FROM contact_messages ORDER BY created_at DESC LIMIT 4")->fetchAll();
} catch (Throwable) { $recentMsgs = []; }

$systemOk = true;
$systemNotes = [];
try {
    $db->query('SELECT 1');
} catch (Throwable) {
    $systemOk = false;
    $systemNotes[] = 'اتصال پایگاه داده';
}
if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
    $systemOk = false;
    $systemNotes[] = 'پوشهٔ بارگذاری';
}
$logDir = STORAGE_DIR . '/logs';
if (!is_dir($logDir) || !is_writable($logDir)) {
    $systemOk = false;
    $systemNotes[] = 'پوشهٔ گزارش‌ها';
}
$systemStatusText = $systemOk ? 'فعال' : 'نیازمند بررسی: ' . implode('، ', $systemNotes);
$adminAccountRow = currentUser();
$lastLoginText = ($adminAccountRow['last_login'] ?? '') !== '' ? persianDate((string)$adminAccountRow['last_login']) : '—';

$dashboardStatGroups = [
    [
        'id' => 'dashboard-content',
        'title' => 'محتوا و انتشارات',
        'description' => 'وضعیت مطالب و منابعی که در پایگاه مدیریت می‌شوند.',
        'columns' => 'col-6 col-xl-3',
        'items' => [
            ['label' => 'مطالب منتشرشده', 'value' => $totalPosts, 'href' => url('admin/content'), 'icon' => 'bi-file-check', 'tone' => 'green'],
            ['label' => 'پیش‌نویس مطالب', 'value' => $totalDrafts, 'href' => url('admin/content'), 'icon' => 'bi-file-earmark', 'tone' => 'gold'],
            ['label' => 'کتاب‌ها · همهٔ وضعیت‌ها', 'value' => $totalBooks, 'href' => url('admin/books'), 'icon' => 'bi-book', 'tone' => 'blue'],
            ['label' => 'پژوهش‌های منتشرشده', 'value' => $totalResearch, 'href' => url('admin/research'), 'icon' => 'bi-journal-richtext', 'tone' => 'violet'],
        ],
    ],
    [
        'id' => 'dashboard-learning',
        'title' => 'آموزش و طبقه‌بندی',
        'description' => 'موضوعات فعال، درس‌ها و مجموعه‌های آموزشی.',
        'columns' => 'col-6 col-lg-4',
        'items' => [
            ['label' => 'موضوعات فعال', 'value' => $totalTopics, 'href' => url('admin/topics'), 'icon' => 'bi-diagram-3', 'tone' => 'green'],
            ['label' => 'درس‌های منتشرشده', 'value' => $totalLessons, 'href' => url('admin/courses'), 'icon' => 'bi-mortarboard', 'tone' => 'blue'],
            ['label' => 'مجموعه‌های درسی فعال', 'value' => $totalCollections, 'href' => url('admin/courses'), 'icon' => 'bi-collection', 'tone' => 'teal'],
        ],
    ],
    [
        'id' => 'dashboard-operations',
        'title' => 'رسانه و پیام‌ها',
        'description' => 'فایل‌های موجود و پیام‌هایی که نیاز به پیگیری دارند.',
        'columns' => 'col-6 col-lg-6',
        'items' => [
            ['label' => 'فایل‌های رسانه', 'value' => $totalMedia, 'href' => url('admin/uploads'), 'icon' => 'bi-images', 'tone' => 'violet'],
            ['label' => 'کل پیام‌های تماس', 'value' => $totalMsgs, 'note' => 'خوانده‌نشده: ' . jhd_persian_digits($unreadMsgs), 'href' => url('admin/messages'), 'icon' => 'bi-envelope', 'tone' => 'rose'],
        ],
    ],
];

$quickActionGroups = [
    [
        'id' => 'admin-quick-publish',
        'title' => 'ایجاد محتوا',
        'icon' => 'bi-plus-circle',
        'actions' => [
            ['label' => 'خبر جدید', 'href' => url('admin/news/create'), 'icon' => 'bi-newspaper', 'class' => 'btn-success'],
            ['label' => 'مقاله جدید', 'href' => url('admin/articles/create'), 'icon' => 'bi-file-text', 'class' => 'btn-primary'],
            ['label' => 'پژوهش جدید', 'href' => url('admin/posts/create', ['post_type' => 'research']), 'icon' => 'bi-journal-richtext', 'class' => 'btn-outline-primary'],
            ['label' => 'کتاب جدید', 'href' => url('admin/books/create'), 'icon' => 'bi-book', 'class' => 'btn-outline-success'],
            ['label' => 'درس جدید', 'href' => url('admin/courses/create'), 'icon' => 'bi-mortarboard', 'class' => 'btn-outline-primary'],
        ],
    ],
    [
        'id' => 'admin-quick-organize',
        'title' => 'سازمان‌دهی و پیگیری',
        'icon' => 'bi-sliders',
        'actions' => [
            ['label' => 'موضوع جدید', 'href' => url('admin/topics/create'), 'icon' => 'bi-diagram-3', 'class' => 'btn-warning'],
            ['label' => 'مدیریت رسانه', 'href' => url('admin/uploads'), 'icon' => 'bi-upload', 'class' => 'btn-outline-secondary'],
            ['label' => 'پیام‌ها', 'href' => url('admin/messages'), 'icon' => 'bi-envelope', 'class' => 'btn-outline-secondary', 'badge' => $unreadMsgs],
        ],
    ],
];
?>

<div class="admin-summary-card mb-4">
    <div class="admin-summary-main">
        <div class="admin-summary-avatar" aria-hidden="true"><?= sanitize(mb_substr($admin['name'] ?: $admin['user'], 0, 1)) ?></div>
        <div>
            <h1 class="admin-summary-title" id="admin-dashboard-title">خوش آمدید، <?= sanitize($admin['name'] ?: $admin['user']) ?></h1>
            <p class="admin-summary-sub">
                <span class="badge <?= $systemOk ? 'bg-success' : 'bg-warning text-dark' ?>"><i class="bi bi-activity ms-1" aria-hidden="true"></i>وضعیت سامانه: <?= sanitize($systemStatusText) ?></span>
                <span class="text-muted"><?= sanitize(jhd_role_label($admin['role'])) ?></span>
                <span class="text-muted">آخرین ورود: <?= sanitize($lastLoginText) ?></span>
            </p>
        </div>
    </div>
    <div class="admin-summary-stats" role="group" aria-label="خلاصهٔ مدیریتی">
        <div><strong><?= number_format($totalPublishedContent) ?></strong><span>محتوای منتشرشده</span></div>
        <div><strong><?= number_format($totalUsers) ?></strong><span>کاربران</span></div>
        <div><strong><?= number_format($unreadMsgs) ?></strong><span>پیام خوانده‌نشده</span></div>
    </div>
</div>

<?php foreach ($dashboardStatGroups as $group): ?>
<section class="admin-dashboard-section" aria-labelledby="<?= sanitize($group['id']) ?>-title" data-dashboard-section>
    <div class="admin-dashboard-section-heading">
        <div>
            <h2 id="<?= sanitize($group['id']) ?>-title"><?= sanitize($group['title']) ?></h2>
            <p><?= sanitize($group['description']) ?></p>
        </div>
    </div>
    <div class="row g-3">
        <?php foreach ($group['items'] as $stat): ?>
        <div class="<?= sanitize($group['columns']) ?>">
            <a class="stat-card stat-link" href="<?= sanitize($stat['href']) ?>">
                <span class="stat-icon stat-icon--<?= sanitize($stat['tone']) ?>"><i class="bi <?= sanitize($stat['icon']) ?>" aria-hidden="true"></i></span>
                <div class="admin-stat-copy">
                    <div class="stat-value"><?= number_format((int)$stat['value']) ?></div>
                    <div class="stat-label"><?= sanitize($stat['label']) ?></div>
                    <?php if (!empty($stat['note'])): ?><div class="admin-stat-note"><?= sanitize($stat['note']) ?></div><?php endif; ?>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<section class="admin-dashboard-section" aria-labelledby="admin-quick-actions-title">
    <div class="admin-dashboard-section-heading">
        <div>
            <h2 id="admin-quick-actions-title">دسترسی‌های پرکاربرد</h2>
            <p>کارهای روزمره را از اینجا، بر اساس نوع فعالیت، آغاز کنید.</p>
        </div>
    </div>
    <div class="row g-3">
        <?php foreach ($quickActionGroups as $group): ?>
        <div class="col-xl-<?= $group['id'] === 'admin-quick-publish' ? '8' : '4' ?>">
            <section class="admin-card admin-quick-group" aria-labelledby="<?= sanitize($group['id']) ?>-title">
                <div class="admin-card-header" id="<?= sanitize($group['id']) ?>-title"><span><i class="bi <?= sanitize($group['icon']) ?> ms-2" aria-hidden="true"></i><?= sanitize($group['title']) ?></span></div>
                <div class="admin-card-body admin-quick-actions">
                    <?php foreach ($group['actions'] as $action): ?>
                    <a href="<?= sanitize($action['href']) ?>" class="btn <?= sanitize($action['class']) ?> admin-quick-action">
                        <i class="bi <?= sanitize($action['icon']) ?> ms-1" aria-hidden="true"></i><?= sanitize($action['label']) ?>
                        <?php if (!empty($action['badge']) && (int)$action['badge'] > 0): ?><span class="badge bg-danger me-1"><?= number_format((int)$action['badge']) ?></span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="row g-3 mt-1">
    <section class="col-lg-8" aria-labelledby="admin-recent-content-title">
        <div class="admin-card h-100">
            <div class="admin-card-header">
                <span id="admin-recent-content-title"><i class="bi bi-clock-history ms-2" aria-hidden="true"></i>آخرین مطالب</span>
                <a href="<?= url('admin/content') ?>" class="btn btn-sm btn-outline-primary">همهٔ مطالب</a>
            </div>
            <div class="admin-card-body p-0">
                <?php if (!$recentPosts): ?>
                <div class="p-4 text-center text-muted small" role="status">هنوز مطلبی ثبت نشده است. از بخش «ایجاد محتوا» شروع کنید.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead><tr><th scope="col">عنوان</th><th scope="col">نوع</th><th scope="col">وضعیت</th><th scope="col">تاریخ</th><th scope="col"><span class="visually-hidden">عملیات</span></th></tr></thead>
                        <tbody>
                            <?php foreach ($recentPosts as $post):
                                $postIsPublished = ($post['status'] ?? '') === 'published';
                                $postStatusLabel = $postIsPublished ? 'منتشرشده' : (($post['status'] ?? '') === 'draft' ? 'پیش‌نویس' : 'غیرفعال');
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= sanitize(url('admin/posts/edit', ['id' => (int)$post['id']])) ?>" class="text-decoration-none fw-bold admin-recent-title"><?= sanitize(mb_strimwidth((string)$post['title'], 0, 44, '…')) ?></a>
                                    <?php if (!empty($post['cat_name'])): ?><div class="text-muted admin-recent-category"><?= sanitize((string)$post['cat_name']) ?></div><?php endif; ?>
                                </td>
                                <td><?= postTypeBadge((string)$post['post_type']) ?></td>
                                <td><span class="badge <?= $postIsPublished ? 'bg-success' : (($post['status'] ?? '') === 'draft' ? 'bg-secondary' : 'bg-warning text-dark') ?>"><?= sanitize($postStatusLabel) ?></span></td>
                                <td class="text-muted admin-recent-date"><?= sanitize(persianDate((string)$post['created_at'])) ?></td>
                                <td class="text-nowrap">
                                    <a href="<?= sanitize(url('admin/posts/edit', ['id' => (int)$post['id']])) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="ویرایش" aria-label="ویرایش مطلب <?= sanitize((string)$post['title']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                    <?php if ($postIsPublished): ?><a href="<?= sanitize(postUrl($post)) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success py-0 px-2" title="مشاهده در سایت" aria-label="مشاهدهٔ مطلب <?= sanitize((string)$post['title']) ?> در سایت"><i class="bi bi-eye" aria-hidden="true"></i></a><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <section class="col-lg-4" aria-labelledby="admin-recent-messages-title">
        <div class="admin-card h-100">
            <div class="admin-card-header">
                <span id="admin-recent-messages-title"><i class="bi bi-envelope ms-2" aria-hidden="true"></i>پیام‌های اخیر</span>
                <a href="<?= url('admin/messages') ?>" class="btn btn-sm btn-outline-primary">همهٔ پیام‌ها</a>
            </div>
            <div class="admin-card-body p-0">
                <?php if (!$recentMsgs): ?>
                <div class="p-4 text-center text-muted small" role="status">پیامی برای نمایش وجود ندارد.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush admin-recent-messages">
                    <?php foreach ($recentMsgs as $msg): $messageUnread = (int)$msg['is_read'] === 0; ?>
                    <li class="list-group-item admin-recent-message <?= $messageUnread ? 'is-unread' : '' ?>">
                        <a href="<?= url('admin/messages') ?>" class="text-decoration-none text-reset d-block" aria-label="پیام از <?= sanitize((string)$msg['name']) ?>: <?= sanitize((string)($msg['subject'] ?? 'بدون عنوان')) ?>">
                            <div class="admin-recent-message-heading"><strong><?= sanitize((string)$msg['name']) ?></strong><?php if ($messageUnread): ?><span class="badge bg-danger">خوانده‌نشده</span><?php else: ?><span class="badge bg-success-subtle text-success">خوانده‌شده</span><?php endif; ?></div>
                            <div class="admin-recent-message-subject"><?= sanitize(mb_strimwidth((string)($msg['subject'] ?? 'بدون عنوان'), 0, 46, '…')) ?></div>
                            <time class="admin-recent-message-date" datetime="<?= sanitize((string)$msg['created_at']) ?>"><?= sanitize(persianDate((string)$msg['created_at'])) ?></time>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
