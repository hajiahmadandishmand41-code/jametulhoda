<?php
/** Create a real library record before accepting files, so every file has a stable folder. */
$adminTitle = 'کتاب جدید';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/media.php';
require_once __DIR__ . '/../../includes/content-delete.php';

$error = '';
$values = ['title'=>'','description'=>'','author'=>'','translator'=>'','publisher'=>'','publish_year'=>'','pages'=>'','toc'=>'','sort_order'=>'0','status'=>'published'];
$selectedTopics = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    beginContentUploadScope();
    $values = array_merge($values, [
        'title'=>trim((string)($_POST['title'] ?? '')),
        'description'=>trim((string)($_POST['description'] ?? '')),
        'author'=>trim((string)($_POST['author'] ?? '')),
        'translator'=>trim((string)($_POST['translator'] ?? '')),
        'publisher'=>trim((string)($_POST['publisher'] ?? '')),
        'publish_year'=>trim((string)($_POST['publish_year'] ?? '')),
        'pages'=>max(0, (int)($_POST['pages'] ?? 0)),
        'toc'=>trim((string)($_POST['toc'] ?? '')),
        'sort_order'=>(int)($_POST['sort_order'] ?? 0),
        'status'=>in_array($_POST['status'] ?? '', ['published','draft'], true) ? $_POST['status'] : 'draft',
    ]);
    $selectedTopics = validatedTopicIds((array)($_POST['topic_ids'] ?? []));
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی. صفحه را تازه‌سازی و دوباره تلاش کنید.';
    } elseif ($values['title'] === '') {
        $error = 'عنوان کتاب الزامی است.';
    } else {
        $db = getDB(); $bookId = 0;
        try {
            $slug = uniqueSlug('books', $values['title']);
            // The record is deliberately created before files; random file keys then
            // live at uploads/books/book-{id}/ and are independent of mutable titles.
            $insert = $db->prepare("INSERT INTO books (title,slug,description,author,translator,publisher,publish_year,pages,toc,is_featured,sort_order,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) RETURNING id");
            $insert->execute([$values['title'],$slug,$values['description'] ?: null,$values['author'] ?: null,$values['translator'] ?: null,$values['publisher'] ?: null,$values['publish_year'] ?: null,$values['pages'] ?: null,$values['toc'] ?: null,!empty($_POST['is_featured']) ? 1 : 0,$values['sort_order'],$values['status']]);
            $bookId = (int)$insert->fetchColumn();
            $insert->closeCursor();
            if ($bookId < 1) throw new RuntimeException('شناسه کتاب ایجاد نشد.');

            $cover = ''; $pdf = ''; $word = '';
            $directCover = jhdDirectUploadPath('cover_image');
            if ($directCover !== '') {
                $cover = adoptDirectUpload($directCover, 'image', contentStorageFolder('book', $bookId));
                if (!$cover) throw new RuntimeException('تصویر جلد مستقیم معتبر نیست یا ثبت نشد.' . storageFailureHint());
            } elseif (!empty($_FILES['cover_image']['name'])) {
                $cover = uploadContentImage($_FILES['cover_image'], 'book', $bookId);
                if (!$cover) throw new RuntimeException('تصویر جلد معتبر نیست یا آپلود نشد.' . storageFailureHint());
            }
            $directPdf = jhdDirectUploadPath('pdf_file');
            if ($directPdf !== '') {
                $pdf = adoptDirectUpload($directPdf, 'pdf', contentStorageFolder('book', $bookId));
                if (!$pdf) throw new RuntimeException('فایل PDF مستقیم معتبر نیست یا ثبت نشد.' . storageFailureHint());
            } elseif (!empty($_FILES['pdf_file']['name'])) {
                $pdf = uploadContentDocument($_FILES['pdf_file'], 'book', $bookId, 'pdf');
                if (!$pdf) throw new RuntimeException('فایل PDF معتبر نیست یا آپلود نشد.' . storageFailureHint());
            }
            $directWord = jhdDirectUploadPath('word_file');
            if ($directWord !== '') {
                $word = adoptDirectUpload($directWord, 'word', contentStorageFolder('book', $bookId));
                if (!$word) throw new RuntimeException('فایل Word مستقیم معتبر نیست یا ثبت نشد.' . storageFailureHint());
            } elseif (!empty($_FILES['word_file']['name'])) {
                $word = uploadContentDocument($_FILES['word_file'], 'book', $bookId, 'word');
                if (!$word) throw new RuntimeException('فایل Word معتبر نیست یا آپلود نشد.' . storageFailureHint());
            }
            $db->prepare('UPDATE books SET cover_image=?,pdf_file=?,word_file=? WHERE id=?')->execute([$cover ?: null,$pdf ?: null,$word ?: null,$bookId]);
            setBookTopics($bookId, $selectedTopics);
            requireMediaUploads(handleMediaUploads('book', $bookId, $_FILES['attachment_files'] ?? [], 'document'));

            $_SESSION['flash_msg'] = 'کتاب و پوشهٔ اختصاصی آن با موفقیت ایجاد شد.';
            $_SESSION['flash_type'] = 'success';
            redirect(siteUrl('admin/books/edit?id=' . $bookId));
        } catch (Throwable $e) {
            error_log('book create failed: ' . get_class($e));
            if ($bookId > 0) { try { deleteContentRecord('books', $bookId); } catch (Throwable) {} }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'ذخیره کتاب با خطا مواجه شد.';
        }
    }
}
$allTopics = getTopics();
?>
<div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h5 mb-0"><i class="bi bi-book ms-2"></i>کتاب جدید</h1><a href="<?= siteUrl('admin/books/') ?>" class="btn btn-outline-secondary btn-sm">بازگشت</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="admin-form">
<?= csrfField() ?>
<div class="row g-4"><div class="col-lg-8">
  <section class="admin-card mb-4"><div class="admin-card-header">شناسه و اطلاعات کتاب</div><div class="admin-card-body">
    <div class="mb-3"><label class="form-label">عنوان <span class="text-danger">*</span></label><input class="form-control" name="title" required value="<?= sanitize((string)$values['title']) ?>"></div>
    <div class="mb-3"><label class="form-label">توضیح / معرفی</label><textarea class="form-control" rows="5" name="description"><?= sanitize((string)$values['description']) ?></textarea></div>
    <div class="row g-3"><div class="col-md-6"><label class="form-label">نویسنده</label><input class="form-control" name="author" value="<?= sanitize((string)$values['author']) ?>"></div><div class="col-md-6"><label class="form-label">مترجم</label><input class="form-control" name="translator" value="<?= sanitize((string)$values['translator']) ?>"></div><div class="col-md-5"><label class="form-label">ناشر</label><input class="form-control" name="publisher" value="<?= sanitize((string)$values['publisher']) ?>"></div><div class="col-md-3"><label class="form-label">سال نشر</label><input class="form-control" name="publish_year" value="<?= sanitize((string)$values['publish_year']) ?>"></div><div class="col-md-2"><label class="form-label">صفحات</label><input class="form-control" type="number" min="0" name="pages" value="<?= (int)$values['pages'] ?>"></div><div class="col-md-2"><label class="form-label">ترتیب</label><input class="form-control" type="number" name="sort_order" value="<?= (int)$values['sort_order'] ?>"></div></div>
    <div class="mt-3"><label class="form-label">فهرست مطالب</label><textarea class="form-control" rows="4" name="toc"><?= sanitize((string)$values['toc']) ?></textarea></div>
  </div></section>
  <section class="admin-card mb-4"><div class="admin-card-header">موضوعات</div><div class="admin-card-body"><input type="search" class="form-control form-control-sm mb-2" data-topic-filter data-topic-target="bookTopicList" placeholder="جستجو در همهٔ موضوعات"><div id="bookTopicList" class="jhd-topic-checklist" style="max-height:280px;overflow:auto">
  <?php if (!$allTopics): ?><p class="text-muted mb-0">ابتدا موضوعی بسازید.</p><?php else: foreach ($allTopics as $topic): ?><label class="form-check <?= $topic['parent_id'] ? 'ms-3' : '' ?>"><input class="form-check-input" type="checkbox" name="topic_ids[]" value="<?= (int)$topic['id'] ?>" <?= in_array((int)$topic['id'], $selectedTopics, true) ? 'checked' : '' ?>><span class="form-check-label"><?= sanitize($topic['name']) ?></span></label><?php endforeach; endif; ?>
  </div></div></section>
  <section class="admin-card"><div class="admin-card-header">فایل‌ها</div><div class="admin-card-body">
    <div class="row g-3"><div class="col-md-4"><label class="form-label">جلد</label><input type="file" class="form-control" name="cover_image" accept="image/jpeg,image/png,image/gif,image/webp"></div><div class="col-md-4"><label class="form-label">PDF</label><input type="file" class="form-control" name="pdf_file" accept="application/pdf,.pdf"></div><div class="col-md-4"><label class="form-label">Word</label><input type="file" class="form-control" name="word_file" accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></div></div>
    <div class="mt-3"><label class="form-label">فایل‌های تکمیلی</label><input type="file" class="form-control" name="attachment_files[]" multiple accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"><div class="form-text">PDF/DOC/DOCX؛ همه در پوشهٔ این کتاب ذخیره می‌شوند.</div></div>
  </div></section>
</div><aside class="col-lg-4"><section class="admin-card"><div class="admin-card-header">انتشار</div><div class="admin-card-body"><label class="form-label">وضعیت</label><select class="form-select mb-3" name="status"><option value="draft" <?= $values['status']==='draft'?'selected':'' ?>>پیش‌نویس</option><option value="published" <?= $values['status']==='published'?'selected':'' ?>>منتشرشده</option></select><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_featured" value="1"><span class="form-check-label">نمایش ویژه</span></label><button class="btn btn-success w-100" type="submit">ایجاد کتاب و پوشهٔ اختصاصی</button></div></section></aside></div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
