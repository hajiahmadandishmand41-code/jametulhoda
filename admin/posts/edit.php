<?php
/**
 * admin/posts/edit.php — ویرایش مطلب + مدیریت حرفه‌ای گالری تصاویر و رسانه
 *
 * گالری تصاویر و رسانه‌ها از طریق نقاط پایانی JSON همین پنل مدیریت می‌شوند:
 *   admin/posts/gallery.php      → reorder / set_primary / delete / alt / replace
 *   admin/posts/media-manage.php → reorder / rename / delete / set_featured / clear_featured
 * همهٔ عملیات‌ها واقعاً در دیتابیس (post_images، media_files، posts) و storage
 * ذخیره می‌شوند؛ هیچ تغییری فقط در مرورگر باقی نمی‌ماند.
 */
$adminTitle = 'ویرایش مطلب';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/media.php';
require_once __DIR__ . '/../../includes/post-gallery.php';

$id   = (int)($_GET['id'] ?? 0);
$post = $id ? getPost($id) : null;
$fixedPostType = in_array((string)($_GET['type'] ?? ''), ['report','article','research','qa','announcement','speech','news','program','religious'], true)
    ? (string)$_GET['type'] : '';
if (!$post || ($fixedPostType !== '' && ($post['post_type'] ?? '') !== $fixedPostType)) {
    $_SESSION['flash_msg']  = 'مطلب یافت نشد.';
    $_SESSION['flash_type'] = 'danger';
    redirect(siteUrl('admin/posts/'));
}

