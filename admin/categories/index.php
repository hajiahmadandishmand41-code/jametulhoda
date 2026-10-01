<?php
/**
 * admin/categories/index.php — مدیریت دسته‌بندی‌ها — اصلاح‌شده
 */
$adminTitle = 'دسته‌بندی‌ها';
require_once __DIR__ . '/../includes/header.php';

$db   = getDB();
$error = $success = '';

// حذف دسته‌بندی
if (isset($_POST['delete'])) {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی. دوباره تلاش کنید.';
    } else {
        $delId = (int)$_POST['delete'];
        if ($delId) {
            // بررسی وجود پست در این دسته
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE category_id=?");
            $cntStmt->execute([$delId]);
            $cnt = (int)$cntStmt->fetchColumn();
            if ($cnt > 0) {
                $error = "این دسته‌بندی دارای $cnt مطلب است و قابل حذف نیست. ابتدا مطالب آن را منتقل کنید.";
            } else {
                $db->prepare("DELETE FROM categories WHERE id=?")->execute([$delId]);
                $success = 'دسته‌بندی حذف شد.';
                redirect(siteUrl('admin/categories/'));
            }
        }
    }
}

// ذخیره (ایجاد/ویرایش)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete'])) {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی.';
    } else {
        $name   = trim($_POST['name']        ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $editId = (int)($_POST['edit_id']    ?? 0);
        $allowedTypes = ['all','news','article','report','research','announcement','speech','program','religious','qa'];
        $postType = trim($_POST['post_type'] ?? 'all');
        if (!in_array($postType, $allowedTypes, true)) $postType = 'all';

        if (!$name) {
            $error = 'نام دسته‌بندی الزامی است.';
        } else {
            $slug = uniqueSlug('categories', $name, $editId);
            if ($editId) {
                $db->prepare("UPDATE categories SET name=?, slug=?, description=?, post_type=?, sort_order=?, updated_at=NOW() WHERE id=?")
                   ->execute([$name, $slug, $desc, $postType, $sort, $editId]);
                $success = 'دسته‌بندی با موفقیت ویرایش شد.';
            } else {
                $db->prepare("INSERT INTO categories (name, slug, description, post_type, sort_order) VALUES (?,?,?,?,?)")
                   ->execute([$name, $slug, $desc, $postType, $sort]);
                $success = 'دسته‌بندی جدید با موفقیت ایجاد شد.';
            }
            redirect(siteUrl('admin/categories/'));
        }
    }
}

// دسته‌بندی برای ویرایش
$editCat = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM categories WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editCat = $stmt->fetch() ?: null;
}

// لیست دسته‌بندی‌ها
$cats = $db->query(
    "SELECT c.*, COUNT(p.id) AS post_count
     FROM categories c
     LEFT JOIN posts p ON p.category_id=c.id
     GROUP BY c.id
     ORDER BY c.sort_order ASC, c.name ASC"
)->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="bi bi-grid ms-2"></i>دسته‌بندی‌ها (<?= count($cats) ?>)</h5>
</div>

<?php if ($error):   ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success alert-auto-dismiss"><?= sanitize($success) ?></div><?php endif; ?>

