<?php
/**
 * admin/books/index.php — مدیریت کتاب‌ها
 */
$adminTitle = 'مدیریت کتاب‌ها';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/functions.php';

ensureBooksTable();

$db     = getDB();
$search = trim($_GET['q'] ?? '');
$topic  = (int)($_GET['topic'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = "(title ILIKE ? OR description ILIKE ?)";
    $s        = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s]);
}
if ($topic > 0) {
    $where[]  = "EXISTS (SELECT 1 FROM book_topics bt WHERE bt.book_id = books.id AND bt.topic_id = ?)";
    $params[] = $topic;
}
$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM books WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = (int)ceil($total / $limit);

$stmt = $db->prepare("SELECT * FROM books WHERE $whereStr ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$limit, $offset]));
$books = $stmt->fetchAll();

$urlBase = siteUrl('admin/books/') . '?q=' . urlencode($search) . '&topic=' . $topic . '&page=';
$allBookTopics = getTopicFlatTree();
?>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#d4edd9;color:#28a745"><i class="bi bi-book-fill"></i></div>
      <div><div class="stat-value"><?= $total ?></div><div class="stat-label">کل کتاب‌ها</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#cfe2ff;color:#084298"><i class="bi bi-file-pdf-fill"></i></div>
      <div>
        <div class="stat-value"><?= (int)$db->query("SELECT COUNT(*) FROM books WHERE pdf_file IS NOT NULL AND pdf_file != ''")->fetchColumn() ?></div>
        <div class="stat-label">دارای PDF</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fff3cd;color:#856404"><i class="bi bi-file-word-fill"></i></div>
      <div>
        <div class="stat-value"><?= (int)$db->query("SELECT COUNT(*) FROM books WHERE word_file IS NOT NULL AND word_file != ''")->fetchColumn() ?></div>
        <div class="stat-label">دارای Word</div>
      </div>
    </div>
  </div>
</div>

<div class="admin-card">
  <div class="admin-card-header">
    <span><i class="bi bi-book ms-2"></i>کتاب‌ها (<?= number_format($total) ?>)</span>
    <a href="<?= siteUrl('admin/books/create') ?>" class="btn btn-success btn-sm">
      <i class="bi bi-plus ms-1"></i>کتاب جدید
    </a>
  </div>
  <div class="admin-card-body">
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-md-5">
        <input type="search" name="q" class="form-control form-control-sm"
               placeholder="جستجو در نام کتاب یا توضیحات..." value="<?= sanitize($search) ?>">
      </div>
      <div class="col-md-4">
        <select name="topic" class="form-select form-select-sm">
          <option value="0">همه موضوعات علمی</option>
          <?php foreach ($allBookTopics as $tp): ?>
            <option value="<?= (int)$tp['id'] ?>" <?= $topic === (int)$tp['id'] ? 'selected' : '' ?>>
              <?= str_repeat('— ', (int)($tp['depth'] ?? 0)) . sanitize($tp['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-search ms-1"></i>جستجو
        </button>
        <?php if ($search || $topic > 0): ?>
        <a href="<?= siteUrl('admin/books/') ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-x"></i>پاک
        </a>
        <?php endif; ?>
      </div>
    </form>

    <?php if (empty($books)): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-book display-3 opacity-25 d-block mb-3"></i>
      <p>هیچ کتابی یافت نشد.</p>
      <a href="<?= siteUrl('admin/books/create') ?>" class="btn btn-success">
        <i class="bi bi-plus ms-1"></i>افزودن اولین کتاب
      </a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table admin-table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>کتاب</th>
            <th>توضیح</th>
            <th>PDF</th>
            <th>Word</th>
            <th>تاریخ</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($books as $b): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if ($b['cover_image']): ?>
                <img src="<?= imgUrl($b['cover_image']) ?>"
                     style="width:40px;height:55px;object-fit:cover;border-radius:4px;border:1px solid #eee"
                     alt="" loading="lazy">
                <?php endif; ?>
                <div>
                  <div class="fw-bold"><?= sanitize(mb_strimwidth($b['title'], 0, 50, '...')) ?></div>
                  <div class="text-muted small">شناسه: <?= $b['id'] ?></div>
                  <?php $bTopics = getBookTopics((int)$b['id']); if ($bTopics): ?>
                  <div class="mt-1">
                    <?php foreach ($bTopics as $bt): ?>
                      <a href="<?= sanitize(adminUrl('topics', ['edit' => (int)$bt['id']])) ?>" class="badge bg-light text-dark border text-decoration-none me-1"><?= sanitize($bt['name']) ?></a>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td class="text-muted small" style="max-width:200px">
              <?= $b['description'] ? sanitize(mb_strimwidth($b['description'], 0, 80, '...')) : '—' ?>
            </td>
            <td>
              <?php if ($b['pdf_file']): ?>
              <span class="badge bg-danger"><i class="bi bi-file-pdf me-1"></i>دارد</span>
              <?php else: ?>
              <span class="text-muted small">ندارد</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($b['word_file']): ?>
              <span class="badge bg-primary"><i class="bi bi-file-word me-1"></i>دارد</span>
              <?php else: ?>
              <span class="text-muted small">ندارد</span>
              <?php endif; ?>
            </td>
            <td class="text-muted small"><?= persianDate($b['created_at']) ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= siteUrl('admin/books/edit?id=' . $b['id']) ?>"
                   class="btn btn-sm btn-outline-primary py-0 px-2" title="ویرایش">
                  <i class="bi bi-pencil"></i>
                </a>
                <a href="<?= siteUrl('admin/books/delete?id=' . $b['id']) ?>"
                   class="btn btn-sm btn-outline-danger py-0 px-2"
                   title="حذف"
                   data-confirm="آیا از حذف کتاب «<?= sanitize($b['title']) ?>» اطمینان دارید؟">
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
  <ul class="pagination justify-content-center">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <li class="page-item <?= $i===$page?'active':'' ?>">
      <a class="page-link" href="<?= $urlBase . $i ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<script>
document.querySelectorAll('[data-confirm]').forEach(function(el){
    el.addEventListener('click',function(e){
        if(!confirm(this.dataset.confirm)){e.preventDefault();}
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
