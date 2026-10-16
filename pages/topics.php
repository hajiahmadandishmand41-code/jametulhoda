<?php
/**
 * topics.php — آرشیو واقعی «موضوعات علمی».
 *
 * موضوعات فعال را با اولویت «ویژه» نشان می‌دهد؛ هر کاشی مستقیماً به صفحهٔ
 * اختصاصی همان موضوع (pages/topic.php) می‌رود. هیچ تغییرمسیر به آرشیو مقالات
 * وجود ندارد.
 */
$pageTitle = 'موضوعات علمی';
$metaTitleOverride = 'موضوعات علمی | مدرسه جامعه‌الهدی';
$pageDesc = 'آرشیو موضوعات علمی مدرسه جامعه‌الهدی: قرآن، حدیث، فقه، مهدویت، اخلاق و سایر حوزه‌های علوم اسلامی.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim((string)($_GET['q'] ?? ''));
$topics = getTopics(['active' => 1]);
// موضوعات ویژه اول
usort($topics, static function (array $a, array $b): int {
    $fa = (int)($a['is_featured'] ?? 0);
    $fb = (int)($b['is_featured'] ?? 0);
    if ($fa !== $fb) return $fb <=> $fa;
    return (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0)
        ?: strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

if ($search !== '') {
    $topics = array_values(array_filter($topics, static function (array $t) use ($search): bool {
        $hay = (string)($t['name'] ?? '') . ' ' . (string)($t['description'] ?? '') . ' ' . (string)($t['intro'] ?? '');
        return mb_stripos($hay, $search) !== false;
    }));
}

// زیرموضوع‌ها را برای هر موضوع پیش‌بارگذاری کن تا کارت بتواند نمایش دهد.
$childrenMap = [];
foreach ($topics as $t) {
    $childrenMap[(int)$t['id']] = getTopicChildren((int)$t['id']);
}
foreach ($topics as &$t) {
    $t['children'] = $childrenMap[(int)$t['id']] ?? [];
}
unset($t);

$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'موضوعات علمی', 'url' => url('topics')],
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

$canonicalOverride = url('topics');

require_once __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <?php foreach ($breadcrumbs as $i => $bc): $isLast = ($i === count($breadcrumbs) - 1); ?>
        <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>>
          <?php if (!$isLast): ?><a href="<?= sanitize($bc['url']) ?>"><?= sanitize($bc['name']) ?></a><?php else: ?><?= sanitize($bc['name']) ?><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </nav>
  </div>
</div>

<div class="jhd-section">
  <div class="container">
    <?= jhd_page_head([
        'eyebrow' => 'هاب علمی',
        'icon' => 'bi-diagram-3',
        'title' => 'موضوعات علمی',
        'lead' => 'حوزه‌های علوم اسلامی را یک‌جا ببینید؛ هر موضوع مقالات، کتاب‌ها، درس‌ها و زیرموضوع‌های مرتبط را گرد می‌آورد.',
        'actions' => count($topics) > 0 ? '<span class="jhd-chip"><i class="bi bi-collection"></i>' . number_format(count($topics)) . ' موضوع</span>' : '',
    ]) ?>

    <form method="get" action="<?= formUrl('topics') ?>" class="mb-4" role="search">
      <?= formRouteFields('topics') ?>
      <div class="input-group" style="max-width:420px">
        <input type="search" name="q" class="form-control" placeholder="جستجو در موضوعات..." value="<?= sanitize($search) ?>">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
        <?php if ($search): ?>
        <a href="<?= sanitize(url('topics')) ?>" class="btn btn-outline-secondary" title="حذف فیلتر"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
      </div>
    </form>

    <?php if (empty($topics)): ?>
      <?= renderEmptyState('bi-diagram-3', $search ? 'موضوعی مطابق با جستجوی شما یافت نشد.' : 'هنوز موضوع فعالی ثبت نشده است.', url(), 'صفحه اصلی') ?>
    <?php else: ?>
      <div class="row g-2">
        <?php foreach ($topics as $t):
            $id = (int)$t['id'];
            $counts = [
                ['icon' => 'bi-file-text', 'label' => 'مطلب', 'value' => countPostsByTopic($id)],
                ['icon' => 'bi-book', 'label' => 'کتاب', 'value' => countBooksByTopic($id)],
                ['icon' => 'bi-mortarboard', 'label' => 'درس', 'value' => countLessonsByTopic($id)],
            ];
            // only show non-zero counts
            $counts = array_values(array_filter($counts, static fn($c) => (int)$c['value'] > 0));
            echo renderTopicCard($t, ['compact' => true, 'counts' => $counts, 'col' => 'col-12 col-sm-6 col-lg-3']);
        endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
