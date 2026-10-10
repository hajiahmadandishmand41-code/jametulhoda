<?php
/**
 * search.php — جستجوی سراسری محتوای منتشرشده و مرور آرشیو
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 200) : '';
$searchFilter = is_string($_GET['type'] ?? null) ? trim($_GET['type']) : 'all';
$allowedSearchFilters = [
    'all' => 'همه محتواها',
    'topic' => 'موضوعات',
    'article' => 'مقالات',
    'research' => 'پژوهش‌ها',
    'report' => 'گزارش‌ها',
    'news' => 'اخبار',
    'announcement' => 'اطلاعیه‌ها',
    'program' => 'برنامه‌های آموزشی',
    'religious' => 'فعالیت‌های مذهبی',
    'event' => 'رویدادها',
    'speech' => 'سخنرانی‌ها',
    'qa' => 'پرسش و پاسخ',
    'book' => 'کتاب‌ها',
    'lesson' => 'دروس',
    'media' => 'رسانه‌ها',
    'audio' => 'صوت‌ها',
    'video' => 'ویدیوها',
];
if (!isset($allowedSearchFilters[$searchFilter])) $searchFilter = 'all';

$sortParam = is_string($_GET['sort'] ?? null) ? trim($_GET['sort']) : '';
$searchSort = in_array($sortParam, ['relevance', 'newest'], true)
    ? $sortParam
    : ($q !== '' ? 'relevance' : 'newest');
if ($q === '') $searchSort = 'newest';

$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$limit = 12;
$topicOptions = getTopics(['active' => 1]);
$topicById = [];
foreach ($topicOptions as $topicOption) {
    $topicById[(int)($topicOption['id'] ?? 0)] = $topicOption;
}
$topicParam = is_string($_GET['topic'] ?? null) ? trim($_GET['topic']) : '';
$topicId = preg_match('/^[0-9]{1,10}$/D', $topicParam) === 1 ? (int)$topicParam : 0;
if ($topicId < 1 || !isset($topicById[$topicId])) $topicId = 0;
$activeTopic = $topicId > 0 ? $topicById[$topicId] : null;
$hasActiveSearch = $q !== '' || $searchFilter !== 'all' || $topicId > 0;

$pageTitle = $q !== ''
    ? 'جستجو: ' . $q
    : ($activeTopic ? 'مطالب موضوع ' . (string)$activeTopic['name'] : ($searchFilter !== 'all' ? 'مرور ' . $allowedSearchFilters[$searchFilter] : 'جستجو در آرشیو محتوا'));
$pageDesc = $q !== ''
    ? 'نتایج جستجو برای «' . $q . '» در محتوای منتشرشدهٔ مدرسه جامعه‌الهدی.'
    : 'جستجو و مرور محتوای منتشرشدهٔ مدرسه جامعه‌الهدی بر پایهٔ نوع محتوا و موضوع.';

$results = [];
$total = 0;
if ($hasActiveSearch) {
    $offset = ($page - 1) * $limit;
    $data = searchAll($q, $limit, $offset, $searchFilter, $topicId, $searchSort);
    $results = $data['results'];
    $total = $data['total'];
}
$pages = (int)ceil($total / $limit);

$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'جستجو', 'url' => url('search')],
];
if ($q !== '') $breadcrumbs[] = ['name' => $q, 'url' => url('search', ['q' => $q])];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="مسیر صفحه">
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

<section class="jhd-section jhd-search-page" aria-labelledby="search-page-title">
  <div class="container">
    <header class="page-header jhd-search-heading">
      <h1 class="page-title" id="search-page-title">
        <i class="bi bi-search ms-2 text-gold" aria-hidden="true"></i>
        <span>جستجو در آرشیو محتوایی</span>
      </h1>
      <div class="section-divider" aria-hidden="true"></div>
      <p class="text-muted mt-2 mb-0">در محتوای منتشرشده جستجو کنید یا با فیلتر نوع و موضوع، آرشیو را مرور کنید.</p>
    </header>

    <form method="get" action="<?= sanitize(formUrl('search')) ?>" class="jhd-search-panel jhd-search-form" role="search">
      <?= formRouteFields('search') ?>
      <div class="jhd-search-field">
        <label class="jhd-search-label" for="archive-search-query">عبارت مورد جستجو</label>
        <div class="jhd-search-control">
          <div class="jhd-search-input-wrap">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input
              id="archive-search-query"
              type="search"
              name="q"
              class="form-control jhd-search-input"
              aria-label="عبارت جستجو در آرشیو محتوا"
              placeholder="عنوان یا بخشی از متن را بنویسید"
              value="<?= sanitize($q) ?>"
              maxlength="200"
              enterkeyhint="search"
            >
          </div>
          <button type="submit" class="btn btn-primary jhd-search-submit">
            <i class="bi bi-search" aria-hidden="true"></i>
            <span>جستجو</span>
          </button>
        </div>
      </div>

      <div class="jhd-search-filter-row">
        <div class="jhd-search-filter">
          <label for="archive-search-type">نوع محتوا</label>
          <select id="archive-search-type" name="type" class="form-select">
            <?php foreach ($allowedSearchFilters as $filterKey => $filterLabel): ?>
              <option value="<?= sanitize($filterKey) ?>" <?= $searchFilter === $filterKey ? 'selected' : '' ?>><?= sanitize($filterLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="jhd-search-filter">
          <label for="archive-search-topic">موضوع</label>
          <select id="archive-search-topic" name="topic" class="form-select">
            <option value="">همهٔ موضوعات</option>
            <?php foreach ($topicOptions as $topicOption): ?>
              <option value="<?= (int)$topicOption['id'] ?>" <?= $topicId === (int)$topicOption['id'] ? 'selected' : '' ?>><?= sanitize((string)$topicOption['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="jhd-search-filter">
          <label for="archive-search-sort">مرتب‌سازی</label>
          <select id="archive-search-sort" name="sort" class="form-select">
            <option value="relevance" <?= $searchSort === 'relevance' ? 'selected' : '' ?>>مرتبط‌ترین</option>
            <option value="newest" <?= $searchSort === 'newest' ? 'selected' : '' ?>>جدیدترین</option>
          </select>
        </div>
        <p class="jhd-search-hint"><i class="bi bi-shield-check" aria-hidden="true"></i> پیش‌نویس‌ها و محتوای خصوصی در نتایج نمایش داده نمی‌شوند.</p>
      </div>
      <?php if ($hasActiveSearch): ?>
        <a class="jhd-search-clear" href="<?= sanitize(url('search')) ?>">پاک‌کردن عبارت و فیلترها</a>
      <?php endif; ?>
    </form>

    <?php if ($hasActiveSearch): ?>
      <section class="jhd-search-results" aria-labelledby="search-results-title" aria-live="polite">
        <div class="jhd-search-results-head">
          <div>
            <h2 id="search-results-title"><?= $q !== '' ? 'نتایج جستجو' : 'محتوای منتشرشده' ?></h2>
            <p>
              <?php if ($q !== ''): ?>برای «<strong><?= sanitize($q) ?></strong>» در <?php endif; ?>
              <?= sanitize($allowedSearchFilters[$searchFilter]) ?>
              <?php if ($activeTopic): ?> · موضوع «<?= sanitize((string)$activeTopic['name']) ?>»<?php endif; ?>
            </p>
          </div>
          <span class="jhd-search-result-count">
            <strong><?= number_format($total) ?></strong>
            <span>نتیجه</span>
          </span>
        </div>

        <?php if ($total === 0): ?>
          <div class="jhd-empty-state jhd-search-empty" role="status">
            <i class="bi bi-search" aria-hidden="true"></i>
            <h2><?= $q !== '' ? 'نتیجه‌ای برای این عبارت پیدا نشد' : 'محتوایی با این فیلترها پیدا نشد' ?></h2>
            <p><?= $q !== '' ? 'املای واژه را بررسی کنید، عبارت کوتاه‌تری بنویسید یا فیلترها را تغییر دهید.' : 'موضوع یا نوع محتوای دیگری انتخاب کنید یا همهٔ فیلترها را پاک کنید.' ?></p>
            <a href="<?= sanitize(url('search')) ?>" class="btn btn-outline-primary btn-sm">پاک‌کردن فیلترها</a>
          </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
          <?php jhd_preload_post_topics($results); ?>
          <?= jhd_grid_open('jhd-search-result-grid') ?>
            <?php foreach ($results as $p):
                if (($p['target'] ?? '') === 'media') {
                    $isAudio = ($p['media_kind'] ?? '') === 'audio';
                    $resultUrl = mediaUrl($isAudio ? 'audio' : 'video', (int)$p['id']);
                    $resultType = $isAudio ? 'audio' : 'video';
                    // A media file is not an image; keep the card's real-media
                    // destination while avoiding an empty/broken image request.
                    $p['featured_image'] = '';
                } elseif (!empty($p['media_kind'])) {
                    $resultUrl = ($p['target'] ?? '') === 'lesson' ? lessonUrl($p) : postUrl($p);
                    $resultType = (string)$p['media_kind'];
                    $p['featured_image'] = '';
                } elseif (($p['target'] ?? '') === 'topic') {
                    $resultUrl = topicUrl($p);
                    $resultType = 'topic';
                    $p['featured_image'] = (string)($p['featured_image'] ?? '');
                } elseif (($p['target'] ?? '') === 'book') {
                    $resultUrl = bookUrl($p);
                    $resultType = 'book';
                    $p['featured_image'] = (string)($p['featured_image'] ?? '');
                } elseif (($p['target'] ?? '') === 'lesson') {
                    $resultUrl = lessonUrl($p);
                    $resultType = 'lesson';
                } else {
                    $resultUrl = postUrl($p);
                    $resultType = (string)($p['post_type'] ?? 'post');
                }
                echo renderPostCard($p, [
                    'type' => $resultType,
                    'url' => $resultUrl,
                    'badge' => postTypeLabel($resultType),
                    'topics' => [],
                    'excerpt' => 110,
                    'cta' => 'مشاهده محتوا',
                ]);
            endforeach; ?>
          <?= jhd_grid_close() ?>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
          <?php $paginationUrl = url('search', ['q' => $q, 'type' => $searchFilter, 'topic' => $topicId, 'sort' => $searchSort, 'page' => '%d']); ?>
          <div class="mt-5"><?= paginate($total, $limit, $page, $paginationUrl) ?></div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <aside class="jhd-search-discovery" aria-labelledby="search-discovery-title">
        <div class="jhd-search-discovery-copy">
          <span class="jhd-search-discovery-icon"><i class="bi bi-compass" aria-hidden="true"></i></span>
          <div>
            <h2 id="search-discovery-title">مرور بر پایهٔ موضوع</h2>
            <p>موضوع‌های فعال را انتخاب کنید تا محتوای منتشرشدهٔ مرتبط را ببینید.</p>
          </div>
        </div>
        <?php if ($topicOptions): ?>
          <div class="jhd-search-suggestions" aria-label="موضوع‌های فعال">
            <?php foreach (array_slice($topicOptions, 0, 6) as $topicOption): ?>
              <a class="jhd-search-suggestion" href="<?= sanitize(url('search', ['topic' => (int)$topicOption['id']])) ?>">
                <i class="bi bi-arrow-up-left" aria-hidden="true"></i><?= sanitize((string)$topicOption['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
