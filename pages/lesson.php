<?php
/**
 * lesson.php — صفحه درس حوزوی محتوامحور (مجموعه → جلد → درس)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/cards.php';
require_once __DIR__ . '/../includes/auth.php';
startPublicSession();

$slug = $_GET['slug'] ?? '';
$slug = is_string($slug) ? trim($slug) : '';
if (!$slug) redirect(siteUrl('lessons'));

if (!jhd_db_ready()) {
    jhd_render_content_unavailable('درس', url('lessons'), 'بازگشت به درس‌ها');
}

$lesson=getLessonBySlug($slug);
if(!$lesson){
    http_response_code(404);
    $pageTitle='درس یافت نشد';
    $pageDesc='درس مورد نظر یافت نشد';
    require_once __DIR__.'/../includes/header.php';
    echo '<div class="container py-5 text-center"><h1>درس یافت نشد</h1><a href="'.siteUrl('lessons').'" class="btn btn-primary mt-3">بازگشت</a></div>';
    require_once __DIR__.'/../includes/footer.php'; exit;
}
// One lesson = one URL: /lessons/<slug>, the same path the sitemap and every
// lesson card publish.
jhd_redirect_to_canonical(lessonUrl($lesson), jhd_route_path('lesson', ['slug' => (string)$lesson['slug']]));

$pageTitle=$lesson['title'];
$canonicalOverride=lessonUrl($lesson);
$pageDesc=$lesson['summary'] ? excerpt($lesson['summary'],160) : excerpt(strip_tags($lesson['content'] ?? ''),160);
$lessonJsonLd = lessonJsonLd($lesson);

$audioUrl = $lesson['audio_file'] ? imgUrl((string)$lesson['audio_file']) : '';
$videoUrl = $lesson['video_file'] ? imgUrl((string)$lesson['video_file']) : '';
$pdfUrl   = $lesson['pdf_file'] ? imgUrl((string)$lesson['pdf_file']) : '';
$lessonTopics=getTopicsForLesson((int)$lesson['id']);
$lessonAttachments = getMediaFor('lesson', (int)$lesson['id'], 'document');
$lessonExtraAudio = array_filter(getMediaFor('lesson', (int)$lesson['id'], 'audio'), static fn($m) => ($m['file_path'] ?? '') !== ($lesson['audio_file'] ?? ''));
$lessonExtraVideo = array_filter(getMediaFor('lesson', (int)$lesson['id'], 'video'), static fn($m) => ($m['file_path'] ?? '') !== ($lesson['video_file'] ?? ''));
$adjacent=getAdjacentLesson($lesson);

// related via same topic or same collection
$relatedLessons=[];
if(!empty($lessonTopics)){
    $relatedLessons=getLessonsByTopic((int)$lessonTopics[0]['id'],4);
    $relatedLessons=array_filter($relatedLessons, fn($r)=>$r['id']!=$lesson['id']);
    $relatedLessons=array_slice($relatedLessons,0,3);
}
if(count($relatedLessons)<3 && $lesson['collection_id']){
    $more=getLessonsByCollection((int)$lesson['collection_id'], (int)($lesson['volume_id'] ?? 0), 10);
    $more=array_filter($more, fn($r)=>$r['id']!=$lesson['id'] && !in_array($r['id'], array_column($relatedLessons,'id')));
    $relatedLessons=array_slice(array_merge($relatedLessons,$more),0,3);
}

// breadcrumbs + jsonld
$breadcrumbs=[
    ['name'=>'صفحه اصلی','url'=>SITE_URL? rtrim(SITE_URL,'/').'/': siteUrl()],
    ['name'=>'دروس','url'=>siteUrl('lessons')],
];
if($lesson['collection_title']){
    $breadcrumbs[]=['name'=>$lesson['collection_title'],'url'=>collectionUrl($lesson['collection_slug'])];
    if($lesson['volume_title']) $breadcrumbs[]=['name'=>$lesson['volume_title'],'url'=>collectionUrl($lesson['collection_slug'], $lesson['volume_slug'])];
} elseif($lesson['subject']){
    $breadcrumbs[]=['name'=>sanitize($lesson['subject']),'url'=>siteUrl('lessons?q='.urlencode($lesson['subject']))];
}
$breadcrumbs[]=['name'=>$lesson['title'],'url'=>canonicalUrl(lessonUrl($lesson))];
$breadcrumbsJsonLd=breadcrumbsJsonLd($breadcrumbs);

require_once __DIR__.'/../includes/header.php';
?>

<div class="breadcrumb-bar">
  <div class="container">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
    <?php foreach ($breadcrumbs as $bi => $bc): $isLast = $bi === count($breadcrumbs) - 1; ?>
      <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>><?php if (!$isLast): ?><a href="<?= sanitize($bc['url']) ?>"><?= sanitize($bc['name']) ?></a><?php else: ?><?= sanitize(mb_strimwidth($bc['name'], 0, 55, '...')) ?><?php endif; ?></li>
    <?php endforeach; ?>
    </ol></nav>
  </div>
</div>

<div class="jhd-section">
 <div class="container">
  <div class="row g-4">
   <div class="col-lg-8">
    <article class="jhd-article" itemscope itemtype="https://schema.org/LearningResource">
      <header class="jhd-article-head">
        <div class="jhd-cat-strip">
          <?php if ($lesson['collection_title']): ?><a class="jhd-chip" href="<?= collectionUrl($lesson['collection_slug']) ?>"><?= sanitize($lesson['collection_title']) ?></a><?php endif; ?>
          <?php if ($lesson['volume_title']): ?><span class="jhd-chip"><?= sanitize($lesson['volume_title']) ?></span><?php endif; ?>
          <?php if ($lesson['subject'] && !$lesson['collection_title']): ?><span class="jhd-chip"><?= sanitize($lesson['subject']) ?></span><?php endif; ?>
          <?php if (!empty($lesson['lesson_number'])): ?><span class="jhd-chip jhd-chip--gold">جلسهٔ <?= (int)$lesson['lesson_number'] ?></span><?php endif; ?>
        </div>
        <h1 class="jhd-article-title" itemprop="name"><?= sanitize($lesson['title']) ?></h1>
        <div class="jhd-meta-bar">
          <span><i class="bi bi-calendar3"></i><?= persianDate($lesson['created_at']) ?></span>
          <?php if ($lesson['teacher']): ?><span><i class="bi bi-person"></i><?= sanitize($lesson['teacher']) ?></span><?php endif; ?>
          <?php if ($audioUrl): ?><span class="text-gold"><i class="bi bi-headphones"></i> دارای صوت</span><?php endif; ?>
          <?php if ($videoUrl): ?><span class="text-gold"><i class="bi bi-camera-video"></i> دارای ویدیو</span><?php endif; ?>
          <?php if ($pdfUrl): ?><span class="text-gold"><i class="bi bi-file-pdf"></i> جزوه PDF</span><?php endif; ?>
        </div>
      </header>

      <?php if ($lesson['featured_image']): ?>
      <figure class="jhd-article-figure"><img src="<?= imgUrl($lesson['featured_image']) ?>" alt="<?= sanitize($lesson['title']) ?>" loading="lazy" decoding="async"></figure>
      <?php endif; ?>

      <?php if ($lesson['summary']): ?>
      <p class="jhd-article-lead"><?= sanitize($lesson['summary']) ?></p>
      <?php endif; ?>

      <?php if ($videoUrl): ?>
      <section class="jhd-player">
        <h2 class="h6 mb-2"><i class="bi bi-camera-video ms-1 text-gold"></i> ویدیوی درس</h2>
        <video controls playsinline preload="none" poster="<?= $lesson['featured_image'] ? imgUrl($lesson['featured_image']) : '' ?>"><source src="<?= htmlspecialchars($videoUrl, ENT_QUOTES) ?>" type="video/mp4">مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.</video>
      </section>
      <?php endif; ?>

      <?php if ($audioUrl): ?>
      <section class="jhd-player jhd-player--audio">
        <div class="jhd-player-head">
          <span class="jhd-audio-icon"><i class="bi bi-headphones" aria-hidden="true"></i></span>
          <div><strong><?= sanitize($lesson['title']) ?></strong><?php if ($lesson['teacher']): ?><div class="jhd-card-meta"><span><?= sanitize($lesson['teacher']) ?></span></div><?php endif; ?></div>
          <a class="btn-read-more" href="<?= htmlspecialchars($audioUrl, ENT_QUOTES) ?>" download><i class="bi bi-download"></i> دانلود صوت</a>
        </div>
        <audio controls preload="none"><source src="<?= htmlspecialchars($audioUrl, ENT_QUOTES) ?>" type="audio/mpeg">مرورگر شما از پخش صوت پشتیبانی نمی‌کند.</audio>
      </section>
      <?php endif; ?>

      <?php if ($pdfUrl): ?>
      <section class="jhd-reader-callout mt-4">
        <div><span class="jhd-eyebrow">مرکز مطالعه</span><h2 class="h5 mb-1">جزوهٔ درس</h2><p class="text-muted mb-0">جزوه در صفحهٔ اختصاصی مطالعه باز می‌شود.</p></div>
        <a class="btn btn-primary" href="<?= sanitize(documentReaderUrl('lesson', (string)$lesson['slug'])) ?>"><i class="bi bi-book ms-2"></i>مطالعه جزوه</a>
      </section>
      <?php endif; ?>
      <?php foreach ($lessonAttachments as $att): if (!preg_match('~\.pdf$~i', (string)$att['file_path'])) continue;
        $attTitle = (string)($att['title'] ?: basename((string)$att['file_path']));
      ?>
      <div class="jhd-file-row mt-3">
        <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
        <span class="file-name"><?= sanitize($attTitle) ?></span>
        <a class="btn btn-sm btn-outline-primary" href="<?= sanitize(documentReaderUrl('lesson', (string)$lesson['slug'], (int)($att['id'] ?? 0))) ?>"><i class="bi bi-book ms-1"></i>مطالعه</a>
      </div>
      <?php endforeach; ?>

      <?php if ($lesson['content']): ?>
      <div class="jhd-prose mt-4" itemprop="description"><?= safeRichText($lesson['content']) ?></div>
      <?php elseif (!$audioUrl && !$videoUrl): ?>
      <p class="text-muted mt-4">متن این درس هنوز ثبت نشده است.</p>
      <?php endif; ?>

      <?php if ($lessonExtraAudio || $lessonExtraVideo): ?>
      <section class="jhd-attachments" aria-label="رسانه‌های تکمیلی درس">
        <div class="jhd-attachments__head"><h2 class="jhd-attachments__title"><i class="bi bi-collection-play" aria-hidden="true"></i>رسانه‌های تکمیلی درس</h2><p class="jhd-attachments__lead">صوت‌ها و ویدیوهای مرتبط با این درس</p></div>
        <div class="jhd-attachments__media-grid">
          <?php foreach ($lessonExtraAudio as $file): ?>
          <article class="jhd-attachment-media">
            <div class="jhd-attachment-media__head"><span class="jhd-attachment-media__icon" aria-hidden="true"><i class="bi bi-headphones"></i></span><strong class="jhd-attachment-media__title"><?= sanitize($file['title'] ?: 'صوت درس') ?></strong></div>
            <audio controls preload="none" src="<?= sanitize(imgUrl($file['file_path'])) ?>" aria-label="<?= sanitize($file['title'] ?: 'صوت درس') ?>"></audio>
            <div class="jhd-attachment-media__foot"><small>صوت تکمیلی</small><a class="btn btn-sm btn-outline-secondary" href="<?= sanitize(imgUrl($file['file_path'])) ?>" download><i class="bi bi-download ms-1" aria-hidden="true"></i>دانلود</a></div>
          </article>
          <?php endforeach; ?>
          <?php foreach ($lessonExtraVideo as $file): ?>
          <article class="jhd-attachment-media">
            <div class="jhd-attachment-media__head"><span class="jhd-attachment-media__icon" aria-hidden="true"><i class="bi bi-camera-video"></i></span><strong class="jhd-attachment-media__title"><?= sanitize($file['title'] ?: 'ویدیوی درس') ?></strong></div>
            <video controls playsinline preload="metadata" src="<?= sanitize(imgUrl($file['file_path'])) ?>" aria-label="<?= sanitize($file['title'] ?: 'ویدیوی درس') ?>"></video>
            <div class="jhd-attachment-media__foot"><small>ویدیوی تکمیلی</small><a class="btn btn-sm btn-outline-secondary" href="<?= sanitize(imgUrl($file['file_path'])) ?>" download><i class="bi bi-download ms-1" aria-hidden="true"></i>دانلود</a></div>
          </article>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if ($lessonAttachments): ?>
      <section class="jhd-attachments" aria-label="فایل‌های تکمیلی درس">
        <div class="jhd-attachments__head"><h2 class="jhd-attachments__title"><i class="bi bi-paperclip" aria-hidden="true"></i>فایل‌های تکمیلی درس</h2><p class="jhd-attachments__lead">جزوه‌ها و اسناد مرتبط با این درس</p></div>
        <div class="jhd-files">
          <?php foreach ($lessonAttachments as $file): ?>
          <?php
          $fileTitle = (string)($file['title'] ?: basename((string)$file['file_path']));
          $isPdf = (bool)preg_match('~\.pdf$~i', (string)$file['file_path']);
          $readerHref = $isPdf ? documentReaderUrl('lesson', (string)$lesson['slug'], (int)($file['id'] ?? 0)) : '';
          $fileSize = (string)($file['file_size'] ?? ($file['size'] ?? ''));
          echo jhd_file_row($fileTitle, imgUrl((string)$file['file_path']), $fileSize, 'bi-file-earmark', $isPdf ? ['reader_target' => $readerHref] : []);
          ?>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if (!empty($lesson['sources'])): ?>      <?php if (!empty($lesson['sources'])): ?>
      <section class="jhd-side-card mt-3">
        <h3><i class="bi bi-journal-text" aria-hidden="true"></i> منابع درس</h3>
        <div class="jhd-prose jhd-prose--sm" style="white-space:pre-wrap"><?= sanitize($lesson['sources']) ?></div>
      </section>
      <?php endif; ?>

      <?php if ($adjacent['prev'] || $adjacent['next']): ?>
      <nav class="jhd-post-nav" aria-label="ناوبری درس‌ها">
        <?php if ($adjacent['prev']): ?><a href="<?= lessonUrl($adjacent['prev']) ?>" rel="prev"><i class="bi bi-arrow-right ms-1" aria-hidden="true"></i><small>درس قبلی</small><?= sanitize(mb_strimwidth($adjacent['prev']['title'], 0, 40, '...')) ?></a><?php else: ?><span></span><?php endif; ?>
        <?php if ($adjacent['next']): ?><a href="<?= lessonUrl($adjacent['next']) ?>" rel="next"><small>درس بعدی</small><?= sanitize(mb_strimwidth($adjacent['next']['title'], 0, 40, '...')) ?><i class="bi bi-arrow-left ms-1" aria-hidden="true"></i></a><?php endif; ?>
      </nav>
      <?php endif; ?>

      <section class="jhd-share">
        <span><i class="bi bi-share ms-1"></i> اشتراک‌گذاری:</span>
        <?php $lessonUrl = canonicalUrl(lessonUrl($lesson)); ?>
        <a class="jhd-icon-btn" href="https://t.me/share/url?url=<?= urlencode($lessonUrl) ?>&text=<?= urlencode($lesson['title']) ?>" target="_blank" rel="noopener" aria-label="اشتراک در تلگرام"><i class="bi bi-telegram"></i></a>
        <a class="jhd-icon-btn" href="https://wa.me/?text=<?= urlencode($lesson['title'] . ' - ' . $lessonUrl) ?>" target="_blank" rel="noopener" aria-label="اشتراک در واتساپ"><i class="bi bi-whatsapp"></i></a>
        <button type="button" class="jhd-icon-btn" data-copy-link="<?= htmlspecialchars($lessonUrl, ENT_QUOTES) ?>" aria-label="کپی لینک"><i class="bi bi-link-45deg"></i></button>
      </section>

      <?php if ($relatedLessons): ?>
      <section class="jhd-related-section" aria-labelledby="lesson-related-title">
        <div class="jhd-related-head">
          <div>
            <span class="jhd-eyebrow">ادامهٔ مسیر مطالعه</span>
            <h2 id="lesson-related-title">جستارهای وابسته به این درس</h2>
            <p>درس‌های دیگری که بر اساس موضوع یا مجموعهٔ آموزشی این درس، برای ادامهٔ مطالعه پیشنهاد می‌شوند.</p>
          </div>
          <?php if ($lessonTopics): ?>
          <a class="jhd-related-link" href="<?= sanitize(topicUrl($lessonTopics[0])) ?>">
            مشاهده موضوع
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
        </div>
        <div class="row g-3 jhd-related-grid">
          <?php foreach ($relatedLessons as $relatedLesson): ?>
            <?= renderLessonCard($relatedLesson, ['col' => 'col-12 col-md-6 col-xl-4', 'class' => 'jhd-related-card']) ?>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    </article>
   </div>

   <aside class="col-lg-4">
    <div class="d-grid gap-3">
      <?php if ($lesson['collection_id']): $vols = getLessonVolumes((int)$lesson['collection_id']); if ($vols): ?>
      <div class="jhd-side-card">
        <h3><i class="bi bi-collection" aria-hidden="true"></i> فهرست <?= sanitize($lesson['collection_title']) ?></h3>
        <?php foreach ($vols as $vol): $vLessons = getLessonsByCollection((int)$lesson['collection_id'], (int)$vol['id'], 100); ?>
        <div class="jhd-side-sub"><?= sanitize($vol['title']) ?></div>
        <ul class="jhd-side-list">
          <?php foreach ($vLessons as $vl): $isCurrent = $vl['id'] == $lesson['id']; ?>
          <li class="<?= $isCurrent ? 'is-current' : '' ?>"><a href="<?= lessonUrl($vl) ?>"><?= sanitize($vl['title']) ?><?php if ($vl['lesson_number']): ?> <small>— درس <?= (int)$vl['lesson_number'] ?></small><?php endif; ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php endforeach; ?>
      </div>
      <?php endif; endif; ?>

      <?php if ($lessonTopics): ?>
      <div class="jhd-side-card">
        <h3><i class="bi bi-tags" aria-hidden="true"></i> موضوعات این درس</h3>
        <div class="jhd-cat-strip">
          <?php foreach ($lessonTopics as $t): ?><a class="jhd-chip" href="<?= topicUrl($t) ?>"><?= sanitize($t['name']) ?></a><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($relatedLessons): ?>
      <div class="jhd-side-card">
        <h3><i class="bi bi-mortarboard" aria-hidden="true"></i> درس‌های مرتبط</h3>
        <ul class="jhd-side-list">
          <?php foreach ($relatedLessons as $rl): ?>
          <li><a href="<?= lessonUrl($rl) ?>"><?= sanitize($rl['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
   </aside>
  </div>
 </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
