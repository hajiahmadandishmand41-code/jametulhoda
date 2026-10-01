<?php
/** Topic detail: a complete, filterable hub for content attached to this topic and its descendants. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/auth.php';
startSecureSession();

$slug = is_string($_GET['slug'] ?? null) ? trim((string)$_GET['slug']) : '';
if ($slug === '') redirect(url('topics'));
if (!jhd_db_ready()) jhd_render_content_unavailable('موضوع', url('topics'), 'همهٔ موضوعات');

$topic = getTopicBySlug($slug);
if (!$topic || !(int)$topic['is_active']) {
    http_response_code(404);
    $pageTitle = 'موضوع یافت نشد';
    require __DIR__ . '/../includes/header.php';
    echo '<main class="container py-5 text-center"><h1>موضوع یافت نشد</h1><p class="text-muted">این موضوع در دسترس نیست.</p><a class="btn btn-primary" href="' . url('topics') . '">همهٔ موضوعات</a></main>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

/** Build a URL which keeps the hierarchical topic slug in both URL modes. */
function topicHubUrl(array $topic, string $section = 'all', array $extra = []): string {
    $query = array_filter(
        array_merge(['slug' => jhd_topic_slug_path($topic)], $section === 'all' ? [] : ['section' => $section], $extra),
        static fn($value): bool => $value !== '' && $value !== null
    );
    return url('topic', $query);
}

/** Managed media plus legacy primary lesson/post files, scoped to all descendant topics. */
function topicMediaRows(PDO $db, array $scopeIds, int $limit, int $offset = 0, string $sort = 'newest'): array {
    if (!$scopeIds) return [];
    $marks = implode(',', array_fill(0, count($scopeIds), '?'));

    $managed = "SELECT DISTINCT
            m.id, m.kind, m.file_path, m.title,
            COALESCE(p.title,l.title) AS parent_title,
            COALESCE(p.slug,l.slug) AS parent_slug,
            CASE WHEN m.ref_type='lesson' THEN 'lesson' ELSE 'post' END AS parent_kind,
            COALESCE(p.post_type,'lesson') AS post_type,
            COALESCE(p.published_at,l.created_at,m.created_at) AS published_at
        FROM media_files m
        LEFT JOIN posts p ON m.ref_type='post' AND p.id=m.ref_id
        LEFT JOIN lessons l ON m.ref_type='lesson' AND l.id=m.ref_id
        LEFT JOIN post_topics pt ON pt.post_id=p.id
        LEFT JOIN lesson_topics lt ON lt.lesson_id=l.id
        WHERE m.kind IN ('audio','video')
          AND ((p.status='published' AND pt.topic_id IN ($marks))
               OR (l.status='published' AND lt.topic_id IN ($marks)))";

    // The legacy branches retain primary files from records predating media_files.
    // DISTINCT prevents a parent/child topic relation from duplicating a file.
    $legacyAudio = "SELECT DISTINCT
            NULL AS id, 'audio' AS kind, l.audio_file AS file_path, l.title,
            l.title AS parent_title, l.slug AS parent_slug, 'lesson' AS parent_kind,
            'lesson' AS post_type, l.created_at AS published_at
        FROM lessons l JOIN lesson_topics lt ON lt.lesson_id=l.id
        WHERE l.status='published' AND l.audio_file IS NOT NULL AND l.audio_file<>''
          AND lt.topic_id IN ($marks)
          AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='audio' AND m.file_path=l.audio_file)";
    $legacyVideo = "SELECT DISTINCT
            NULL AS id, 'video' AS kind, l.video_file AS file_path, l.title,
            l.title AS parent_title, l.slug AS parent_slug, 'lesson' AS parent_kind,
            'lesson' AS post_type, l.created_at AS published_at
        FROM lessons l JOIN lesson_topics lt ON lt.lesson_id=l.id
        WHERE l.status='published' AND l.video_file IS NOT NULL AND l.video_file<>''
          AND lt.topic_id IN ($marks)
          AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='video' AND m.file_path=l.video_file)";
    $legacyPostVideo = "SELECT DISTINCT
            NULL AS id, 'video' AS kind, p.featured_video AS file_path, p.title,
            p.title AS parent_title, p.slug AS parent_slug, 'post' AS parent_kind,
            p.post_type, p.published_at
        FROM posts p JOIN post_topics pt ON pt.post_id=p.id
        WHERE p.status='published' AND p.featured_video IS NOT NULL AND p.featured_video<>''
          AND pt.topic_id IN ($marks)
          AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='post' AND m.ref_id=p.id AND m.kind='video' AND m.file_path=p.featured_video)";

    try {
        $order = $sort === 'oldest' ? 'ASC' : 'DESC';
        $sql = "SELECT * FROM ($managed UNION ALL $legacyAudio UNION ALL $legacyVideo UNION ALL $legacyPostVideo) topic_media
                ORDER BY published_at $order, (id IS NULL) ASC, id $order LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($scopeIds, $scopeIds, $scopeIds, $scopeIds, $scopeIds, [$limit, $offset]));
        return $stmt->fetchAll();
    } catch (PDOException) {
        return [];
    }
}