<div class="row g-4">
    <!-- فرم ایجاد/ویرایش -->
    <div class="col-lg-4">
        <div class="admin-card">
            <div class="admin-card-header">
                <?= $editCat ? '<i class="bi bi-pencil ms-2"></i>ویرایش دسته‌بندی' : '<i class="bi bi-plus-circle ms-2"></i>دسته‌بندی جدید' ?>
            </div>
            <div class="admin-card-body">
                <form method="post" class="admin-form">
                    <?= csrfField() ?>
                    <?php if ($editCat): ?>
                    <input type="hidden" name="edit_id" value="<?= $editCat['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">نام دسته‌بندی <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= sanitize($editCat['name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">توضیح (اختیاری)</label>
                        <textarea name="description" class="form-control" rows="3"><?= sanitize($editCat['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">بخش مادر (منوی سایت)</label>
                        <?php $curType = $editCat['post_type'] ?? 'all'; ?>
                        <select name="post_type" class="form-select">
                            <option value="all" <?= $curType==='all'?'selected':'' ?>>عمومی — همه بخش‌ها</option>
                            <option value="news" <?= $curType==='news'?'selected':'' ?>>اخبار</option>
                            <option value="article" <?= $curType==='article'?'selected':'' ?>>مقالات</option>
                            <option value="report" <?= $curType==='report'?'selected':'' ?>>گزارش‌ها</option>
                            <option value="research" <?= $curType==='research'?'selected':'' ?>>پژوهش</option>
                            <option value="announcement" <?= $curType==='announcement'?'selected':'' ?>>اطلاعیه‌ها</option>
                            <option value="speech" <?= $curType==='speech'?'selected':'' ?>>سخنرانی</option>
                            <option value="program" <?= $curType==='program'?'selected':'' ?>>برنامه‌ها</option>
                            <option value="religious" <?= $curType==='religious'?'selected':'' ?>>فعالیت مذهبی</option>
                            <option value="qa" <?= $curType==='qa'?'selected':'' ?>>پرسش و پاسخ</option>
                        </select>
                        <div class="form-text">این دسته به‌صورت زیرمنوی همان بخش در منوی همبرگر و نوار دسکتاپ نمایش داده می‌شود.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ترتیب نمایش</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= $editCat['sort_order'] ?? 0 ?>" min="0">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-grow-1">
                            <i class="bi bi-<?= $editCat ? 'save' : 'plus-circle' ?> ms-1"></i>
                            <?= $editCat ? 'ذخیره' : 'ایجاد' ?>
                        </button>
                        <?php if ($editCat): ?>
                        <a href="<?= siteUrl('admin/categories/') ?>" class="btn btn-outline-secondary">انصراف</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- لیست دسته‌بندی‌ها -->
    <div class="col-lg-8">
        <div class="admin-card">
            <div class="admin-card-body p-0">
                <?php if (empty($cats)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-grid display-4 d-block mb-3 opacity-25"></i>
                    <p>دسته‌بندی‌ای وجود ندارد.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>نام</th>
                                <th>بخش</th>
                                <th>مطالب</th>
                                <th>ترتیب</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cats as $i => $cat): ?>
                            <tr <?= ($editCat && $editCat['id']==$cat['id']) ? 'class="table-warning"' : '' ?>>
                                <td class="text-muted small"><?= $i+1 ?></td>
                                <td>
                                    <strong><?= sanitize($cat['name']) ?></strong>
                                    <?php if ($cat['description']): ?>
                                    <div class="text-muted" style="font-size:.78rem"><?= sanitize(mb_strimwidth($cat['description'],0,50,'...')) ?></div>
                                    <?php endif; ?>
                                    <code style="font-size:.7rem;color:#666"><?= sanitize($cat['slug']) ?></code>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= ($cat['post_type'] ?? 'all') === 'all' ? 'عمومی' : postTypeLabel((string)$cat['post_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= siteUrl('admin/posts/?') ?>" class="badge bg-primary text-decoration-none">
                                        <?= $cat['post_count'] ?> مطلب
                                    </a>
                                </td>
                                <td class="text-muted small"><?= $cat['sort_order'] ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="<?= siteUrl('admin/categories/?edit=' . $cat['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="ویرایش"><i class="bi bi-pencil"></i></a>
                                        <a href="<?= categoryUrl($cat) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success py-0 px-2" title="مشاهده"><i class="bi bi-eye"></i></a>
                                        <?php if ($cat['post_count'] == 0): ?>
                                        <a href="?delete=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2" title="حذف" data-confirm="حذف دسته‌بندی «<?= sanitize($cat['name']) ?>»؟"><i class="bi bi-trash"></i></a>
                                        <?php else: ?>
                                        <span class="btn btn-sm btn-outline-secondary py-0 px-2 disabled" title="ابتدا مطالب را منتقل کنید"><i class="bi bi-trash"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
