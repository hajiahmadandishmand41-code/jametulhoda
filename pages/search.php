<?php
/**
 * search.php — جستجوی یکپارچه در آرشیو موضوعات، نوشته‌ها، کتاب‌ها، دروس و رسانه‌ها
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startSecureSession();

$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 200) : '';
$pageTitle = $q ? 'جستجو: ' . $q : 'جستجو در آرشیو محتوا';
$pageDesc = $q ? 'نتایج جستجو برای «' . $q . '» در موضوعات، مقالات، گزارش‌ها، کتاب‌ها، دروس و رسانه‌های جامعه‌الهدی.' : 'جستجو در آرشیو محتوایی مدرسه جامعه‌الهدی — موضوعات، مقالات، گزارش‌ها، کتاب‌ها، دروس، ویدیو و صوت.';

$searchFilter = is_string($_GET['type'] ?? null) ? (string)$_GET['type'] : 'all';
$allowedSearchFilters = ['all'=>'همه محتواها','topic'=>'موضوعات','article'=>'مقالات','research'=>'پژوهش‌ها','report'=>'گزارش‌ها','news'=>'اخبار','book'=>'کتاب‌ها','lesson'=>'دروس','media'=>'رسانه‌ها','audio'=>'صوت‌ها','video'=>'ویدیوها'];
if (!isset($allowedSearchFilters[$searchFilter])) $searchFilter = 'all';
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$results = [];
$total = 0;

if ($q) {
    $offset = ($page - 1) * $limit;
    $data = searchAll($q, $limit, $offset, $searchFilter);
    $results = $data['results'];
    $total = $data['total'];
}
$pages = (int)ceil($total / $limit);

$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'جستجو', 'url' => url('search')]
];
if ($q) {
    $breadcrumbs[] = ['name' => $q, 'url' => url('search', ['q' => $q])];
}
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);
$searchSuggestions = ['قرآن و حدیث', 'فقه و اصول', 'اخلاق اسلامی', 'پژوهش‌های علمی'];

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
      <p class="text-muted mt-2 mb-0">در میان موضوعات، مقالات، پژوهش‌ها، کتاب‌ها، درس‌ها و رسانه‌های جامعة‌الهدی جستجو کنید.</p>
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
              placeholder="مثلاً: قرآن، فلسفه یا اصول فقه"
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
          <label for="archive-search-type">جستجو در</label>
          <select id="archive-search-type" name="type" class="form-select" aria-label="انتخاب نوع محتوا">
            <?php foreach ($allowedSearchFilters as $filterKey => $filterLabel): ?>
              <option value="<?= sanitize($filterKey) ?>" <?= $searchFilter === $filterKey ? 'selected' : '' ?>><?= sanitize($filterLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="jhd-search-hint"><i class="bi bi-info-circle" aria-hidden="true"></i> برای جستجوی گسترده، «همه محتواها» را انتخاب کنید.</p>
      </div>
    </form>

    <?php if ($q): ?>
      <section class="jhd-search-results" aria-labelledby="search-results-title" aria-live="polite">
        <?php if ($total > 0): ?>
          <div class="jhd-search-results-head">
            <div>
              <h2 id="search-results-title">نتایج جستجو</h2>
              <p>نتایج برای «<strong><?= sanitize($q) ?></strong>» در <?= sanitize($allowedSearchFilters[$searchFilter]) ?></p>
            </div>
            <span class="jhd-search-result-count">
              <strong><?= number_format($total) ?></strong>
              <span>نتیجه</span>
            </span>
          </div>
        <?php else: ?>
          <div class="jhd-empty-state jhd-search-empty" role="status">
            <i class="bi bi-search" aria-hidden="true"></i>
            <h2 id="search-results-title">نتیجه‌ای برای «<?= sanitize($q) ?>» پیدا نشد</h2>
            <p>املای واژه را بررسی کنید، عبارت کوتاه‌تری بنویسید یا همه محتواها را جستجو کنید.</p>
            <a href="<?= sanitize(url('topics')) ?>" class="btn btn-outline-primary btn-sm">
              مرور اطلس موضوعات <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </a>
          </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
          <?php jhd_preload_post_topics($results); ?>
          <?= jhd_grid_open('jhd-search-result-grid') ?>
            <?php foreach ($results as $p):
                if ($p['target'] === 'media') {
                    $isAudio = ($p['media_kind'] ?? '') === 'audio';
                    $resultUrl = mediaUrl($isAudio ? 'audio' : 'video', (int)$p['id']);
                    $resultType = $isAudio ? 'audio' : 'video';
                    // فایل صوتی/ویدیویی تصویر نیست؛ کارت رسانه باید متنی بماند.
                    $p['featured_image'] = '';
                } elseif (!empty($p['media_kind'])) {
                    // رسانهٔ قدیمی مقصد مستقل ندارد و به صفحهٔ محتوای اصلی می‌رود.
                    $resultUrl = $p['target'] === 'lesson' ? lessonUrl($p) : postUrl($p);
                    $resultType = (string)$p['media_kind'];
                    $p['featured_image'] = '';
                } elseif ($p['target'] === 'topic') {
                    $resultUrl = topicUrl($p);
                    $resultType = 'topic';
                    // جلد/تصویر واقعی همان رکورد؛ هیچ تصویر ساختگی ساخته نمی‌شود.
                    $p['featured_image'] = (string)($p['cover_image'] ?? '');
                } elseif ($p['target'] === 'book') {
                    $resultUrl = bookUrl($p);
                    $resultType = 'book';
                    $p['featured_image'] = (string)($p['cover_image'] ?? '');
                } elseif ($p['target'] === 'lesson') {
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
                    // شناسهٔ موضوع/کتاب/درس/رسانه شناسهٔ نوشته نیست؛ چیپ موضوع را نخوان.
                    'topics' => [],
                    'excerpt' => 120,
                    'cta' => 'مشاهده محتوا',
                ]);
            endforeach; ?>
          <?= jhd_grid_close() ?>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
          <div class="mt-5"><?= paginate($total, $limit, $page, url('search', ['q' => $q, 'type' => $searchFilter, 'page' => '%d'])) ?></div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <aside class="jhd-search-discovery" aria-labelledby="search-discovery-title">
        <div class="jhd-search-discovery-copy">
          <span class="jhd-search-discovery-icon"><i class="bi bi-compass" aria-hidden="true"></i></span>
          <div>
            <h2 id="search-discovery-title">برای شروع، یکی از این موضوع‌ها را ببینید</h2>
            <p>یک پیشنهاد را انتخاب کنید یا عبارت دلخواهتان را در کادر بالا بنویسید.</p>
          </div>
        </div>
        <div class="jhd-search-suggestions" aria-label="پیشنهادهای جستجو">
          <?php foreach ($searchSuggestions as $suggestion): ?>
            <a class="jhd-search-suggestion" href="<?= sanitize(url('search', ['q' => $suggestion])) ?>">
              <i class="bi bi-arrow-up-left" aria-hidden="true"></i><?= sanitize($suggestion) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </aside>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