function topicMediaCount(PDO $db, array $scopeIds): int {
    if (!$scopeIds) return 0;
    $marks = implode(',', array_fill(0, count($scopeIds), '?'));
    $managed = "SELECT DISTINCT m.id,m.kind,m.file_path
        FROM media_files m
        LEFT JOIN posts p ON m.ref_type='post' AND p.id=m.ref_id
        LEFT JOIN lessons l ON m.ref_type='lesson' AND l.id=m.ref_id
        LEFT JOIN post_topics pt ON pt.post_id=p.id
        LEFT JOIN lesson_topics lt ON lt.lesson_id=l.id
        WHERE m.kind IN ('audio','video')
          AND ((p.status='published' AND pt.topic_id IN ($marks))
               OR (l.status='published' AND lt.topic_id IN ($marks)))";
    $legacyAudio = "SELECT DISTINCT NULL AS id,'audio' AS kind,l.audio_file AS file_path
        FROM lessons l JOIN lesson_topics lt ON lt.lesson_id=l.id
        WHERE l.status='published' AND l.audio_file IS NOT NULL AND l.audio_file<>'' AND lt.topic_id IN ($marks)
          AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='audio' AND m.file_path=l.audio_file)";
    $legacyVideo = "SELECT DISTINCT NULL AS id,'video' AS kind,l.video_file AS file_path
        FROM lessons l JOIN lesson_topics lt ON lt.lesson_id=l.id
        WHERE l.status='published' AND l.video_file IS NOT NULL AND l.video_file<>'' AND lt.topic_id IN ($marks)
          AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='video' AND m.file_path=l.video_file)";
    $legacyPostVideo = "SELECT DISTINCT NULL AS id,'video' AS kind,p.featured_video AS file_path
        FROM posts p JOIN post_topics pt ON pt.post_id=p.id
        WHERE p.status='published' AND p.featured_video IS NOT NULL AND p.featured_video<>'' AND pt.topic_id IN ($marks)
          AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='post' AND m.ref_id=p.id AND m.kind='video' AND m.file_path=p.featured_video)";
    $stmt = $db->prepare("SELECT COUNT(*) FROM ($managed UNION ALL $legacyAudio UNION ALL $legacyVideo UNION ALL $legacyPostVideo) topic_media");
    $stmt->execute(array_merge($scopeIds, $scopeIds, $scopeIds, $scopeIds, $scopeIds));
    return (int)$stmt->fetchColumn();
}

function topicMediaDestination(array $file): string {
    if (!empty($file['id'])) return mediaUrl((string)$file['kind'], (int)$file['id']);
    if (($file['parent_kind'] ?? '') === 'lesson') return lessonUrl((string)$file['parent_slug']);
    return postUrl(['slug' => (string)($file['parent_slug'] ?? ''), 'post_type' => (string)($file['post_type'] ?? '')]);
}

$allowedSections = ['all','news','articles','research','reports','other','books','lessons','media'];
$section = in_array($_GET['section'] ?? 'all', $allowedSections, true) ? (string)($_GET['section'] ?? 'all') : 'all';
$sort = ($_GET['sort'] ?? 'newest') === 'oldest' ? 'oldest' : 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$scopeIds = getTopicScopeIds((int)$topic['id']);
$children = getTopicChildren((int)$topic['id']);
$parents = getTopicBreadcrumbs((int)$topic['id']);
$counts = [
    'news' => countPostsByTopic((int)$topic['id'], 'news', $scopeIds),
    'articles' => countPostsByTopic((int)$topic['id'], 'article', $scopeIds),
    'research' => countPostsByTopic((int)$topic['id'], 'research', $scopeIds),
    'reports' => countPostsByTopic((int)$topic['id'], 'report', $scopeIds),
    'other' => 0,
    'books' => countBooksByTopic((int)$topic['id'], $scopeIds),
    'lessons' => countLessonsByTopic((int)$topic['id'], $scopeIds),
    'media' => 0,
];
$counts['other'] = max(0, countPostsByTopic((int)$topic['id'], null, $scopeIds) - $counts['news'] - $counts['articles'] - $counts['research'] - $counts['reports']);
$db = getDB();
try { $counts['media'] = topicMediaCount($db, $scopeIds); } catch (Throwable) { $counts['media'] = 0; }

