<?php
/**
 * news.php — صفحه اخبار مدرسه علمیه جامعه‌الهدی
 */
$pageTitle = 'اخبار';
$metaTitleOverride = 'اخبار جامعه‌الهدی | اخبار مدرسه علمیه و رویدادها';
$pageDesc = 'تازه‌ترین اخبار، اطلاعیه‌ها، گزارش‌ها و رویدادهای مدرسه جامعه‌الهدی در حوزه آموزش، فرهنگ و علوم اسلامی.';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$search = trim($_GET['q'] ?? '');
$category = max(0, (int)($_GET['cat'] ?? 0));
$sort = (string)($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest', 'popular'], true)) $sort = 'newest';
$archiveCategories = getCategories();
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = POSTS_PER_PAGE;
$offset = ($page - 1) * $limit;

$opts = ['types' => ['news', 'announcement'], 'limit' => $limit, 'offset' => $offset, 'sort' => $sort];
if ($category) $opts['cat'] = $category;
if ($search) $opts['search'] = $search;

$posts = getPosts($opts);
$total = countPosts($opts);
$pages = (int)ceil($total / $limit);
jhd_validate_pagination($page, $total, $limit, 'اخبار', url('news'), 'اخبار');
$noindexSeo = ($total === 0);

$breadcrumbs = [
    ['name' => 'صفحه اصلی', 'url' => url()],
    ['name' => 'اخبار', 'url' => url('news')],
];
$breadcrumbsJsonLd = breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__ . '/../includes/header.php';

?>

<div class="breadcrumb-bar">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= url() ?>">صفحه اصلی</a></li>
                <li class="breadcrumb-item active" aria-current="page">اخبار</li>
            </ol>
        </nav>
    </div>
</div>

<div class="jhd-section">
    <div class="container">
        <?= jhd_page_head([
    'eyebrow' => 'اطلاع‌رسانی و رویدادها',
    'icon' => 'bi-newspaper',
    'title' => 'اخبار',
    'lead' => 'تازه‌ترین اخبار، اطلاعیه‌ها و رویدادهای جاری مدرسه جامعه‌الهدی',
]) ?>

        <!-- جستجو در اخبار -->
        <form method="get" class="mb-4" role="search">
            <?= formRouteFields('news') ?>
            <div class="input-group" style="max-width:440px">
                <label class="visually-hidden" for="news-search">جستجو در اخبار</label>
                <input id="news-search" type="search" name="q" class="form-control" placeholder="جستجو در اخبار..." value="<?= sanitize($search) ?>">
                <button type="submit" class="btn btn-primary" aria-label="جستجو"><i class="bi bi-search"></i></button>
                <?php if ($search): ?>
                <a href="<?= url('news') ?>" class="btn btn-outline-secondary" title="پاک کردن جستجو"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>

        <div class="d-flex flex-wrap gap-2 my-2">
          <label>دسته‌بندی
            <select class="form-select" name="cat">
              <option value="0">همهٔ دسته‌ها</option>
              <?php foreach ($archiveCategories as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>" <?= $category === (int)$cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>مرتب‌سازی
            <select class="form-select" name="sort">
              <?php foreach (['newest' => 'جدیدترین', 'oldest' => 'قدیمی‌ترین', 'popular' => 'بیشترین بازدید'] as $value => $label): ?>
              <option value="<?= $value ?>" <?= $sort === $value ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn btn-outline-primary align-self-end" type="submit">اعمال فیلتر</button>
        </div>
</form>


        <?php if ($search): ?>
        <div class="alert alert-info mb-4">
            نتایج جستجو برای «<strong><?= sanitize($search) ?></strong>» — <?= number_format($total) ?> نتیجه یافت شد.
        </div>
        <?php endif; ?>

        <?php if (empty($posts)): ?>
        <div class="jhd-empty-state">
            <i class="bi bi-newspaper" aria-hidden="true"></i>
            <h4>خبری یافت نشد</h4>
            <?php if ($search): ?>
            <a href="<?= url('news') ?>" class="btn btn-primary mt-3">نمایش همه اخبار</a>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <?= jhd_post_archive($posts, ['page' => $page, 'search' => $search, 'sort' => $sort]) ?>

    <?php $rails = jhd_listing_rails($posts); ?>
    <?= jhd_discovery_band([
        'latest'  => $rails['latest'],
        'popular' => $rails['popular'],
        'latest_title' => 'تازه‌ترین‌ها',
        'latest_more'  => 'همهٔ اخبار',
        'latest_more_url' => url('news'),
        'popular_title' => 'پربازدیدترین‌ها',
    ]) ?>

        <!-- صفحه‌بندی -->
        <?php if ($pages > 1): ?>
        <div class="mt-5">
            <?= paginate($total, $limit, $page, url('news', ['q' => $search, 'cat' => $category, 'sort' => $sort, 'page' => '%d'])) ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
