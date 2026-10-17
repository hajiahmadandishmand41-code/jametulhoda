<?php
/**
 * topic.php — هاب علمی موضوع: زیرموضوع‌ها و محتوای مرتبط (اخبار، مقالات، کتاب‌ها، درس‌ها، رسانه‌ها).
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
$topicDisplay = jhd_topic_display($topicId);
if (($topicDisplay['visibility'] ?? 'public') === 'inactive') {
    http_response_code(404);
    $pageTitle = 'موضوع یافت نشد';
    require __DIR__ . '/../includes/header.php';
    echo '<section class="container py-5"><h1>موضوع مورد نظر یافت نشد.</h1><a class="btn btn-primary mt-3" href="' . sanitize(url('articles')) . '">بازگشت به مقالات</a></section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$canonicalOverride = topicUrl($topic);
$topicName = (string)($topic['name'] ?? '');
$pageTitle = $topicName;
$metaTitleOverride = $topicName . ' | موضوعات جامعه‌الهدی';
$rawDesc = trim((string)($topic['description'] ?? ''));
$rawIntro = trim((string)($topic['intro'] ?? ''));
$pageDesc = $rawDesc !== '' ? $rawDesc : ($rawIntro !== '' ? excerpt($rawIntro, 180) : ('مطالب، کتاب‌ها و درس‌های مرتبط با موضوع ' . $topicName));

$crumbs = getTopicBreadcrumbs($topicId);
$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'موضوعات علمی', 'url' => url('topics')],
];
foreach ($crumbs as $c) {
    $breadcrumbs[] = ['name' => (string)$c['name'], 'url' => topicUrl($c)];
}
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

$children = getTopicChildren($topicId);
$relatedTopics = ($topicDisplay['show_related_topics'] ?? 1) ? getRelatedTopicsForTopic($topicId, $topicDisplay['related_topic_ids'] ?? [], 8) : [];

$displayMode = (string)($topicDisplay['mode'] ?? 'featured');
$contentLimit = (int)($topicDisplay['limit'] ?? 12);
$requestedSort = isset($_GET['sort']) && is_string($_GET['sort']) ? trim($_GET['sort']) : '';
$allowedSorts = ['newest', 'oldest', 'popular', 'featured', 'manual'];
$contentSort = in_array($requestedSort, $allowedSorts, true)
    ? $requestedSort
    : (string)($topicDisplay['sort'] ?? 'newest');
$postTypeFilter = isset($_GET['type']) && is_string($_GET['type']) ? trim($_GET['type']) : '';

$manualPostIds = is_array($topicDisplay['manual_post_ids'] ?? null)
    ? $topicDisplay['manual_post_ids']
    : array_filter(array_map('intval', preg_split('/[\s,،]+/', (string)($topicDisplay['manual_post_ids'] ?? '')) ?: []));
$manualBookIds = is_array($topicDisplay['manual_book_ids'] ?? null)
    ? $topicDisplay['manual_book_ids']
    : array_filter(array_map('intval', preg_split('/[\s,،]+/', (string)($topicDisplay['manual_book_ids'] ?? '')) ?: []));
$manualLessonIds = is_array($topicDisplay['manual_lesson_ids'] ?? null)
    ? $topicDisplay['manual_lesson_ids']
    : array_filter(array_map('intval', preg_split('/[\s,،]+/', (string)($topicDisplay['manual_lesson_ids'] ?? '')) ?: []));

$posts = getPostsByTopic($topicId, [
    'limit'      => $contentLimit,
    'offset'     => 0,
    'type'       => $postTypeFilter !== '' ? $postTypeFilter : null,
    'sort'       => $contentSort,
    'manual_ids' => $manualPostIds,
]);
if ($posts) {
    jhd_preload_post_images($posts);
}
$books = getBooksByTopic($topicId, $contentLimit, 0, $contentSort, null, $manualBookIds);
$lessons = getLessonsByTopic($topicId, $contentLimit, 0, $contentSort, null, $manualLessonIds);
$mediaItems = getMediaByTopic($topicId, min(8, $contentLimit), $contentSort);

$rawSecOrder = $topicDisplay['section_order'] ?? ['posts', 'books', 'lessons', 'media'];
$sectionOrder = is_array($rawSecOrder)
    ? $rawSecOrder
    : array_values(array_filter(array_map('trim', explode(',', (string)$rawSecOrder))));
if (!$sectionOrder) $sectionOrder = ['posts', 'books', 'lessons', 'media'];
$sectionCfg = [
    'show_media'   => $topicDisplay['show_media'] ?? true,
    'show_excerpt' => $topicDisplay['show_excerpt'] ?? true,
    'show_date'    => $topicDisplay['show_date'] ?? true,
    'show_meta'    => $topicDisplay['show_meta'] ?? true,
    'cta'          => 'ادامه مطلب',
];

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

<div class="jhd-section" data-display-mode="<?= sanitize($displayMode) ?>" data-topic-sort="<?= sanitize($contentSort) ?>">
  <div class="container">
    <div class="row g-3 align-items-center mb-3">
      <div class="<?= !empty($topic['cover_image']) ? 'col-lg-8' : 'col-12' ?>">
        <?= jhd_page_head([
            'eyebrow' => 'هاب علمی موضوع',
            'icon' => 'bi-diagram-3',
            'title' => $topicName,
            'lead' => $rawDesc !== '' ? $rawDesc : ('مطالب، کتاب‌ها، درس‌ها و رسانه‌های مرتبط با موضوع ' . $topicName),
        ]) ?>
        <?php if ($rawIntro !== '' && $rawIntro !== $rawDesc): ?>
          <div class="jhd-surface p-3 mt-2 small text-secondary" style="line-height:1.9"><?= nl2br(sanitize($rawIntro)) ?></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($topic['cover_image']) && jhd_is_usable_media_path((string)$topic['cover_image'])): ?>
      <div class="col-lg-4">
        <div class="rounded-4 overflow-hidden border shadow-sm">
          <img src="<?= sanitize(imgUrl((string)$topic['cover_image'])) ?>" alt="<?= sanitize($topicName) ?>" class="w-100" style="max-height:220px;object-fit:cover" loading="eager">
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($children): ?>
    <section class="mb-4" aria-label="زیرموضوع‌ها">
      <h2 class="h6 fw-bold mb-2"><i class="bi bi-diagram-2 ms-1"></i>زیرموضوع‌های «<?= sanitize($topicName) ?>»</h2>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($children as $ch): ?>
          <a class="jhd-chip" href="<?= sanitize(topicUrl($ch)) ?>"><i class="bi bi-folder2-open ms-1"></i><?= sanitize((string)$ch['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($posts || $books || $lessons || $mediaItems): ?>
    <!-- نوار مرتب‌سازی محتوا در صفحه موضوع -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
      <div class="small text-muted">
        مجموع محتوای مرتبط در این صفحه:
        <strong><?= jhd_persian_digits(count($posts) + count($books) + count($lessons)) ?></strong> مورد
      </div>
      <div class="d-flex flex-wrap align-items-center gap-1 small">
        <span class="text-muted ms-1">مرتب‌سازی:</span>
        <?php foreach (['newest' => 'جدیدترین', 'popular' => 'پربازدیدترین', 'featured' => 'شاخص‌ها', 'oldest' => 'قدیمی‌ترین'] as $sKey => $sLbl): ?>
          <a href="<?= sanitize(url('topic', ['slug' => $topic['slug'], 'sort' => $sKey])) ?>" class="jhd-chip <?= $contentSort === $sKey ? 'active' : '' ?>"><?= sanitize($sLbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
    foreach ($sectionOrder as $secName):
        if ($secName === 'posts' && $posts):
    ?>
    <section class="mb-5" data-topic-section="posts">
      <?= jhd_section_head(['title' => 'مقالات، اخبار و نوشتارهای مرتبط', 'icon' => 'bi-file-earmark-richtext', 'count' => count($posts)]) ?>
      <?php if ($displayMode === 'featured'): ?>
        <?= jhd_lead_story($posts[0], $sectionCfg) ?>
        <?php $restPosts = array_slice($posts, 1); ?>
        <?php if ($restPosts): ?>
          <div class="mt-3">
            <?= jhd_render_section_items($restPosts, 'post', 'grid', $sectionCfg) ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <?= jhd_render_section_items($posts, 'post', $displayMode, $sectionCfg) ?>
      <?php endif; ?>
    </section>
    <?php
        elseif ($secName === 'books' && $books):
    ?>
    <section class="mt-4 mb-5" data-topic-section="books">
      <?= jhd_section_head(['title' => 'کتاب‌های مرتبط', 'icon' => 'bi-book', 'count' => count($books)]) ?>
      <?= jhd_render_section_items($books, 'book', $displayMode === 'featured' ? 'grid' : $displayMode, $sectionCfg) ?>
    </section>
    <?php
        elseif ($secName === 'lessons' && $lessons):
    ?>
    <section class="mt-4 mb-5" data-topic-section="lessons">
      <?= jhd_section_head(['title' => 'درس‌ها و مجموعه‌های آموزشی', 'icon' => 'bi-mortarboard', 'count' => count($lessons)]) ?>
      <?= jhd_render_section_items($lessons, 'lesson', $displayMode === 'featured' ? 'grid' : $displayMode, $sectionCfg) ?>
    </section>
    <?php
        elseif ($secName === 'media' && $mediaItems):
    ?>
    <section class="mt-4 mb-5" data-topic-section="media">
      <?= jhd_section_head(['title' => 'ویدیوها و فایل‌های صوتی مرتبط', 'icon' => 'bi-play-btn', 'count' => count($mediaItems)]) ?>
      <?= jhd_render_section_items($mediaItems, 'media', $displayMode === 'featured' ? 'grid' : $displayMode, $sectionCfg) ?>
    </section>
    <?php
        endif;
    endforeach;
    ?>

    <?php if (empty($posts) && empty($books) && empty($lessons) && empty($mediaItems)): ?>
    <div class="jhd-empty-state" data-topic-empty="1">
      <i class="bi bi-diagram-3" aria-hidden="true"></i>
      <h4>محتوای منتشرشده‌ای برای این موضوع یافت نشد</h4>
      <p><?= sanitize(!empty($topicDisplay['empty_message']) ? (string)$topicDisplay['empty_message'] : 'در حال حاضر مقاله، کتاب یا درسی در این موضوع منتشر نشده است. می‌توانید سایر موضوعات علمی را مرور کنید.') ?></p>
      <a href="<?= sanitize(url('topics')) ?>" class="btn btn-outline-primary btn-sm mt-2">مشاهده همه موضوعات علمی</a>
    </div>
    <?php endif; ?>

    <?php if ($relatedTopics): ?>
    <section class="mt-5 pt-3 border-top" aria-label="موضوعات علمی مرتبط">
      <?= jhd_section_head(['title' => 'موضوعات علمی هم‌خانواده و مرتبط', 'icon' => 'bi-diagram-3']) ?>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($relatedTopics as $rt): ?>
          <a class="jhd-chip" href="<?= sanitize(topicUrl($rt)) ?>">
            <i class="bi bi-diagram-3 ms-1" aria-hidden="true"></i><?= sanitize((string)$rt['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?= jhd_share_row(topicUrl($topic), $topicName) ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