$sectionTitles = ['all'=>'همهٔ محتوا','news'=>'اخبار','articles'=>'مقالات','research'=>'پژوهش‌ها','reports'=>'گزارش‌ها','other'=>'سایر مطالب','books'=>'کتاب‌ها','lessons'=>'دروس','media'=>'ویدیوها و صوت‌ها'];
$items = [];
$total = 0;
if (in_array($section, ['news','articles','research','reports'], true)) {
    $type = ['news'=>'news','articles'=>'article','research'=>'research','reports'=>'report'][$section];
    $total = $counts[$section];
    $items = getPostsByTopic((int)$topic['id'], ['type'=>$type,'limit'=>$limit,'offset'=>$offset,'sort'=>$sort,'scope_ids'=>$scopeIds]);
} elseif ($section === 'other') {
    $total = $counts['other'];
    $items = getPostsByTopic((int)$topic['id'], ['exclude_types'=>['news','article','research','report'],'limit'=>$limit,'offset'=>$offset,'sort'=>$sort,'scope_ids'=>$scopeIds]);
} elseif ($section === 'books') {
    $total = $counts['books'];
    $items = getBooksByTopic((int)$topic['id'], $limit, $offset, $sort, $scopeIds);
} elseif ($section === 'lessons') {
    $total = $counts['lessons'];
    $items = getLessonsByTopic((int)$topic['id'], $limit, $offset, $sort, $scopeIds);
} elseif ($section === 'media') {
    $total = $counts['media'];
    $items = topicMediaRows($db, $scopeIds, $limit, $offset, $sort);
}
$pages = (int)ceil($total / $limit);

$pageTitle = $topic['name'];
$pageDesc = $topic['intro'] ?: ($topic['description'] ?: 'محتوای وابسته به موضوع ' . $topic['name']);
$canonicalOverride = topicHubUrl($topic, $section, $section === 'all' ? [] : ['sort' => $sort]);
$breadcrumbs = [['name'=>'صفحه اصلی','url'=>url()], ['name'=>'موضوعات','url'=>url('topics')]];
foreach ($parents as $parent) {
    if ((int)$parent['id'] !== (int)$topic['id']) $breadcrumbs[] = ['name'=>$parent['name'],'url'=>topicUrl($parent)];
}
$breadcrumbs[] = ['name'=>$topic['name'],'url'=>topicHubUrl($topic)];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require __DIR__ . '/../includes/header.php';
?>
<div class="breadcrumb-bar"><div class="container"><nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
<?php foreach ($breadcrumbs as $index => $crumb): $last = $index === count($breadcrumbs) - 1; ?>
  <li class="breadcrumb-item <?= $last ? 'active' : '' ?>" <?= $last ? 'aria-current="page"' : '' ?>>
    <?php if (!$last): ?><a href="<?= sanitize($crumb['url']) ?>"><?= sanitize($crumb['name']) ?></a><?php else: ?><?= sanitize($crumb['name']) ?><?php endif; ?>
  </li>
<?php endforeach; ?>
</ol></nav></div></div>

