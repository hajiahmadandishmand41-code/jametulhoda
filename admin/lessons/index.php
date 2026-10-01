<?php
/**
 * admin/lessons/index.php — مدیریت درس‌ها — اصلاح‌شده
 */
$adminTitle = 'مدیریت درس‌ها';
require_once __DIR__ . '/../includes/header.php';

$db     = getDB();
$search = trim($_GET['q']     ?? '');
$level  = trim($_GET['level'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 15;
$offset = ($page - 1) * $per;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = "(l.title ILIKE ? OR l.content ILIKE ? OR l.summary ILIKE ? OR l.teacher ILIKE ?)";
    $s        = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s, $s, $s]);
}
if ($level) {
    $where[]  = "l.level = ?";
    $params[] = $level;
}
$whereStr = implode(' AND ', $where);

// تعداد کل
$countStmt = $db->prepare("SELECT COUNT(*) FROM lessons l WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// لیست درس‌ها
try {
    $stmt = $db->prepare(
        "SELECT l.*, u.full_name AS author_name
         FROM lessons l
         LEFT JOIN users u ON u.id = l.created_by
         WHERE $whereStr
         ORDER BY l.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per, $offset]));
    $lessons = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('admin/lessons query error: ' . get_class($e));
    $lessons = [];
}
$pages   = (int)ceil($total / $per);

// آمار کلی
$totalPub   = (int)$db->query("SELECT COUNT(*) FROM lessons WHERE status='published'")->fetchColumn();
$totalDraft = (int)$db->query("SELECT COUNT(*) FROM lessons WHERE status='draft'")->fetchColumn();
$withAudio  = (int)$db->query("SELECT COUNT(*) FROM lessons WHERE audio_file IS NOT NULL AND audio_file != ''")->fetchColumn();
?>

<!-- آمار -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#d4edd9;color:#28a745"><i class="bi bi-play-circle-fill"></i></div>
      <div><div class="stat-value"><?= $totalPub ?></div><div class="stat-label">منتشرشده</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fff3cd;color:#856404"><i class="bi bi-file-earmark"></i></div>
      <div><div class="stat-value"><?= $totalDraft ?></div><div class="stat-label">پیش‌نویس</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#cfe2ff;color:#084298"><i class="bi bi-headphones"></i></div>
      <div><div class="stat-value"><?= $withAudio ?></div><div class="stat-label">دارای صوت</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#f8d7da;color:#721c24"><i class="bi bi-book"></i></div>
      <div><div class="stat-value"><?= $totalPub + $totalDraft ?></div><div class="stat-label">کل درس‌ها</div></div>
    </div>
  </div>
</div>

<!-- فیلتر و عملیات -->
<div class="admin-card mb-4">
  <div class="admin-card-header">
    <span><i class="bi bi-play-circle ms-2"></i>درس‌ها (<?= number_format($total) ?>)</span>
    <a href="<?= siteUrl('admin/lessons/create') ?>" class="btn btn-success btn-sm"><i class="bi bi-plus ms-1"></i>درس جدید</a>
  </div>
  <div class="admin-card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-5">
        <input type="search" name="q" class="form-control form-control-sm" placeholder="جستجو در عنوان، توضیح یا استاد..." value="<?= sanitize($search) ?>">
      </div>
      <div class="col-md-3">
        <select name="level" class="form-select form-select-sm">
          <option value="">همه سطوح</option>
          <option value="beginner"     <?= $level==='beginner'    ?'selected':'' ?>>مبتدی</option>
          <option value="intermediate" <?= $level==='intermediate'?'selected':'' ?>>متوسط</option>
          <option value="advanced"     <?= $level==='advanced'    ?'selected':'' ?>>پیشرفته</option>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search ms-1"></i>فیلتر</button>
      </div>
      <?php if ($search || $level): ?>
      <div class="col-md-2">
        <a href="<?= siteUrl('admin/lessons/') ?>" class="btn btn-outline-secondary btn-sm w-100">پاک کردن</a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- جدول درس‌ها -->
<div class="admin-card">
  <div class="admin-card-body p-0">
    <?php if (empty($lessons)): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-play-circle display-4 d-block mb-3 opacity-25"></i>
      <p>درسی یافت نشد.</p>
      <a href="<?= siteUrl('admin/lessons/create') ?>" class="btn btn-success">درس جدید ایجاد کنید</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table admin-table mb-0">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>عنوان</th>
            <th>استاد</th>
            <th>موضوع</th>
            <th>سطح</th>
            <th>صوت</th>
            <th>وضعیت</th>
            <th>بازدید</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lessons as $i => $l):
            $lLevel   = $l['level'] ?? '';
            $levelBg  = $lLevel === 'beginner' ? 'success' : ($lLevel === 'intermediate' ? 'warning' : ($lLevel ? 'danger' : 'secondary'));
            $levelLbl = ['beginner' => 'مبتدی', 'intermediate' => 'متوسط', 'advanced' => 'پیشرفته'][$lLevel] ?? ($lLevel ?: '—');
          ?>
          <tr>
            <td class="text-muted small"><?= $offset + $i + 1 ?></td>
            <td>
              <?php if ($l['featured_image']): ?>
              <img src="<?= imgUrl($l['featured_image']) ?>" style="width:34px;height:34px;object-fit:cover;border-radius:6px;margin-left:8px" alt="" loading="lazy">
              <?php endif; ?>
              <a href="<?= siteUrl('admin/lessons/edit?id=' . $l['id']) ?>" class="fw-bold text-dark text-decoration-none">
                <?= sanitize(mb_strimwidth($l['title'], 0, 45, '...')) ?>
              </a>
            </td>
            <td class="small"><?= sanitize($l['teacher'] ?: '—') ?></td>
            <td class="small"><?= sanitize($l['subject'] ?: '—') ?></td>
            <td><?php if ($lLevel): ?><span class="badge bg-<?= $levelBg ?>"><?= $levelLbl ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td class="text-center">
              <?php if ($l['audio_file']): ?>
              <i class="bi bi-headphones text-primary" title="دارای فایل صوتی"></i>
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $l['status']==='published'?'bg-success':'bg-secondary' ?>">
                <?= $l['status']==='published'?'منتشر':'پیش‌نویس' ?>
              </span>
            </td>
            <td class="text-muted small"><?= 0 ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= siteUrl('admin/lessons/edit?id=' . $l['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="ویرایش"><i class="bi bi-pencil"></i></a>
                <a href="<?= lessonUrl($l) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success py-0 px-2" title="مشاهده"><i class="bi bi-eye"></i></a>
                <a href="<?= siteUrl('admin/lessons/delete?id=' . $l['id']) ?>"
                   class="btn btn-sm btn-outline-danger py-0 px-2"
                   title="حذف"
                   data-confirm="آیا از حذف درس «<?= sanitize($l['title']) ?>» اطمینان دارید؟">
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

<!-- صفحه‌بندی -->
<?php if ($pages > 1): ?>
<nav class="mt-3">
  <ul class="pagination justify-content-center">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <li class="page-item <?= $i===$page?'active':'' ?>">
      <a class="page-link" href="?q=<?= urlencode($search) ?>&level=<?= urlencode($level) ?>&page=<?= $i ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
