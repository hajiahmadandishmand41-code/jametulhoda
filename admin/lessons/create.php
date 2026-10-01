<?php
/** Create a lesson in the real Collection → Volume → Lesson hierarchy. */
$adminTitle = 'درس جدید';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/media.php';
require_once __DIR__ . '/../../includes/post-gallery.php';
require_once __DIR__ . '/../../includes/content-delete.php';

$error = '';
$values=['title'=>'','subject'=>'','teacher'=>'','summary'=>'','content'=>'','sources'=>'','collection_id'=>'','volume_id'=>'','lesson_number'=>'','level'=>'beginner','status'=>'draft','sort_order'=>'0'];
$selectedTopics=[];
$collections=getLessonCollections(['active'=>null]);
$volumes=[]; foreach($collections as $collection) foreach(getLessonVolumes((int)$collection['id']) as $volume) $volumes[]=['collection'=>$collection,'volume'=>$volume];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    beginContentUploadScope();
    foreach(array_keys($values) as $key) if (isset($_POST[$key])) $values[$key]=is_string($_POST[$key]) ? trim($_POST[$key]) : $values[$key];
    $values['status']=in_array($values['status'],['published','draft'],true)?$values['status']:'draft';
    $values['level']=in_array($values['level'],['beginner','intermediate','advanced'],true)?$values['level']:'beginner';
    $collectionId=(int)$values['collection_id']; $volumeId=(int)$values['volume_id']; $lessonNo=(int)$values['lesson_number'];
    $selectedTopics=validatedTopicIds((array)($_POST['topic_ids'] ?? []));
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) $error='خطای امنیتی.';
    elseif ($values['title']==='') $error='عنوان درس الزامی است.';
    else {
        try {
            if ($volumeId > 0) {
                $volume=getLessonVolumeById($volumeId);
                if (!$volume || ($collectionId > 0 && (int)$volume['collection_id'] !== $collectionId)) throw new RuntimeException('جلد انتخاب‌شده با دوره انتخابی هماهنگ نیست.');
                $collectionId=(int)$volume['collection_id'];
            }
            $db=getDB(); $slug=uniqueSlug('lessons',$values['title']);
            $insert=$db->prepare("INSERT INTO lessons (title,slug,subject,teacher,content,summary,sources,status,page_section,level,sort_order,lesson_number,collection_id,volume_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) RETURNING id");
            $insert->execute([$values['title'],$slug,$values['subject'] ?: null,$values['teacher'] ?: null,$values['content'] ?: null,$values['summary'] ?: null,$values['sources'] ?: null,$values['status'],'lessons',$values['level'],(int)$values['sort_order'],$lessonNo ?: null,$collectionId ?: null,$volumeId ?: null,(int)currentAdmin()['id']]);
            $lessonId=(int)$insert->fetchColumn();$insert->closeCursor(); if($lessonId<1) throw new RuntimeException('شناسه درس ایجاد نشد.');
            $context=['collection_id'=>$collectionId,'volume_id'=>$volumeId];
            $image='';$audio='';$video='';$pdf='';
            if((($_FILES['featured_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) { if ($_FILES['featured_image']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(jhd_upload_error_message((int)$_FILES['featured_image']['error'])); $image=uploadContentImage($_FILES['featured_image'],'lesson',$lessonId,$context); if(!$image) throw new RuntimeException('تصویر درس معتبر نیست.'); }
            if((($_FILES['audio_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) { if ($_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(jhd_upload_error_message((int)$_FILES['audio_file']['error'])); $audio=uploadContentAudio($_FILES['audio_file'],'lesson',$lessonId,$context); if(!$audio) throw new RuntimeException('فایل صوتی معتبر نیست.'); }
            if((($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) { if ($_FILES['video_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(jhd_upload_error_message((int)$_FILES['video_file']['error'])); $video=uploadContentVideo($_FILES['video_file'],'lesson',$lessonId,$context); if(!$video) throw new RuntimeException('فایل ویدیویی معتبر نیست.'); }
            if((($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) { if ($_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(jhd_upload_error_message((int)$_FILES['pdf_file']['error'])); $pdf=uploadContentDocument($_FILES['pdf_file'],'lesson',$lessonId,'pdf',$context); if(!$pdf) throw new RuntimeException('PDF درس معتبر نیست.'); }
            $db->prepare('UPDATE lessons SET featured_image=?,audio_file=?,video_file=?,pdf_file=? WHERE id=?')->execute([$image ?: null,$audio ?: null,$video ?: null,$pdf ?: null,$lessonId]);
            syncPrimaryMediaFile('lesson',$lessonId,'audio',$audio,$values['title']);
            syncPrimaryMediaFile('lesson',$lessonId,'video',$video,$values['title']);
            setLessonTopics($lessonId,$selectedTopics);
            requireMediaUploads(
                handleMediaUploads('lesson', $lessonId, $_FILES['attachment_files'] ?? [], 'document', $context),
                handleMediaUploads('lesson', $lessonId, $_FILES['audio_files'] ?? [], 'audio', $context),
                handleMediaUploads('lesson', $lessonId, $_FILES['video_files'] ?? [], 'video', $context)
            );
            $_SESSION['flash_msg']='درس و پوشهٔ اختصاصی آن با موفقیت ایجاد شد.';$_SESSION['flash_type']='success';redirect(siteUrl('admin/lessons/edit?id='.$lessonId));
        } catch(Throwable $e) { error_log('lesson create failed: '.get_class($e)); if(!empty($lessonId))try{deleteContentRecord('lessons',(int)$lessonId);}catch(Throwable){} $error=$e instanceof RuntimeException?$e->getMessage():'ذخیره درس با خطا مواجه شد.'; }
    }
}
$allTopics=getTopics();
?>
<div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h5 mb-0"><i class="bi bi-mortarboard ms-2"></i>درس جدید</h1><a href="<?= siteUrl('admin/lessons/') ?>" class="btn btn-outline-secondary btn-sm">بازگشت</a></div>
<?php if($error):?><div class="alert alert-danger"><?=sanitize($error)?></div><?php endif;?>
<form method="post" enctype="multipart/form-data" class="admin-form"><?=csrfField()?><div class="row g-4"><div class="col-lg-8">
<section class="admin-card mb-4"><div class="admin-card-header">محتوای درس</div><div class="admin-card-body"><div class="mb-3"><label class="form-label">عنوان <span class="text-danger">*</span></label><input class="form-control" name="title" required value="<?=sanitize((string)$values['title'])?>"></div><div class="row g-3"><div class="col-md-6"><label class="form-label">موضوع درسی</label><input class="form-control" name="subject" value="<?=sanitize((string)$values['subject'])?>"></div><div class="col-md-6"><label class="form-label">مدرس</label><input class="form-control" name="teacher" value="<?=sanitize((string)$values['teacher'])?>"></div></div><div class="mt-3"><label class="form-label">خلاصه</label><textarea rows="3" class="form-control" name="summary"><?=sanitize((string)$values['summary'])?></textarea></div><div class="mt-3"><label class="form-label">متن کامل</label><textarea rows="10" class="form-control" name="content"><?=htmlspecialchars((string)$values['content'],ENT_QUOTES,'UTF-8')?></textarea></div><div class="mt-3"><label class="form-label">منابع</label><textarea rows="4" class="form-control" name="sources"><?=sanitize((string)$values['sources'])?></textarea></div></div></section>
<section class="admin-card mb-4"><div class="admin-card-header">مسیر آموزشی و موضوعات</div><div class="admin-card-body"><div class="row g-3"><div class="col-md-5"><label class="form-label">دوره / مجموعه</label><select class="form-select" name="collection_id"><option value="">بدون دوره</option><?php foreach($collections as $collection):?><option value="<?= (int)$collection['id']?>" <?= (int)$values['collection_id']===(int)$collection['id']?'selected':''?>><?=sanitize($collection['title'])?></option><?php endforeach;?></select></div><div class="col-md-5"><label class="form-label">جلد / بخش</label><select class="form-select" name="volume_id"><option value="">بدون جلد</option><?php foreach($volumes as $entry):?><option value="<?= (int)$entry['volume']['id']?>" <?= (int)$values['volume_id']===(int)$entry['volume']['id']?'selected':''?>><?=sanitize($entry['collection']['title'].' — '.$entry['volume']['title'])?></option><?php endforeach;?></select></div><div class="col-md-2"><label class="form-label">شماره</label><input type="number" min="1" class="form-control" name="lesson_number" value="<?= (int)$values['lesson_number']?>"></div></div><div class="mt-3"><input type="search" class="form-control form-control-sm mb-2" data-topic-filter data-topic-target="lessonTopicList" placeholder="جستجو در همهٔ موضوعات"><div id="lessonTopicList" style="max-height:240px;overflow:auto"><?php foreach($allTopics as $topic):?><label class="form-check <?= $topic['parent_id']?'ms-3':''?>"><input type="checkbox" class="form-check-input" name="topic_ids[]" value="<?= (int)$topic['id']?>" <?= in_array((int)$topic['id'],$selectedTopics,true)?'checked':''?>><span class="form-check-label"><?=sanitize($topic['name'])?></span></label><?php endforeach;?></div></div></div></section>
<section class="admin-card"><div class="admin-card-header">رسانه و فایل‌ها</div><div class="admin-card-body"><div class="row g-3"><div class="col-md-3"><label class="form-label">تصویر</label><input class="form-control" type="file" name="featured_image" accept="image/jpeg,image/png,image/gif,image/webp"></div><div class="col-md-3"><label class="form-label">صوت</label><input class="form-control" type="file" name="audio_file" accept="audio/*,.mp3,.ogg,.wav,.m4a"></div><div class="col-md-3"><label class="form-label">ویدیو</label><input class="form-control" type="file" name="video_file" accept="video/*,.mp4,.webm,.mov,.mkv"></div><div class="col-md-3"><label class="form-label">PDF</label><input class="form-control" type="file" name="pdf_file" accept="application/pdf,.pdf"></div></div><div class="row g-3 mt-2"><div class="col-md-6"><label class="form-label">صوت‌های تکمیلی</label><input class="form-control" type="file" multiple name="audio_files[]" accept="audio/*"></div><div class="col-md-6"><label class="form-label">ویدیوهای تکمیلی</label><input class="form-control" type="file" multiple name="video_files[]" accept="video/*"></div></div><div class="mt-3"><label class="form-label">فایل‌های تکمیلی</label><input class="form-control" multiple type="file" name="attachment_files[]" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></div></div></section>
</div><aside class="col-lg-4"><section class="admin-card"><div class="admin-card-header">انتشار</div><div class="admin-card-body"><label class="form-label">وضعیت</label><select class="form-select mb-3" name="status"><option value="draft" <?=$values['status']==='draft'?'selected':''?>>پیش‌نویس</option><option value="published" <?=$values['status']==='published'?'selected':''?>>منتشرشده</option></select><label class="form-label">سطح</label><select class="form-select mb-3" name="level"><option value="beginner" <?=$values['level']==='beginner'?'selected':''?>>مبتدی</option><option value="intermediate" <?=$values['level']==='intermediate'?'selected':''?>>متوسط</option><option value="advanced" <?=$values['level']==='advanced'?'selected':''?>>پیشرفته</option></select><label class="form-label">ترتیب</label><input class="form-control mb-3" type="number" name="sort_order" value="<?= (int)$values['sort_order']?>"><button class="btn btn-success w-100">ایجاد درس</button></div></section></aside></div></form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
