<?php
/**
 * admin/posts/index.php — مدیریت کل محتوا و مطالب پورتال
 */
$adminTitle = 'مدیریت مطالب';
require_once __DIR__ . '/../includes/header.php';

$type   = trim($_GET['type']   ?? '');
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['q']      ?? '');
$topic  = (int)($_GET['topic'] ?? 0);
$media  = trim($_GET['media']  ?? '');   // '' | image | video
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$db     = getDB();
$where  = ['1=1'];
$params = [];

if ($type)   { $where[] = "p.post_type = ?"; $params[] = $type; }
if ($status) { $where[] = "p.status = ?";    $params[] = $status; }
if ($search) { $where[] = "(p.title ILIKE ? OR p.summary ILIKE ? OR p.content ILIKE ?)"; $s = "%$search%"; $params = array_merge($params, [$s, $s, $s]); }
if ($topic > 0) {
    $where[]  = "EXISTS (SELECT 1 FROM post_topics pt WHERE pt.post_id = p.id AND pt.topic_id = ?)";
    $params[] = $topic;
}
if ($media === 'image') { $where[] = "EXISTS (SELECT 1 FROM post_images pi WHERE pi.post_id = p.id)"; }
if ($media === 'video') { $where[] = "EXISTS (SELECT 1 FROM media_files mf WHERE mf.ref_type = 'post' AND mf.ref_id = p.id AND mf.kind IN ('video','audio'))"; }

$whereStr = implode(' AND ', $where);

// تعداد کل
$countStmt = $db->prepare("SELECT COUNT(*) FROM posts p WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// لیست مطالب
$stmt = $db->prepare(
    "SELECT p.*, c.name AS cat_name, COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name,
            (SELECT COUNT(*) FROM post_images pi WHERE pi.post_id = p.id) AS image_count,
            (SELECT COUNT(*) FROM media_files mf WHERE mf.ref_type = 'post' AND mf.ref_id = p.id AND mf.kind IN ('video','audio')) AS media_count
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN users u ON u.id = p.author_id
     WHERE $whereStr
     ORDER BY p.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$limit, $offset]));
$posts = $stmt->fetchAll();

$pages   = (int)ceil($total / $limit);
$urlBase  = '?type=' . urlencode($type) . '&status=' . urlencode($status) . '&q=' . urlencode($search)
    . '&topic=' . $topic . '&media=' . urlencode($media) . '&page=';
$topics   = getTopicFlatTree();   // فهرست تختِ درختی برای فیلتر موضوع
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">
        <i class="bi bi-collection ms-2 text-primary"></i>
        مطالب <?php if($type): ?>(<?= postTypeLabel($type) ?>)<?php endif; ?>
        <span class="badge bg-secondary ms-1"><?= number_format($total) ?></span>
    </h5>
    <div class="d-flex gap-2">
        <a href="<?= url('admin/news/create') ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-plus-circle ms-1"></i>خبر جدید</a>
        <a href="<?= url('admin/articles/create') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-circle ms-1"></i>مقاله جدید</a>
        <a href="<?= url('admin/reports/create') ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-plus-circle ms-1"></i>گزارش جدید</a>
        <a href="<?= url('admin/research/create') ?>" class="btn btn-outline-dark btn-sm"><i class="bi bi-plus-circle ms-1"></i>پژوهش جدید</a>
        <a href="<?= url('admin/posts/create') ?>" class="btn btn-success btn-sm"><i class="bi bi-plus-circle ms-1"></i>محتوای دیگر</a>
    </div>
</div>

