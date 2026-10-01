<?php
/**
 * admin/messages/index.php — مشاهده پیام‌های تماس با مدیر
 * اصلاح‌شده: از is_read استفاده می‌کند (سازگار با ساختار اصلی دیتابیس)
 */
$adminTitle = 'پیام‌های تماس';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

// اطمینان از وجود جدول (با is_read)
/* Schema installed by CLI migration. */

// Migration: اگر ستون is_read وجود نداشت اضافه کن
/* Schema installed by CLI migration. */

// علامت‌گذاری به‌عنوان خوانده‌شده
if (isset($_POST['read']) && is_numeric($_POST['read'])) {
    if (verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $db->prepare("UPDATE contact_messages SET is_read=1 WHERE id=? AND is_read=0")
           ->execute([(int)$_POST['read']]);
    }
    header('Location: ' . siteUrl('admin/messages/'));
    exit;
}

// علامت‌گذاری همه پیام‌ها به‌عنوان خوانده‌شده
// (این قابلیت از admin/messages.php که حذف تکراری شد، به اینجا منتقل شده است.)
if (isset($_POST['mark_all_read'])) {
    requirePostCsrf();
    $db->exec("UPDATE contact_messages SET is_read=1 WHERE is_read=0");
    $_SESSION['flash_msg']  = 'همه پیام‌ها خوانده علامت خوردند.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . siteUrl('admin/messages'));
    exit;
}

// حذف پیام
if (isset($_POST['delete']) && is_numeric($_POST['delete'])) {
    if (verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $db->prepare("DELETE FROM contact_messages WHERE id=?")->execute([(int)$_POST['delete']]);
        $_SESSION['flash_msg']  = 'پیام حذف شد.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: ' . siteUrl('admin/messages/'));
    exit;
}

// فیلتر وضعیت خواندن
$filter = in_array($_GET['filter'] ?? '', ['unread','read','all']) ? ($_GET['filter'] ?? 'all') : 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = '1=1';
$params = [];
if ($filter === 'unread') { $where = 'is_read = 0'; }
if ($filter === 'read')   { $where = 'is_read = 1'; }

$cstmt = $db->prepare("SELECT COUNT(*) FROM contact_messages WHERE $where");
$cstmt->execute($params);
$total = (int)$cstmt->fetchColumn();

