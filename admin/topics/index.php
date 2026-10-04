<?php
/**
 * admin/topics/index.php — مدیریت موضوعات (ستون فقرات سایت)
 * قابلیت: ایجاد موضوع اصلی، زیرموضوع، زیرِ زیرموضوع، ویرایش، ترتیب، فعال/غیرفعال، تصویر کاور، توضیح
 */
$adminTitle='مدیریت موضوعات';
require_once __DIR__ . '/../includes/header.php';

$db=getDB();
$error=''; $success='';

// حذف
if(isset($_POST['delete'])){
    if(!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) $error='خطای امنیتی.';
    else{
        $delId=(int)$_POST['delete'];
        try{
            // check has children or linked content
            $cntChild=$db->prepare("SELECT COUNT(*) FROM topics WHERE parent_id=?");
            $cntChild->execute([$delId]); $hasChild=(int)$cntChild->fetchColumn();
            if($hasChild>0) $error='این موضوع دارای زیرموضوع است. ابتدا زیرموضوع‌ها را حذف یا منتقل کنید.';
            else{
                // A topic is a shared taxonomy. Never delete a row while any
                // content relation still points to it (posts, books or lessons).
                $links=[];
                foreach (['post_topics'=>'مطلب','book_topics'=>'کتاب','lesson_topics'=>'درس'] as $table=>$label) {
                    $linked=$db->prepare("SELECT COUNT(*) FROM $table WHERE topic_id=?"); $linked->execute([$delId]);
                    $count=(int)$linked->fetchColumn(); if($count) $links[]="$count $label";
                }
                if($links) $error='این موضوع به '.implode('، ',$links).' متصل است؛ ابتدا اتصال‌ها را مدیریت کنید.';
                else{
                    $row=getTopicById($delId);
                    $db->prepare("DELETE FROM topics WHERE id=?")->execute([$delId]);
                    if(!empty($row['cover_image'])) scheduleFileDeletion((string)$row['cover_image']);
                    $success='موضوع و فایل تصویرِ بدون مرجع آن با موفقیت حذف شد.';
                    $_SESSION['flash_msg']=$success; $_SESSION['flash_type']='success';
                    redirect(siteUrl('admin/topics/'));
                }
            }
        }catch(PDOException $e){ $error='خطا در حذف.'; error_log($e->getMessage()); }
    }
}