$db         = getDB();
$error      = '';
$categories = getCategories();
$allTopics  = getTopics();
$postImages = getPostImages($id);           // مرتب‌شده بر اساس sort_order
$galleryReady = post_images_sort_column_exists();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    beginContentUploadScope();
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'خطای امنیتی.';
    } else {
        $title       = trim($_POST['title']       ?? '');
        $summary     = trim($_POST['summary']     ?? '');
        $content     = $_POST['content']          ?? '';
        $sources     = trim($_POST['sources'] ?? '');
        $author_name = trim($_POST['author_name'] ?? '');
        $speaker     = trim($_POST['speaker']     ?? '');
        $post_type   = $fixedPostType !== '' ? $fixedPostType : (in_array($_POST['post_type'] ?? '', ['report','article','research','qa','announcement','speech','news','program','religious'])
                       ? $_POST['post_type'] : 'article');
        // Keep a legacy speech safe even if an old generic form submits no
        // speaker input. A rendered speaker input may still intentionally be
        // cleared by the editor, so only fall back when it was absent.
        if ($post_type === 'speech') {
            if (!array_key_exists('speaker', $_POST)) {
                $speaker = $author_name !== '' ? $author_name : (string)($post['speaker'] ?? '');
            }
            $author_name = '';
        } else {
            $speaker = '';
        }
        // The row ID owns its folder. Changing the editorial type later must
        // not scatter one post's new uploads into a second directory.
        $storageEntity = in_array((string)($post['post_type'] ?? ''), ['report','article','research'], true)
            ? (string)$post['post_type'] : 'post';
        $category_id = (int)($_POST['category_id'] ?? 0);
        $status      = in_array($_POST['status'] ?? '', ['published','draft']) ? $_POST['status'] : 'draft';
        $is_featured = (int)!empty($_POST['is_featured']);
        $pub_date    = !empty($_POST['published_at'])
                       ? date('Y-m-d H:i:s', strtotime($_POST['published_at']))
                       : ($post['published_at'] ?? date('Y-m-d H:i:s'));

        // بخش‌های نمایش
        $sections = $_POST['page_section'] ?? [];
        if (!is_array($sections)) $sections = explode(',', $sections);
        $page_section = implode(',', array_filter(array_map('trim', $sections)));
        if (!$page_section) $page_section = 'other';

        $topicIds = $_POST['topic_ids'] ?? [];
        if(!is_array($topicIds)) $topicIds=[$topicIds];
        $topicIds=array_filter(array_map('intval',$topicIds));

        if (!$title) {
            $error = 'عنوان مطلب الزامی است.';
        } else {
            $slug    = uniqueSlug('posts', $title, $id);
            $featImg = $post['featured_image'];
            $featVid = $post['featured_video'] ?? '';

            // آپلود تصویر شاخص جدید
            $directFeatured = jhdDirectUploadPath('featured_image');
            if ($directFeatured !== '') {
                $up = adoptDirectUpload($directFeatured, 'image', contentStorageFolder($storageEntity, $id));
                if ($up) {
                    if ($featImg) scheduleFileDeletion($featImg);
                    $featImg = $up;
                } else {
                    $error = 'خطا در ثبت تصویر شاخص مستقیم در Storage.' . storageFailureHint();
                }
            } elseif ((($_FILES['featured_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                if (($_FILES['featured_image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $error = jhd_upload_error_message((int)$_FILES['featured_image']['error']);
                } else {
                    $up = uploadContentImage($_FILES['featured_image'], $storageEntity, $id);
                    if ($up) {
                        // حذف تصویر قدیمی
                        if ($featImg) scheduleFileDeletion($featImg);
                        $featImg = $up;
                    } else {
                        $error = 'خطا در آپلود تصویر شاخص.' . storageFailureHint();
                    }
                }
            }

            // حذف تصویر شاخص
            if (!$error && !empty($_POST['remove_featured'])) {
                if ($featImg) scheduleFileDeletion($featImg);
                $featImg = '';
            }

            // تولید خودکار Thumbnail از ویدیو (اگر هیچ تصویر شاخصی وجود ندارد)
            if (!$error && !$featImg && !empty($_POST['auto_thumbnail'])) {
                $thumbPath = saveBase64Thumbnail($_POST['auto_thumbnail'], contentStorageFolder($storageEntity, $id));
                if ($thumbPath) $featImg = $thumbPath;
            }

            // آپلود ویدیو شاخص جدید
            $directFeaturedVideo = jhdDirectUploadPath('featured_video');
            if (!$error && $directFeaturedVideo !== '') {
                $upV = adoptDirectUpload($directFeaturedVideo, 'video', contentStorageFolder($storageEntity, $id));
                if ($upV) {
                    if ($featVid) scheduleFileDeletion($featVid);
                    $featVid = $upV;
                } else {
                    $error = 'خطا در ثبت ویدیوی شاخص مستقیم در Storage.' . storageFailureHint();
                }
            } elseif (!$error && (($_FILES['featured_video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                if (($_FILES['featured_video']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $error = jhd_upload_error_message((int)$_FILES['featured_video']['error']);
                } else {
                    $upV = uploadContentVideo($_FILES['featured_video'], $storageEntity, $id);
                    if ($upV) {
                        if ($featVid) scheduleFileDeletion($featVid);
                        $featVid = $upV;
                    } else {
                        $error = 'خطا در آپلود ویدیو شاخص. فرمت‌های مجاز: MP4، WebM، MOV، MKV (حداکثر 150MB)' . storageFailureHint();
                    }
                }
            }

            // حذف ویدیو شاخص
            if (!$error && !empty($_POST['remove_featured_video'])) {
                if ($featVid) scheduleFileDeletion($featVid);
                $featVid = '';
            }

            // A rejected attachment stops the edit before content metadata,
            // topic relations, and deletion requests can be changed.
            if (!$error) {
                $mediaUploadResults = [
                    handleMediaUploads('post', $id, $_FILES['audio_files'] ?? [], 'audio', ['post_type' => $storageEntity]),
                    handleMediaUploads('post', $id, $_FILES['video_files'] ?? [], 'video', ['post_type' => $storageEntity]),
                    handleMediaUploads('post', $id, $_FILES['document_files'] ?? [], 'document', ['post_type' => $storageEntity]),
                ];
                requireMediaUploads(...$mediaUploadResults);
            }

            if (!$error) {
                ensureFeaturedVideoColumn();
                $stmt = $db->prepare(
                    "UPDATE posts SET title=?, slug=?, summary=?, content=?, sources=?, author_name=?, speaker=?, featured_image=?, featured_video=?, post_type=?, page_section=?,
                     category_id=?, status=?, is_featured=?, published_at=?, updated_at=NOW() WHERE id=?"
                );
                $stmt->execute([
                    $title, $slug, $summary, $content, $sources ?: null, $author_name ?: null, $speaker ?: null, $featImg, $featVid,
                    $post_type, $page_section,
                    $category_id ?: null,
                    $status, $is_featured, $pub_date, $id
                ]);

                syncPrimaryMediaFile('post', $id, 'video', $featVid, $title);

                // همگام‌سازی موضوعات (ستون فقرات)
                $primaryTopicId = (int)($_POST['primary_topic_id'] ?? 0);
                setPostTopics($id, $topicIds, $primaryTopicId > 0 ? $primaryTopicId : null);

                // تصاویر اضافی تازه (با ترتیب واقعی در post_images.sort_order)
                foreach (jhdDirectUploadPaths('images') as $directPath) {
                    $imgPath = adoptDirectUpload($directPath, 'image', contentStorageFolder($storageEntity, $id));
                    if ($imgPath) {
                        addPostImageUnique($id, $imgPath, '', false);
                    } else {
                        throw new RuntimeException('یکی از تصاویر مستقیم گالری در Storage ثبت نشد.' . storageFailureHint());
                    }
                }
                if (!empty($_FILES['images']['name'][0])) {
                    $imageErrors = [];
                    foreach ($_FILES['images']['name'] as $k => $name) {
                        if ($name === '' || !isset($_FILES['images']['tmp_name'][$k])) continue;
                        if (($_FILES['images']['error'][$k] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                        if (($_FILES['images']['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            $imageErrors[] = jhd_upload_error_message((int)$_FILES['images']['error'][$k]);
                            continue;
                        }
                        $file = [
                            'name'     => $name,
                            'type'     => $_FILES['images']['type'][$k] ?? '',
                            'tmp_name' => $_FILES['images']['tmp_name'][$k] ?? '',
                            'error'    => $_FILES['images']['error'][$k],
                            'size'     => $_FILES['images']['size'][$k] ?? 0,
                        ];
                        $imgPath = uploadContentImage($file, $storageEntity, $id);
                        if ($imgPath) {
                            // بدون امکان رکورد تکراری؛ همان مسیر دوبار ثبت نمی‌شود.
                            addPostImageUnique($id, $imgPath, (string)($_POST['image_alts'][$k] ?? ''), false);
                        } else {
                            $imageErrors[] = 'یکی از تصاویر پیوست معتبر نیست (فرمت JPG/PNG/GIF/WebP و حداکثر ۲۰ مگابایت).' . storageFailureHint();
                        }
                    }
                    if ($imageErrors) {
                        throw new RuntimeException(implode(' ', array_slice(array_unique($imageErrors), 0, 3)));
                    }
                }

                // انتخاب‌های رسانهٔ شاخص از همین فرم (چند تصویر و چند ویدیو).
                if (isset($_POST['featured_images'])) {
                    $sel = is_array($_POST['featured_images']) ? $_POST['featured_images'] : [];
                    setPostFeaturedImages($id, $sel);
                }
                if (isset($_POST['featured_videos'])) {
                    $sel = is_array($_POST['featured_videos']) ? $_POST['featured_videos'] : [];
                    setPostFeaturedVideos($id, $sel);
                }
                syncPostFeaturedPointers($id);

                // رفرش داده‌های پست
                $post = getPost($id);
                $postImages = getPostImages($id);

                $_SESSION['flash_msg']  = 'مطلب با موفقیت بروزرسانی شد.';
                $_SESSION['flash_type'] = 'success';
                redirect(siteUrl('admin/posts/edit?id=' . $id));
            }
        }
    }
}

$currentSections = !empty($post['page_section'])
                 ? explode(',', $post['page_section'])
                 : ['home','news'];
$existingAudio = getMediaFor('post', $id, 'audio');
$existingVideo = array_values(array_filter(getMediaFor('post', $id, 'video'), static fn(array $row): bool => (int)($row['sort_order'] ?? 0) !== -1000000));
$existingDocuments = getMediaFor('post', $id, 'document');
// رسانهٔ شاخص چندگانه — همان ردیف‌های بالا، فقط با پرچم is_featured.
$featuredImageIds = array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedImages($id));
$featuredVideoIds = array_map(static fn(array $row): int => (int)$row['id'], getPostFeaturedVideos($id));
$featuredMedia    = getPostFeaturedMedia($id);
$formPostType = $fixedPostType !== '' ? $fixedPostType : (string)($_POST['post_type'] ?? $post['post_type']);
$galleryEndpoint  = adminUrl('content/gallery');
$mediaEndpoint    = adminUrl('content/media');
$csrfToken        = generateCsrfToken();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h5 class="mb-0"><i class="bi bi-pencil-square ms-2"></i>ویرایش: <?= sanitize(mb_strimwidth($post['title'],0,40,'...')) ?></h5>
    <div class="d-flex gap-2">
        <a href="<?= postUrl($post) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success"><i class="bi bi-eye ms-1"></i>مشاهده</a>
        <a href="<?= siteUrl('admin/posts/') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right ms-1"></i>بازگشت</a>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle ms-2"></i><?= sanitize($error) ?></div>
<?php endif; ?>
<?php if (!$galleryReady): ?>
<div class="alert alert-warning"><i class="bi bi-database-exclamation ms-2"></i>
    ستون ترتیب گالری (<code dir="ltr">post_images.sort_order</code>) در این دیتابیس وجود ندارد؛ مرتب‌سازی و انتخاب تصویر اصلی کار نمی‌کند.
    لطفاً یک‌بار <code dir="ltr">php bin/migrate.php</code> (یا نشانی <code dir="ltr">/php/migrate</code> روی میزبان بدون شل) را اجرا کنید.
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="admin-form" id="editPostForm">
    <?= csrfField() ?>
    <!-- فیلد مخفی برای Thumbnail خودکار از ویدیو -->
    <input type="hidden" name="auto_thumbnail" id="autoThumbnailDataEdit">

    <div class="row g-4">
        <!-- ستون اصلی -->
        <div class="col-lg-8">
            <div class="admin-card mb-4">
                <div class="admin-card-header">محتوای مطلب</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">عنوان مطلب <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?= sanitize($post['title']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">خلاصه / چکیده</label>
                        <textarea name="summary" class="form-control" rows="3"><?= sanitize($post['summary'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label fw-bold">متن کامل مطلب</label>
                        <textarea name="content" class="form-control" rows="12"><?= htmlspecialchars($post['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <?php if ($fixedPostType === 'speech'): ?>
                    <div class="mt-3">
                        <label class="form-label">نام سخنران</label>
                        <input type="text" name="speaker" class="form-control" value="<?= sanitize($_POST['speaker'] ?? $post['speaker'] ?? '') ?>" placeholder="نام استاد / سخنران">
                    </div>
                    <?php elseif ($fixedPostType !== ''): ?>
                    <div class="mt-3">
                        <label class="form-label">نویسنده (اختیاری — نمایش در صفحه مقاله)</label>
                        <input type="text" name="author_name" class="form-control" value="<?= sanitize($_POST['author_name'] ?? $post['author_name'] ?? '') ?>" placeholder="نام نویسنده">
                    </div>
                    <?php else: ?>
                    <div class="mt-3" id="postAuthorField"<?= $formPostType === 'speech' ? ' hidden' : '' ?>>
                        <label class="form-label">نویسنده (اختیاری — نمایش در صفحه مقاله)</label>
                        <input type="text" name="author_name" class="form-control" value="<?= sanitize($_POST['author_name'] ?? $post['author_name'] ?? '') ?>" placeholder="نام نویسنده">
                    </div>
                    <div class="mt-3" id="postSpeakerField"<?= $formPostType !== 'speech' ? ' hidden' : '' ?>>
                        <label class="form-label">نام سخنران</label>
                        <input type="text" name="speaker" class="form-control" value="<?= sanitize($_POST['speaker'] ?? $post['speaker'] ?? '') ?>" placeholder="نام استاد / سخنران">
                    </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <label class="form-label">منابع</label>
                        <textarea name="sources" class="form-control" rows="3" placeholder="منابع و مآخذ..." ><?= sanitize($post['sources'] ?? $_POST['sources'] ?? '') ?></textarea>
                        <div class="form-text">منابع به شکل لیست نمایش داده می‌شود.</div>
                    </div>
                </div>
            </div>

            <!-- ═══ تصویر اصلی ═══════════════════════════════════════════ -->
            <div class="admin-card mb-4" id="featuredImageCard">
                <div class="admin-card-header"><i class="bi bi-star-fill ms-2 text-warning"></i>تصویر اصلی مطلب</div>
                <div class="admin-card-body">
                    <div id="featuredImageBox" class="d-flex flex-wrap gap-3 align-items-start">
                        <?php if (!empty($post['featured_image'])): ?>
                        <figure class="jhd-media-figure mb-0">
                            <img src="<?= imgUrl($post['featured_image']) ?>" alt="تصویر اصلی مطلب" id="currentFeatImg" style="max-width:280px;max-height:190px">
                        </figure>
                        <?php else: ?>
                        <div class="jhd-media-empty" id="featuredEmptyState">
                            <i class="bi bi-image" aria-hidden="true"></i>
                            <span>تصویر اصلی انتخاب نشده است</span>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex flex-column gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="pickFromGalleryBtn" <?= empty($postImages) ? 'disabled' : '' ?>>
                                <i class="bi bi-images ms-1"></i>انتخاب از گالری
                            </button>
                            <label class="btn btn-outline-secondary btn-sm mb-0" for="featuredImageInput">
                                <i class="bi bi-upload ms-1"></i>آپلود تصویر تازه
                            </label>
                            <input type="file" name="featured_image" id="featuredImageInput" class="visually-hidden" accept="image/*">
                            <?php if (!empty($post['featured_image'])): ?>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="remove_featured" id="remove_featured" value="1">
                                <label class="form-check-label text-danger small" for="remove_featured">حذف تصویر اصلی</label>
                            </div>
                            <?php endif; ?>
                            <img id="featPreviewEdit" src="" alt="" style="display:none;max-width:240px;max-height:160px;border-radius:10px;margin-top:6px">
                            <div id="autoThumbNoticeEdit" class="alert alert-info mt-1 py-1 px-2 small mb-0" style="display:none">
                                <i class="bi bi-magic ms-1"></i> تصویر بندانگشتی خودکار از ویدیو ساخته شد.
                            </div>
                        </div>
                    </div>
                    <div class="form-text mt-2">تصویر اصلی در بالای صفحهٔ مطلب، کارت مطالب و اشتراک‌گذاری استفاده می‌شود. می‌توانید یکی از تصاویر گالری را به تصویر اصلی ارتقا دهید.</div>

                    <!-- انتخابگر تصویر اصلی از گالری -->
                    <div id="galleryPicker" class="jhd-gallery-picker" style="display:none">
                        <div class="jhd-gallery-picker__head">
                            <strong><i class="bi bi-images ms-1"></i>یکی از تصاویر گالری را به‌عنوان تصویر اصلی انتخاب کنید</strong>
                            <button type="button" class="btn-close" id="galleryPickerClose" aria-label="بستن"></button>
                        </div>
                        <div class="jhd-gallery-picker__grid" id="galleryPickerGrid"></div>
                    </div>
                </div>
            </div>

            <!-- ═══ رسانهٔ شاخص (چند تصویر + چند ویدیو) ═══════════════════ -->
            <div class="admin-card mb-4" id="featuredMediaCard">
                <div class="admin-card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <span><i class="bi bi-stars ms-2"></i>رسانهٔ شاخص این مطلب</span>
                    <span class="small text-muted">
                        <span class="badge bg-success" id="featuredImageCount"><?= count($featuredImageIds) ?></span> تصویر ·
                        <span class="badge bg-success" id="featuredVideoCount"><?= count($featuredVideoIds) ?></span> ویدیو
                    </span>
                </div>
                <div class="admin-card-body">
                    <p class="small text-muted mb-2">
                        رسانهٔ شاخص از رسانهٔ داخل متن جداست: این فایل‌ها در بالای صفحهٔ مطلب و در کارت‌های فهرست دیده می‌شوند.
                        برای افزودن یا برداشتن، دکمهٔ «شاخص کن» را روی هر تصویر گالری یا هر ویدیو بزنید — همان لحظه ذخیره می‌شود.
                    </p>
                    <?php if ($featuredMedia): ?>
                    <div class="d-flex flex-wrap gap-2" id="featuredMediaStrip">
                        <?php foreach ($featuredMedia as $fm): ?>
                        <figure class="jhd-featured-thumb mb-0">
                            <?php if ($fm['type'] === 'image'): ?>
                            <img src="<?= imgUrl((string)$fm['src']) ?>" alt="<?= sanitize((string)$fm['alt']) ?>" loading="lazy">
                            <?php else: ?>
                            <video src="<?= siteUrl((string)$fm['src']) ?>" muted preload="metadata"></video>
                            <?php endif; ?>
                            <figcaption><i class="bi <?= $fm['type'] === 'image' ? 'bi-image' : 'bi-camera-video' ?>"></i> <?= $fm['type'] === 'image' ? 'تصویر' : 'ویدیو' ?></figcaption>
                        </figure>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-light border small mb-0"><i class="bi bi-info-circle ms-1"></i>هنوز رسانهٔ شاخصی انتخاب نشده است؛ نخستین تصویر گالری به‌عنوان تصویر کارت استفاده می‌شود.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══ گالری تصاویر ══════════════════════════════════════════ -->
            <div class="admin-card mb-4">
                <div class="admin-card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <span><i class="bi bi-collection ms-2"></i>گالری تصاویر مطلب <span class="badge bg-secondary ms-1" id="galleryCountBadge"><?= count($postImages) ?></span></span>
                    <span class="text-muted small">ترتیب با کشیدن یا دکمه‌های حرکت تغییر می‌کند</span>
                </div>
                <div class="admin-card-body">
                    <?php if (!$galleryReady): ?>
                    <div class="alert alert-warning py-2 small mb-3">برای فعال‌سازی مرتب‌سازی و انتخاب تصویر اصلی، مهاجرت schema لازم است (دکمه‌ها غیرفعال هستند).</div>
                    <?php endif; ?>

                    <ul class="jhd-gallery-manager list-unstyled mb-3" id="galleryManager">
                        <?php foreach ($postImages as $index => $img): ?>
                        <?php $isFeaturedImg = in_array((int)$img['id'], $featuredImageIds, true); ?>
                        <li class="jhd-gallery-item<?= $isFeaturedImg ? ' is-featured' : '' ?>" draggable="true" data-id="<?= (int)$img['id'] ?>" data-featured="<?= $isFeaturedImg ? '1' : '0' ?>" data-path="<?= sanitize(imgUrl((string)$img['image_path'])) ?>">
                            <span class="jhd-gallery-item__handle" title="جابه‌جایی"><i class="bi bi-grip-vertical"></i></span>
                            <img src="<?= imgUrl((string)$img['image_path']) ?>" alt="<?= sanitize((string)($img['alt_text'] ?? '')) ?>" loading="lazy">
                            <div class="jhd-gallery-item__body">
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <span class="badge bg-light text-dark border">#<?= $index + 1 ?></span>
                                    <span class="small text-muted text-truncate" style="max-width:180px" dir="ltr"><?= sanitize(basename((string)$img['image_path'])) ?></span>
                                    <?php if (!empty($post['featured_image']) && (string)$img['image_path'] === (string)$post['featured_image']): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-star-fill ms-1"></i>تصویر اصلی</span>
                                    <?php endif; ?>
                                    <span class="badge bg-success jhd-featured-flag" <?= $isFeaturedImg ? '' : 'hidden' ?>><i class="bi bi-images ms-1"></i>تصویر شاخص</span>
                                </div>
                                <div class="input-group input-group-sm mt-2">
                                    <span class="input-group-text">توضیح (alt)</span>
                                    <input type="text" class="form-control jhd-gallery-alt" value="<?= sanitize((string)($img['alt_text'] ?? '')) ?>" placeholder="توضیح تصویر برای دسترسی‌پذیری و سئو" maxlength="200">
                                </div>
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2 jhd-gallery-primary" title="انتخاب به‌عنوان تصویر اصلی" <?= $galleryReady ? '' : 'disabled' ?>><i class="bi bi-star"></i> تصویر اصلی</button>
                                    <button type="button" class="btn btn-sm py-0 px-2 jhd-gallery-feature <?= $isFeaturedImg ? 'btn-success' : 'btn-outline-warning' ?>" title="افزودن/برداشتن از تصاویر شاخص"><i class="bi bi-images"></i> <span class="jhd-feature-label"><?= $isFeaturedImg ? 'شاخص ✓' : 'شاخص کن' ?></span></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-gallery-move" data-dir="up" title="انتقال به بالا" aria-label="انتقال به بالا"><i class="bi bi-arrow-up"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-gallery-move" data-dir="down" title="انتقال به پایین" aria-label="انتقال به پایین"><i class="bi bi-arrow-down"></i></button>
                                    <label class="btn btn-sm btn-outline-primary py-0 px-2 mb-0" title="جایگزینی تصویر">
                                        <i class="bi bi-arrow-repeat"></i> جایگزینی
                                        <input type="file" class="visually-hidden jhd-gallery-replace" accept="image/*">
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 jhd-gallery-delete" title="حذف تصویر"><i class="bi bi-trash"></i> حذف</button>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="jhd-gallery-empty alert alert-light border mb-3" id="galleryEmptyState" <?= empty($postImages) ? '' : 'style="display:none"' ?>>
                        <i class="bi bi-images ms-1"></i> هنوز تصویری به گالری این مطلب اضافه نشده است.
                    </div>

                    <div class="jhd-upload-zone" id="galleryDropZone">
                        <input type="file" name="images[]" id="galleryUploadInput" class="visually-hidden" accept="image/*" multiple>
                        <div class="jhd-upload-zone__inner">
                            <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
                            <div>
                                <strong>تصاویر را اینجا رها کنید</strong>
                                <div class="small text-muted">یا برای انتخاب چند فایل کلیک کنید — JPG، PNG، GIF، WebP تا ۲۰ مگابایت</div>
                            </div>
                            <button type="button" class="btn btn-outline-success btn-sm" id="galleryUploadBtn">انتخاب تصویر</button>
                        </div>
                        <div class="jhd-upload-previews d-flex flex-wrap gap-2 mt-2" id="galleryUploadPreviews"></div>
                    </div>
                    <div class="form-text mt-2">تصاویر بلافاصله پس از انتخاب/رها کردن، با شناسهٔ واقعی مطلب در دیتابیس ثبت می‌شوند و در گالری همین صفحه و صفحهٔ عمومی مطلب نمایش داده می‌شوند؛ «ذخیره تغییرات» نیازی نیست.</div>
                </div>
            </div>

            <!-- ═══ ویدیو شاخص ════════════════════════════════════════════ -->
            <div class="admin-card mb-4">
                <div class="admin-card-header"><i class="bi bi-camera-video-fill ms-2 text-danger"></i>ویدیو شاخص (اختیاری)</div>
                <div class="admin-card-body">
                    <?php if (!empty($post['featured_video'])): ?>
                    <div class="mb-3">
                        <video src="<?= siteUrl($post['featured_video']) ?>" controls style="max-width:100%;max-height:240px;border-radius:8px;background:#000"></video>
                        <div class="mt-2 small text-muted">فایل فعلی: <code dir="ltr"><?= sanitize(basename($post['featured_video'])) ?></code></div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" id="clearFeaturedVideoBtn"><i class="bi bi-x-circle ms-1"></i>حذف ویدیو شاخص</button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-light border small"><i class="bi bi-info-circle ms-1"></i>ویدیو شاخصی انتخاب نشده است. اگر در بخش «ویدیوهای مطلب» فایلی داشته باشید، می‌توانید یکی را به‌عنوان ویدیوی شاخص انتخاب کنید.</div>
                    <?php endif; ?>
                    <label class="form-label small" for="featuredVideoInputEdit">آپلود ویدیوی تازه</label>
                    <input type="file" name="featured_video" id="featuredVideoInputEdit" class="form-control" accept="video/*,.mp4,.webm,.mov,.mkv">
                    <div class="form-text mb-2">این ویدیو در ابتدای صفحهٔ مطلب نمایش داده می‌شود. حداکثر <?= (int)(MAX_VIDEO_SIZE / 1024 / 1024) ?>MB با آپلود مستقیم و resumable در Supabase Storage — MP4، WebM، MOV، MKV</div>
                    <video id="featVideoPreviewEdit" controls style="display:none;max-width:100%;max-height:240px;border-radius:8px;margin-top:8px;background:#000"></video>
                </div>
            </div>

            <!-- ═══ فایل‌های صوتی ══════════════════════════════════════════ -->
            <div class="admin-card mb-4">
                <div class="admin-card-header"><i class="bi bi-mic-fill ms-2"></i>فایل‌های صوتی <span class="badge bg-secondary ms-1"><?= count($existingAudio) ?></span></div>
                <div class="admin-card-body">
                    <ul class="jhd-media-manager list-unstyled mb-3" id="audioManager" data-kind="audio">
                        <?php foreach ($existingAudio as $a): ?>
                        <li class="jhd-media-item" data-id="<?= (int)$a['id'] ?>">
                            <span class="jhd-media-item__icon"><i class="bi bi-music-note-beamed"></i></span>
                            <div class="flex-grow-1">
                                <input type="text" class="form-control form-control-sm jhd-media-title" value="<?= sanitize((string)($a['title'] ?? '')) ?>" placeholder="عنوان فایل صوتی">
                                <audio controls preload="none" src="<?= siteUrl($a['file_path']) ?>" class="w-100 mt-1" style="height:32px"></audio>
                            </div>
                            <div class="d-flex flex-column gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-media-move" data-dir="up" title="انتقال به بالا"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-media-move" data-dir="down" title="انتقال به پایین"><i class="bi bi-arrow-down"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 jhd-media-delete" title="حذف فایل"><i class="bi bi-trash"></i></button>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="file" name="audio_files[]" class="form-control" accept="audio/*,.mp3,.ogg,.wav,.m4a" multiple>
                    <div class="form-text">افزودن فایل‌های صوتی جدید — MP3، OGG، WAV، M4A (تا 20MB هرکدام).</div>
                </div>
            </div>

            <div class="admin-card mb-4">
                <div class="admin-card-header"><i class="bi bi-paperclip ms-2"></i>فایل‌های تکمیلی <span class="badge bg-secondary ms-1"><?= count($existingDocuments) ?></span></div>
                <div class="admin-card-body">
                    <ul class="jhd-media-manager list-unstyled mb-3" id="documentManager" data-kind="document">
                        <?php foreach ($existingDocuments as $doc): ?>
                        <li class="jhd-media-item" data-id="<?= (int)$doc['id'] ?>">
                            <span class="jhd-media-item__icon"><i class="bi bi-file-earmark-text"></i></span>
                            <div class="flex-grow-1">
                                <input type="text" class="form-control form-control-sm jhd-media-title" value="<?= sanitize((string)($doc['title'] ?? '')) ?>" placeholder="عنوان فایل">
                                <a class="small text-truncate d-block" href="<?= imgUrl((string)$doc['file_path']) ?>" target="_blank" rel="noopener" dir="ltr"><?= sanitize(basename((string)$doc['file_path'])) ?></a>
                            </div>
                            <div class="d-flex flex-column gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-media-move" data-dir="up" title="انتقال به بالا"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-media-move" data-dir="down" title="انتقال به پایین"><i class="bi bi-arrow-down"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 jhd-media-delete" title="حذف فایل"><i class="bi bi-trash"></i></button>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="file" name="document_files[]" class="form-control" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple>
                    <div class="form-text">افزودن PDF، DOC یا DOCX به پوشهٔ اختصاصی محتوا.</div>
                </div>
            </div>

            <!-- ═══ ویدیوهای مطلب ══════════════════════════════════════════ -->
            <div class="admin-card">
                <div class="admin-card-header"><i class="bi bi-camera-video ms-2"></i>ویدیوهای مطلب <span class="badge bg-secondary ms-1" id="videoCountBadge"><?= count($existingVideo) ?></span></div>
                <div class="admin-card-body">
                    <ul class="jhd-media-manager list-unstyled mb-3" id="videoManager" data-kind="video">
                        <?php foreach ($existingVideo as $v): ?>
                        <?php $isFeaturedVid = in_array((int)$v['id'], $featuredVideoIds, true); ?>
                        <li class="jhd-media-item<?= $isFeaturedVid ? ' is-featured' : '' ?>" data-id="<?= (int)$v['id'] ?>" data-featured="<?= $isFeaturedVid ? '1' : '0' ?>">
                            <span class="jhd-media-item__icon"><i class="bi bi-camera-video"></i></span>
                            <div class="flex-grow-1">
                                <input type="text" class="form-control form-control-sm jhd-media-title" value="<?= sanitize((string)($v['title'] ?? '')) ?>" placeholder="عنوان ویدیو">
                                <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
                                    <video controls preload="none" src="<?= siteUrl($v['file_path']) ?>" style="max-width:220px;max-height:130px;border-radius:8px;background:#000"></video>
                                    <div class="d-flex flex-column gap-1">
                                        <button type="button" class="btn btn-sm py-0 px-2 jhd-video-feature <?= $isFeaturedVid ? 'btn-success' : 'btn-outline-warning' ?>" title="افزودن/برداشتن از ویدیوهای شاخص"><i class="bi bi-camera-reels"></i> <span class="jhd-feature-label"><?= $isFeaturedVid ? 'شاخص ✓' : 'شاخص کن' ?></span></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-video-featured" title="قرار دادن به‌عنوان نخستین ویدیوی شاخص"><i class="bi bi-star"></i> ویدیوی اصلی</button>
                                        <a href="<?= siteUrl($v['file_path']) ?>" download class="btn btn-sm btn-outline-success py-0 px-2"><i class="bi bi-download"></i> دانلود</a>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex flex-column gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-media-move" data-dir="up" title="انتقال به بالا"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-media-move" data-dir="down" title="انتقال به پایین"><i class="bi bi-arrow-down"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 jhd-media-delete" title="حذف ویدیو"><i class="bi bi-trash"></i></button>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="file" name="video_files[]" id="videoFilesInputEdit" class="form-control" accept="video/*,.mp4,.webm,.mov,.mkv" multiple>
                    <div class="form-text">افزودن ویدیوهای جدید — MP4، WebM، MOV، MKV (تا <?= (int)(MAX_VIDEO_SIZE / 1024 / 1024) ?>MB برای هرکدام با آپلود مستقیم و resumable در Supabase Storage). ویدیوها در صفحهٔ همین مطلب نمایش داده می‌شوند.</div>
                    <div id="videoThumbProgressEdit" class="mt-2" style="display:none">
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <div class="spinner-border spinner-border-sm" role="status"></div>
                            <span>در حال تولید تصویر بندانگشتی از ویدیو...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- ستون جانبی -->
        <div class="col-lg-4">
            <!-- انتشار -->
            <div class="admin-card mb-3">
                <div class="admin-card-header">انتشار</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label class="form-label">وضعیت</label>
                        <select name="status" class="form-select">
                            <option value="draft"     <?= $post['status']==='draft'?'selected':'' ?>>پیش‌نویس</option>
                            <option value="published" <?= $post['status']==='published'?'selected':'' ?>>منتشرشده</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاریخ انتشار</label>
                        <input type="datetime-local" name="published_at" class="form-control"
                               value="<?= date('Y-m-d\TH:i', strtotime($post['published_at'] ?? 'now')) ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured_e" value="1"
                               <?= $post['is_featured'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_featured_e">برگزیدهٔ سردبیر</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success"><i class="bi bi-save ms-1"></i>ذخیره تغییرات</button>
                        <a href="<?= siteUrl('admin/posts/delete?id=' . $id) ?>"
                           class="btn btn-outline-danger"
                           data-confirm="آیا از حذف این مطلب اطمینان دارید؟">
                            <i class="bi bi-trash ms-1"></i>حذف مطلب
                        </a>
                    </div>
                </div>
            </div>

            <!-- نوع و دسته -->
            <div class="admin-card mb-3">
                <div class="admin-card-header">نوع و دسته‌بندی</div>
                <div class="admin-card-body">
                    <div class="mb-3">
                        <label class="form-label">نوع مطلب</label>
                        <?php if ($fixedPostType): ?>
                        <input type="hidden" name="post_type" value="<?= sanitize($fixedPostType) ?>">
                        <div class="form-control bg-light"><?= sanitize(postTypeLabel($fixedPostType)) ?></div>
                        <?php else: ?>
                        <select name="post_type" id="postTypeSelect" class="form-select">
                            <?php foreach (['report'=>'📋 گزارش','article'=>'📄 مقاله','research'=>'🔬 پژوهش','qa'=>'❓ پرسش و پاسخ','announcement'=>'📢 اطلاعیه','news'=>'📰 خبر','speech'=>'🎤 سخنرانی','program'=>'📅 برنامه','religious'=>'⭐ فعالیت مذهبی'] as $kind=>$label): ?>
                            <option value="<?= $kind ?>" <?= $formPostType === $kind ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <?php $curTopicIds = getTopicIdsForPost((int)$post['id']); ?>
                    <div class="mb-3"><label class="form-label fw-bold"><i class="bi bi-diagram-3 ms-1"></i> موضوعات (ستون فقرات)</label>
                    <input type="search" class="form-control form-control-sm mb-2" data-topic-filter data-topic-target="postTopicList" placeholder="جستجو در همهٔ موضوعات">
                    <div id="postTopicList" style="max-height:220px;overflow:auto;border:1px solid #e8e6dc;border-radius:10px;padding:10px;background:#fafaf7">
                        <?php if(empty($allTopics)): ?><div class="text-muted small">موضوعی نیست.</div><?php else: foreach($allTopics as $t): ?>
                        <label class="form-check" <?= $t['parent_id'] ? 'style="padding-inline-start:14px"' : '' ?>>
                            <input type="checkbox" class="form-check-input" name="topic_ids[]" value="<?= $t['id'] ?>" <?= in_array($t['id'], $curTopicIds)?'checked':'' ?>>
                            <span class="form-check-label small"><?= sanitize($t['name']) ?></span>
                        </label>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="mt-2">
                        <label class="form-label small">موضوع اصلی</label>
                        <select name="primary_topic_id" class="form-select form-select-sm">
                            <option value="">— اولین موضوع انتخاب‌شده —</option>
                            <?php foreach ($allTopics as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (!empty($curTopicIds) && (int)$curTopicIds[0] === (int)$t['id']) ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mt-2">
                        <a href="<?= siteUrl('admin/topics/') ?>" class="small" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-left ms-1"></i>مدیریت موضوعات و زیرموضوع‌ها</a>
                    </div>
                    </div>
                    <div>
                        <label class="form-label">دسته‌بندی قدیمی (اختیاری)</label>
                        <select name="category_id" class="form-select">
                            <option value="">— بدون دسته —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $post['category_id']==$cat['id']?'selected':'' ?>>
                                <?= sanitize($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- بخش‌های نمایش -->
            <div class="admin-card mb-3">
                <div class="admin-card-header"><i class="bi bi-layout-text-window ms-2"></i>نمایش در کدام بخش‌ها؟</div>
                <div class="admin-card-body">
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
                        $checked = in_array($val, $currentSections) ? 'checked' : '';
                    ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="page_section[]" id="esec_<?= $val ?>" value="<?= $val ?>" <?= $checked ?>>
                        <label class="form-check-label small" for="esec_<?= $val ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- آمار -->
            <div class="admin-card">
                <div class="admin-card-header">آمار مطلب</div>
                <div class="admin-card-body">

                    <div class="d-flex justify-content-between small mb-2"><span>ایجاد:</span><strong><?= persianDate($post['created_at']) ?></strong></div>
                    <div class="d-flex justify-content-between small"><span>ویرایش:</span><strong><?= persianDate($post['updated_at']) ?></strong></div>
                    <?php if ($post['slug']): ?>
                    <hr class="my-2">
                    <div class="small"><strong>اسلاگ:</strong><br><code style="font-size:.72rem;word-break:break-all"><?= sanitize($post['slug']) ?></code></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<style>
/* ─── مدیریت گالری و رسانه در پنل ─────────────────────────────────────────── */
.jhd-media-figure { margin: 0; border: 1px solid var(--admin-border); border-radius: 12px; overflow: hidden; background: #fff; }
.jhd-media-figure img { display: block; object-fit: cover; }
.jhd-media-empty { border: 2px dashed var(--admin-border); border-radius: 12px; padding: 22px 26px; color: var(--admin-muted); text-align: center; font-size: .85rem; }
.jhd-media-empty i { display: block; font-size: 1.6rem; margin-bottom: 6px; }

.jhd-gallery-manager { display: grid; gap: .6rem; }
.jhd-gallery-item { display: flex; gap: .7rem; align-items: flex-start; border: 1px solid var(--admin-border); border-radius: 12px; padding: .6rem; background: #fff; }
.jhd-gallery-item.dragging { opacity: .45; }
.jhd-gallery-item.drop-target { border-color: var(--admin-green); box-shadow: 0 0 0 3px rgba(45,106,79,.12); }
.jhd-gallery-item__handle { cursor: grab; color: var(--admin-muted); padding: 26px 2px 0; }
.jhd-gallery-item > img { width: 118px; height: 86px; object-fit: cover; border-radius: 9px; flex: none; background: #f1f3f2; }
.jhd-gallery-item__body { flex: 1 1 auto; min-width: 0; }
.jhd-upload-zone { border: 2px dashed var(--admin-border); border-radius: 14px; padding: 14px; background: #fbfdfc; transition: border-color .18s, background .18s; }
.jhd-upload-zone.is-dragover { border-color: var(--admin-green); background: #f2faf6; }
.jhd-upload-zone__inner { display: flex; flex-wrap: wrap; gap: .8rem; align-items: center; }
.jhd-upload-zone__inner > i { font-size: 1.7rem; color: var(--admin-green); }
.jhd-upload-previews img { width: 92px; height: 70px; object-fit: cover; border-radius: 8px; border: 1px solid var(--admin-border); }

.jhd-gallery-picker { margin-top: 14px; border: 1px solid var(--admin-border); border-radius: 12px; background: #fbfdfc; padding: 12px; }
.jhd-gallery-picker__head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 10px; font-size: .88rem; }
.jhd-gallery-picker__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: .6rem; }
.jhd-gallery-picker__grid button { border: 1px solid var(--admin-border); border-radius: 10px; overflow: hidden; padding: 0; background: #fff; }
.jhd-gallery-picker__grid img { width: 100%; height: 84px; object-fit: cover; display: block; }

.jhd-media-manager { display: grid; gap: .5rem; }
.jhd-media-item { display: flex; gap: .7rem; align-items: center; border: 1px solid var(--admin-border); border-radius: 12px; padding: .55rem .7rem; background: #fff; }
.jhd-gallery-item.is-featured, .jhd-media-item.is-featured { border-color: var(--admin-green); box-shadow: 0 0 0 2px rgba(45,106,79,.14); }
.jhd-featured-thumb { width: 118px; border: 1px solid var(--admin-border); border-radius: 10px; overflow: hidden; background: #f1f3f2; }
.jhd-featured-thumb img, .jhd-featured-thumb video { width: 100%; height: 76px; object-fit: cover; display: block; background: #000; }
.jhd-featured-thumb figcaption { font-size: .68rem; padding: .2rem .35rem; color: var(--admin-muted); text-align: center; }
.jhd-media-item__icon { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; background: rgba(45,106,79,.09); color: var(--admin-green); flex: none; }
html[data-theme="dark"] .jhd-gallery-item,
html[data-theme="dark"] .jhd-media-item,
html[data-theme="dark"] .jhd-media-figure,
html[data-theme="dark"] .jhd-gallery-picker__grid button { background: #111c16; border-color: var(--admin-border); }
html[data-theme="dark"] .jhd-upload-zone,
html[data-theme="dark"] .jhd-gallery-picker { background: #101a14; }
@media (max-width: 575.98px) {
    .jhd-gallery-item { flex-wrap: wrap; }
    .jhd-gallery-item > img { width: 100%; height: 150px; }
    .jhd-gallery-item__handle { display: none; }
}
</style>

<script>
(function () {
    var CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
    var CSRF_NAME = <?= json_encode(CSRF_TOKEN_NAME, JSON_UNESCAPED_UNICODE) ?>;
    var POST_ID = <?= (int)$post['id'] ?>;
    var GALLERY_ENDPOINT = <?= json_encode($galleryEndpoint, JSON_UNESCAPED_UNICODE) ?>;
    var MEDIA_ENDPOINT = <?= json_encode($mediaEndpoint, JSON_UNESCAPED_UNICODE) ?>;
    // Browser-resolvable URL (the DB stores the relative storage path).
    var FEATURED_IMAGE = <?= json_encode(imgUrl((string)($post['featured_image'] ?? '')), JSON_UNESCAPED_UNICODE) ?>;
    var manager = document.getElementById('galleryManager');
    // If this page is a stale copy whose script cannot see the post's id, no
    // gallery/media operation can succeed: say so once and disable the inputs
    // instead of surfacing server errors on every click.
    if (POST_ID < 1) {
        var guard = document.createElement('div');
        guard.className = 'alert alert-danger';
        guard.innerHTML = '<i class="bi bi-exclamation-triangle ms-2"></i>شناسهٔ مطلب در این نسخهٔ صفحه در دسترس نیست؛ صفحه را یک‌بار رفرش کنید تا پنل گالری و رسانه فعال شود.';
        var guardHost = document.querySelector('.admin-content');
        if (guardHost) guardHost.insertBefore(guard, guardHost.firstChild);
        document.querySelectorAll('.jhd-gallery-primary,.jhd-gallery-move,.jhd-gallery-delete,.jhd-media-move,.jhd-media-delete,.jhd-video-featured,.jhd-gallery-alt,.jhd-gallery-replace,#galleryUploadInput,#galleryUploadBtn').forEach(function (el) { el.disabled = true; });
    }
    var badge = document.getElementById('galleryCountBadge');
    var emptyState = document.getElementById('galleryEmptyState');
    var featuredBox = document.getElementById('featuredImageBox');
    var pickFromGalleryBtn = document.getElementById('pickFromGalleryBtn');
    var picker = document.getElementById('galleryPicker');
    var pickerGrid = document.getElementById('galleryPickerGrid');
    var pickerClose = document.getElementById('galleryPickerClose');

    function toast(message, type) {
        var box = document.createElement('div');
        box.className = 'alert alert-' + (type || 'success') + ' alert-dismissible fade show';
        box.setAttribute('role', 'alert');
        box.innerHTML = '<i class="bi bi-' + (type === 'danger' ? 'exclamation-triangle' : 'check-circle') + ' ms-2"></i>' + message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        var content = document.querySelector('.admin-content');
        content.insertBefore(box, content.firstChild);
        setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 6000);
    }

    function post(url, payload) {
        var body = new FormData();
        body.append(CSRF_NAME, CSRF);
        body.append('post_id', String(POST_ID));
        Object.keys(payload || {}).forEach(function (key) {
            var value = payload[key];
            // فهرست‌ها باید به‌صورت order[] ارسال شوند تا PHP آرایه ببیند.
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(key + '[]', item); });
            } else {
                body.append(key, value);
            }
        });
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'پاسخ نامعتبر از سرور' }; })
                    .then(function (data) {
                        if (!response.ok || !data.ok) throw new Error(data.error || ('خطای سرور (' + response.status + ')'));
                        return data;
                    });
            });
    }
    function itemList() { return Array.prototype.slice.call(manager.querySelectorAll('.jhd-gallery-item')); }
    function orderedIds() { return itemList().map(function (li) { return li.getAttribute('data-id'); }); }
    function refreshNumbers() {
        itemList().forEach(function (li, index) {
            var badgeEl = li.querySelector('.badge');
            if (badgeEl) badgeEl.textContent = '#' + (index + 1);
        });
        if (badge) badge.textContent = String(itemList().length);
        if (emptyState) emptyState.style.display = itemList().length ? 'none' : 'block';
        if (pickFromGalleryBtn) pickFromGalleryBtn.disabled = itemList().length === 0;
        itemList().forEach(function (li) {
            li.querySelectorAll('.jhd-gallery-move').forEach(function (button) {
                var up = button.getAttribute('data-dir') === 'up';
                var index = itemList().indexOf(li);
                button.disabled = up ? index === 0 : index === itemList().length - 1;
            });
        });
    }

    function saveOrder() {
        return post(GALLERY_ENDPOINT, { action: 'reorder', order: orderedIds() }).catch(function (error) {
            toast(error.message, 'danger');
            throw error;
        });
    }

    // ── جابه‌جایی با کشیدن (دسکتاپ) ────────────────────────────────────────
    var dragEl = null;
    manager.addEventListener('dragstart', function (event) {
        var li = event.target.closest('.jhd-gallery-item');
        if (!li) return;
        dragEl = li; li.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
        try { event.dataTransfer.setData('text/plain', li.getAttribute('data-id')); } catch (e) {}
    });
    manager.addEventListener('dragend', function () {
        if (dragEl) dragEl.classList.remove('dragging');
        manager.querySelectorAll('.drop-target').forEach(function (el) { el.classList.remove('drop-target'); });
        dragEl = null;
        refreshNumbers();
        saveOrder();
    });
    manager.addEventListener('dragover', function (event) {
        if (!dragEl) return;
        event.preventDefault();
        var li = event.target.closest('.jhd-gallery-item');
        manager.querySelectorAll('.drop-target').forEach(function (el) { el.classList.remove('drop-target'); });
        if (li && li !== dragEl) {
            li.classList.add('drop-target');
            var rect = li.getBoundingClientRect();
            var after = (event.clientY - rect.top) / rect.height > .5;
            li.parentNode.insertBefore(dragEl, after ? li.nextSibling : li);
        }
    });

    /** رنگ‌آمیزی وضعیت «تصویر شاخص» روی همهٔ ردیف‌های گالری. */
    function applyFeaturedImages(ids) {
        var wanted = (ids || []).map(String);
        Array.prototype.forEach.call(manager.querySelectorAll('.jhd-gallery-item'), function (li) {
            var on = wanted.indexOf(String(li.getAttribute('data-id'))) !== -1;
            li.setAttribute('data-featured', on ? '1' : '0');
            li.classList.toggle('is-featured', on);
            var flag = li.querySelector('.jhd-featured-flag');
            if (flag) flag.hidden = !on;
            var btn = li.querySelector('.jhd-gallery-feature');
            if (btn) {
                btn.classList.toggle('btn-success', on);
                btn.classList.toggle('btn-outline-warning', !on);
                var label = btn.querySelector('.jhd-feature-label');
                if (label) label.textContent = on ? 'شاخص ✓' : 'شاخص کن';
            }
        });
        var counter = document.getElementById('featuredImageCount');
        if (counter) counter.textContent = String(wanted.length);
    }
    window.jhdApplyFeaturedImages = applyFeaturedImages;

    // ── دکمه‌های حرکت (موبایل و دسکتاپ) ────────────────────────────────────
    manager.addEventListener('click', function (event) {
        var moveBtn = event.target.closest('.jhd-gallery-move');
        if (moveBtn) {
            var li = moveBtn.closest('.jhd-gallery-item');
            if (moveBtn.getAttribute('data-dir') === 'up' && li.previousElementSibling) li.parentNode.insertBefore(li, li.previousElementSibling);
            if (moveBtn.getAttribute('data-dir') === 'down' && li.nextElementSibling) li.parentNode.insertBefore(li.nextElementSibling, li);
            refreshNumbers();
            saveOrder();
            return;
        }
        var featureBtn = event.target.closest('.jhd-gallery-feature');
        if (featureBtn) {
            var fitem = featureBtn.closest('.jhd-gallery-item');
            var on = fitem.getAttribute('data-featured') !== '1';
            featureBtn.disabled = true;
            post(GALLERY_ENDPOINT, { action: on ? 'feature' : 'unfeature', image_id: fitem.getAttribute('data-id') })
                .then(function (data) {
                    applyFeaturedImages(data.featured_ids || []);
                    FEATURED_IMAGE = data.featured_image || '';
                    renderFeatured();
                    toast(on ? 'به تصاویر شاخص افزوده شد.' : 'از تصاویر شاخص برداشته شد.');
                })
                .catch(function (error) { toast(error.message, 'danger'); })
                .finally(function () { featureBtn.disabled = false; });
            return;
        }
        var primaryBtn = event.target.closest('.jhd-gallery-primary');
        if (primaryBtn) {
            var item = primaryBtn.closest('.jhd-gallery-item');
            post(GALLERY_ENDPOINT, { action: 'set_primary', image_id: item.getAttribute('data-id') })
                .then(function (data) {
                    FEATURED_IMAGE = data.featured_image || '';
                    renderFeatured();
                    toast('تصویر اصلی مطلب به‌روزرسانی شد.');
                })
                .catch(function (error) { toast(error.message, 'danger'); });
            return;
        }
        var deleteBtn = event.target.closest('.jhd-gallery-delete');
        if (deleteBtn) {
            if (!window.confirm('این تصویر از گالری حذف شود؟')) return;
            var target = deleteBtn.closest('.jhd-gallery-item');
            post(GALLERY_ENDPOINT, { action: 'delete', image_id: target.getAttribute('data-id') })
                .then(function () {
                    target.parentNode.removeChild(target);
                    refreshNumbers();
                    toast('تصویر حذف شد.');
                })
                .catch(function (error) { toast(error.message, 'danger'); });
            return;
        }
    });

    // ── ذخیره توضیح (alt) ───────────────────────────────────────────────────
    manager.addEventListener('change', function (event) {
        var input = event.target.closest('.jhd-gallery-alt');
        if (!input) return;
        var li = input.closest('.jhd-gallery-item');
        post(GALLERY_ENDPOINT, { action: 'alt', image_id: li.getAttribute('data-id'), alt: input.value })
            .then(function () { toast('توضیح تصویر ذخیره شد.'); })
            .catch(function (error) { toast(error.message, 'danger'); });
    });

    // ── جایگزینی فایل تصویر ─────────────────────────────────────────────────
    manager.addEventListener('change', function (event) {
        var input = event.target.closest('.jhd-gallery-replace');
        if (!input || !input.files.length) return;
        var li = input.closest('.jhd-gallery-item');
        var body = new FormData();
        body.append(CSRF_NAME, CSRF);
        body.append('post_id', String(POST_ID));
        body.append('action', 'replace');
        body.append('image_id', li.getAttribute('data-id'));
        body.append('image', input.files[0]);
        fetch(GALLERY_ENDPOINT, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'جایگزینی انجام نشد.');
                var img = li.querySelector('img');
                img.src = data.path + '?t=' + Date.now();
                li.setAttribute('data-path', data.path);
                if (FEATURED_IMAGE && FEATURED_IMAGE.indexOf(li.getAttribute('data-path')) !== -1) renderFeatured();
                toast('تصویر جایگزین شد.');
            })
            .catch(function (error) { toast(error.message, 'danger'); })
            .finally(function () { input.value = ''; });
    });

    // ── انتخاب تصویر اصلی از گالری ──────────────────────────────────────────
    function renderFeatured() {
        if (!featuredBox) return;
        featuredBox.innerHTML = '';
        if (FEATURED_IMAGE) {
            var figure = document.createElement('figure');
            figure.className = 'jhd-media-figure mb-0';
            var img = document.createElement('img');
            img.id = 'currentFeatImg';
            img.src = FEATURED_IMAGE;
            img.alt = 'تصویر اصلی مطلب';
            img.style.maxWidth = '280px'; img.style.maxHeight = '190px';
            figure.appendChild(img);
            featuredBox.appendChild(figure);
        } else {
            var empty = document.createElement('div');
            empty.className = 'jhd-media-empty';
            empty.id = 'featuredEmptyState';
            empty.innerHTML = '<i class="bi bi-image" aria-hidden="true"></i><span>تصویر اصلی انتخاب نشده است</span>';
            featuredBox.appendChild(empty);
        }
        var column = document.createElement('div');
        column.className = 'd-flex flex-column gap-2';
        var pick = document.createElement('button');
        pick.type = 'button'; pick.className = 'btn btn-outline-primary btn-sm'; pick.id = 'pickFromGalleryBtn';
        pick.innerHTML = '<i class="bi bi-images ms-1"></i>انتخاب از گالری';
        pick.disabled = itemList().length === 0;
        pick.addEventListener('click', openPicker);
        var label = document.createElement('label');
        label.className = 'btn btn-outline-secondary btn-sm mb-0';
        label.setAttribute('for', 'featuredImageInput');
        label.innerHTML = '<i class="bi bi-upload ms-1"></i>آپلود تصویر تازه';
        column.appendChild(pick); column.appendChild(label);
        var fileInput = document.createElement('input');
        fileInput.type = 'file'; fileInput.name = 'featured_image'; fileInput.id = 'featuredImageInput';
        fileInput.className = 'visually-hidden'; fileInput.accept = 'image/*';
        column.appendChild(fileInput);
        if (FEATURED_IMAGE) {
            var check = document.createElement('div');
            check.className = 'form-check mt-1';
            check.innerHTML = '<input class="form-check-input" type="checkbox" name="remove_featured" id="remove_featured" value="1">' +
                '<label class="form-check-label text-danger small" for="remove_featured">حذف تصویر اصلی</label>';
            column.appendChild(check);
        }
        featuredBox.appendChild(column);
    }

    function openPicker() {
        if (!picker || !pickerGrid) return;
        pickerGrid.innerHTML = '';
        itemList().forEach(function (li) {
            var button = document.createElement('button');
            button.type = 'button';
            button.title = 'انتخاب این تصویر';
            var img = document.createElement('img');
            img.src = li.getAttribute('data-path');
            img.alt = '';
            button.appendChild(img);
            button.addEventListener('click', function () {
                post(GALLERY_ENDPOINT, { action: 'set_primary', image_id: li.getAttribute('data-id') })
                    .then(function (data) {
                        FEATURED_IMAGE = data.featured_image || '';
                        renderFeatured();
                        picker.style.display = 'none';
                        toast('تصویر اصلی مطلب به‌روزرسانی شد.');
                    })
                    .catch(function (error) { toast(error.message, 'danger'); });
            });
            pickerGrid.appendChild(button);
        });
        picker.style.display = 'block';
    }
    if (pickFromGalleryBtn) pickFromGalleryBtn.addEventListener('click', openPicker);
    if (pickerClose) pickerClose.addEventListener('click', function () { picker.style.display = 'none'; });

    // ── آپلود فوری چند تصویر (بدون نیاز به «ذخیره تغییرات») ────────────────
    // Files go straight to the gallery JSON endpoint (action=add) with the
    // post's real database id. The server validates id, CSRF, format and size,
    // journals the files, and returns the fresh gallery state.
    var dropZone = document.getElementById('galleryDropZone');
    var uploadInput = document.getElementById('galleryUploadInput');
    var uploadBtn = document.getElementById('galleryUploadBtn');
    var previews = document.getElementById('galleryUploadPreviews');
    var uploadBusy = false;

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    // Mirrors the server-rendered .jhd-gallery-item template so AJAX-added
    // images get exactly the same controls (the manager's delegated handlers
    // pick them up automatically).
    function renderGalleryItem(item, index) {
        var isPrimary = FEATURED_IMAGE && FEATURED_IMAGE === item.path;
        var html =
            '<li class="jhd-gallery-item' + (item.featured ? ' is-featured' : '') + '" draggable="true" data-id="' + item.id + '" data-featured="' + (item.featured ? '1' : '0') + '" data-path="' + escapeHtml(item.path) + '">' +
            '<span class="jhd-gallery-item__handle" title="جابه‌جایی"><i class="bi bi-grip-vertical"></i></span>' +
            '<img src="' + escapeHtml(item.path) + '" alt="' + escapeHtml(item.alt) + '" loading="lazy">' +
            '<div class="jhd-gallery-item__body">' +
                '<div class="d-flex flex-wrap gap-2 align-items-center">' +
                    '<span class="badge bg-light text-dark border">#' + (index + 1) + '</span>' +
                    '<span class="small text-muted text-truncate" style="max-width:180px" dir="ltr">' + escapeHtml(item.path.split('/').pop()) + '</span>' +
                    (isPrimary ? '<span class="badge bg-warning text-dark"><i class="bi bi-star-fill ms-1"></i>تصویر اصلی</span>' : '') +
                    '<span class="badge bg-success jhd-featured-flag"' + (item.featured ? '' : ' hidden') + '><i class="bi bi-images ms-1"></i>تصویر شاخص</span>' +
                '</div>' +
                '<div class="input-group input-group-sm mt-2">' +
                    '<span class="input-group-text">توضیح (alt)</span>' +
                    '<input type="text" class="form-control jhd-gallery-alt" value="' + escapeHtml(item.alt) + '" placeholder="توضیح تصویر برای دسترسی‌پذیری و سئو" maxlength="200">' +
                '</div>' +
                '<div class="d-flex flex-wrap gap-1 mt-2">' +
                    '<button type="button" class="btn btn-sm btn-outline-success py-0 px-2 jhd-gallery-primary" title="انتخاب به‌عنوان تصویر اصلی"><i class="bi bi-star"></i> تصویر اصلی</button>' +
                    '<button type="button" class="btn btn-sm py-0 px-2 jhd-gallery-feature ' + (item.featured ? 'btn-success' : 'btn-outline-warning') + '" title="افزودن/برداشتن از تصاویر شاخص"><i class="bi bi-images"></i> <span class="jhd-feature-label">' + (item.featured ? 'شاخص ✓' : 'شاخص کن') + '</span></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-gallery-move" data-dir="up" title="انتقال به بالا" aria-label="انتقال به بالا"><i class="bi bi-arrow-up"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 jhd-gallery-move" data-dir="down" title="انتقال به پایین" aria-label="انتقال به پایین"><i class="bi bi-arrow-down"></i></button>' +
                    '<label class="btn btn-sm btn-outline-primary py-0 px-2 mb-0" title="جایگزینی تصویر"><i class="bi bi-arrow-repeat"></i> جایگزینی' +
                    '<input type="file" class="visually-hidden jhd-gallery-replace" accept="image/*"></label>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 jhd-gallery-delete" title="حذف تصویر"><i class="bi bi-trash"></i> حذف</button>' +
                '</div>' +
            '</div>' +
            '</li>';
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        var li = wrapper.firstChild;
        manager.appendChild(li);
        return li;
    }

    function setUploadProgress(message, type) {
        if (!previews) return;
        previews.innerHTML = '';
        if (!message) return;
        var note = document.createElement('div');
        note.className = 'alert alert-' + (type || 'info') + ' py-2 small mb-0 d-flex align-items-center gap-2';
        note.innerHTML = (type === 'success' ? '<i class="bi bi-check-circle"></i>' : type === 'danger' ? '<i class="bi bi-exclamation-triangle"></i>' : '<div class="spinner-border spinner-border-sm"></div>') + '<span>' + escapeHtml(message) + '</span>';
        previews.appendChild(note);
    }

    function uploadSelectedFiles() {
        if (!uploadInput || uploadBusy) return;
        var files = Array.prototype.filter.call(uploadInput.files || [], function (file) { return file && file.type.match(/^image\//); });
        if (!files.length) return;
        if (POST_ID < 1) {
            setUploadProgress('شناسهٔ مطلب در این صفحه معتبر نیست؛ صفحه را رفرش کنید و دوباره تلاش کنید.', 'danger');
            return;
        }
        uploadBusy = true;
        setUploadProgress('در حال آپلود ' + files.length + ' تصویر به گالری…');
        var body = new FormData();
        body.append(CSRF_NAME, CSRF);
        body.append('post_id', String(POST_ID));
        body.append('action', 'add');
        files.forEach(function (file, index) {
            body.append('images[' + index + ']', file, file.name);
            body.append('alts[' + index + ']', '');
        });
        fetch(GALLERY_ENDPOINT, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'پاسخ نامعتبر از سرور' }; })
                    .then(function (data) { return { status: response.status, data: data }; });
            })
            .then(function (result) {
                var data = result.data || {};
                if (Array.isArray(data.images) && data.images.length) {
                    // Rebuild the list in server order (new images included).
                    manager.innerHTML = '';
                    data.images.forEach(function (item, index) { renderGalleryItem(item, index); });
                    refreshNumbers();
                }
                if (data.ok) {
                    setUploadProgress(data.added + ' تصویر به گالری افزوده شد.', 'success');
                    uploadInput.value = '';
                } else {
                    // Partial/total failure: the files are kept in the input so
                    // the editor can fix and retry; uploaded ones (if any) are
                    // already visible in the gallery.
                    setUploadProgress(data.error || 'آپلود تصاویر انجام نشد.', 'danger');
                }
            })
            .catch(function () { setUploadProgress('اتصال به سرور برقرار نشد؛ دوباره تلاش کنید.', 'danger'); })
            .finally(function () { uploadBusy = false; });
    }

    if (uploadBtn && uploadInput) uploadBtn.addEventListener('click', function (event) {
        event.preventDefault();
        uploadInput.click();
    });
    if (dropZone) {
        dropZone.addEventListener('click', function (event) {
            if (event.target === dropZone || event.target.closest('.jhd-upload-zone__inner') && !event.target.closest('button') && !event.target.closest('label')) uploadInput.click();
        });
        ['dragenter', 'dragover'].forEach(function (type) {
            dropZone.addEventListener(type, function (event) { event.preventDefault(); dropZone.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            dropZone.addEventListener(type, function (event) { event.preventDefault(); dropZone.classList.remove('is-dragover'); });
        });
        dropZone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files.length) {
                try { uploadInput.files = event.dataTransfer.files; } catch (e) { return; }
                uploadSelectedFiles();
            }
        });
    }
    if (uploadInput) uploadInput.addEventListener('change', uploadSelectedFiles);

    /** رنگ‌آمیزی وضعیت «ویدیوی شاخص». */
    function applyFeaturedVideos(ids) {
        var wanted = (ids || []).map(String);
        document.querySelectorAll('#videoManager .jhd-media-item').forEach(function (li) {
            var on = wanted.indexOf(String(li.getAttribute('data-id'))) !== -1;
            li.setAttribute('data-featured', on ? '1' : '0');
            li.classList.toggle('is-featured', on);
            var btn = li.querySelector('.jhd-video-feature');
            if (btn) {
                btn.classList.toggle('btn-success', on);
                btn.classList.toggle('btn-outline-warning', !on);
                var label = btn.querySelector('.jhd-feature-label');
                if (label) label.textContent = on ? 'شاخص ✓' : 'شاخص کن';
            }
        });
        var counter = document.getElementById('featuredVideoCount');
        if (counter) counter.textContent = String(wanted.length);
    }

    // ── مدیریت رسانه‌ها (ویدیو/صوت/مستندات) ─────────────────────────────────
    document.querySelectorAll('.jhd-media-manager').forEach(function (list) {
        var kind = list.getAttribute('data-kind');
        var countBadge = document.getElementById(kind + 'CountBadge');
        function mediaIds() { return Array.prototype.slice.call(list.querySelectorAll('.jhd-media-item')).map(function (li) { return li.getAttribute('data-id'); }); }
        function refresh() {
            Array.prototype.slice.call(list.querySelectorAll('.jhd-media-item')).forEach(function (li, index) {
                li.querySelectorAll('.jhd-media-move').forEach(function (button) {
                    var up = button.getAttribute('data-dir') === 'up';
                    var items = Array.prototype.slice.call(list.querySelectorAll('.jhd-media-item'));
                    button.disabled = up ? index === 0 : index === items.length - 1;
                });
            });
            if (countBadge) countBadge.textContent = String(mediaIds().length);
        }
        function saveMediaOrder() {
            return post(MEDIA_ENDPOINT, { action: 'reorder', kind: kind, order: mediaIds() })
                .catch(function (error) { toast(error.message, 'danger'); throw error; });
        }
        list.addEventListener('click', function (event) {
            var moveBtn = event.target.closest('.jhd-media-move');
            if (moveBtn) {
                var li = moveBtn.closest('.jhd-media-item');
                if (moveBtn.getAttribute('data-dir') === 'up' && li.previousElementSibling) li.parentNode.insertBefore(li, li.previousElementSibling);
                if (moveBtn.getAttribute('data-dir') === 'down' && li.nextElementSibling) li.parentNode.insertBefore(li.nextElementSibling, li);
                refresh(); saveMediaOrder();
                return;
            }
            var featuredBtn = event.target.closest('.jhd-video-featured');
            if (featuredBtn) {
                var target = featuredBtn.closest('.jhd-media-item');
                post(MEDIA_ENDPOINT, { action: 'set_featured', media_id: target.getAttribute('data-id') })
                    .then(function (data) {
                        applyFeaturedVideos(data.featured_ids || []);
                        toast('این ویدیو نخستین ویدیوی شاخص مطلب شد و همان لحظه در صفحهٔ مطلب نمایش داده می‌شود.');
                    })
                    .catch(function (error) { toast(error.message, 'danger'); });
                return;
            }
            var vFeatureBtn = event.target.closest('.jhd-video-feature');
            if (vFeatureBtn) {
                var vItem = vFeatureBtn.closest('.jhd-media-item');
                var vOn = vItem.getAttribute('data-featured') !== '1';
                vFeatureBtn.disabled = true;
                post(MEDIA_ENDPOINT, { action: vOn ? 'feature' : 'unfeature', media_id: vItem.getAttribute('data-id') })
                    .then(function (data) {
                        applyFeaturedVideos(data.featured_ids || []);
                        toast(vOn ? 'به ویدیوهای شاخص افزوده شد.' : 'از ویدیوهای شاخص برداشته شد.');
                    })
                    .catch(function (error) { toast(error.message, 'danger'); })
                    .finally(function () { vFeatureBtn.disabled = false; });
                return;
            }
            var deleteBtn = event.target.closest('.jhd-media-delete');
            if (deleteBtn) {
                if (!window.confirm('این فایل حذف شود؟')) return;
                var item = deleteBtn.closest('.jhd-media-item');
                post(MEDIA_ENDPOINT, { action: 'delete', media_id: item.getAttribute('data-id') })
                    .then(function () { item.parentNode.removeChild(item); refresh(); toast('فایل حذف شد.'); })
                    .catch(function (error) { toast(error.message, 'danger'); });
            }
        });
        list.addEventListener('change', function (event) {
            var input = event.target.closest('.jhd-media-title');
            if (!input) return;
            var li = input.closest('.jhd-media-item');
            post(MEDIA_ENDPOINT, { action: 'rename', media_id: li.getAttribute('data-id'), title: input.value })
                .then(function () { toast('عنوان فایل ذخیره شد.'); })
                .catch(function (error) { toast(error.message, 'danger'); });
        });
        refresh();
    });

    var clearFeaturedVideo = document.getElementById('clearFeaturedVideoBtn');
    if (clearFeaturedVideo) {
        clearFeaturedVideo.addEventListener('click', function () {
            if (!window.confirm('ویدیو شاخص حذف شود؟')) return;
            post(MEDIA_ENDPOINT, { action: 'clear_featured' })
                .then(function () { applyFeaturedVideos([]); toast('همهٔ ویدیوهای شاخص برداشته شدند.'); })
                .catch(function (error) { toast(error.message, 'danger'); });
        });
    }

    refreshNumbers();
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
    var videoInput     = document.getElementById('videoFilesInputEdit');
    var imageInput     = document.getElementById('featuredImageInput');
    var removeFeatChk  = document.getElementById('remove_featured');
    var thumbDataInput = document.getElementById('autoThumbnailDataEdit');
    var thumbNotice    = document.getElementById('autoThumbNoticeEdit');
    var thumbProgress  = document.getElementById('videoThumbProgressEdit');
    var hasFeatImg     = <?= !empty($post['featured_image']) ? 'true' : 'false' ?>;

    if (!videoInput) return;

    function shouldGenerateThumb() {
        if (imageInput && imageInput.files.length > 0) return false;
        if (hasFeatImg && (!removeFeatChk || !removeFeatChk.checked)) return false;
        return true;
    }

    videoInput.addEventListener('change', function() {
        var file = this.files[0];
        if (!file || !shouldGenerateThumb()) return;

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
            canvas.getContext('2d').drawImage(videoEl, 0, 0, w, h);

            var dataUrl = canvas.toDataURL('image/jpeg', 0.82);
            if (thumbDataInput) thumbDataInput.value = dataUrl;
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
            if (this.files.length > 0 && thumbDataInput) thumbDataInput.value = '';
        });
    }

    var featVidInput = document.getElementById('featuredVideoInputEdit');
    var featVidPrev  = document.getElementById('featVideoPreviewEdit');
    if (featVidInput && featVidPrev) {
        featVidInput.addEventListener('change', function() {
            var f = this.files[0];
            if (!f) {
                featVidPrev.style.display = 'none';
                featVidPrev.removeAttribute('src');
                return;
            }
            var u = URL.createObjectURL(f);
            featVidPrev.src           = u;
            featVidPrev.style.display = 'block';
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
