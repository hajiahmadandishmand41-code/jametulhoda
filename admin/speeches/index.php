<?php
/**
 * admin/speeches/index.php — مدیریت سخنرانی‌ها
 */
$adminTitle = 'مدیریت سخنرانی‌ها';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/media.php';

$search = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$db     = getDB();

// اطمینان از وجود ستون speaker
/* Schema installed by CLI migration. */

$where  = ["p.post_type = 'speech'"];
$params = [];

if ($status) { $where[] = "p.status = ?"; $params[] = $status; }
if ($search) { $where[] = "p.title ILIKE ?"; $params[] = "%$search%"; }

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM posts p WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT p.*, c.name AS cat_name FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereStr
     ORDER BY p.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$limit, $offset]));
$posts = $stmt->fetchAll();

$pages   = (int)ceil($total / $limit);
$urlBase = '?status=' . urlencode($status) . '&q=' . urlencode($search) . '&page=';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="bi bi-mic ms-2 text-info"></i>سخنرانی‌ها (<?= number_format($total) ?>)</h5>
    <a href="<?= siteUrl('admin/speeches/create') ?>" class="btn btn-info text-white">
        <i class="bi bi-plus-circle ms-1"></i>سخنرانی جدید
    </a>
</div>

<form method="get" class="admin-card mb-4">
    <div class="admin-card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <input type="text" name="q" class="form-control" placeholder="جستجو در عنوان..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="published" <?= $status==='published'?'selected':'' ?>>منتشرشده</option>
                    <option value="draft"     <?= $status==='draft'?'selected':'' ?>>پیش‌نویس</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search ms-1"></i>جستجو</button>
            </div>
        </div>
    </div>
</form>

<div class="admin-card">
    <div class="admin-card-body p-0">
        <?php if (empty($posts)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-mic display-4 d-block mb-3 opacity-25"></i>
            <p>سخنرانی‌ای یافت نشد.</p>
            <a href="<?= siteUrl('admin/speeches/create') ?>" class="btn btn-info text-white">سخنرانی جدید ثبت کنید</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>عنوان</th>
                        <th>سخنران</th>
                        <th>صوت</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $i => $p):
                        $audioCount = countMediaFor('post', (int)$p['id'], 'audio');
                    ?>
                    <tr>
                        <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                        <td>
                            <a href="<?= siteUrl('admin/speeches/edit?id=' . $p['id']) ?>" class="fw-bold text-dark text-decoration-none">
                                <?= sanitize(mb_strimwidth($p['title'], 0, 55, '...')) ?>
                            </a>
                        </td>
                        <td class="text-muted small"><?= sanitize($p['speaker'] ?? '—') ?></td>
                        <td>
                            <?php if ($audioCount > 0): ?>
                            <span class="badge bg-success"><i class="bi bi-headphones"></i> <?= $audioCount ?></span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">بدون صوت</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $p['status']==='published'?'bg-success':'bg-secondary' ?>">
                                <?= $p['status']==='published'?'منتشر':'پیش‌نویس' ?>
                            </span>
                        </td>
                        <td class="text-muted" style="font-size:.78rem;white-space:nowrap"><?= persianDate($p['created_at']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= siteUrl('admin/speeches/edit?id=' . $p['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="ویرایش"><i class="bi bi-pencil"></i></a>
                                <a href="<?= speechUrl($p) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success py-0 px-2" title="مشاهده"><i class="bi bi-eye"></i></a>
                                <a href="<?= siteUrl('admin/speeches/delete?id=' . $p['id']) ?>"
                                   class="btn btn-sm btn-outline-danger py-0 px-2"
                                   title="حذف"
                                   data-confirm="آیا از حذف «<?= sanitize($p['title']) ?>» اطمینان دارید؟">
                                    <i class="bi bi-trash"></i>
                                </a>
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

<?php if ($pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination justify-content-center flex-wrap">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <li class="page-item <?= $i===$page?'active':'' ?>">
            <a class="page-link" href="<?= $urlBase . $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
