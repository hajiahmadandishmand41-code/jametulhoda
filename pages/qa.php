<?php
$pageTitle='پرسش و پاسخ';
$pageDesc='پرسش و پاسخ‌های دینی و علمی — پاسخ‌های مستند بر اساس قرآن و روایات.';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/functions.php';
$search=trim($_GET['q'] ?? '');
$page=max(1,(int)($_GET['page'] ?? 1));
$limit=12; $offset=($page-1)*$limit;
$opts=['type'=>'qa','limit'=>$limit,'offset'=>$offset];
if($search) $opts['search']=$search;
$posts=getPosts($opts);
$total=countPosts(array_merge(['type'=>'qa'], $search?['search'=>$search]:[]));
$pages=(int)ceil($total/$limit);
jhd_validate_pagination($page, $total, $limit, 'پرسش و پاسخ', url('qa'), 'پرسش و پاسخ');
$noindexSeo = ($total === 0);

?>
<div class="breadcrumb-bar"><div class="container"><nav><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="<?= siteUrl() ?>">صفحه اصلی</a></li><li class="breadcrumb-item active">پرسش و پاسخ</li></ol></nav></div></div>
<div class="jhd-section"><div class="container">
<div class="page-header mb-4"><h1 class="page-title"><i class="bi bi-question-circle ms-2 text-gold"></i> پرسش و پاسخ</h1><div class="section-divider"></div></div>
<form method="get" class="mb-4"><?= queryKeepFields() ?><div class="input-group" style="max-width:480px"><input type="search" name="q" class="form-control" aria-label="جستجوی پرسش و پاسخ" placeholder="جستجوی پرسش..." value="<?= sanitize($search) ?>"><button type="submit" class="btn btn-primary" aria-label="جستجو"><i class="bi bi-search" aria-hidden="true"></i></button></div></form>
<?= renderCategoryChips(['qa'], url('qa'), 'همه پرسش‌ها') ?>
<?php if(empty($posts)): ?><?= renderEmptyState('bi-question-circle', 'هنوز پرسشی در این بخش ثبت نشده است.', url(), 'بازگشت به صفحه اصلی') ?>
<?php else: ?><?php jhd_preload_post_topics($posts); ?>
<?= jhd_grid_open() ?><?php foreach($posts as $p):
echo renderPostCard($p, ['cta'=>'مشاهده پاسخ','excerpt'=>120]);
endforeach; ?></div>
<?php if($pages>1): ?><div class="mt-4"><?= paginate($total,$limit,$page, url('qa', ['q' => $search, 'page' => '%d'])) ?></div><?php endif; ?>
<?php endif; ?>
</div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
