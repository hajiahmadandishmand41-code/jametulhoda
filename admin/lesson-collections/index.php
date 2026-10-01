<?php
/**
 * admin/lesson-collections/index.php — مدیریت مجموعه‌ها و جلدها (ساختار درس: مجموعه → جلد → درس)
 */
$adminTitle='مجموعه‌های درسی';
require_once __DIR__ . '/../includes/header.php';

$db=getDB();
$error=''; $success='';

// حذف مجموعه
if(isset($_POST['delete_collection'])){
    if(!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) $error='خطای امنیتی.';
    else{
        $del=(int)$_POST['delete_collection'];
        $cnt=$db->prepare("SELECT COUNT(*) FROM lessons WHERE collection_id=?"); $cnt->execute([$del]); $c=(int)$cnt->fetchColumn();
        if($c>0) $error="این مجموعه دارای $c درس است، قابل حذف نیست.";
        else{
            $coverStmt=$db->prepare('SELECT cover_image FROM lesson_collections WHERE id=?');
            $coverStmt->execute([$del]); $cover=(string)($coverStmt->fetchColumn() ?: '');
            $db->prepare("DELETE FROM lesson_collections WHERE id=?")->execute([$del]);
            $db->prepare("DELETE FROM lesson_volumes WHERE collection_id=?")->execute([$del]); // cascade already
            if($cover) scheduleFileDeletion($cover);
            $_SESSION['flash_msg']='مجموعه و فایل بدون‌مرجع آن حذف شد.'; $_SESSION['flash_type']='success'; redirect(siteUrl('admin/lesson-collections/'));
        }
    }
}
// حذف جلد
if(isset($_POST['delete_volume'])){
    if(!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) $error='خطای امنیتی.';
    else{
        $del=(int)$_POST['delete_volume'];
        $cnt=$db->prepare("SELECT COUNT(*) FROM lessons WHERE volume_id=?"); $cnt->execute([$del]); $c=(int)$cnt->fetchColumn();
        if($c>0) $error="این جلد دارای $c درس است.";
        else{ $db->prepare("DELETE FROM lesson_volumes WHERE id=?")->execute([$del]); $_SESSION['flash_msg']='جلد حذف شد.'; $_SESSION['flash_type']='success'; redirect(siteUrl('admin/lesson-collections/')); }
    }
}
// ذخیره مجموعه
if(isset($_POST['save_collection'])){
    beginContentUploadScope();
    if(!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) $error='خطای امنیتی.';
    else{
        $title=trim($_POST['title'] ?? '');
        $desc=trim($_POST['description'] ?? '');
        $sort=(int)($_POST['sort_order'] ?? 0);
        $is_active=isset($_POST['is_active'])?1:0;
        $is_featured=isset($_POST['is_featured'])?1:0;
        $editId=(int)($_POST['edit_id'] ?? 0);
        if(!$title) $error='عنوان الزامی است.';
        else{
            $slug=uniqueSlug('lesson_collections',$title,$editId);
            if($editId){
                $current=$db->prepare('SELECT cover_image FROM lesson_collections WHERE id=?');
                $current->execute([$editId]);
                $currentRow=$current->fetch();
                if(!$currentRow) $error='مجموعه موردنظر یافت نشد.';
                $cover=(string)($currentRow['cover_image'] ?? '');
                if(!$error && !empty($_FILES['cover_image']['name'])){
                    $newCover=uploadContentImage($_FILES['cover_image'],'lesson_collection',$editId);
                    if(!$newCover) $error='خطا در آپلود کاور.';
                    else { if($cover) scheduleFileDeletion($cover); $cover=$newCover; }
                }
                if(!$error) $db->prepare("UPDATE lesson_collections SET title=?, slug=?, description=?, cover_image=?, sort_order=?, is_active=?, is_featured=?, updated_at=NOW() WHERE id=?")->execute([$title,$slug,$desc ?: null,$cover ?: null,$sort,$is_active,$is_featured,$editId]);
            } else {
                $collectionId=0; $cover='';
                try {
                    $insert=$db->prepare("INSERT INTO lesson_collections (title,slug,description,cover_image,sort_order,is_active,is_featured) VALUES (?,?,?,NULL,?,?,?) RETURNING id");
                    $insert->execute([$title,$slug,$desc ?: null,$sort,$is_active,$is_featured]);
                    $collectionId=(int)$insert->fetchColumn(); $insert->closeCursor();
                    if($collectionId<1) throw new RuntimeException('شناسه مجموعه ایجاد نشد.');
                    if(!empty($_FILES['cover_image']['name'])){
                        $cover=uploadContentImage($_FILES['cover_image'],'lesson_collection',$collectionId);
                        if(!$cover) throw new RuntimeException('خطا در آپلود کاور.');
                        $db->prepare('UPDATE lesson_collections SET cover_image=? WHERE id=?')->execute([$cover,$collectionId]);
                    }
                } catch(Throwable $e) {
                    error_log('lesson collection create failed: '.get_class($e));
                    if($collectionId>0){
                        $db->prepare('DELETE FROM lesson_collections WHERE id=?')->execute([$collectionId]);
                        if($cover) scheduleFileDeletion($cover);
                    }
                    $error=$e instanceof RuntimeException ? $e->getMessage() : 'خطا در ذخیره مجموعه.';
                }
            }
            if(!$error){ $_SESSION['flash_msg']='مجموعه و پوشهٔ اختصاصی آن ذخیره شد.'; $_SESSION['flash_type']='success'; redirect(siteUrl('admin/lesson-collections/')); }
        }
    }
}
// ذخیره جلد
if(isset($_POST['save_volume'])){
    if(!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) $error='خطای امنیتی.';
    else{
        $colId=(int)($_POST['collection_id'] ?? 0);
        $title=trim($_POST['volume_title'] ?? '');
        $desc=trim($_POST['volume_description'] ?? '');
        $sort=(int)($_POST['volume_sort'] ?? 0);
        $editId=(int)($_POST['volume_edit_id'] ?? 0);
        if(!$colId || !$title) $error='مجموعه و عنوان جلد الزامی است.';
        else{
            $slug=uniqueSlug('lesson_volumes',$title,$editId);
            // Ensure slug unique within collection — our uniqueSlug checks globally, adjust if need
            if($editId){ $db->prepare("UPDATE lesson_volumes SET title=?, slug=?, description=?, sort_order=?, updated_at=NOW() WHERE id=?")->execute([$title,$slug,$desc,$sort,$editId]); }
            else{ $db->prepare("INSERT INTO lesson_volumes (collection_id,title,slug,description,sort_order) VALUES (?,?,?,?,?)")->execute([$colId,$title,$slug,$desc,$sort]); }
            $_SESSION['flash_msg']='جلد ذخیره شد.'; $_SESSION['flash_type']='success'; redirect(siteUrl('admin/lesson-collections/'));
        }
    }
}

