<?php
/**
 * news.php — صفحه اخبار مدرسه علمیه جامعه‌الهدی
 */
$pageTitle = 'اخبار';
$pageDesc = 'اخبار، رویدادها، اطلاعیه‌ها و گزارش‌های جاری مدرسه علمیه جامعه‌الهدی';
require_once __DIR__ . '/../includes/header.php';

$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = POSTS_PER_PAGE;
$offset = ($page - 1) * $limit;

$opts = ['type' => 'news', 'limit' => $limit, 'offset' => $offset];
if ($search) $opts['search'] = $search;

$posts = getPosts($opts);
$total = countPosts(array_merge(['type' => 'news'], $search ? ['search' => $search] : []));
$pages = (int)ceil($total / $limit);
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
    'title' => 'اخبار مدرسه',
    'lead' => 'تازه‌ترین اخبار، اطلاعیه‌ها و رویدادهای جاری مدرسه علمیه جامعة‌الهدی',
]) ?>

        <!-- جستجو در اخبار -->
        <form method="get" class="mb-4" role="search">
            <?= queryKeepFields() ?>
            <div class="input-group" style="max-width:440px">
                <label class="visually-hidden" for="news-search">جستجو در اخبار</label>
                <input id="news-search" type="search" name="q" class="form-control" placeholder="جستجو در اخبار..." value="<?= sanitize($search) ?>">
                <button type="submit" class="btn btn-primary" aria-label="جستجو"><i class="bi bi-search"></i></button>
                <?php if ($search): ?>
                <a href="<?= url('news') ?>" class="btn btn-outline-secondary" title="پاک کردن جستجو"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>

        <?= renderCategoryChips(['news'], url('news'), 'همه اخبار') ?>
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

        <?php
        $newsList = $posts;
        if (!$search && $page === 1 && $newsList) {
            echo '<div class="mb-4">' . renderPostCard($newsList[0], [
                'featured' => true,
                'col' => 'col-12',
                'cta' => 'ادامه مطلب',
                'excerpt' => 130,
            ]) . '</div>';
            $newsList = array_slice($newsList, 1);
        }
        ?>
        <?php jhd_preload_post_topics($newsList); ?>
        <?= jhd_grid_open() ?>
            <?php foreach ($newsList as $news):
                echo renderPostCard($news, [
                    'cta' => 'ادامه مطلب',
                    'excerpt' => 130,
                ]);
            endforeach; ?>
        <?= jhd_grid_close() ?>

        <!-- صفحه‌بندی -->
        <?php if ($pages > 1): ?>
        <div class="mt-5">
            <?= paginate($total, $limit, $page, url('news', ['q' => $search, 'page' => '%d'])) ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
