<?php
/**
 * topics.php — اطلس و فهرست جامع موضوعات (ستون فقرات سامانه معارف جامعه‌الهدی)
 */
$pageTitle = 'اطلس موضوعات';
$pageDesc = 'منظومه و موضوعات معارف اسلامی مدرسه جامعه‌الهدی — هر موضوع مرکز گردآوری مقالات، اخبار، گزارش‌ها، کتاب‌ها، دروس و رسانه‌های تخصصی است.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

// The atlas remains complete at scale: page through root topics while each
// root card keeps its real descendants accessible from the topic hub.
$allTopicRoots = getTopicTree();
$allTopics = getTopics();
$topicPage = max(1, (int)($_GET['page'] ?? 1));
$topicLimit = 12;
$topicTotal = count($allTopicRoots);
$topicPages = max(1, (int)ceil($topicTotal / $topicLimit));
jhd_validate_pagination($topicPage, $topicTotal, $topicLimit, 'موضوعات', url('topics'), 'اطلس موضوعات');
$noindexSeo = ($topicTotal === 0);
$tree = array_slice($allTopicRoots, ($topicPage - 1) * $topicLimit, $topicLimit);
$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'موضوعات', 'url' => url('topics')]
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__ . '/../includes/header.php';
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
    <!-- سربرگ فشرده + ردیف موضوعات در همان بخش بالا (بدون بنر بلند) -->
    <header class="jhd-hero-compact">
      <div class="jhd-hero-compact__main">
        <p class="jhd-hero-compact__eyebrow"><i class="bi bi-diagram-3" aria-hidden="true"></i> منظومه فکری و درخت‌واره علوم اسلامی</p>
        <h1 class="jhd-hero-compact__title">اطلس جامع موضوعات</h1>
        <p class="jhd-hero-compact__lead">هر موضوع، مرکز گردآوری اخبار، مقالات، گزارش‌ها، کتاب‌ها، دروس و رسانه‌های تخصصی همان حوزه است.</p>
      </div>
      <?php if ($topicTotal > 0): ?>
      <ul class="jhd-hero-compact__stats" aria-label="آمار اطلس">
        <li><strong><?= number_format($topicTotal) ?></strong><span>موضوع اصلی</span></li>
        <li><strong><?= number_format(count($allTopics ?? getTopics())) ?></strong><span>موضوع و زیرموضوع</span></li>
      </ul>
      <?php endif; ?>
    </header>

    <?php if ($allTopicRoots): ?>
    <nav class="jhd-topic-rail" aria-label="انتخاب سریع موضوع">
      <span class="jhd-topic-rail__label"><i class="bi bi-signpost-split" aria-hidden="true"></i>انتخاب سریع</span>
      <div class="jhd-topic-rail__track">
        <?php foreach ($allTopicRoots as $railTopic): ?><a class="jhd-chip" href="<?= topicUrl($railTopic) ?>"><?= sanitize((string)$railTopic['name']) ?></a><?php endforeach; ?>
      </div>
    </nav>
    <?php endif; ?>

    <?php if (empty($tree)): ?>
    <?= renderEmptyState('bi-diagram-3', 'موضوعی در این بخش ثبت نشده است.', url(), 'بازگشت به صفحه اصلی') ?>
    <?php else: ?>
    <?php
    // شمارش دسته‌ای: سه کوئری برای کل صفحه، نه سه کوئری برای هر کاشی.
    $topicCounts = jhd_topic_content_counts(array_map(static fn(array $t): int => (int)$t['id'], $tree));
    ?>
    <?= jhd_grid_open() ?>
      <?php foreach ($tree as $top): $c = $topicCounts[(int)$top['id']] ?? ['posts'=>0,'lessons'=>0,'books'=>0]; ?>
      <?= renderTopicCard($top, [
          'counts' => [
              ['value' => $c['posts'], 'label' => 'مطلب', 'icon' => 'bi-journal-text'],
              ['value' => $c['lessons'], 'label' => 'درس', 'icon' => 'bi-mortarboard'],
              ['value' => $c['books'], 'label' => 'کتاب', 'icon' => 'bi-book'],
          ],
      ]) ?>
      <?php endforeach; ?>
    <?= jhd_grid_close() ?>
    <?php if ($topicPages > 1): ?>
    <nav class="mt-4" aria-label="صفحه‌بندی موضوعات"><?= paginate($topicTotal, $topicLimit, $topicPage, url('topics', ['page' => '%d'])) ?></nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