$editCol=null; if(!empty($_GET['edit'])){ $s=$db->prepare("SELECT * FROM lesson_collections WHERE id=?"); $s->execute([(int)$_GET['edit']]); $editCol=$s->fetch(); }
$editVol=null; if(!empty($_GET['edit_volume'])){ $s=$db->prepare("SELECT * FROM lesson_volumes WHERE id=?"); $s->execute([(int)$_GET['edit_volume']]); $editVol=$s->fetch(); }
$collections=getLessonCollections(['active'=>null]); // all
?>
<div class="d-flex justify-content-between align-items-center mb-4">
<h5 class="mb-0"><i class="bi bi-collection ms-2"></i> مجموعه‌های درسی</h5>
<span class="text-muted small"><?= count($collections) ?> مجموعه</span>
</div>
<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>

<div class="row g-4">
<div class="col-lg-4">
<div class="admin-card">
<div class="admin-card-header"><?= $editCol?'<i class="bi bi-pencil ms-2"></i> ویرایش مجموعه':'<i class="bi bi-plus-circle ms-2"></i> مجموعه جدید' ?></div>
<div class="admin-card-body">
<form method="post" enctype="multipart/form-data" class="admin-form">
<?= csrfField() ?>
<input type="hidden" name="save_collection" value="1">
<?php if($editCol): ?><input type="hidden" name="edit_id" value="<?= (int)$editCol['id'] ?>"><?php endif; ?>
<div class="mb-3"><label class="form-label">عنوان مجموعه <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" value="<?= sanitize($editCol['title'] ?? '') ?>" required placeholder="مثلاً: لمعه دمشقیه"></div>
<div class="mb-3"><label class="form-label">توضیح</label><textarea name="description" class="form-control" rows="3"><?= sanitize($editCol['description'] ?? '') ?></textarea></div>
<div class="mb-3"><label class="form-label">کاور</label><input type="file" name="cover_image" class="form-control" accept="image/*"><?php if($editCol && $editCol['cover_image']): ?><img src="<?= imgUrl($editCol['cover_image']) ?>" style="max-width:120px;border-radius:8px;margin-top:8px"><?php endif; ?></div>
<div class="row g-2 mb-3"><div class="col-6"><label class="form-label">ترتیب</label><input type="number" name="sort_order" class="form-control" value="<?= $editCol['sort_order'] ?? 0 ?>"></div><div class="col-6 d-flex flex-column justify-content-end gap-2"><label class="form-check"><input type="checkbox" name="is_active" class="form-check-input" <?= ($editCol['is_active']??1)?'checked':'' ?>> فعال</label><label class="form-check"><input type="checkbox" name="is_featured" class="form-check-input" <?= ($editCol['is_featured']??0)?'checked':'' ?>> ویژه</label></div></div>
<div class="d-flex gap-2"><button class="btn btn-success flex-grow-1" type="submit"><i class="bi bi-<?= $editCol?'save':'plus-circle' ?> ms-1"></i> <?= $editCol?'ذخیره':'ایجاد' ?></button><?php if($editCol): ?><a href="<?= siteUrl('admin/lesson-collections/') ?>" class="btn btn-outline-secondary">انصراف</a><?php endif; ?></div>
</form>
</div>
</div>

