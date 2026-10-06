<?php
/**
 * speeches.php — صفحه اختصاصی سخنرانی‌ها با جستجو، فیلتر، دسته‌بندی
 * نسخه ۲.۰ — کامل‌شده با امکانات کامل
 */
$pageTitle = 'سخنرانی‌ها';
$pageDesc  = 'فهرست کامل سخنرانی‌های مدرسه مدرسه جامعه‌الهدی — جستجو بر اساس عنوان، سخنران، موضوع و تاریخ';
require_once __DIR__ . '/../includes/header.php';

$db = jhd_db(); // null → the page renders with its own empty state

// ─── پارامترهای فیلتر ────────────────────────────────────────────
$search   = trim($_GET['q']       ?? '');
$speaker  = trim($_GET['speaker'] ?? '');
$category = trim($_GET['cat']     ?? '');
$year     = (int)($_GET['year']   ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = POSTS_PER_PAGE;
$offset   = ($page - 1) * $limit;

// ─── ساخت کوئری پویا ─────────────────────────────────────────────
$where  = ["p.post_type = 'speech'", "p.status = 'published'"];
$params = [];

if ($search) {
    $where[]  = "(p.title ILIKE ? OR p.summary ILIKE ? OR p.content ILIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// جستجوی سخنران — ابتدا در ستون speaker، سپس در محتوا
if ($speaker) {
    $where[]  = "(p.speaker ILIKE ? OR p.content ILIKE ? OR p.summary ILIKE ? OR p.title ILIKE ?)";
    $params[] = '%' . $speaker . '%';
    $params[] = '%' . $speaker . '%';
    $params[] = '%' . $speaker . '%';
    $params[] = '%' . $speaker . '%';
}

if ($category) {
    $where[]  = "c.slug = ?";
    $params[] = $category;
}

if ($year) {
    $where[]  = "EXTRACT(YEAR FROM p.published_at) = ?";
    $params[] = $year;
}

$whereSQL = implode(' AND ', $where);

// دریافت تعداد کل
$total = 0;
if ($db !== null) try {
    $countSQL = "SELECT COUNT(*) FROM posts p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE $whereSQL";
    $cStmt = $db->prepare($countSQL);
    $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();
} catch (PDOException $e) {
    $total = 0;
}

// دریافت سخنرانی‌ها
$speeches = [];
if ($db !== null) try {
    $listSQL = "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
                       EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='post' AND m.ref_id=p.id AND m.kind='audio') AS has_audio
                FROM posts p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE $whereSQL
                ORDER BY p.published_at DESC
                LIMIT ? OFFSET ?";
    $listParams   = $params;
    $listParams[] = $limit;
    $listParams[] = $offset;
    $lStmt = $db->prepare($listSQL);
    $lStmt->execute($listParams);
    $speeches = $lStmt->fetchAll();
} catch (PDOException $e) {
    $speeches = [];
}

$pages = $total > 0 ? (int)ceil($total / $limit) : 1;

// ─── دریافت سال‌های موجود برای فیلتر ─────────────────────────────
$availableYears = [];
if ($db !== null) try {
    $yStmt = $db->prepare(
        "SELECT DISTINCT EXTRACT(YEAR FROM published_at) AS yr FROM posts
         WHERE post_type='speech' AND status='published'
         ORDER BY yr DESC"
    );
    $yStmt->execute();
    $availableYears = $yStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ─── دریافت دسته‌بندی‌ها ─────────────────────────────────────────
$speechCats = [];
if ($db !== null) try {
    $scStmt = $db->prepare(
        "SELECT c.*, COUNT(p.id) AS cnt
         FROM categories c
         JOIN posts p ON p.category_id = c.id AND p.post_type='speech' AND p.status='published'
         GROUP BY c.id HAVING COUNT(p.id) > 0
         ORDER BY c.name"
    );
    $scStmt->execute();
    $speechCats = $scStmt->fetchAll();
} catch (PDOException $e) {}

// ─── ساخت URL برای صفحه‌بندی با حفظ فیلترها ────────────────────
function speechPageUrl(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return url('speeches', $q);
}

?>

<!-- Breadcrumb -->
<div class="breadcrumb-bar">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= siteUrl() ?>">صفحه اصلی</a></li>
                <li class="breadcrumb-item active">سخنرانی‌ها</li>
            </ol>
        </nav>
    </div>
</div>

<div class="py-4 py-lg-5">
    <div class="container">

        <!-- ─── هدر صفحه ─────────────────────────────────────────── -->
        <div class="page-header mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h1 class="page-title mb-1">
                        <i class="bi bi-mic-fill ms-2 text-gold"></i>سخنرانی‌ها
                    </h1>
                    <p class="text-muted small mb-0">
                        <?= number_format($total) ?> سخنرانی موجود است
                        <?php if ($search || $speaker || $category || $year): ?>
                        — <a href="<?= siteUrl('speeches') ?>" class="text-danger small">
                            <i class="bi bi-x-circle ms-1"></i>حذف فیلترها
                        </a>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="section-divider mt-2"></div>
        </div>

        <div class="row g-4">
            <!-- ─── ستون اصلی ────────────────────────────────────── -->
            <div class="col-lg-9 order-2 order-lg-1">

                <!-- فرم جستجو و فیلتر -->
                <div class="speeches-filter-bar mb-4">
                    <form action="<?= formUrl('speeches') ?>" method="get" class="row g-2 align-items-end">
                    <?= formRouteFields('speeches') ?>
                        <div class="col-12 col-md-5">
                            <label class="form-label small fw-600 mb-1" for="speech-search">جستجو در سخنرانی‌ها</label>
                            <div class="input-group">
                                <input id="speech-search" type="search" name="q" class="form-control"
                                       placeholder="جستجوی عنوان یا متن..."
                                       value="<?= sanitize($search) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-600 mb-1" for="speech-speaker">سخنران</label>
                            <input id="speech-speaker" type="text" name="speaker" class="form-control"
                                   placeholder="نام سخنران..."
                                   value="<?= sanitize($speaker) ?>">
                        </div>
                        <?php if (!empty($availableYears)): ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small fw-600 mb-1" for="speech-year">سال</label>
                            <select id="speech-year" name="year" class="form-select">
                                <option value="">همه</option>
                                <?php foreach ($availableYears as $yr): ?>
                                <option value="<?= (int)$yr ?>" <?= $year === (int)$yr ? 'selected' : '' ?>>
                                    <?= (int)$yr ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12 col-md-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-gold flex-fill">
                                    <i class="bi bi-search ms-1"></i>جستجو
                                </button>
                                <?php if ($search || $speaker || $category || $year): ?>
                                <a href="<?= siteUrl('speeches') ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-x"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- نتایج -->
                <?php if (empty($speeches)): ?>
                <div class="jhd-empty-state">
                    <i class="bi bi-mic" aria-hidden="true"></i>
                    <h4>سخنرانی‌ای یافت نشد</h4>
                    <?php if ($search || $speaker || $category || $year): ?>
                    <p class="text-muted">فیلترها را تغییر داده یا <a href="<?= siteUrl('speeches') ?>">همه سخنرانی‌ها</a> را ببینید.</p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <?= jhd_grid_open() ?>
                    <?php foreach ($speeches as $i => $sp):
                        // نام سخنران: از ستون speaker (اولویت) یا استخراج از summary
                        $spkName = $sp['speaker'] ?? '';
                        if (!$spkName && !empty($sp['summary'])) {
                            if (preg_match('/سخنران[:\s]+([^—\n]+)/u', $sp['summary'], $m)) {
                                $spkName = trim($m[1]);
                            }
                        }
                        $sp['speaker'] = $spkName;
                        // سخنرانی هم دقیقاً همان کارت استاندارد سایت است؛ نشانه‌های
                        // صوت/ویدیو روی تصویر شاخص و دسته در متادیتا نمایش داده می‌شود.
                        echo renderPostCard($sp, [
                            'type'  => 'speech',
                            'url'   => speechUrl($sp),
                            'badge' => 'سخنرانی',
                            'cta'   => 'مشاهده',
                            'col'   => 'col-12 col-sm-6 col-xl-4',
                            'eager' => $i < 3,
                            'topics' => [],
                            'meta'  => !empty($sp['cat_name']) ? [['icon' => 'bi-folder2', 'text' => (string)$sp['cat_name']]] : [],
                            'has_audio' => !empty($sp['has_audio']),
                        ]);
                    endforeach; ?>
                <?= jhd_grid_close() ?>

                <!-- صفحه‌بندی -->
                <?php if ($pages > 1): ?>
                <div class="mt-5">
                    <nav aria-label="صفحه‌بندی سخنرانی‌ها">
                        <ul class="pagination justify-content-center flex-wrap gap-1">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= speechPageUrl($page - 1) ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= speechPageUrl($p) ?>"><?= $p ?></a>
                            </li>
                            <?php endfor; ?>
                            <?php if ($page < $pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= speechPageUrl($page + 1) ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

                <?php endif; // end empty check ?>
            </div>

            <!-- ─── سایدبار ──────────────────────────────────────── -->
            <div class="col-lg-3 order-1 order-lg-2">
                <div class="sidebar">

                    <!-- فیلتر دسته‌بندی -->
                    <?php if (!empty($speechCats)): ?>
                    <div class="sidebar-widget">
                        <h5 class="sidebar-title">
                            <i class="bi bi-tags-fill ms-2 text-gold"></i>موضوعات
                        </h5>
                        <ul class="list-unstyled mb-0">
                            <li>
                                <a href="<?= url('speeches', $search ? ['q' => $search] : []) ?>"
                                   class="d-flex align-items-center justify-content-between py-2 border-bottom text-decoration-none <?= !$category ? 'fw-bold text-primary' : 'text-dark' ?>">
                                    <span><i class="bi bi-collection ms-2 text-gold"></i>همه موضوعات</span>
                                    <span class="badge bg-secondary"><?= number_format($total > 0 && !$search ? $total : $total) ?></span>
                                </a>
                            </li>
                            <?php foreach ($speechCats as $sc): ?>
                            <li>
                                <?php
                                $catQuery = array_filter(['q' => $search, 'speaker' => $speaker, 'year' => $year ?: '', 'cat' => $sc['slug']]);
                                $catUrl   = url('speeches', $catQuery);
                                ?>
                                <a href="<?= htmlspecialchars($catUrl) ?>"
                                   class="d-flex align-items-center justify-content-between py-2 border-bottom text-decoration-none <?= $category === $sc['slug'] ? 'fw-bold text-primary' : 'text-dark' ?>">
                                    <span><i class="bi bi-chevron-left ms-2 text-gold"></i><?= sanitize($sc['name']) ?></span>
                                    <span class="badge bg-secondary"><?= (int)$sc['cnt'] ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- فیلتر سال -->
                    <?php if (!empty($availableYears)): ?>
                    <div class="sidebar-widget mt-3">
                        <h5 class="sidebar-title">
                            <i class="bi bi-calendar3 ms-2 text-gold"></i>بایگانی سالانه
                        </h5>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($availableYears as $yr): ?>
                            <li>
                                <?php
                                $yQuery = array_filter(['q' => $search, 'speaker' => $speaker, 'cat' => $category, 'year' => $yr]);
                                $yUrl   = url('speeches', $yQuery);
                                ?>
                                <a href="<?= htmlspecialchars($yUrl) ?>"
                                   class="d-flex align-items-center py-2 border-bottom text-decoration-none <?= $year === (int)$yr ? 'fw-bold text-primary' : 'text-dark' ?>">
                                    <i class="bi bi-chevron-left ms-2 text-muted"></i>
                                    سال <?= (int)$yr ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- آمار سریع -->
                    <div class="sidebar-widget mt-3 p-3" style="background:var(--primary-soft);border-radius:12px;">
                        <div class="text-center">
                            <div style="font-size:2.5rem;font-weight:800;color:var(--primary)">
                                <?= number_format($total) ?>
                            </div>
                            <div class="text-muted small">سخنرانی موجود</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ─── Speech Cards ─────────────────────────────────── */
.speech-card {
    background:#fff;border-radius:16px;overflow:hidden;
    box-shadow:0 2px 14px rgba(0,0,0,.07);
    transition:transform .25s,box-shadow .25s;
    display:flex;flex-direction:column;
    border:1px solid #f0f2f5;
}
.speech-card:hover {
    transform:translateY(-5px);
    box-shadow:0 10px 32px rgba(0,0,0,.13);
}
.speech-card-media {
    position:relative;height:190px;overflow:hidden;flex-shrink:0;
}
.speech-card-img {
    width:100%;height:100%;object-fit:cover;
    transition:transform .4s;display:block;
}
.speech-card:hover .speech-card-img { transform:scale(1.05); }
.speech-card-badges {
    position:absolute;bottom:8px;left:8px;
    display:flex;gap:4px;
}
.speech-badge {
    font-size:.72rem;font-weight:600;padding:3px 9px;
    border-radius:20px;display:flex;align-items:center;gap:4px;
}
.speech-badge-video { background:rgba(229,57,53,.9);color:#fff; }
.speech-badge-audio { background:rgba(13,110,253,.9);color:#fff; }
.speech-cat-badge {
    position:absolute;top:10px;right:10px;
    background:rgba(0,0,0,.6);color:#fff;
    font-size:.72rem;padding:3px 9px;border-radius:20px;
    backdrop-filter:blur(4px);
}
.speech-card-body {
    padding:1.1rem;flex:1;display:flex;flex-direction:column;
}
.speech-card-meta {
    display:flex;gap:10px;flex-wrap:wrap;margin-bottom:.6rem;
    font-size:.8rem;color:#888;
}
.speech-date,.speech-speaker {
    display:flex;align-items:center;gap:3px;
}
.speech-speaker { color:var(--primary,#1a7a4a); }
.speech-card-title {
    font-size:1rem;font-weight:700;color:#1a1a2e;
    margin-bottom:.5rem;line-height:1.5;
}
.speech-card-title a { color:inherit;text-decoration:none; }
.speech-card-title a:hover { color:var(--primary,#1a7a4a); }
.speech-card-summary {
    font-size:.87rem;color:#666;line-height:1.7;
    margin-bottom:.8rem;flex:1;
}
.speech-card-footer {
    display:flex;align-items:center;justify-content:space-between;
    margin-top:auto;padding-top:.7rem;border-top:1px solid #f0f2f5;
}

/* فیلتر بار */
.speeches-filter-bar {
    background:#f8f9fa;border-radius:14px;padding:1.2rem;
    border:1px solid #e8eaed;
}
.speeches-filter-bar .form-label { color:#555;margin-bottom:4px; }
.fw-600 { font-weight:600; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