<!-- فیلترها -->
<form method="get" class="admin-card mb-4" role="search">
    <div class="admin-card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted">جستجو در عنوان، خلاصه و متن</label>
                <input type="text" name="q" class="form-control" placeholder="جستجو..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">نوع محتوا</label>
                <select name="type" class="form-select">
                    <option value="">همه انواع</option>
                    <?php foreach (['news'=>'اخبار','article'=>'مقالات','report'=>'گزارش‌ها','research'=>'پژوهش‌ها','program'=>'رویدادها و برنامه‌ها','announcement'=>'اطلاعیه‌ها','speech'=>'سخنرانی‌ها','qa'=>'پرسش و پاسخ'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $type===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">وضعیت انتشار</label>
                <select name="status" class="form-select">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="published" <?= $status==='published'?'selected':'' ?>>منتشرشده</option>
                    <option value="draft"     <?= $status==='draft'?'selected':'' ?>>پیش‌نویس</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">موضوع</label>
                <select name="topic" class="form-select">
                    <option value="0">همهٔ موضوعات</option>
                    <?php foreach ($topics as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $topic === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= str_repeat('— ', (int)($t['depth'] ?? 0)) . sanitize($t['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">رسانه</label>
                <select name="media" class="form-select">
                    <option value="">همه</option>
                    <option value="image" <?= $media === 'image' ? 'selected' : '' ?>>دارای گالری تصویر</option>
                    <option value="video" <?= $media === 'video' ? 'selected' : '' ?>>دارای ویدیو/صوت</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search ms-1"></i>اعمال فیلتر</button>
            </div>
        </div>
    </div>
</form>

<!-- جدول مطالب -->
<div class="admin-card">
    <div class="admin-card-body p-0">
        <?php if (empty($posts)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-file-text display-4 d-block mb-3 opacity-25"></i>
            <p>مطلبی با این مشخصات یافت نشد.</p>
            <a href="<?= url('admin/posts/create') ?>" class="btn btn-success btn-sm">ایجاد مطلب جدید</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table mb-0">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>عنوان</th>
                        <th>نوع</th>
                        <th>دسته‌بندی</th>
                        <th style="width:96px" title="تعداد تصویر و ویدیو/صوت">رسانه</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th style="width:160px">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $i => $p):
                        $editRoute = match ($p['post_type']) {
                            'article' => 'admin/articles/edit',
                            'report' => 'admin/reports/edit',
                            'research' => 'admin/research/edit',
                            'news' => 'admin/news/edit',
                            'speech' => 'admin/speeches/edit',
                            default => 'admin/posts/edit',
                        };
                        $editUrl = url($editRoute, ['id' => (int)$p['id']]);
                    ?>
                    <tr>
                        <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if ($p['featured_image']): ?>
                                <img src="<?= imgUrl($p['featured_image']) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:6px;margin-left:8px" alt="" loading="lazy">
                                <?php endif; ?>
                                <a href="<?= $editUrl ?>" class="fw-bold text-dark text-decoration-none">
                                    <?= sanitize(mb_strimwidth($p['title'], 0, 50, '...')) ?>
                                </a>
                            </div>
                        </td>
                        <td><?= postTypeBadge($p['post_type']) ?></td>
                        <td class="text-muted small"><?= sanitize($p['cat_name'] ?: '—') ?></td>
                        <td class="text-muted small" style="white-space:nowrap">
                            <i class="bi bi-images" title="تصویرها"></i> <?= (int)($p['image_count'] ?? 0) ?>
                            <?php if ((int)($p['media_count'] ?? 0) > 0): ?>
                            <i class="bi bi-camera-video ms-1" title="ویدیو/صوت"></i> <?= (int)$p['media_count'] ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $p['status']==='published'?'bg-success':'bg-secondary' ?>">
                                <?= $p['status']==='published'?'منتشر':'پیش‌نویس' ?>
                            </span>
                        </td>
                        <td class="text-muted" style="font-size:.78rem;white-space:nowrap"><?= persianDate($p['created_at']) ?></td>
                        <td>
                            <div class="d-flex gap-1 align-items-center">
                                <!-- دکمه وضعیت انتشار سریع -->
                                <?php if ($p['status'] === 'draft'): ?>
                                <a href="<?= contentActionUrl((int)$p['id'], 'publish') ?>" class="btn btn-sm btn-outline-success py-0 px-2" title="انتشار مطلب"><i class="bi bi-check2"></i></a>
                                <?php else: ?>
                                <a href="<?= contentActionUrl((int)$p['id'], 'unpublish') ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="پیش‌نویس کردن"><i class="bi bi-pause"></i></a>
                                <?php endif; ?>

                                <!-- ویرایش -->
                                <a href="<?= $editUrl ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="ویرایش"><i class="bi bi-pencil"></i></a>

                                <!-- مشاهده در سایت -->
                                <a href="<?= postUrl($p) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info py-0 px-2" title="مشاهده عمومی"><i class="bi bi-eye"></i></a>

                                <!-- حذف -->
                                <a href="<?= contentActionUrl((int)$p['id'], 'delete') ?>" class="btn btn-sm btn-outline-danger py-0 px-2" title="حذف مطلب"><i class="bi bi-trash"></i></a>
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

<!-- صفحه‌بندی -->
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
