<?php
/**
 * admin/media/index.php — مدیریت رسانه — اصلاح‌شده
 */
$adminTitle = 'مدیریت رسانه';
require_once __DIR__ . '/../includes/header.php';


$error = $success = '';

// آپلود تصاویر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['jhd_direct']['images'] ?? false || !empty($_FILES['images']['name'][0]))) {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی.';
    } else {
        $uploaded = 0;
        $failed   = 0;
        foreach (jhdDirectUploadPaths('images') as $directPath) {
            $stored = adoptDirectUpload($directPath, 'image', 'media');
            if ($stored) $uploaded++; else $failed++;
        }
        foreach ($_FILES['images']['name'] as $k => $name) {
            if ($name === '') continue;
            if (($_FILES['images']['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $failed++;
                continue;
            }
            $file = [
                    'name'     => $name,
                    'type'     => $_FILES['images']['type'][$k],
                    'tmp_name' => $_FILES['images']['tmp_name'][$k],
                    'error'    => $_FILES['images']['error'][$k],
                    'size'     => $_FILES['images']['size'][$k],
                ];
                $result = uploadImage($file, 'media');
            if ($result) $uploaded++;
            else $failed++;
        }
        if ($uploaded > 0) $success = $uploaded . ' تصویر با موفقیت آپلود شد.' . ($failed > 0 ? " ($failed فایل خطا)" : '');
        elseif ($failed > 0) $error = 'همه فایل‌ها با خطا مواجه شدند. فرمت یا حجم مجاز نیست.';
    }
}

if (!empty($_POST['delete'])) {
    requirePostCsrf();
    $id = (int)$_POST['delete'];
    $stmt = getDB()->prepare('SELECT url FROM stored_files WHERE id=?');
    $stmt->execute([$id]);
    if ($url=$stmt->fetchColumn()) {
        if (storedFileIsReferenced($url)) {
            $_SESSION['flash_msg']='فایل به محتوا متصل است؛ ابتدا آن را از محتوا جدا کنید.';
            $_SESSION['flash_type']='warning';
        } else deleteStoredFile($url);
    }
    redirect(siteUrl('admin/media/'));
}
$page=max(1,min(10000,(int)($_GET['page']??1))); $limit=48;
$total=(int)getDB()->query('SELECT COUNT(*) FROM stored_files')->fetchColumn();
$listing=getDB()->prepare('SELECT * FROM stored_files ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?');
$listing->execute([$limit,($page-1)*$limit]); $rows=$listing->fetchAll();
$images = array_map(fn($row) => ['name'=>$row['id'], 'display_name'=>basename(parse_url($row['url'],PHP_URL_PATH)??''), 'mime'=>$row['mime'], 'path'=>$row['url'], 'size'=>$row['size'], 'time'=>strtotime($row['created_at'])], $rows);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="bi bi-images ms-2"></i>مدیریت رسانه</h5>
    <span class="text-muted small"><?= $total ?> فایل</span>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success alert-auto-dismiss"><?= sanitize($success) ?></div><?php endif; ?>

<!-- آپلود -->
<div class="admin-card mb-4">
    <div class="admin-card-header">آپلود تصاویر جدید</div>
    <div class="admin-card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="d-flex gap-3 align-items-end flex-wrap">
                <div class="flex-grow-1">
                    <label class="fw-bold mb-1">انتخاب فایل‌ها (چند فایل)</label>
                    <input type="file" name="images[]" class="form-control" accept="image/*" multiple required>
                    <div class="form-text">فرمت‌های مجاز: JPG، PNG، GIF، WebP — حداکثر 20MB هر فایل</div>
                </div>
                <button type="submit" class="btn btn-success"><i class="bi bi-upload ms-1"></i>آپلود</button>
            </div>
        </form>
    </div>
</div>

<!-- گالری -->
<?php if (!empty($images)): ?>
<div class="admin-card">
    <div class="admin-card-header">کتابخانه رسانه</div>
    <div class="admin-card-body">
        <div class="row g-3">
            <?php foreach ($images as $img): ?>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="position-relative border rounded overflow-hidden" style="aspect-ratio:1">
                    <?php if (str_starts_with($img['mime'],'image/')): ?>
                    <img src="<?= imgUrl($img['path']) ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= sanitize($img['display_name']) ?>" loading="lazy">
                    <?php else: $icon=str_starts_with($img['mime'],'audio/')?'music-note-beamed':(str_starts_with($img['mime'],'video/')?'camera-video':'file-earmark-text'); ?>
                    <div class="d-flex h-100 align-items-center justify-content-center flex-column" style="background:var(--jhd-paper)"><i class="bi bi-<?= $icon ?> fs-1" aria-hidden="true"></i><small><?= sanitize($img['mime']) ?></small></div>
                    <?php endif; ?>
                    <div class="position-absolute bottom-0 start-0 end-0 d-flex justify-content-between p-1" style="background:rgba(0,0,0,.6)">
                        <a href="<?= imgUrl($img['path']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-xs text-white p-0" style="font-size:.7rem" title="مشاهده"><i class="bi bi-eye"></i></a>
                        <button data-copy-url="<?= sanitize(imgUrl($img['path'])) ?>" class="btn btn-xs text-white p-0" style="font-size:.7rem" title="کپی لینک"><i class="bi bi-link-45deg"></i></button>
                        <form method="post" class="m-0"><input type="hidden" name="delete" value="<?= (int)$img['name'] ?>"><?= csrfField() ?><button type="submit" class="btn btn-xs text-danger p-0" style="font-size:.7rem" data-confirm="حذف این فایل؟" title="حذف"><i class="bi bi-trash"></i></button></form>
                    </div>
                </div>
                <div class="text-muted mt-1" style="font-size:.7rem;overflow:hidden;white-space:nowrap;text-overflow:ellipsis" title="<?= sanitize($img['display_name']) ?>"><?= sanitize($img['display_name']) ?></div>
                <div class="text-muted" style="font-size:.68rem"><?= round($img['size']/1024) ?> KB</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="text-center py-5 text-muted">
    <i class="bi bi-images display-4 d-block mb-3 opacity-25"></i>
    <p>فایلی آپلود نشده است.</p>
</div>
<?php endif; ?>

<div class="my-4"><?= paginate($total,$limit,$page,siteUrl('admin/media/').'?page=%d') ?></div>
<script>
document.querySelectorAll('[data-copy-url]').forEach(function(button){button.addEventListener('click',function(){copyToClipboard(button.dataset.copyUrl);});});
function copyToClipboard(text) {
    if (!navigator.clipboard) { prompt('لینک فایل:',text); return; }
    navigator.clipboard.writeText(text).then(function() {
        alert('لینک کپی شد:\n' + text);
    }).catch(function() {
        prompt('لینک تصویر:', text);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