// ذخیره (ایجاد/ویرایش)
if($_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['delete'])){
    beginContentUploadScope();
    if(!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) $error='خطای امنیتی.';
    else{
        $name=trim($_POST['name'] ?? '');
        $desc=trim($_POST['description'] ?? '');
        $intro=trim($_POST['intro'] ?? '');
        $requestedSlug=trim((string)($_POST['slug'] ?? ''));
        // parent_id is optional: an absent/empty/zero value means "root topic".
        $parent_id = (int)($_POST['parent_id'] ?? 0) > 0 ? (int)$_POST['parent_id'] : null;
        $sort=(int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $editId=(int)($_POST['edit_id'] ?? 0);
        if(!$name) $error='نام موضوع الزامی است.';
        else{
            // A parent must exist and can never be this topic or one of its
            // descendants. This keeps the tree traversable and prevents cycles.
            $invalidParent = false;
            if ($parent_id !== null) {
                $parent = getTopicById($parent_id);
                if (!$parent) $invalidParent = true;
                if ($editId && ($parent_id === $editId || in_array($parent_id, getTopicScopeIds($editId), true))) $invalidParent = true;
            }
            if($invalidParent) $error='والد انتخاب‌شده معتبر نیست یا باعث حلقه در درخت موضوعات می‌شود.';
            else{
                $oldTopic=$editId ? getTopicById($editId) : null;
                if ($editId && !$oldTopic) { $error='موضوع موردنظر برای ویرایش یافت نشد.'; }
                if (!$error) {
                $slug=uniqueSlug('topics',$requestedSlug !== '' ? $requestedSlug : $name,$editId);
                if($editId){
                    $cover=(string)($oldTopic['cover_image'] ?? '');
                    if(!empty($_POST['remove_cover']) && $cover){ scheduleFileDeletion($cover); $cover=''; }
                    $directCover = jhdDirectUploadPath('cover_image');
                    if($directCover!==''){
                        $up=adoptDirectUpload($directCover,'image',contentStorageFolder('topic',$editId));
                    } elseif(!empty($_FILES['cover_image']['name'])){
                        $up=uploadContentImage($_FILES['cover_image'],'topic',$editId);
                        if(!$up) $error='خطا در آپلود کاور. فرمت‌های مجاز: JPG، PNG، GIF، WebP';
                        else { if($cover) scheduleFileDeletion($cover); $cover=$up; }
                    }
                    if(!$error){
                        $db->prepare("UPDATE topics SET parent_id=?, name=?, slug=?, description=?, intro=?, cover_image=?, sort_order=?, is_active=?, is_featured=?, updated_at=NOW() WHERE id=?")
                           ->execute([$parent_id,$name,$slug,$desc,$intro,$cover ?: null,$sort,$is_active,$is_featured,$editId]);
                        $success='موضوع ویرایش شد.';
                    }
                } else {
                    // Obtain the stable database ID before accepting a cover, so
                    // every topic owns its media folder (topics/topic-{id}/).
                    $topicId=0; $cover='';
                    try {
                        $insert=$db->prepare("INSERT INTO topics (parent_id,name,slug,description,intro,cover_image,sort_order,is_active,is_featured) VALUES (?,?,?,?,?,NULL,?,?,?) RETURNING id");
                        $insert->execute([$parent_id,$name,$slug,$desc ?: null,$intro ?: null,$sort,$is_active,$is_featured]);
                        $topicId=(int)$insert->fetchColumn(); $insert->closeCursor();
                        if($topicId<1) throw new RuntimeException('شناسه موضوع ایجاد نشد.');
                        $directCover = jhdDirectUploadPath('cover_image');
                        if($directCover!==''){
                            $cover=adoptDirectUpload($directCover,'image',contentStorageFolder('topic',$topicId));
                        } elseif(!empty($_FILES['cover_image']['name'])){
                            $cover=uploadContentImage($_FILES['cover_image'],'topic',$topicId);
                            if(!$cover) throw new RuntimeException('خطا در آپلود کاور. فرمت‌های مجاز: JPG، PNG، GIF، WebP');
                            $db->prepare('UPDATE topics SET cover_image=? WHERE id=?')->execute([$cover,$topicId]);
                        }
                        $success='موضوع جدید ایجاد شد.';
                    } catch(Throwable $e) {
                        error_log('topic create failed: '.get_class($e));
                        if($topicId>0) {
                            $db->prepare('DELETE FROM topics WHERE id=?')->execute([$topicId]);
                            if($cover) scheduleFileDeletion($cover);
                        }
                        $error=$e instanceof RuntimeException ? $e->getMessage() : 'خطا در ذخیره موضوع.';
                    }
                }
                if(!$error){
                    $_SESSION['flash_msg']=$success; $_SESSION['flash_type']='success';
                    redirect(siteUrl('admin/topics/'));
                }
                }
            }
        }
    }
}

$editTopic=null;
if(!empty($_GET['edit'])){
    $editTopic=getTopicById((int)$_GET['edit']);
}
$allTopics=getTopics();
$tree=getTopicTree(['active'=>null]);

// For parent select list flatten with depth
function flattenTopics($nodes,$depth=0,&$out=[]){
    foreach($nodes as $n){
        $out[]=['id'=>$n['id'],'name'=>str_repeat('— ',$depth).$n['name'],'depth'=>$depth];
        if(!empty($n['children'])) flattenTopics($n['children'],$depth+1,$out);
    }
    return $out;
}
$flat=[];
flattenTopics($tree,0,$flat);
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
<h5 class="mb-0"><i class="bi bi-diagram-3 ms-2"></i> موضوعات (<?= count($allTopics) ?>)</h5>
<div class="d-flex gap-2">
<a href="<?= siteUrl('topics') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">اطلس در سایت <i class="bi bi-box-arrow-up-left ms-1"></i></a>
<a href="<?= url() ?>#topics-section" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">نمایش در صفحه اصلی</a>
</div>
</div>

<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success"><?= sanitize($success) ?></div><?php endif; ?>

<div class="row g-4">
<div class="col-lg-4">
<div class="admin-card">
<div class="admin-card-header"><?= $editTopic?'<i class="bi bi-pencil ms-2"></i> ویرایش موضوع':'<i class="bi bi-plus-circle ms-2"></i> موضوع جدید' ?></div>
<div class="admin-card-body">
<div class="admin-help">
<strong>روند افزودن موضوع:</strong>
<ol>
<li>نام موضوع را بنویسید؛ نشانی یکتا در صورت خالی‌بودن از نام ساخته می‌شود.</li>
<li>موضوع اصلی بدون والد است؛ برای زیرموضوع، والد را از فهرست انتخاب کنید.</li>
<li>کاور در کاشی اطلس و صفحهٔ موضوع نمایش داده می‌شود.</li>
<li>گزینهٔ «ویژه در صفحه اصلی» موضوع را در بخش موضوعات صفحهٔ اول نشان می‌دهد.</li>
</ol>
</div>
<form method="post" enctype="multipart/form-data" class="admin-form" id="topicForm">
<?= csrfField() ?>
<?php if($editTopic): ?><input type="hidden" name="edit_id" value="<?= (int)$editTopic['id'] ?>"><?php endif; ?>
<div class="mb-3"><label class="form-label">نام موضوع <span class="text-danger">*</span></label><input type="text" name="name" id="topicNameInput" class="form-control" value="<?= sanitize($editTopic['name'] ?? '') ?>" required placeholder="مثلاً: مهدویت"></div>
<div class="mb-3"><label class="form-label">Slug (نشانی یکتا)</label><input type="text" name="slug" class="form-control" dir="ltr" value="<?= sanitize($editTopic['slug'] ?? '') ?>" placeholder="در صورت خالی‌بودن از نام ساخته می‌شود"><div class="form-text">حروف فارسی/لاتین، عدد و خط تیره پذیرفته می‌شود؛ درخت موضوع از این شناسه استفاده می‌کند.</div></div>
<div class="mb-3"><label class="form-label">والد (برای زیرموضوع)</label>
<select name="parent_id" class="form-select"><option value="">— بدون والد (موضوع اصلی) —</option>
<?php foreach($flat as $f): if($editTopic && $f['id']==$editTopic['id']) continue; ?>
<option value="<?= $f['id'] ?>" <?= ($editTopic['parent_id']??'')==$f['id']?'selected':'' ?>><?= sanitize($f['name']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="mb-3"><label class="form-label">معرفی کوتاه (intro)</label><textarea name="intro" class="form-control" rows="2" placeholder="توضیح کوتاه برای کارت موضوع"><?= sanitize($editTopic['intro'] ?? '') ?></textarea></div>
<div class="mb-3"><label class="form-label">توضیح کامل</label><textarea name="description" class="form-control" rows="3" placeholder="معرفی موضوع برای صفحه لندینگ"><?= sanitize($editTopic['description'] ?? '') ?></textarea></div>
<div class="mb-3"><label class="form-label">کاور / تصویر موضوع</label><input type="file" name="cover_image" class="form-control" accept="image/*" onchange="previewImg(this,'topicCoverPreview')"><div class="form-text">JPG/PNG/WebP — حداکثر 20MB — در کاشی اطلس سایت نمایش داده می‌شود</div>
<img id="topicCoverPreview" class="admin-topic-cover-preview mt-2" alt="پیش‌نمایش کاور" <?= ($editTopic && $editTopic['cover_image']) ? 'src="'.sanitize(imgUrl($editTopic['cover_image'])).'"' : 'style="display:none"' ?>>
<?php if($editTopic && $editTopic['cover_image']): ?><div class="mt-2"><label class="form-check small text-danger mt-1"><input class="form-check-input" type="checkbox" name="remove_cover" value="1"> حذف تصویر</label></div><?php endif; ?></div>
<div class="row g-2 mb-3">
<div class="col-6"><label class="form-label">ترتیب نمایش</label><input type="number" name="sort_order" class="form-control" value="<?= $editTopic['sort_order'] ?? 0 ?>"></div>
<div class="col-6 d-flex flex-column gap-2 justify-content-end">
<label class="form-check"><input type="checkbox" name="is_active" class="form-check-input" <?= ($editTopic['is_active']??1)?'checked':'' ?>> فعال</label>
<label class="form-check"><input type="checkbox" name="is_featured" class="form-check-input" <?= ($editTopic['is_featured']??0)?'checked':'' ?>> ویژه در صفحه اصلی</label>
</div>
</div>
<div class="d-flex gap-2">
<button class="btn btn-success flex-grow-1" type="submit"><i class="bi bi-<?= $editTopic?'save':'plus-circle' ?> ms-1"></i> <?= $editTopic?'ذخیره':'ایجاد' ?></button>
<?php if($editTopic): ?><a href="<?= siteUrl('admin/topics/') ?>" class="btn btn-outline-secondary">انصراف</a><?php endif; ?>
</div>
</form>
<div class="admin-preview-box">
<div class="form-text mb-2">پیش‌نمایش کاشی در سایت</div>
<div class="jhd-atlas-grid" style="grid-template-columns:1fr">
<a class="jhd-atlas-tile" href="#" tabindex="-1" onclick="return false">
<span class="jhd-atlas-body">
<strong><i class="bi bi-diagram-2" aria-hidden="true"></i><span id="topicPreviewName"><?= sanitize($editTopic['name'] ?? 'نام موضوع') ?></span></strong>
<small>کارت موضوع در اطلس و صفحهٔ اصلی</small>
</span>
</a>
</div>
</div>
</div>
</div>
</div>

<div class="col-lg-8">
<div class="admin-card">
<div class="admin-card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
<span>درخت موضوعات</span>
<input type="search" class="form-control form-control-sm" style="max-width:240px" data-admin-table-filter="topics-table" placeholder="جستجو در نام یا نشانی…">
</div>
<div class="admin-card-body p-0">
<?php if(empty($tree)): ?><div class="text-center py-5 text-muted">موضوعی وجود ندارد. از فرم سمت راست نخستین موضوع را بسازید.</div>
<?php else: ?>
<div class="table-responsive"><table class="table admin-table mb-0" id="topics-table">
<thead><tr><th>نام</th><th>والد</th><th>وضعیت</th><th>ترتیب</th><th>مطالب</th><th>عملیات</th></tr></thead>
<tbody>
<?php
function renderRows($nodes,$depth=0){
    foreach($nodes as $n){
        $cnt=countPostsByTopic((int)$n['id']);
        $childCnt=count($n['children'] ?? []);
        echo '<tr'.($depth?' style="background:'.($depth===1?'#fdfdfd':'#f8f7f2').'"':'').'>';
        echo '<td style="padding-inline-start:'.(12+$depth*18).'px"><strong>'.sanitize($n['name']).'</strong>';
        if($n['is_featured']) echo ' <span class="badge bg-warning text-dark" style="font-size:.65rem">ویژه</span>';
        if($n['cover_image']) echo ' <i class="bi bi-image text-success" title="دارای کاور"></i>';
        echo '<br><code style="font-size:.68rem;color:#888">'.sanitize($n['slug']).'</code>';
        if($n['intro']) echo '<div class="text-muted" style="font-size:.75rem">'.sanitize(mb_strimwidth($n['intro'],0,60,'...')).'</div>';
        echo '</td>';
        echo '<td class="text-muted small">'.($depth===0?'—': sanitize($GLOBALS['parentNames'][$n['parent_id']] ?? '')).'</td>';
        echo '<td>'.($n['is_active']?'<span class="badge bg-success">فعال</span>':'<span class="badge bg-secondary">غیرفعال</span>').'</td>';
        echo '<td>'.$n['sort_order'].'</td>';
        echo '<td><span class="badge bg-light text-dark border">'.$cnt.'</span></td>';
        echo '<td><div class="d-flex gap-1">';
        echo '<a href="'.siteUrl('admin/topics/?edit='. $n['id']).'" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-pencil"></i></a>';
        echo '<a href="'.topicUrl($n).'" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success py-0 px-2"><i class="bi bi-eye"></i></a>';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'حذف موضوع؟\')"><input type="hidden" name="delete" value="'.$n['id'].'">'.csrfField().'<button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button></form>';
        echo '</div></td>';
        echo '</tr>';
        if(!empty($n['children'])) renderRows($n['children'],$depth+1);
    }
}
$parentNames=[]; foreach($allTopics as $t) $parentNames[$t['id']]=$t['name'];
$GLOBALS['parentNames']=$parentNames;
renderRows($tree);
?>
</tbody>
</table></div>
<?php endif; ?>
</div></div>
</div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
