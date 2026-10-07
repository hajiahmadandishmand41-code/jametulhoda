<?php
/**
 * admin/posts/create.php — ایجاد مطلب (گزارش / مقاله / پژوهش / پرسش و پاسخ / خبر)
 * ستون فقرات: موضوعات (post_topics) + نوع مطلب post_type
 */
$adminTitle = 'مطلب جدید';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/media.php';
require_once __DIR__ . '/../../includes/post-gallery.php';
require_once __DIR__ . '/../../includes/content-draft.php';

$error = '';
// شناسهٔ پیش‌نویسِ خودکار (اگر مدیر پیش از ذخیره فایلی آپلود کرده باشد).
$draftId = (int)($_POST['draft_id'] ?? $_GET['draft_id'] ?? 0);
if ($draftId > 0 && !jhd_is_auto_draft($draftId)) $draftId = 0;
$fixedPostType = in_array((string)($_GET['type'] ?? ''), ['report','article','research','qa','announcement','speech','news','program','religious'], true)
    ? (string)$_GET['type'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    beginContentUploadScope();
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی. لطفاً صفحه را رفرش کنید.';
    } else {
        $title       = trim($_POST['title']       ?? '');
        $summary     = trim($_POST['summary']     ?? '');
        $content     = $_POST['content']          ?? '';
        $sources     = trim($_POST['sources'] ?? '');
        $author_name = trim($_POST['author_name'] ?? '');
        $speaker     = trim($_POST['speaker'] ?? '');
        $allowedTypes=['report','article','research','qa','announcement','speech','news','program','religious'];
        $post_type   = $fixedPostType !== '' ? $fixedPostType : (in_array($_POST['post_type'] ?? '', $allowedTypes) ? $_POST['post_type'] : 'article');
        // Untyped editor requests may have been submitted before the type-aware
        // field switch runs. Treat their author value as the speaker once,
        // rather than creating a speech with an empty speaker.
        if ($post_type === 'speech') {
            $speaker = $speaker !== '' ? $speaker : $author_name;
            $author_name = '';
        } else {
            $speaker = '';
        }
        $category_id = (int)($_POST['category_id'] ?? 0);
        $status      = in_array($_POST['status'] ?? '', ['published','draft']) ? $_POST['status'] : 'draft';
        $is_featured = (int)!empty($_POST['is_featured']);
        $pub_date    = !empty($_POST['published_at'])
                       ? date('Y-m-d H:i:s', strtotime($_POST['published_at']))
                       : date('Y-m-d H:i:s');

        $sections = $_POST['page_section'] ?? [];
        if (!is_array($sections)) $sections = [$sections];
        $page_section = implode(',', array_filter(array_map('trim', $sections)));
        if (!$page_section) $page_section = 'other';

        $topicIds = $_POST['topic_ids'] ?? [];
        if(!is_array($topicIds)) $topicIds=[$topicIds];
        $topicIds=array_filter(array_map('intval',$topicIds));

        if (!$title) {
            $error = 'عنوان مطلب الزامی است.';
        } else {
            // Persist first to obtain an immutable ID, then place every file in
            // its own entity folder (articles/article-12, reports/report-12 …).
            // A title/slug can change; an ID-based path never breaks old files.
            $admin2 = currentAdmin();
            $db = getDB();
            ensureFeaturedVideoColumn();
            try {
                // ── ترتیب تضمین‌شدهٔ انتشار ─────────────────────────────────
                // ۱) ثبت محتوا و دریافت شناسهٔ واقعی. اگر مدیر پیش از ذخیره
                //    فایلی آپلود کرده باشد، همان «پیش‌نویس خودکار» به‌روزرسانی
                //    می‌شود تا رسانه‌های پیوست‌شده مالک خود را از دست ندهند و
                //    هیچ ردیف تکراری ساخته نشود.
                $isDraftUpdate = $draftId > 0;
                $slug = uniqueSlug('posts', $title, $isDraftUpdate ? $draftId : 0);
                if ($isDraftUpdate) {
                    $stmt = $db->prepare(
                        "UPDATE posts SET title=?, slug=?, summary=?, content=?, sources=?, author_name=?, speaker=?,
                                post_type=?, page_section=?, category_id=?, author_id=?, status=?, is_featured=?,
                                published_at=?, updated_at=NOW()
                         WHERE id=?"
                    );
                    $stmt->execute([$title, $slug, $summary ?: null, $content ?: null, $sources ?: null, $author_name ?: null, $speaker ?: null, $post_type, $page_section, $category_id ?: null, $admin2['id'], $status, $is_featured, $pub_date, $draftId]);
                    $postId = $draftId;
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO posts (title, slug, summary, content, sources, author_name, speaker, featured_image, featured_video, post_type, page_section, category_id, author_id, status, is_featured, published_at, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) RETURNING id"
                    );
                    $stmt->execute([$title, $slug, $summary ?: null, $content ?: null, $sources ?: null, $author_name ?: null, $speaker ?: null, $post_type, $page_section, $category_id ?: null, $admin2['id'], $status, $is_featured, $pub_date]);
                    $postId = (int)$stmt->fetchColumn();
                }
                $stmt->closeCursor();
                if ($postId < 1) throw new RuntimeException('شناسه مطلب ایجاد نشد.');

                $context = ['post_type' => $post_type];
                $featImg = '';
                $featVid = '';
                $directFeatured = jhdDirectUploadPath('featured_image');
                if ($directFeatured !== '') {
                    $featImg = adoptDirectUpload($directFeatured, 'image', contentStorageFolder($post_type, $postId));
                    if (!$featImg) throw new RuntimeException('تصویر شاخص مستقیم در Storage ثبت نشد.' . storageFailureHint());
                } elseif ((($_FILES['featured_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                    if (($_FILES['featured_image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(jhd_upload_error_message((int)$_FILES['featured_image']['error']));
                    }
                    $featImg = uploadContentImage($_FILES['featured_image'], $post_type, $postId);
                    if (!$featImg) throw new RuntimeException('تصویر شاخص معتبر نیست یا آپلود نشد (فرمت JPG/PNG/GIF/WebP و حداکثر ۲۰ مگابایت).' . storageFailureHint());
                }
                if (!$featImg && !empty($_POST['auto_thumbnail'])) {
                    // Browser thumbnail data is kept for backwards compatibility;
                    // it still ends up under the entity folder after the record exists.
                    $raw = saveBase64Thumbnail($_POST['auto_thumbnail'], contentStorageFolder($post_type, $postId));
                    if ($raw) $featImg = $raw;
                }
                $directFeaturedVideo = jhdDirectUploadPath('featured_video');
                if ($directFeaturedVideo !== '') {
                    $featVid = adoptDirectUpload($directFeaturedVideo, 'video', contentStorageFolder($post_type, $postId));
                    if (!$featVid) throw new RuntimeException('ویدیوی شاخص مستقیم در Storage ثبت نشد.' . storageFailureHint());
                } elseif ((($_FILES['featured_video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                    if (($_FILES['featured_video']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(jhd_upload_error_message((int)$_FILES['featured_video']['error']));
                    }
                    $featVid = uploadContentVideo($_FILES['featured_video'], $post_type, $postId);
                    if (!$featVid) throw new RuntimeException('ویدیو شاخص معتبر نیست یا آپلود نشد (MP4، WebM، MOV، MKV — حداکثر ۲۰۰ مگابایت).' . storageFailureHint());
                }
                // ── ۳) پیوست رسانه با همان شناسه و ۴) تعیین رسانهٔ شاخص ───
                // تصویر شاخص هم یک ردیف واقعی گالری می‌شود تا در مدیریت رسانه
                // قابل حذف/جابه‌جایی/جایگزینی باشد و هیچ فایل بی‌رکوردی نماند.
                if ($featImg !== '') addPostImageUnique($postId, $featImg, $title, true);
                if ($featVid !== '') {
                    syncPrimaryMediaFile('post', $postId, 'video', $featVid, $title);
                    $videoRow = $db->prepare("SELECT id FROM media_files WHERE ref_type='post' AND ref_id=? AND kind='video' AND file_path=? LIMIT 1");
                    $videoRow->execute([$postId, $featVid]);
                    $videoId = (int)$videoRow->fetchColumn();
                    $videoRow->closeCursor();
                    if ($videoId > 0) togglePostFeaturedVideo($postId, $videoId, true);
                }
                $db->prepare('UPDATE posts SET featured_image=?, featured_video=? WHERE id=?')->execute([$featImg ?: null, $featVid ?: null, $postId]);

                $primaryTopicId = (int)($_POST['primary_topic_id'] ?? 0);
                $topicIds = validatedTopicIds($topicIds);
                setPostTopics($postId, $topicIds, $primaryTopicId > 0 ? $primaryTopicId : null);

                $imageErrors = [];
                foreach (jhdDirectUploadPaths('images') as $directPath) {
                    $imgPath = adoptDirectUpload($directPath, 'image', contentStorageFolder($post_type, $postId));
                    if (!$imgPath) { $imageErrors[] = 'یکی از تصاویر مستقیم گالری در Storage ثبت نشد.'; continue; }
                    $imgId = addPostImageUnique($postId, $imgPath, '', !empty($_POST['gallery_featured']));
                    if (!$imgId) { scheduleFileDeletion($imgPath); $imageErrors[] = 'ثبت یکی از تصاویر گالری انجام نشد.'; }
                }
                if (!empty($_FILES['images']['name'][0])) {
                    foreach ($_FILES['images']['name'] as $k => $name) {
                        if ($name === '' || !isset($_FILES['images']['tmp_name'][$k])) continue;
                        if (($_FILES['images']['error'][$k] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                        if (($_FILES['images']['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            $imageErrors[] = jhd_upload_error_message((int)$_FILES['images']['error'][$k]);
                            continue;
                        }
                        $file = ['name'=>$name, 'type'=>$_FILES['images']['type'][$k] ?? '', 'tmp_name'=>$_FILES['images']['tmp_name'][$k] ?? '', 'error'=>$_FILES['images']['error'][$k], 'size'=>$_FILES['images']['size'][$k] ?? 0];
                        $imgPath = uploadContentImage($file, $post_type, $postId);
                        if (!$imgPath) {
                            $imageErrors[] = 'یکی از تصاویر پیوست معتبر نیست (فرمت JPG/PNG/GIF/WebP و حداکثر ۲۰ مگابایت).' . storageFailureHint();
                            continue;
                        }
                        addPostImageUnique($postId, $imgPath, (string)($_POST['image_alts'][$k] ?? ''), !empty($_POST['gallery_featured']));
                    }
                }
                if ($imageErrors) {
                    throw new RuntimeException(implode(' ', array_slice(array_unique($imageErrors), 0, 3)));
                }
                $mediaUploadResults = [
                    handleMediaUploads('post', $postId, $_FILES['audio_files'] ?? [], 'audio', $context),
                    handleMediaUploads('post', $postId, $_FILES['video_files'] ?? [], 'video', $context),
                    handleMediaUploads('post', $postId, $_FILES['document_files'] ?? [], 'document', $context),
                ];
                requireMediaUploads(...$mediaUploadResults);

                // ── ۵) انتشار کامل: اشاره‌گرهای شاخص با رسانه‌های واقعی هم‌گام
                //    می‌شوند، بنابراین همان لحظهٔ انتشار، تصویر/ویدیوی انتخاب‌شده
                //    در کارت‌ها و صفحهٔ مطلب دیده می‌شود.
                syncPostFeaturedPointers($postId);
                jhd_forget_auto_draft($postId);
                jhd_purge_stale_auto_drafts($postId);

                $_SESSION['flash_msg']  = 'مطلب با موفقیت ذخیره شد و همهٔ فایل‌ها در پوشهٔ اختصاصی آن قرار گرفتند.';
                $_SESSION['flash_type'] = 'success';
                $editRoute = match ($post_type) {
                    'article' => 'admin/articles/edit', 'report' => 'admin/reports/edit',
                    'research' => 'admin/research/edit', 'news' => 'admin/news/edit',
                    'speech' => 'admin/speeches/edit', default => 'admin/posts/edit',
                };
                redirect(url($editRoute, ['id' => $postId]));
            } catch (Throwable $e) {
                error_log('post create failed: ' . get_class($e));
                // ردیفی که همین درخواست ساخته است حذف می‌شود تا مطلب ناقص نماند؛
                // اما پیش‌نویسی که رسانه‌های آپلودشده دارد حفظ می‌شود تا کار مدیر
                // از دست نرود (فرم دوباره با همان شناسه ارسال می‌شود).
                if (!empty($postId) && empty($isDraftUpdate)) {
                    try { deleteContentRecord('posts', (int)$postId); } catch (Throwable) { }
                } elseif (!empty($postId)) {
                    $draftId = (int)$postId;
                    jhd_remember_auto_draft($draftId);
                }
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'خطا در ذخیره یا آپلود فایل‌ها.';
            }
        }
    }
}

$categories      = getCategories();
$allTopics       = getTopics();
$topicTree       = getTopicTree();
$selectedSections = is_array($_POST['page_section'] ?? null)
                   ? $_POST['page_section']
                   : explode(',', $_POST['page_section'] ?? 'home,news');
$selectedTopicIds = $_POST['topic_ids'] ?? [];
if(!is_array($selectedTopicIds)) $selectedTopicIds=[$selectedTopicIds];
$formPostType = $fixedPostType !== '' ? $fixedPostType : (string)($_POST['post_type'] ?? 'article');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="bi bi-plus-circle ms-2"></i>مطلب جدید</h5>
    <a href="<?= siteUrl('admin/posts/') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-right ms-1"></i>بازگشت</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle ms-2"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="admin-form" id="createPostForm">
    <?= csrfField() ?>
    <input type="hidden" name="auto_thumbnail" id="autoThumbnailData">
    <!-- شناسهٔ پیش‌نویس واقعی: به‌محض نخستین آپلود پر می‌شود تا رسانه‌ها هرگز
         بدون مالک نمانند و ثبت نهایی همان ردیف را به‌روزرسانی کند. -->
    <input type="hidden" name="draft_id" id="draftPostId" value="<?= (int)$draftId ?>">

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="admin-card mb-4">
                <div class="admin-card-header">محتوای مطلب</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">عنوان مطلب <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?= sanitize($_POST['title'] ?? '') ?>" required placeholder="عنوان مطلب را بنویسید">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">خلاصه / چکیده</label>
                        <textarea name="summary" class="form-control" rows="3" placeholder="خلاصه کوتاه مطلب..."><?= sanitize($_POST['summary'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label fw-bold">متن کامل مطلب</label>
                        <textarea name="content" class="form-control" rows="12" placeholder="متن کامل مطلب را بنویسید..."><?= htmlspecialchars($_POST['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <?php if ($fixedPostType === 'speech'): ?>
                    <div class="mt-3">
                        <label class="form-label">نام سخنران</label>
                        <input type="text" name="speaker" class="form-control" value="<?= sanitize($_POST['speaker'] ?? '') ?>" placeholder="نام استاد / سخنران">
                    </div>
                    <?php elseif ($fixedPostType !== ''): ?>
                    <div class="mt-3">
                        <label class="form-label">نویسنده</label>
                        <input type="text" name="author_name" class="form-control" value="<?= sanitize($_POST['author_name'] ?? '') ?>" placeholder="نام نویسنده">
                    </div>
                    <?php else: ?>
                    <div class="mt-3" id="postAuthorField"<?= $formPostType === 'speech' ? ' hidden' : '' ?>>
                        <label class="form-label">نویسنده</label>
                        <input type="text" name="author_name" class="form-control" value="<?= sanitize($_POST['author_name'] ?? '') ?>" placeholder="نام نویسنده">
                    </div>
                    <div class="mt-3" id="postSpeakerField"<?= $formPostType !== 'speech' ? ' hidden' : '' ?>>
                        <label class="form-label">نام سخنران</label>
                        <input type="text" name="speaker" class="form-control" value="<?= sanitize($_POST['speaker'] ?? '') ?>" placeholder="نام استاد / سخنران">
                    </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <label class="form-label">منابع</label>
                        <textarea name="sources" class="form-control" rows="3" placeholder="منابع..."><?= sanitize($_POST['sources'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="admin-card mb-4">
                <div class="admin-card-header">تصویر شاخص</div>
                <div class="admin-card-body">
                    <input type="file" name="featured_image" id="featuredImageInput" class="form-control" accept="image/*" onchange="previewImg(this,'featPreview')">
                    <div class="form-text mb-2">فرمت‌های مجاز: JPG، PNG، GIF، WebP — حداکثر 20MB</div>
                    <img id="featPreview" src="" style="display:none;max-width:300px;max-height:200px;border-radius:8px;margin-top:8px" alt="">
                    <div id="autoThumbNotice" class="alert alert-info mt-2 py-2 small" style="display:none">
                        <i class="bi bi-magic ms-1"></i> تصویر بندانگشتی به‌صورت خودکار از ویدیو تولید شد.
                    </div>
                </div>
            </div>

            <div class="admin-card mb-4">
                <div class="admin-card-header"><i class="bi bi-camera-video-fill ms-2 text-danger"></i>ویدیو شاخص (اختیاری)</div>
                <div class="admin-card-body">
                    <input type="file" name="featured_video" id="featuredVideoInput" class="form-control" accept="video/*,.mp4,.webm,.mov,.mkv">
                    <div class="form-text mb-2">این ویدیو مستقل از فایل‌های ویدیویی گالری است و در ابتدای مطلب نمایش داده می‌شود. <?= env_value('VERCEL') !== '' ? 'حداکثر ۱۵۰ مگابایت با آپلود مستقیم و resumable در Supabase Storage' : 'حداکثر 200MB' ?> — MP4، WebM، MOV، MKV</div>
                    <video id="featVideoPreview" controls style="display:none;max-width:100%;max-height:240px;border-radius:8px;margin-top:8px;background:#000"></video>
                </div>
            </div>

            <div class="admin-card mb-4">
                <div class="admin-card-header"><i class="bi bi-images ms-2"></i>گالری تصاویر مطلب (اختیاری — چند تصویر)</div>
                <div class="admin-card-body">
                    <div class="jhd-upload-zone" id="galleryDropZone">
                        <input type="file" name="images[]" id="galleryUploadInput" class="visually-hidden" accept="image/*" multiple>
                        <div class="jhd-upload-zone__inner">
                            <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
                            <div>
                                <strong>تصاویر گالری را اینجا رها کنید</strong>
                                <div class="small text-muted">یا برای انتخاب چند فایل همزمان کلیک کنید — JPG، PNG، GIF، WebP تا ۲۰ مگابایت</div>
                            </div>
                            <button type="button" class="btn btn-outline-success btn-sm" id="galleryUploadBtn">انتخاب تصویر</button>
                        </div>
                        <div class="jhd-upload-previews d-flex flex-wrap gap-2 mt-2" id="galleryUploadPreviews"></div>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="gallery_featured" id="galleryFeatured" value="1" <?= !empty($_POST['gallery_featured']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="galleryFeatured">
                            این تصاویر هم جزو <strong>تصاویر شاخص</strong> باشند (نمایش در ابتدای مطلب و در کارت‌ها)
                        </label>
                    </div>
                    <div class="form-text mt-2">می‌توانید چند تصویر شاخص و چند ویدیوی شاخص داشته باشید؛ ترتیب، انتخاب شاخص، توضیح و حذف همه در صفحهٔ ویرایش همین مطلب در دسترس است.</div>
                </div>
            </div>

            <div class="admin-card mb-4">
                <div class="admin-card-header"><i class="bi bi-paperclip ms-2"></i>فایل‌های تکمیلی</div>
                <div class="admin-card-body">
                    <input type="file" name="document_files[]" class="form-control" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple>
                    <div class="form-text">PDF، DOC و DOCX؛ هر فایل به‌صورت امن در پوشهٔ اختصاصی همین محتوا ذخیره می‌شود.</div>
                </div>
            </div>

            <div class="admin-card mb-4">
                <div class="admin-card-header"><i class="bi bi-mic-fill ms-2"></i>فایل‌های صوتی (اختیاری)</div>
                <div class="admin-card-body">
                    <input type="file" name="audio_files[]" class="form-control" accept="audio/*,.mp3,.ogg,.wav,.m4a" multiple>
                    <div class="form-text">MP3، OGG، WAV، M4A — تا 20MB برای هر فایل.</div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header"><i class="bi bi-camera-video-fill ms-2"></i>فایل‌های ویدیویی (اختیاری)</div>
                <div class="admin-card-body">
                    <input type="file" name="video_files[]" id="videoFilesInput" class="form-control" accept="video/*,.mp4,.webm,.mov,.mkv" multiple>
                    <div class="form-text">MP4، WebM، MOV، MKV — <?= env_value('VERCEL') !== '' ? 'تا ۱۵۰ مگابایت با آپلود مستقیم و resumable در Supabase Storage' : 'تا 150MB برای هر فایل' ?>.</div>
                    <div id="videoThumbProgress" class="mt-2" style="display:none">
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <div class="spinner-border spinner-border-sm" role="status"></div>
                            <span>در حال تولید تصویر بندانگشتی از ویدیو...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="admin-card mb-3">
                <div class="admin-card-header">انتشار</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label class="form-label">وضعیت</label>
                        <select name="status" class="form-select">
                            <option value="draft"     <?= ($_POST['status']??'draft')==='draft'?'selected':'' ?>>پیش‌نویس</option>
                            <option value="published" <?= ($_POST['status']??'')==='published'?'selected':'' ?>>منتشرشده</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاریخ انتشار</label>
                        <input type="datetime-local" name="published_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" value="1" <?= !empty($_POST['is_featured'])?'checked':'' ?>>
                        <label class="form-check-label" for="is_featured">ویژه</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success"><i class="bi bi-send ms-1"></i>ذخیره مطلب</button>
                    </div>
                </div>
            </div>

            <div class="admin-card mb-3">
                <div class="admin-card-header">نوع مطلب &amp; موضوعات</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label class="form-label">نوع مطلب <span class="text-danger">*</span></label>
                        <?php if ($fixedPostType): ?>
                        <input type="hidden" name="post_type" value="<?= sanitize($fixedPostType) ?>">
                        <div class="form-control bg-light"><?= sanitize(postTypeLabel($fixedPostType)) ?></div>
                        <?php else: ?>
                        <select name="post_type" id="postTypeSelect" class="form-select">
                            <?php foreach (['report'=>'📋 گزارش','article'=>'📄 مقاله','research'=>'🔬 پژوهش','qa'=>'❓ پرسش و پاسخ','announcement'=>'📢 اطلاعیه','news'=>'📰 خبر','speech'=>'🎤 سخنرانی/بیان','program'=>'📅 برنامه','religious'=>'⭐ فعالیت مذهبی'] as $kind=>$label): ?>
                            <option value="<?= $kind ?>" <?= (($_POST['post_type'] ?? 'article') === $kind) ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <div class="form-text">نوع محتوا، محل نمایش و مسیر پوشهٔ اختصاصی آن را مشخص می‌کند.</div>
                    </div>
                    <div class="mb-2"><label class="form-label fw-bold"><i class="bi bi-diagram-3 ms-1"></i> موضوعات (ستون فقرات) — چند انتخابی</label></div>
                    <input type="search" class="form-control form-control-sm mb-2" data-topic-filter data-topic-target="postTopicList" placeholder="جستجو در همهٔ موضوعات">
                    <div id="postTopicList" style="max-height:240px;overflow:auto;border:1px solid #e8e6dc;border-radius:10px;padding:10px;background:#fafaf7">
                        <?php if(empty($allTopics)): ?><div class="text-muted small">موضوعی وجود ندارد — از <a href="<?= siteUrl('admin/topics/') ?>">مدیریت موضوعات</a> اضافه کنید.</div>
                        <?php else: foreach($allTopics as $t): $indent = $t['parent_id'] ? 'style="padding-inline-start:18px"' : ''; ?>
                        <label class="form-check" <?= $indent ?>>
                            <input type="checkbox" class="form-check-input" name="topic_ids[]" value="<?= $t['id'] ?>" <?= in_array($t['id'], (array)$selectedTopicIds)?'checked':'' ?>>
                            <span class="form-check-label small"><?= sanitize($t['name']) ?></span>
                        </label>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="mt-3">
                        <label class="form-label small fw-bold">موضوع اصلی</label>
                        <select name="primary_topic_id" class="form-select form-select-sm">
                            <option value="">— اولین موضوع انتخاب‌شده —</option>
                            <?php foreach ($allTopics as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= sanitize($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-text mt-2">اتصال به موضوعات باعث نمایش در صفحهٔ موضوع و پیوند داخلی می‌شود.</div>
                    <div class="mt-3">
                        <label class="form-label">دسته‌بندی قدیمی (اختیاری)</label>
                        <select name="category_id" class="form-select">
                            <option value="">— بدون دسته —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($_POST['category_id']??'')==$cat['id']?'selected':'' ?>>
                                <?= sanitize($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header"><i class="bi bi-layout-text-window ms-2"></i>نمایش در کدام بخش‌ها؟</div>
                <div class="admin-card-body">
                    <p class="text-muted small mb-3">تقاطع قدیمی page_section — برای سازگاری باقی مانده.</p>
                    <?php
                    $sectionOpts = [
                        'home'          => '🏠 صفحه اصلی',
                        'news'          => '📰 صفحه اخبار',
                        'articles'      => '📄 مقالات',
                        'announcements' => '📢 اطلاعیه‌ها',
                        'speeches'      => '🎤 سخنرانی‌ها',
                        'programs'      => '📅 برنامه‌ها',
                        'religious'     => '⭐ فعالیت مذهبی',
                        'other'         => '📋 سایر صفحات',
                    ];
                    foreach ($sectionOpts as $val => $label):
                        $checked = in_array($val, $selectedSections) ? 'checked' : '';
                    ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="page_section[]" id="sec_<?= $val ?>" value="<?= $val ?>" <?= $checked ?>>
                        <label class="form-check-label small" for="sec_<?= $val ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<style>
.jhd-upload-zone { border: 2px dashed var(--admin-border); border-radius: 14px; padding: 14px; background: #fbfdfc; transition: border-color .18s, background .18s; }
.jhd-upload-zone.is-dragover { border-color: var(--admin-green); background: #f2faf6; }
.jhd-upload-zone__inner { display: flex; flex-wrap: wrap; gap: .8rem; align-items: center; }
.jhd-upload-zone__inner > i { font-size: 1.7rem; color: var(--admin-green); }
.jhd-upload-previews img { width: 92px; height: 70px; object-fit: cover; border-radius: 8px; border: 1px solid var(--admin-border); }
html[data-theme="dark"] .jhd-upload-zone { background: #101a14; }
</style>

<script>
(function () {
    // پیش‌نمایش و رهاکردن چند تصویر برای گالری
    var dropZone = document.getElementById('galleryDropZone');
    var uploadInput = document.getElementById('galleryUploadInput');
    var uploadBtn = document.getElementById('galleryUploadBtn');
    var previews = document.getElementById('galleryUploadPreviews');
    function renderPreviews() {
        if (!previews || !uploadInput) return;
        previews.innerHTML = '';
        Array.prototype.forEach.call(uploadInput.files, function (file) {
            if (!file.type.match(/^image\//)) return;
            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = file.name; img.title = file.name;
            img.onload = function () { URL.revokeObjectURL(img.src); };
            previews.appendChild(img);
        });
    }
    if (uploadBtn && uploadInput) uploadBtn.addEventListener('click', function (event) { event.preventDefault(); uploadInput.click(); });
    if (uploadInput) uploadInput.addEventListener('change', renderPreviews);
    if (dropZone) {
        dropZone.addEventListener('click', function (event) {
            if (!event.target.closest('button') && !event.target.closest('label') && !event.target.closest('img')) uploadInput.click();
        });
        ['dragenter', 'dragover'].forEach(function (type) {
            dropZone.addEventListener(type, function (event) { event.preventDefault(); dropZone.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            dropZone.addEventListener(type, function (event) { event.preventDefault(); dropZone.classList.remove('is-dragover'); });
        });
        dropZone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files.length) {
                uploadInput.files = event.dataTransfer.files;
                renderPreviews();
            }
        });
    }
})();
</script>

<?php if (!$fixedPostType): ?>
<script>
(function () {
    var type = document.getElementById('postTypeSelect');
    var author = document.getElementById('postAuthorField');
    var speaker = document.getElementById('postSpeakerField');
    if (!type || !author || !speaker) return;
    function toggleIdentityField() {
        var isSpeech = type.value === 'speech';
        author.hidden = isSpeech;
        speaker.hidden = !isSpeech;
        var authorInput = author.querySelector('input[name="author_name"]');
        var speakerInput = speaker.querySelector('input[name="speaker"]');
        if (authorInput) authorInput.disabled = isSpeech;
        if (speakerInput) speakerInput.disabled = !isSpeech;
    }
    type.addEventListener('change', toggleIdentityField);
    toggleIdentityField();
}());
</script>
<?php endif; ?>

<script>
(function() {
    var videoInput     = document.getElementById('videoFilesInput');
    var imageInput     = document.getElementById('featuredImageInput');
    var thumbDataInput = document.getElementById('autoThumbnailData');
    var featPreview    = document.getElementById('featPreview');
    var thumbNotice    = document.getElementById('autoThumbNotice');
    var thumbProgress  = document.getElementById('videoThumbProgress');
    if (!videoInput) return;
    videoInput.addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;
        if (imageInput && imageInput.files.length > 0) return;
        if (thumbDataInput && thumbDataInput.value) return;
        if (thumbProgress) thumbProgress.style.display = 'block';
        if (thumbNotice)   thumbNotice.style.display   = 'none';
        var objectUrl = URL.createObjectURL(file);
        var videoEl   = document.createElement('video');
        videoEl.muted    = true;
        videoEl.preload  = 'metadata';
        videoEl.playsInline = true;
        videoEl.style.display = 'none';
        videoEl.addEventListener('loadedmetadata', function() {
            var seekTime = Math.min(1, videoEl.duration * 0.1);
            videoEl.currentTime = seekTime;
        });
        videoEl.addEventListener('seeked', function() {
            var w = videoEl.videoWidth  || 640;
            var h = videoEl.videoHeight || 360;
            var maxW = 1280;
            if (w > maxW) { h = Math.round(h * maxW / w); w = maxW; }
            var canvas = document.createElement('canvas');
            canvas.width  = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(videoEl, 0, 0, w, h);
            var dataUrl = canvas.toDataURL('image/jpeg', 0.82);
            if (thumbDataInput) thumbDataInput.value = dataUrl;
            if (featPreview) { featPreview.src = dataUrl; featPreview.style.display = 'block'; }
            if (thumbProgress) thumbProgress.style.display = 'none';
            if (thumbNotice)   thumbNotice.style.display   = 'flex';
            URL.revokeObjectURL(objectUrl);
            videoEl.remove();
        });
        videoEl.addEventListener('error', function() {
            if (thumbProgress) thumbProgress.style.display = 'none';
            URL.revokeObjectURL(objectUrl);
            videoEl.remove();
        });
        document.body.appendChild(videoEl);
        videoEl.src = objectUrl;
    });
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                if (thumbDataInput) thumbDataInput.value = '';
                if (thumbNotice)    thumbNotice.style.display = 'none';
            }
        });
    }
    var featVidInput = document.getElementById('featuredVideoInput');
    var featVidPrev  = document.getElementById('featVideoPreview');
    if (featVidInput && featVidPrev) {
        featVidInput.addEventListener('change', function() {
            var f = this.files[0];
            if (!f) { featVidPrev.style.display = 'none'; featVidPrev.removeAttribute('src'); return; }
            var u = URL.createObjectURL(f);
            featVidPrev.src = u;
            featVidPrev.style.display = 'block';
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
