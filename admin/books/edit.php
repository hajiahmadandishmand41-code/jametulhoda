<?php
/** Edit a book and all files stored beneath uploads/books/book-{id}/. */
$adminTitle = 'ویرایش کتاب';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/media.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare('SELECT * FROM books WHERE id=? LIMIT 1'); $stmt->execute([$id]);
$book = $stmt->fetch();
if (!$book) { $_SESSION['flash_msg']='کتاب یافت نشد.'; $_SESSION['flash_type']='danger'; redirect(siteUrl('admin/books/')); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    beginContentUploadScope();
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی.';
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') $error = 'عنوان کتاب الزامی است.';
        if ($error === '') {
            try {
                $cover = (string)($book['cover_image'] ?? '');
                $pdf = (string)($book['pdf_file'] ?? '');
                $word = (string)($book['word_file'] ?? '');
                if (!empty($_POST['remove_cover']) && $cover) { scheduleFileDeletion($cover); $cover=''; }
                if (!empty($_POST['remove_pdf']) && $pdf) { scheduleFileDeletion($pdf); $pdf=''; }
                if (!empty($_POST['remove_word']) && $word) { scheduleFileDeletion($word); $word=''; }
                $directCover=jhdDirectUploadPath('cover_image');
                if ($directCover!=='') { $new=adoptDirectUpload($directCover,'image',contentStorageFolder('book',$id)); if (!$new) throw new RuntimeException('تصویر جلد مستقیم معتبر نیست.'); if ($cover) scheduleFileDeletion($cover); $cover=$new; }
                elseif (!empty($_FILES['cover_image']['name'])) { $new=uploadContentImage($_FILES['cover_image'],'book',$id); if (!$new) throw new RuntimeException('تصویر جلد معتبر نیست.'); if ($cover) scheduleFileDeletion($cover); $cover=$new; }
                $directPdf=jhdDirectUploadPath('pdf_file');
                if ($directPdf!=='') { $new=adoptDirectUpload($directPdf,'pdf',contentStorageFolder('book',$id)); if (!$new) throw new RuntimeException('فایل PDF مستقیم معتبر نیست.'); if ($pdf) scheduleFileDeletion($pdf); $pdf=$new; }
                elseif (!empty($_FILES['pdf_file']['name'])) { $new=uploadContentDocument($_FILES['pdf_file'],'book',$id,'pdf'); if (!$new) throw new RuntimeException('فایل PDF معتبر نیست.'); if ($pdf) scheduleFileDeletion($pdf); $pdf=$new; }
                $directWord=jhdDirectUploadPath('word_file');
                if ($directWord!=='') { $new=adoptDirectUpload($directWord,'word',contentStorageFolder('book',$id)); if (!$new) throw new RuntimeException('فایل Word مستقیم معتبر نیست.'); if ($word) scheduleFileDeletion($word); $word=$new; }
                elseif (!empty($_FILES['word_file']['name'])) { $new=uploadContentDocument($_FILES['word_file'],'book',$id,'word'); if (!$new) throw new RuntimeException('فایل Word معتبر نیست.'); if ($word) scheduleFileDeletion($word); $word=$new; }
                requireMediaUploads(handleMediaUploads('book', $id, $_FILES['attachment_files'] ?? [], 'document'));
                $status = in_array($_POST['status'] ?? '', ['published','draft'], true) ? $_POST['status'] : 'draft';
                $pages = max(0, (int)($_POST['pages'] ?? 0));
                $slug = uniqueSlug('books', $title, $id);
                $db->prepare('UPDATE books SET title=?,slug=?,description=?,author=?,translator=?,publisher=?,publish_year=?,pages=?,toc=?,cover_image=?,pdf_file=?,word_file=?,is_featured=?,sort_order=?,status=?,updated_at=NOW() WHERE id=?')->execute([
                    $title,$slug,trim((string)($_POST['description'] ?? '')) ?: null,trim((string)($_POST['author'] ?? '')) ?: null,trim((string)($_POST['translator'] ?? '')) ?: null,trim((string)($_POST['publisher'] ?? '')) ?: null,trim((string)($_POST['publish_year'] ?? '')) ?: null,$pages ?: null,trim((string)($_POST['toc'] ?? '')) ?: null,$cover ?: null,$pdf ?: null,$word ?: null,!empty($_POST['is_featured']) ? 1 : 0,(int)($_POST['sort_order'] ?? 0),$status,$id
                ]);
                setBookTopics($id, validatedTopicIds((array)($_POST['topic_ids'] ?? [])));
                foreach ((array)($_POST['delete_attachment'] ?? []) as $mediaId) deleteMediaFile((int)$mediaId,'book',$id);
                $stmt->execute([$id]); $book=$stmt->fetch();
                $_SESSION['flash_msg']='کتاب و فایل‌های وابسته با موفقیت به‌روزرسانی شدند.'; $_SESSION['flash_type']='success';
                redirect(siteUrl('admin/books/edit?id='.$id));
            } catch (Throwable $e) { error_log('book edit failed: '.get_class($e)); $error=$e instanceof RuntimeException ? $e->getMessage() : 'ذخیره تغییرات با خطا مواجه شد.'; }
        }
    }
}
$allTopics = getTopics(); $topicIds = getTopicIdsForBook($id); $attachments = getMediaFor('book',$id,'document');
?>
<div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h5 mb-0">ویرایش کتاب: <?= sanitize(mb_strimwidth($book['title'],0,45,'…')) ?></h1><div class="d-flex gap-2"><a class="btn btn-outline-success btn-sm" target="_blank" rel="noopener noreferrer" href="<?= bookUrl($book) ?>">مشاهده</a><a class="btn btn-outline-secondary btn-sm" href="<?= siteUrl('admin/books/') ?>">بازگشت</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="admin-form"><?= csrfField() ?><div class="row g-4"><div class="col-lg-8">
<section class="admin-card mb-4"><div class="admin-card-header">اطلاعات کتاب</div><div class="admin-card-body"><div class="mb-3"><label class="form-label">عنوان <span class="text-danger">*</span></label><input class="form-control" required name="title" value="<?= sanitize($book['title']) ?>"></div><div class="mb-3"><label class="form-label">توضیح / معرفی</label><textarea class="form-control" name="description" rows="5"><?= sanitize((string)$book['description']) ?></textarea></div><div class="row g-3"><div class="col-md-6"><label class="form-label">نویسنده</label><input class="form-control" name="author" value="<?= sanitize((string)$book['author']) ?>"></div><div class="col-md-6"><label class="form-label">مترجم</label><input class="form-control" name="translator" value="<?= sanitize((string)$book['translator']) ?>"></div><div class="col-md-5"><label class="form-label">ناشر</label><input class="form-control" name="publisher" value="<?= sanitize((string)$book['publisher']) ?>"></div><div class="col-md-3"><label class="form-label">سال نشر</label><input class="form-control" name="publish_year" value="<?= sanitize((string)$book['publish_year']) ?>"></div><div class="col-md-2"><label class="form-label">صفحات</label><input class="form-control" type="number" min="0" name="pages" value="<?= (int)$book['pages'] ?>"></div><div class="col-md-2"><label class="form-label">ترتیب</label><input class="form-control" type="number" name="sort_order" value="<?= (int)$book['sort_order'] ?>"></div></div><div class="mt-3"><label class="form-label">فهرست مطالب</label><textarea class="form-control" name="toc" rows="4"><?= sanitize((string)$book['toc']) ?></textarea></div></div></section>
<section class="admin-card mb-4"><div class="admin-card-header">موضوعات</div><div class="admin-card-body"><input type="search" class="form-control form-control-sm mb-2" data-topic-filter data-topic-target="bookTopicList" placeholder="جستجو در همهٔ موضوعات"><div id="bookTopicList" style="max-height:280px;overflow:auto"><?php foreach($allTopics as $topic): ?><label class="form-check <?= $topic['parent_id'] ? 'ms-3' : '' ?>"><input class="form-check-input" type="checkbox" name="topic_ids[]" value="<?= (int)$topic['id'] ?>" <?= in_array((int)$topic['id'],$topicIds,true)?'checked':'' ?>><span class="form-check-label"><?= sanitize($topic['name']) ?></span></label><?php endforeach; ?></div></div></section>
<section class="admin-card"><div class="admin-card-header">فایل‌های کتاب</div><div class="admin-card-body"><div class="row g-3"><div class="col-md-4"><label class="form-label">جلد</label><?php if($book['cover_image']): ?><a class="d-block small mb-2" target="_blank" rel="noopener noreferrer" href="<?= imgUrl($book['cover_image']) ?>">مشاهده جلد فعلی</a><label class="form-check small"><input class="form-check-input" type="checkbox" name="remove_cover" value="1"> حذف جلد</label><?php endif; ?><input class="form-control mt-2" type="file" name="cover_image" accept="image/jpeg,image/png,image/gif,image/webp"></div><div class="col-md-4"><label class="form-label">PDF</label><?php if($book['pdf_file']): ?><a class="d-block small mb-2" target="_blank" rel="noopener noreferrer" href="<?= imgUrl($book['pdf_file']) ?>">فایل فعلی</a><label class="form-check small"><input class="form-check-input" type="checkbox" name="remove_pdf" value="1"> حذف PDF</label><?php endif; ?><input class="form-control mt-2" type="file" name="pdf_file" accept="application/pdf,.pdf"></div><div class="col-md-4"><label class="form-label">Word</label><?php if($book['word_file']): ?><a class="d-block small mb-2" target="_blank" rel="noopener noreferrer" href="<?= imgUrl($book['word_file']) ?>">فایل فعلی</a><label class="form-check small"><input class="form-check-input" type="checkbox" name="remove_word" value="1"> حذف Word</label><?php endif; ?><input class="form-control mt-2" type="file" name="word_file" accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></div></div><hr><label class="form-label">افزودن فایل تکمیلی</label><input class="form-control" multiple type="file" name="attachment_files[]" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"><?php if($attachments): ?><ul class="list-group list-group-flush mt-3"><?php foreach($attachments as $file): ?><li class="list-group-item d-flex justify-content-between gap-2"><a class="small text-truncate" target="_blank" rel="noopener noreferrer" href="<?= imgUrl($file['file_path']) ?>"><?= sanitize($file['title'] ?: basename($file['file_path'])) ?></a><label class="form-check small text-danger"><input class="form-check-input" type="checkbox" name="delete_attachment[]" value="<?= (int)$file['id'] ?>"> حذف</label></li><?php endforeach; ?></ul><?php endif; ?></div></section>
</div><aside class="col-lg-4"><section class="admin-card"><div class="admin-card-header">انتشار</div><div class="admin-card-body"><label class="form-label">وضعیت</label><select class="form-select mb-3" name="status"><option value="draft" <?= $book['status']==='draft'?'selected':'' ?>>پیش‌نویس</option><option value="published" <?= $book['status']==='published'?'selected':'' ?>>منتشرشده</option></select><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_featured" value="1" <?= !empty($book['is_featured'])?'checked':'' ?>><span class="form-check-label">نمایش ویژه</span></label><button type="submit" class="btn btn-success w-100">ذخیره تغییرات</button></div></section></aside></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