<main class="jhd-section"><div class="container">
  <!-- سربرگ فشرده: بدون بنر بلند؛ عنوان، آمار و ردیف موضوعات همگی در همین بخش بالا -->
  <!-- کلاس jhd-topic-banner فقط قلاب سازگاری است (قرارداد قالب و آزمون‌ها):
       سربرگ کوتاه همان بنر موضوع است، فقط فشرده و بدون بلعیدن فضای بالا. -->
  <header class="jhd-hero-compact jhd-topic-banner<?= !empty($topic['cover_image']) ? ' jhd-hero-compact--cover' : '' ?>"
          <?php if (!empty($topic['cover_image'])): ?>style="--jhd-hero-image:url('<?= sanitize(imgUrl($topic['cover_image'])) ?>')"<?php endif; ?>>
    <div class="jhd-hero-compact__main">
      <p class="jhd-hero-compact__eyebrow"><i class="bi bi-diagram-3" aria-hidden="true"></i> مرکز محتوایی موضوع</p>
      <h1 class="jhd-hero-compact__title"><?= sanitize($topic['name']) ?></h1>
      <?php $topicLead = trim((string)($topic['intro'] ?: $topic['description'] ?: '')); ?>
      <?php if ($topicLead !== ''): ?><p class="jhd-hero-compact__lead"><?= sanitize(excerpt($topicLead, 190)) ?></p><?php endif; ?>
    </div>
    <ul class="jhd-hero-compact__stats" aria-label="آمار محتوای این موضوع<?= count($scopeIds) > 1 ? ' با زیرموضوع‌ها' : '' ?>">
      <li><strong><?= number_format($counts['articles'] + $counts['research']) ?></strong><span>مقاله و پژوهش</span></li>
      <li><strong><?= number_format($counts['news'] + $counts['reports']) ?></strong><span>خبر و گزارش</span></li>
      <li><strong><?= number_format($counts['books']) ?></strong><span>کتاب</span></li>
      <li><strong><?= number_format($counts['lessons']) ?></strong><span>درس</span></li>
      <li><strong><?= number_format($counts['media']) ?></strong><span>رسانه</span></li>
    </ul>
  </header>

  <!-- ردیف موضوعات: بلافاصله زیر سربرگ، قابل دیدن و انتخاب در موبایل -->
  <nav class="jhd-topic-rail" aria-label="موضوعات هم‌خانواده">
    <?php
    $railTopics = $children ?: array_values(array_filter($parents, static fn(array $p): bool => (int)$p['id'] !== (int)$topic['id']));
    $railLabel = $children ? 'زیرموضوع‌ها' : 'مسیر موضوع';
    ?>
    <span class="jhd-topic-rail__label"><i class="bi bi-signpost-split" aria-hidden="true"></i><?= sanitize($railLabel) ?></span>
    <div class="jhd-topic-rail__track">
      <a class="jhd-chip" href="<?= sanitize(url('topics')) ?>"><i class="bi bi-grid" aria-hidden="true"></i>اطلس موضوعات</a>
      <?php foreach ($railTopics as $child): ?><a class="jhd-chip" href="<?= topicUrl($child) ?>"><?= sanitize($child['name']) ?></a><?php endforeach; ?>
    </div>
  </nav>

  <nav class="jhd-cat-strip mb-4 jhd-cat-strip--sticky" aria-label="فیلتر نوع محتوا">
    <?php foreach (['all'=>'همه','news'=>'اخبار','articles'=>'مقالات','research'=>'پژوهش‌ها','reports'=>'گزارش‌ها','other'=>'سایر مطالب','books'=>'کتاب‌ها','lessons'=>'دروس','media'=>'رسانه'] as $key => $label): $badge = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0); ?>
      <a class="jhd-chip <?= $section === $key ? 'jhd-chip--all' : '' ?>" href="<?= topicHubUrl($topic, $key, ['sort'=>$sort]) ?>"><?= sanitize($label) ?> <small>(<?= number_format($badge) ?>)</small></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($section === 'all'): ?>
    <?php
    $previews = [
        'news' => getPostsByTopic((int)$topic['id'], ['type'=>'news','limit'=>3,'scope_ids'=>$scopeIds]),
        'articles' => getPostsByTopic((int)$topic['id'], ['type'=>'article','limit'=>3,'scope_ids'=>$scopeIds]),
        'research' => getPostsByTopic((int)$topic['id'], ['type'=>'research','limit'=>3,'scope_ids'=>$scopeIds]),
        'reports' => getPostsByTopic((int)$topic['id'], ['type'=>'report','limit'=>3,'scope_ids'=>$scopeIds]),
        'other' => getPostsByTopic((int)$topic['id'], ['exclude_types'=>['news','article','research','report'],'limit'=>3,'scope_ids'=>$scopeIds]),
        'books' => getBooksByTopic((int)$topic['id'], 3, 0, 'newest', $scopeIds),
        'lessons' => getLessonsByTopic((int)$topic['id'], 3, 0, 'newest', $scopeIds),
        'media' => topicMediaRows($db, $scopeIds, 3),
    ];
    $hasContent = array_sum($counts) > 0;
    ?>
    <?php foreach (['news'=>'اخبار','articles'=>'مقالات','research'=>'پژوهش‌ها','reports'=>'گزارش‌ها','other'=>'سایر مطالب'] as $key => $heading): if ($previews[$key]): ?>
      <section class="mb-5"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4 mb-0"><?= sanitize($heading) ?></h2><a href="<?= topicHubUrl($topic, $key) ?>" class="btn btn-sm btn-outline-primary">همه (<?= number_format($counts[$key]) ?>)</a></div><?= jhd_grid_open() ?>
        <?php jhd_preload_post_topics($previews[$key]); foreach ($previews[$key] as $post) echo renderPostCard($post, ['cta'=>'مشاهده']); ?>
      <?= jhd_grid_close() ?></section>
    <?php endif; endforeach; ?>
    <?php foreach (['books'=>'کتاب‌ها','lessons'=>'دروس'] as $key => $heading): if ($previews[$key]): ?>
      <section class="mb-5"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4 mb-0"><?= sanitize($heading) ?></h2><a href="<?= topicHubUrl($topic, $key) ?>" class="btn btn-sm btn-outline-primary">همه (<?= number_format($counts[$key]) ?>)</a></div><?= jhd_grid_open() ?>
        <?php foreach ($previews[$key] as $row) echo $key === 'books' ? renderBookCard($row) : renderLessonCard($row); ?>
      <?= jhd_grid_close() ?></section>
    <?php endif; endforeach; ?>
    <?php if ($previews['media']): ?><section class="mb-5"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4 mb-0">رسانه‌های مرتبط</h2><a href="<?= topicHubUrl($topic, 'media') ?>" class="btn btn-sm btn-outline-primary">همه (<?= number_format($counts['media']) ?>)</a></div><?= jhd_grid_open() ?>
      <?php foreach ($previews['media'] as $file) echo renderMediaCard($file, ['url' => topicMediaDestination($file)]); ?>
    <?= jhd_grid_close() ?></section><?php endif; ?>
    <?php if (!$hasContent): ?><?php echo renderEmptyState('bi-inboxes', 'هنوز محتوایی در این موضوع ثبت نشده است.', url('topics'), 'همه موضوعات'); ?><?php endif; ?>
  <?php else: ?>
    <section>
      <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-4"><h2 class="h3 mb-0"><?= sanitize($sectionTitles[$section]) ?></h2><form method="get" action="<?= formUrl('topic') ?>" class="d-flex gap-2"><?= formRouteFields('topic') ?><input type="hidden" name="slug" value="<?= sanitize(jhd_topic_slug_path($topic)) ?>"><input type="hidden" name="section" value="<?= sanitize($section) ?>"><select name="sort" class="form-select form-select-sm"><option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>تازه‌ترین</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>قدیمی‌ترین</option></select><button class="btn btn-sm btn-outline-secondary">مرتب‌سازی</button></form></div>
      <?php if (!$items): ?><?php echo renderEmptyState('bi-inboxes', 'هنوز محتوایی در این موضوع ثبت نشده است.', url('topics'), 'همه موضوعات'); ?>
      <?php elseif (in_array($section, ['news','articles','research','reports','other'], true)): ?><?= jhd_grid_open() ?><?php jhd_preload_post_topics($items); foreach ($items as $post) echo renderPostCard($post, ['cta'=>'مشاهده']); ?><?= jhd_grid_close() ?>
      <?php elseif ($section === 'books'): ?><?= jhd_grid_open() ?><?php foreach ($items as $book) echo renderBookCard($book); ?><?= jhd_grid_close() ?>
      <?php elseif ($section === 'lessons'): ?><?= jhd_grid_open() ?><?php foreach ($items as $lesson) echo renderLessonCard($lesson); ?><?= jhd_grid_close() ?>
      <?php else: ?><?= jhd_grid_open() ?><?php foreach ($items as $file) echo renderMediaCard($file, ['url' => topicMediaDestination($file)]); ?><?= jhd_grid_close() ?><?php endif; ?>
      <?php if ($pages > 1): ?><div class="mt-5"><?= paginate($total, $limit, $page, topicHubUrl($topic, $section, ['sort'=>$sort,'page'=>'%d'])) ?></div><?php endif; ?>
    </section>
  <?php endif; ?>
</div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
