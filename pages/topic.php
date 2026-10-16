<?php
/**
 * topic.php — هاب علمی موضوع: زیرموضوع‌ها و محتوای مرتبط (اخبار، مقالات، کتاب‌ها، درس‌ها).
 */
$pageTitle = 'موضوع';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$slug = trim((string)($_GET['slug'] ?? ''));
$topic = $slug !== '' ? getTopicBySlug($slug) : null;
if (!jhd_db_ready()) {
    jhd_render_content_unavailable('موضوع', url('articles'), 'مقالات');
}
if (!$topic) {
    http_response_code(404);
    $pageTitle = 'موضوع یافت نشد';
    require __DIR__ . '/../includes/header.php';
    echo '<section class="container py-5"><h1>موضوع مورد نظر یافت نشد.</h1><a class="btn btn-primary mt-3" href="' . sanitize(url('articles')) . '">بازگشت به مقالات</a></section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$topicId = (int)$topic['id'];
$canonicalOverride = topicUrl($topic);
$topicName = (string)($topic['name'] ?? '');
$pageTitle = $topicName;
$metaTitleOverride = $topicName . ' | موضوعات جامعه‌الهدی';
$rawDesc = trim((string)($topic['description'] ?? $topic['intro'] ?? ''));
$pageDesc = $rawDesc !== '' ? $rawDesc : ('مطالب، کتاب‌ها و درس‌های مرتبط با موضوع ' . $topicName);

$crumbs = getTopicBreadcrumbs($topicId);
$breadcrumbs = [['name' => 'صفحه اصلی', 'url' => url()]];
foreach ($crumbs as $c) {
    $breadcrumbs[] = ['name' => (string)$c['name'], 'url' => topicUrl($c)];
}
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

$children = getTopicChildren($topicId);
$scope = getTopicScopeIds($topicId);

$posts = getPostsByTopic($topicId, ['limit' => 12]);
$books = getBooksByTopic($topicId, 8);
$lessons = getLessonsByTopic($topicId, 8);

require __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <?php foreach ($breadcrumbs as $i => $bc): $isLast = ($i === count($breadcrumbs) - 1); ?>
      <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>>
        <?php if (!$isLast): ?><a href="<?= sanitize($bc['url']) ?>"><?= sanitize($bc['name']) ?></a><?php else: ?><?= sanitize($bc['name']) ?><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol></nav>
  </div>
</div>

<div class="jhd-section">
  <div class="container">
    <?= jhd_page_head([
        'eyebrow' => 'هاب علمی',
        'icon' => 'bi-diagram-3',
        'title' => $topicName,
        'lead' => $rawDesc !== '' ? $rawDesc : ('مطالب، کتاب‌ها و درس‌های مرتبط با موضوع ' . $topicName),
    ]) ?>

    <?php if ($children): ?>
    <section class="mb-4">
      <h2 class="h5 mb-2">زیرموضوع‌ها</h2>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($children as $ch): ?>
          <a class="btn btn-outline-primary btn-sm" href="<?= sanitize(topicUrl($ch)) ?>"><?= sanitize((string)$ch['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($posts): ?>
      <?php
      echo jhd_lead_story($posts[0]);
      $rest = array_slice($posts, 1);
      ?>
      <?php if ($rest): ?>
      <div class="mt-4">
        <?= jhd_grid_open() ?>
        <?php foreach ($rest as $p) echo renderPostCard($p, ['compact' => true, 'cta' => 'ادامه مطلب', 'excerpt' => 110]); ?>
        <?= jhd_grid_close() ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($books): ?>
    <section class="mt-5">
      <?= jhd_section_head(['title' => 'کتاب‌های مرتبط', 'icon' => 'bi-book']) ?>
      <?= jhd_grid_open() ?>
      <?php foreach ($books as $b) echo renderBookCard($b, ['compact' => true]); ?>
      <?= jhd_grid_close() ?>
    </section>
    <?php endif; ?>

    <?php if ($lessons): ?>
    <section class="mt-5">
      <?= jhd_section_head(['title' => 'درس‌ها و مجموعه‌ها', 'icon' => 'bi-mortarboard']) ?>
      <?= jhd_grid_open() ?>
      <?php foreach ($lessons as $l) echo renderLessonCard($l, ['compact' => true]); ?>
      <?= jhd_grid_close() ?>
    </section>
    <?php endif; ?>

    <?php if (empty($posts) && empty($books) && empty($lessons)): ?>
    <div class="jhd-empty-state">
      <i class="bi bi-diagram-3" aria-hidden="true"></i>
      <h4>محتوای منتشرشده‌ای برای این موضوع یافت نشد</h4>
    </div>
    <?php endif; ?>

    <?= jhd_share_row(topicUrl($topic), $topicName) ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