<!-- فرم جلد -->
<div class="admin-card mt-4">
<div class="admin-card-header"><?= $editVol?'<i class="bi bi-pencil ms-2"></i> ویرایش جلد':'<i class="bi bi-plus-square ms-2"></i> جلد / بخش جدید' ?></div>
<div class="admin-card-body">
<form method="post" class="admin-form">
<?= csrfField() ?>
<input type="hidden" name="save_volume" value="1">
<?php if($editVol): ?><input type="hidden" name="volume_edit_id" value="<?= (int)$editVol['id'] ?>"><?php endif; ?>
<div class="mb-3"><label class="form-label">مجموعه</label>
<select name="collection_id" class="form-select" required>
<option value="">انتخاب مجموعه...</option>
<?php foreach($collections as $c): ?><option value="<?= $c['id'] ?>" <?= ($editVol && $editVol['collection_id']==$c['id'])?'selected':'' ?>><?= sanitize($c['title']) ?></option><?php endforeach; ?>
</select>
</div>
<div class="mb-3"><label class="form-label">عنوان جلد/بخش <span class="text-danger">*</span></label><input type="text" name="volume_title" class="form-control" value="<?= sanitize($editVol['title'] ?? '') ?>" required placeholder="مثلاً: جلد اول"></div>
<div class="mb-3"><label class="form-label">توضیح</label><textarea name="volume_description" class="form-control" rows="2"><?= sanitize($editVol['description'] ?? '') ?></textarea></div>
<div class="mb-3"><label class="form-label">ترتیب</label><input type="number" name="volume_sort" class="form-control" value="<?= $editVol['sort_order'] ?? 0 ?>"></div>
<div class="d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-<?= $editVol?'save':'plus-square' ?> ms-1"></i> <?= $editVol?'ذخیره':'ایجاد جلد' ?></button><?php if($editVol): ?><a href="<?= siteUrl('admin/lesson-collections/') ?>" class="btn btn-outline-secondary">انصراف</a><?php endif; ?></div>
</form>
</div>
</div>
</div>

<div class="col-lg-8">
<?php if(empty($collections)): ?><div class="text-center py-5 text-muted">مجموعه‌ای وجود ندارد.</div>
<?php else: foreach($collections as $col): $vols=getLessonVolumes((int)$col['id']); $lessonCount=count(getLessonsByCollection((int)$col['id'])); ?>
<div class="admin-card mb-4">
<div class="admin-card-header">
<span><i class="bi bi-collection ms-2"></i><?= sanitize($col['title']) ?> <small class="text-muted">(<?= $lessonCount ?> درس · <?= count($vols) ?> جلد)</small></span>
<div class="d-flex gap-1">
<a href="<?= siteUrl('admin/lesson-collections/?edit='.$col['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-pencil"></i></a>
<a href="<?= collectionUrl($col) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success py-0 px-2"><i class="bi bi-eye"></i></a>
<form method="post" style="display:inline" onsubmit="return confirm('حذف مجموعه؟')"><?= csrfField() ?><input type="hidden" name="delete_collection" value="<?= $col['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button></form>
</div>
</div>
<div class="admin-card-body">
<?php if($col['description']): ?><p class="text-muted small"><?= sanitize($col['description']) ?></p><?php endif; ?>
<?php if(empty($vols)): ?><div class="text-muted small">جلدی ثبت نشده — از فرم کنار جلد اضافه کنید.</div>
<?php else: ?>
<div class="table-responsive"><table class="table admin-table mb-0">
<thead><tr><th>عنوان جلد</th><th>ترتیب</th><th>درس‌ها</th><th>عملیات</th></tr></thead>
<tbody>
<?php foreach($vols as $v): $cnt=count(getLessonsByCollection((int)$col['id'], (int)$v['id'])); ?>
<tr>
<td><strong><?= sanitize($v['title']) ?></strong><br><code style="font-size:.70rem"><?= sanitize($v['slug']) ?></code></td>
<td><?= $v['sort_order'] ?></td>
<td><span class="badge bg-light text-dark border"><?= $cnt ?></span> <a href="<?= collectionUrl($col, $v) ?>" target="_blank" rel="noopener noreferrer" class="small">مشاهده</a></td>
<td><div class="d-flex gap-1"><a href="<?= siteUrl('admin/lesson-collections/?edit_volume='.$v['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-pencil"></i></a><form method="post" style="display:inline" onsubmit="return confirm('حذف جلد؟')"><?= csrfField() ?><input type="hidden" name="delete_volume" value="<?= $v['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button></form></div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
</div>
</div>
<?php endforeach; endif; ?>
</div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