$mstmt = $db->prepare("SELECT * FROM contact_messages WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$mstmt->execute($params);
$messages = $mstmt->fetchAll();

$newCount = (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE is_read=0")->fetchColumn();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">
        <i class="bi bi-envelope-fill ms-2 text-primary"></i>پیام‌های تماس با مدیر
        <?php if ($newCount > 0): ?>
        <span class="badge bg-danger ms-2"><?= $newCount ?> جدید</span>
        <?php endif; ?>
    </h5>
    <?php if ($newCount > 0): ?>
    <form method="post" action="<?= sanitize(siteUrl('admin/messages')) ?>" class="mb-0">
        <?= csrfField() ?>
        <button type="submit" name="mark_all_read" value="1" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-check2-all ms-1"></i>همه را خوانده علامت بزن
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if (!empty($_SESSION['flash_msg'])): ?>
<div class="alert alert-<?= sanitize($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible">
    <?= sanitize($_SESSION['flash_msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); endif; ?>

<!-- فیلتر -->
<div class="d-flex gap-2 mb-4 flex-wrap">
    <?php
    $filterOpts = ['all' => 'همه', 'unread' => 'خوانده‌نشده', 'read' => 'خوانده‌شده'];
    foreach ($filterOpts as $fv => $fl):
    ?>
    <a href="?filter=<?= $fv ?>"
       class="btn btn-sm <?= $filter===$fv ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= $fl ?>
        <?php if ($fv==='unread' && $newCount>0): ?>
        <span class="badge bg-danger ms-1"><?= $newCount ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="admin-card mb-3">
    <div class="admin-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span>صندوق پشتیبانی</span>
        <input type="search" class="form-control form-control-sm" style="max-width:240px" data-admin-list-filter="messages-list" placeholder="جستجو در پیام‌ها…">
    </div>
    <div class="admin-card-body">
<?php if (empty($messages)): ?>
<div class="jhd-empty-state">
    <i class="bi bi-inbox" aria-hidden="true"></i>
    <h5>پیامی یافت نشد</h5>
</div>
<?php else: ?>
<div id="messages-list">
            <?php foreach ($messages as $msg): ?>
            <article class="admin-msg-card <?= !$msg['is_read'] ? 'is-unread' : '' ?>">
                <div class="admin-msg-head">
                    <div>
                        <strong><?= sanitize($msg['name']) ?></strong>
                        <?php if (!$msg['is_read']): ?>
                        <span class="badge bg-danger">جدید</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">خوانده</span>
                        <?php endif; ?>
                        <div class="admin-msg-meta">
                            <?php if ($msg['email']): ?><span><i class="bi bi-envelope"></i> <?= sanitize($msg['email']) ?></span><?php endif; ?>
                            <?php if ($msg['phone']): ?><span><i class="bi bi-telephone"></i> <?= sanitize($msg['phone']) ?></span><?php endif; ?>
                            <span><i class="bi bi-calendar3"></i> <?= persianDate($msg['created_at']) ?></span>
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#msgModal_<?= (int)$msg['id'] ?>">
                            <i class="bi bi-eye"></i> مشاهده
                        </button>
                        <?php if (!$msg['is_read']): ?>
                        <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="read" value="<?= (int)$msg['id'] ?>"><button class="btn btn-sm btn-outline-success" title="علامت خوانده"><i class="bi bi-check2"></i></button></form>
                        <?php endif; ?>
                        <?php if ($msg['email']): ?>
                        <a href="mailto:<?= sanitize($msg['email']) ?>?subject=<?= rawurlencode('پاسخ: ' . (string)($msg['subject'] ?? '')) ?>"
                           class="btn btn-sm btn-outline-info" title="پاسخ ایمیل">
                            <i class="bi bi-reply-fill"></i>
                        </a>
                        <?php endif; ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('حذف این پیام؟')"><?= csrfField() ?><input type="hidden" name="delete" value="<?= (int)$msg['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button></form>
                    </div>
                </div>
                <div class="fw-bold mt-2"><?= sanitize(mb_strimwidth((string)($msg['subject'] ?? '(بدون موضوع)'), 0, 80, '...')) ?></div>
                <p class="admin-msg-excerpt mb-0"><?= sanitize(excerpt($msg['message'], 140)) ?></p>
            </article>

            <!-- Modal پیام کامل -->
            <div class="modal fade" id="msgModal_<?= $msg['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?= sanitize($msg['subject'] ?? '(بدون موضوع)') ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4"><strong>فرستنده:</strong> <?= sanitize($msg['name']) ?></div>
                                <?php if ($msg['email']): ?>
                                <div class="col-md-4"><strong>ایمیل:</strong> <a href="mailto:<?= sanitize($msg['email']) ?>"><?= sanitize($msg['email']) ?></a></div>
                                <?php endif; ?>
                                <?php if ($msg['phone']): ?>
                                <div class="col-md-4"><strong>تلفن:</strong> <?= sanitize($msg['phone']) ?></div>
                                <?php endif; ?>
                                <div class="col-12"><strong>تاریخ:</strong> <?= persianDate($msg['created_at']) ?></div>
                            </div>
                            <hr>
                            <div class="p-3 bg-light rounded" style="white-space:pre-wrap"><?= sanitize($msg['message']) ?></div>
                        </div>
                        <div class="modal-footer">
                            <?php if ($msg['email']): ?>
                            <a href="mailto:<?= sanitize($msg['email']) ?>?subject=پاسخ: <?= urlencode($msg['subject'] ?? '') ?>"
                               class="btn btn-success">
                                <i class="bi bi-reply-fill ms-1"></i>پاسخ با ایمیل
                            </a>
                            <?php endif; ?>
                            <?php if (!$msg['is_read']): ?>
                            <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="read" value="<?= (int)$msg['id'] ?>"><button class="btn btn-outline-primary"><i class="bi bi-check2 ms-1"></i>علامت‌گذاری خوانده‌شده</button></form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                        </div>
                    </div>
                </div>
            </div>

            <?php endforeach; ?>
</div>

<!-- صفحه‌بندی -->
<?php if ($total > $limit): ?>
<div class="mt-4">
    <?= paginate($total, $limit, $page, siteUrl('admin/messages/') . '?filter=' . $filter . '&page=%d') ?>
</div>
<?php endif; ?>

<?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
