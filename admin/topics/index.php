<?php
$pageTitle = 'مدیریت موضوعات علمی';
$adminTitle = 'مدیریت موضوعات علمی';
$adminSection = 'topics';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/storage.php';
require_once __DIR__ . '/../../includes/content-delete.php';
requireLogin();

$db = getDB();
$errors = [];

/** Does setting `topics.parent_id = $parentId` on `$topicId` introduce a cycle? */
function topicParentCreatesCycle(PDO $db, int $topicId, ?int $parentId): bool {
    if ($parentId === null || $parentId < 1) return false;
    if ($topicId > 0 && $parentId === $topicId) return true;
    $seen = $topicId > 0 ? [$topicId => true] : [];
    $cur = $parentId;
    $stmt = $db->prepare('SELECT parent_id FROM topics WHERE id = ?');
    for ($depth = 0; $cur > 0 && $depth < 50; $depth++) {
        if (isset($seen[$cur])) return true;
        $seen[$cur] = true;
        $stmt->execute([$cur]);
        $next = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($next === false) return true;
        $cur = (int)$next;
    }
    return $cur > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    beginContentUploadScope();
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'توکن امنیتی نامعتبر است.';
    } elseif (isset($_POST['toggle_active'])) {
        $tid = (int)$_POST['toggle_active'];
        $db->prepare('UPDATE topics SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$tid]);
        $_SESSION['flash_msg'] = 'وضعیت فعال‌بودن موضوع تغییر یافت.';
        $_SESSION['flash_type'] = 'success';
        redirect(adminUrl('topics'));
    } elseif (isset($_POST['toggle_featured'])) {
        $tid = (int)$_POST['toggle_featured'];
        $db->prepare('UPDATE topics SET is_featured = CASE WHEN is_featured = 1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$tid]);
        $_SESSION['flash_msg'] = 'وضعیت نمایش ویژه موضوع به‌روزرسانی شد.';
        $_SESSION['flash_type'] = 'success';
        redirect(adminUrl('topics'));
    } elseif (isset($_POST['reorder_topics']) && !empty($_POST['orders']) && is_array($_POST['orders'])) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE topics SET sort_order = ? WHERE id = ?');
            foreach ($_POST['orders'] as $tid => $ord) {
                $tid = (int)$tid;
                $ord = max(0, min(9999, (int)$ord));
                if ($tid > 0) $stmt->execute([$ord, $tid]);
            }
            $db->commit();
            $_SESSION['flash_msg'] = 'ترتیب نمایش موضوعات با موفقیت ذخیره شد.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_msg'] = 'ذخیره ترتیب موضوعات انجام نشد.';
            $_SESSION['flash_type'] = 'danger';
        }
        redirect(adminUrl('topics'));
    } elseif (isset($_POST['move_topic'])) {
        $tid = (int)$_POST['move_topic'];
        $dir = ($_POST['move_dir'] ?? 'up') === 'down' ? 1 : -1;
        $row = getTopicById($tid);
        if ($row) {
            $newOrder = max(0, (int)($row['sort_order'] ?? 0) + $dir);
            $db->prepare('UPDATE topics SET sort_order = ? WHERE id = ?')->execute([$newOrder, $tid]);
            $_SESSION['flash_msg'] = 'جایگاه موضوع تغییر کرد.';
            $_SESSION['flash_type'] = 'success';
        }
        redirect(adminUrl('topics'));
    } elseif (isset($_POST['merge_topic'])) {
        $sourceId = (int)($_POST['source_topic_id'] ?? 0);
        $targetId = (int)($_POST['target_topic_id'] ?? 0);
        $deleteSource = !empty($_POST['delete_source_after_merge']);
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId) {
            $_SESSION['flash_msg'] = 'برای ادغام، موضوع مبدأ و موضوع مقصد باید متفاوت و معتبر باشند.';
            $_SESSION['flash_type'] = 'danger';
            redirect(adminUrl('topics'));
        }
        $res = jhd_transfer_or_unlink_topic_relations($sourceId, 'transfer', $targetId);
        if (empty($res['ok'])) {
            $_SESSION['flash_msg'] = (string)($res['error'] ?? 'ادغام موضوع انجام نشد.');
            $_SESSION['flash_type'] = 'danger';
            redirect(adminUrl('topics'));
        }
        if ($deleteSource) {
            $oldCover = $db->prepare('SELECT cover_image FROM topics WHERE id = ?');
            $oldCover->execute([$sourceId]);
            $coverPath = (string)$oldCover->fetchColumn();
            $db->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['topic_display_' . $sourceId]);
            $db->prepare('DELETE FROM topics WHERE id = ?')->execute([$sourceId]);
            if ($coverPath !== '') scheduleFileDeletion($coverPath);
            clearSettingCache();
            $_SESSION['flash_msg'] = 'همهٔ محتواها به موضوع مقصد منتقل شد و موضوع تکراری حذف گردید.';
        } else {
            $db->prepare('UPDATE topics SET is_active = 0 WHERE id = ?')->execute([$sourceId]);
            $_SESSION['flash_msg'] = 'همهٔ محتواها به موضوع مقصد منتقل شد و موضوع مبدأ غیرفعال گردید.';
        }
        $_SESSION['flash_type'] = 'success';
        redirect(adminUrl('topics'));
    } elseif (isset($_POST['quick_link_content'])) {
        $tid = (int)($_POST['topic_id'] ?? 0);
        if ($tid > 0 && getTopicById($tid)) {
            $added = 0;
            foreach (jhd_parse_id_list($_POST['link_post_ids'] ?? []) as $pid) {
                try {
                    $db->prepare('INSERT INTO post_topics (post_id, topic_id, is_primary) VALUES (?, ?, 0) ON CONFLICT DO NOTHING')->execute([$pid, $tid]);
                } catch (PDOException) {
                    $db->prepare('INSERT INTO post_topics (post_id, topic_id) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$pid, $tid]);
                }
                $added++;
            }
            foreach (jhd_parse_id_list($_POST['link_book_ids'] ?? []) as $bid) {
                $db->prepare('INSERT INTO book_topics (book_id, topic_id) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$bid, $tid]);
                $added++;
            }
            foreach (jhd_parse_id_list($_POST['link_lesson_ids'] ?? []) as $lid) {
                $db->prepare('INSERT INTO lesson_topics (lesson_id, topic_id) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$lid, $tid]);
                $added++;
            }
            $_SESSION['flash_msg'] = $added > 0 ? 'محتواهای انتخابی بدون ایجاد رکورد تکراری به موضوع متصل شدند.' : 'موردی برای اتصال انتخاب نشده بود.';
            $_SESSION['flash_type'] = $added > 0 ? 'success' : 'warning';
        }
        redirect(adminUrl('topics', ['edit' => $tid]));
    } elseif (isset($_POST['quick_unlink_content'])) {
        $tid = (int)($_POST['topic_id'] ?? 0);
        $entity = (string)($_POST['entity_type'] ?? '');
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($tid > 0 && $itemId > 0) {
            if ($entity === 'post') {
                $db->prepare('DELETE FROM post_topics WHERE topic_id = ? AND post_id = ?')->execute([$tid, $itemId]);
            } elseif ($entity === 'book') {
                $db->prepare('DELETE FROM book_topics WHERE topic_id = ? AND book_id = ?')->execute([$tid, $itemId]);
            } elseif ($entity === 'lesson') {
                $db->prepare('DELETE FROM lesson_topics WHERE topic_id = ? AND lesson_id = ?')->execute([$tid, $itemId]);
            }
            $_SESSION['flash_msg'] = 'ارتباط محتوا با این موضوع قطع شد (خود محتوا دست‌نخورده باقی ماند).';
            $_SESSION['flash_type'] = 'success';
        }
        redirect(adminUrl('topics', ['edit' => $tid]));
    } elseif (isset($_POST['delete'])) {
        $delId = (int)$_POST['delete'];
        $linked = getTopicLinkedCounts($delId);
        $hasRelations = ($linked['total'] > 0 || $linked['children_count'] > 0);
        $confirmedStrategy = !empty($_POST['confirm_delete_relations']);

        if ($hasRelations && !$confirmedStrategy) {
            $_SESSION['flash_msg'] = 'این موضوع دارای ' . jhd_persian_digits($linked['total']) . ' محتوای متصل و ' . jhd_persian_digits($linked['children_count']) . ' زیرموضوع است. لطفاً پیش از حذف، نحوهٔ مدیریت محتواهای متصل (انتقال به موضوع دیگر یا قطع ارتباط بدون حذف محتوا) را مشخص کنید.';
            $_SESSION['flash_type'] = 'warning';
            redirect(adminUrl('topics', ['confirm_delete' => $delId]));
        }

        $oldCover = $db->prepare('SELECT cover_image FROM topics WHERE id = ?');
        $oldCover->execute([$delId]);
        $coverPath = (string)$oldCover->fetchColumn();
        $oldCover->closeCursor();

        $db->beginTransaction();
        try {
            if ($hasRelations) {
                $strategy = (string)($_POST['delete_strategy'] ?? 'unlink');
                $targetTopicId = (int)($_POST['target_topic_id'] ?? 0);
                $relRes = jhd_transfer_or_unlink_topic_relations($delId, $strategy, $targetTopicId);
                if (empty($relRes['ok'])) {
                    throw new RuntimeException((string)($relRes['error'] ?? 'مدیریت ارتباط‌های موضوع انجام نشد.'));
                }
            }
            $db->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['topic_display_' . $delId]);
            $db->prepare('DELETE FROM topics WHERE id = ?')->execute([$delId]);
            if ($coverPath !== '' && !scheduleFileDeletion($coverPath)) {
                throw new RuntimeException('Cannot queue cover cleanup.');
            }
            $db->commit();
            clearSettingCache();
            $_SESSION['flash_msg'] = 'موضوع با موفقیت حذف شد و محتواهای سایت بدون آسیب حفظ شدند.';
            $_SESSION['flash_type'] = 'success';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_msg'] = $e->getMessage() ?: 'حذف موضوع انجام نشد.';
            $_SESSION['flash_type'] = 'danger';
        }
        redirect(adminUrl('topics'));
    } else {
        $id          = (int)($_POST['edit_id'] ?? $_POST['id'] ?? 0);
        $name        = trim((string)($_POST['name'] ?? ''));
        $customSlug  = trim((string)($_POST['slug'] ?? ''));
        $parentId    = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
        $description = trim((string)($_POST['description'] ?? ''));
        $intro       = trim((string)($_POST['intro'] ?? ''));
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);
        $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;
        $isActive    = isset($_POST['is_active']) ? 1 : 0;
        $visibility  = (string)($_POST['visibility'] ?? ($isActive ? 'public' : 'inactive'));
        if ($visibility === 'inactive') {
            $isActive = 0;
        }

        if ($name === '') $errors[] = 'نام موضوع الزامی است.';
        if ($parentId !== null && topicParentCreatesCycle($db, $id, $parentId)) {
            $errors[] = 'موضوع والد انتخاب‌شده معتبر نیست (ایجاد حلقه در درخت موضوعات).';
        }

        if (empty($errors)) {
            $slug = $customSlug !== '' ? uniqueSlug('topics', $customSlug, $id) : uniqueSlug('topics', $name, $id);
            $oldCover = '';
            if ($id > 0) {
                $prev = $db->prepare('SELECT cover_image FROM topics WHERE id = ?');
                $prev->execute([$id]);
                $oldCover = (string)$prev->fetchColumn();
                $prev->closeCursor();
            }
            $cover = $oldCover;
            if (!empty($_POST['remove_cover_image'])) {
                $cover = '';
            }
            $directCover = jhdDirectUploadPath('cover_image');
            if ($directCover === '') {
                $directCover = trim((string)($_POST['cover_image__direct'] ?? ''));
            }
            $hasCoverUpload = $directCover !== '' || !empty($_FILES['cover_image']['name']);

            $adoptedCover = '';
            try {
                if ($hasCoverUpload) {
                    $folder = $id > 0 ? contentStorageFolder('topic', $id) : 'topics';
                    $adoptedCover = $directCover !== ''
                        ? adoptDirectUpload($directCover, 'image', $folder)
                        : uploadImage($_FILES['cover_image'], $folder);
                    if ($adoptedCover === '') throw new RuntimeException('تصویر کاور معتبر نیست یا ذخیره نشد.');
                    $cover = $adoptedCover;
                }

                $db->beginTransaction();
                if ($id > 0) {
                    $db->prepare('UPDATE topics SET parent_id=?, name=?, slug=?, description=?, intro=?, cover_image=?, sort_order=?, is_featured=?, is_active=? WHERE id=?')
                       ->execute([$parentId, $name, $slug, $description ?: null, $intro ?: null, $cover ?: null, $sortOrder, $isFeatured, $isActive, $id]);
                    if (($adoptedCover !== '' || !empty($_POST['remove_cover_image'])) && $oldCover !== '' && $oldCover !== $cover) {
                        if (!scheduleFileDeletion($oldCover)) throw new RuntimeException('Cannot queue old cover cleanup.');
                    }
                } else {
                    $ins = $db->prepare('INSERT INTO topics (parent_id, name, slug, description, intro, cover_image, sort_order, is_featured, is_active) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
                    $ins->execute([$parentId, $name, $slug, $description ?: null, $intro ?: null, $cover ?: null, $sortOrder, $isFeatured, $isActive]);
                    $id = (int)$ins->fetchColumn();
                    $ins->closeCursor();
                }

                $displayInput = [
                    'mode'              => (string)($_POST['display_mode'] ?? 'featured'),
                    'limit'             => (int)($_POST['content_limit'] ?? 12),
                    'sort'              => (string)($_POST['content_sort'] ?? 'newest'),
                    'visibility'        => $visibility,
                    'show_media'        => (string)($_POST['show_media'] ?? '1') !== '0' ? 1 : 0,
                    'show_excerpt'      => (string)($_POST['show_excerpt'] ?? '1') !== '0' ? 1 : 0,
                    'show_date'         => (string)($_POST['show_date'] ?? '1') !== '0' ? 1 : 0,
                    'show_meta'         => (string)($_POST['show_meta'] ?? '1') !== '0' ? 1 : 0,
                    'empty_behavior'    => (string)($_POST['empty_behavior'] ?? 'message'),
                    'empty_message'     => trim((string)($_POST['empty_message'] ?? '')),
                    'section_order'     => $_POST['section_order'] ?? 'posts,books,lessons,media',
                    'related_topic_ids' => $_POST['related_topic_ids'] ?? [],
                    'manual_post_ids'   => $_POST['manual_post_ids'] ?? '',
                    'manual_book_ids'   => $_POST['manual_book_ids'] ?? '',
                    'manual_lesson_ids' => $_POST['manual_lesson_ids'] ?? '',
                ];
                if (!empty($_POST['placements_present'])) {
                    $displayInput['placements'] = $_POST['placements'] ?? [];
                }
                jhd_save_topic_display($id, $displayInput);

                $db->commit();
                clearSettingCache();
                $_SESSION['flash_msg'] = 'موضوع و تنظیمات نمایش آن با موفقیت ذخیره شد.';
                $_SESSION['flash_type'] = 'success';
                redirect(adminUrl('topics'));
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($adoptedCover !== '') scheduleFileDeletion($adoptedCover);
                $errors[] = $e->getMessage() ?: 'ذخیرهٔ موضوع انجام نشد.';
            }
        }
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $editing = getTopicById((int)$_GET['edit']);
}
$confirmDeleteTopic = null;
$confirmDeleteCounts = null;
if (isset($_GET['confirm_delete'])) {
    $confirmDeleteTopic = getTopicById((int)$_GET['confirm_delete']);
    if ($confirmDeleteTopic) {
        $confirmDeleteCounts = getTopicLinkedCounts((int)$confirmDeleteTopic['id']);
    }
}

$allTopics = getTopics();
$countsById = [];
foreach ($allTopics as $t) {
    $tid = (int)$t['id'];
    $countsById[$tid] = getTopicLinkedCounts($tid);
}

// شناسایی موضوعات خالی و موضوعات دارای عنوان مشابه (تکراری)
$emptyTopics = [];
$normNameMap = [];
foreach ($allTopics as $t) {
    $tid = (int)$t['id'];
    $c = $countsById[$tid];
    if ($c['total'] === 0 && $c['children_count'] === 0) {
        $emptyTopics[] = $t;
    }
    $normKey = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', (string)$t['name'])), 'UTF-8');
    $normNameMap[$normKey][] = $t;
}
$duplicateGroups = array_filter($normNameMap, static fn(array $grp): bool => count($grp) > 1);

// فیلتر، جستجو و صفحه‌بندی جدول موضوعات
$searchQuery = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$parentFilter = trim((string)($_GET['parent'] ?? ''));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$flatTree = getTopicFlatTree(null, 6);
// اگر موضوعی به دلیل والد ناموجود در درخت نیامده باشد، در انتها اضافه شود
$inTreeIds = array_column($flatTree, 'id');
foreach ($allTopics as $t) {
    if (!in_array($t['id'], $inTreeIds, true)) {
        $t['depth'] = 0;
        $flatTree[] = $t;
    }
}

$filteredTopics = array_values(array_filter($flatTree, static function (array $t) use ($searchQuery, $statusFilter, $parentFilter, $countsById, $normNameMap): bool {
    $tid = (int)$t['id'];
    $c = $countsById[$tid] ?? ['total' => 0, 'children_count' => 0];
    if ($searchQuery !== '') {
        $haystack = mb_strtolower((string)($t['name'] ?? '') . ' ' . (string)($t['slug'] ?? '') . ' ' . (string)($t['description'] ?? ''), 'UTF-8');
        if (!str_contains($haystack, mb_strtolower($searchQuery, 'UTF-8'))) return false;
    }
    if ($parentFilter !== '') {
        if ($parentFilter === 'root' && !empty($t['parent_id'])) return false;
        if (ctype_digit($parentFilter) && (int)($t['parent_id'] ?? 0) !== (int)$parentFilter) return false;
    }
    if ($statusFilter === 'active' && (int)$t['is_active'] !== 1) return false;
    if ($statusFilter === 'inactive' && (int)$t['is_active'] !== 0) return false;
    if ($statusFilter === 'featured' && (int)$t['is_featured'] !== 1) return false;
    if ($statusFilter === 'empty' && ($c['total'] > 0 || $c['children_count'] > 0)) return false;
    if ($statusFilter === 'non_empty' && $c['total'] === 0) return false;
    if ($statusFilter === 'duplicate') {
        $normKey = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', (string)$t['name'])), 'UTF-8');
        if (count($normNameMap[$normKey] ?? []) < 2) return false;
    }
    return true;
}));

$totalFiltered = count($filteredTopics);
$pagedTopics = array_slice($filteredTopics, ($currentPage - 1) * $perPage, $perPage);

$editDisplay = $editing ? jhd_topic_display((int)$editing['id']) : jhd_topic_display(0);
$placementLabels = jhd_topic_placement_labels();
$displayModes = [
    'featured'   => 'شاخص (آیتم بزرگ + شبکه فرعی)',
    'rows'       => 'ردیف‌های فشرده',
    'grid'       => 'شبکه‌ای (کارتی ۳ ستونی)',
    'list'       => 'لیستی (تک‌ستونی)',
    'horizontal' => 'افقی (رسانه در کنار متن)',
    'video'      => 'ویدیویی (قاب رسانه شاخص)',
    'slider'     => 'اسلایدر / کاروسل افقی',
    'minimal'    => 'فشرده (فقط عنوان و لینک)',
    'combined'   => 'ترکیبی (شاخص در کنار فهرست فرعی)',
];

// برای بخش اتصال سریع محتوا در حالت ویرایش
$linkedPosts = [];
$linkedBooks = [];
$linkedLessons = [];
$candidatePosts = [];
$candidateBooks = [];
$candidateLessons = [];
if ($editing) {
    $editId = (int)$editing['id'];
    $stmt = $db->prepare('SELECT p.id, p.title, p.post_type, p.status, pt.is_primary FROM posts p JOIN post_topics pt ON pt.post_id = p.id WHERE pt.topic_id = ? ORDER BY p.id DESC');
    $stmt->execute([$editId]);
    $linkedPosts = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT b.id, b.title, b.status FROM books b JOIN book_topics bt ON bt.book_id = b.id WHERE bt.topic_id = ? ORDER BY b.id DESC');
    $stmt->execute([$editId]);
    $linkedBooks = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT l.id, l.title, l.status FROM lessons l JOIN lesson_topics lt ON lt.lesson_id = l.id WHERE lt.topic_id = ? ORDER BY l.id DESC');
    $stmt->execute([$editId]);
    $linkedLessons = $stmt->fetchAll();

    $candidatePosts = $db->query('SELECT id, title, post_type, status FROM posts ORDER BY id DESC LIMIT 150')->fetchAll();
    $candidateBooks = $db->query('SELECT id, title, status FROM books ORDER BY id DESC LIMIT 100')->fetchAll();
    $candidateLessons = $db->query('SELECT id, title, status FROM lessons ORDER BY id DESC LIMIT 100')->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<!-- نوار خلاصه وضعیت موضوعات علمی -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="admin-card p-3 h-100 d-flex align-items-center justify-content-between">
      <div>
        <div class="text-muted small">کل موضوعات علمی</div>
        <div class="fs-4 fw-bold"><?= jhd_persian_digits(count($allTopics)) ?></div>
      </div>
      <i class="bi bi-diagram-3 fs-3 text-primary"></i>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="admin-card p-3 h-100 d-flex align-items-center justify-content-between">
      <div>
        <div class="text-muted small">موضوعات فعال عمومی</div>
        <div class="fs-4 fw-bold text-success"><?= jhd_persian_digits(count(array_filter($allTopics, static fn($t) => (int)$t['is_active'] === 1))) ?></div>
      </div>
      <i class="bi bi-check2-circle fs-3 text-success"></i>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="admin-card p-3 h-100 d-flex align-items-center justify-content-between">
      <div>
        <div class="text-muted small">موضوعات بدون محتوا (خالی)</div>
        <div class="fs-4 fw-bold <?= $emptyTopics ? 'text-warning' : 'text-muted' ?>"><?= jhd_persian_digits(count($emptyTopics)) ?></div>
      </div>
      <a href="<?= sanitize(adminUrl('topics', ['status' => 'empty'])) ?>" class="btn btn-sm btn-outline-secondary">مشاهده</a>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="admin-card p-3 h-100 d-flex align-items-center justify-content-between">
      <div>
        <div class="text-muted small">عناوین مشابه / تکراری</div>
        <div class="fs-4 fw-bold <?= $duplicateGroups ? 'text-danger' : 'text-muted' ?>"><?= jhd_persian_digits(count($duplicateGroups)) ?></div>
      </div>
      <a href="<?= sanitize(adminUrl('topics', ['status' => 'duplicate'])) ?>" class="btn btn-sm btn-outline-secondary">بررسی</a>
    </div>
  </div>
</div>

<?php if ($confirmDeleteTopic && $confirmDeleteCounts): ?>
<!-- کادر تأیید امن حذف موضوع دارای محتوا -->
<div class="admin-card border border-warning mb-4" id="topic-delete-confirm-box" style="background:#fffdf5">
  <div class="admin-card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
    <span class="fw-bold text-dark"><i class="bi bi-exclamation-triangle-fill text-warning ms-2"></i>تأیید حذف موضوع علمی: «<?= sanitize($confirmDeleteTopic['name']) ?>»</span>
    <a href="<?= sanitize(adminUrl('topics')) ?>" class="btn btn-sm btn-outline-secondary">انصراف</a>
  </div>
  <div class="p-3">
    <p class="mb-2">
      این موضوع پیش از حذف دارای ارتباط‌های زیر در دیتابیس است.
      <strong>توجه مهم: حذف موضوع در هیچ حالتی باعث حذف خود مقالات، کتاب‌ها، درس‌ها، ویدیوها یا فایل‌های سایت نمی‌شود.</strong>
    </p>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <span class="badge bg-primary">مطالب و مقالات متصل: <?= jhd_persian_digits($confirmDeleteCounts['posts_total']) ?></span>
      <span class="badge bg-success">کتاب‌های متصل: <?= jhd_persian_digits($confirmDeleteCounts['books_total']) ?></span>
      <span class="badge bg-info text-dark">درس‌های متصل: <?= jhd_persian_digits($confirmDeleteCounts['lessons_total']) ?></span>
      <span class="badge bg-secondary">زیرموضوع‌های وابسته: <?= jhd_persian_digits($confirmDeleteCounts['children_count']) ?></span>
      <span class="badge bg-dark">رسانه‌های مرتبط: <?= jhd_persian_digits($confirmDeleteCounts['media_count']) ?></span>
    </div>

    <form method="POST" action="<?= sanitize(adminUrl('topics')) ?>" class="row g-3 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="delete" value="<?= (int)$confirmDeleteTopic['id'] ?>">
      <input type="hidden" name="confirm_delete_relations" value="1">

      <div class="col-12 col-md-5">
        <label class="form-label fw-bold">نحوهٔ مدیریت محتواهای متصل پیش از حذف موضوع:</label>
        <select name="delete_strategy" id="deleteStrategySelect" class="form-select" onchange="document.getElementById('targetTopicWrap').style.display = this.value === 'transfer' ? '' : 'none';">
          <option value="transfer">انتقال همهٔ محتواها و زیرموضوع‌ها به یک موضوع دیگر (پیشنهادی)</option>
          <option value="unlink">حذف ارتباط موضوع بدون حذف محتواها (قطع اتصال امن)</option>
        </select>
      </div>

      <div class="col-12 col-md-4" id="targetTopicWrap">
        <label class="form-label fw-bold">موضوع مقصد برای انتقال:</label>
        <select name="target_topic_id" class="form-select">
          <option value="">— انتخاب موضوع مقصد —</option>
          <?php foreach ($allTopics as $cand): if ((int)$cand['id'] === (int)$confirmDeleteTopic['id']) continue; ?>
            <option value="<?= (int)$cand['id'] ?>"><?= sanitize($cand['name']) ?> (<?= sanitize($cand['slug']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-danger flex-grow-1"><i class="bi bi-trash ms-1"></i>تأیید و حذف نهایی موضوع</button>
        <a href="<?= sanitize(adminUrl('topics')) ?>" class="btn btn-outline-secondary">لغو</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- ستون فرم ایجاد / ویرایش کامل موضوع علمی -->
  <div class="col-lg-5">
    <div class="admin-card">
      <div class="admin-card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-2"></i><?= $editing ? 'ویرایش موضوع: ' . sanitize($editing['name']) : 'افزودن موضوع علمی جدید' ?></span>
        <?php if ($editing): ?>
          <a href="<?= sanitize(topicUrl($editing)) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="مشاهده صفحه اختصاصی موضوع در سایت">
            <i class="bi bi-box-arrow-up-right ms-1"></i>پیش‌نمایش صفحه
          </a>
        <?php endif; ?>
      </div>
      <div class="p-3">
        <?php foreach ($errors as $e): ?>
          <div class="alert alert-danger py-2"><?= sanitize($e) ?></div>
        <?php endforeach; ?>

        <form method="POST" enctype="multipart/form-data" class="admin-form">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label fw-bold">نام موضوع علمی *</label>
            <input type="text" name="name" class="form-control" required value="<?= sanitize($_POST['name'] ?? ($editing['name'] ?? '')) ?>" placeholder="مثلاً: فقه، اصول فقه، مهدویت...">
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-7">
              <label class="form-label">اسلاگ (نشانی یکتا در URL)</label>
              <input type="text" name="slug" class="form-control" dir="ltr" value="<?= sanitize($_POST['slug'] ?? ($editing['slug'] ?? '')) ?>" placeholder="خالی = تولید خودکار از نام">
            </div>
            <div class="col-md-5">
              <label class="form-label">ترتیب نمایش</label>
              <input type="number" name="sort_order" class="form-control" value="<?= (int)($_POST['sort_order'] ?? ($editing['sort_order'] ?? 0)) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">موضوع والد (برای ساختار درختی)</label>
            <select name="parent_id" class="form-select">
              <option value="">— بدون والد (موضوع اصلی سطح اول) —</option>
              <?php foreach ($flatTree as $t): if ($editing && (int)$t['id'] === (int)$editing['id']) continue; ?>
                <option value="<?= (int)$t['id'] ?>" <?= ((int)($_POST['parent_id'] ?? ($editing['parent_id'] ?? 0)) === (int)$t['id']) ? 'selected' : '' ?>>
                  <?= str_repeat('— ', (int)($t['depth'] ?? 0)) . sanitize($t['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">توضیح کوتاه (نمایش در کارت‌ها و فهرست موضوعات)</label>
            <input type="text" name="description" class="form-control" value="<?= sanitize($_POST['description'] ?? ($editing['description'] ?? '')) ?>" placeholder="یک جمله در معرفی کوتاه موضوع">
          </div>

          <div class="mb-3">
            <label class="form-label">متن معرفی علمی (نمایش در بالای صفحه اختصاصی موضوع)</label>
            <textarea name="intro" class="form-control" rows="3" placeholder="معرفی کامل‌تر حوزه علمی، منابع و محورهای این موضوع..."><?= sanitize($_POST['intro'] ?? ($editing['intro'] ?? '')) ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">تصویر کاور موضوع</label>
            <?php if (!empty($editing['cover_image'])): ?>
              <div class="mb-2 d-flex align-items-center gap-3">
                <img src="<?= sanitize(imgUrl((string)$editing['cover_image'])) ?>" style="max-height:70px;border-radius:8px;border:1px solid #dee2e6" alt="">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="remove_cover_image" value="1" id="rmTopicCover">
                  <label class="form-check-label small text-danger" for="rmTopicCover">حذف تصویر کاور فعلی</label>
                </div>
              </div>
            <?php endif; ?>
            <input type="file" name="cover_image" class="form-control" accept="image/*" data-direct-upload="image">
          </div>

          <hr class="my-3">
          <h6 class="fw-bold mb-2"><i class="bi bi-Sliders ms-1"></i>تنظیمات انتشار و محل نمایش موضوع</h6>

          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small">وضعیت انتشار عمومی</label>
              <?php $curVis = (string)($_POST['visibility'] ?? ($editing && (int)$editing['is_active'] === 0 ? 'inactive' : ($editDisplay['visibility'] ?? 'public'))); ?>
              <select name="visibility" class="form-select form-select-sm">
                <option value="public" <?= $curVis === 'public' ? 'selected' : '' ?>>عمومی (فعال در سایت و فهرست‌ها)</option>
                <option value="hidden_lists" <?= $curVis === 'hidden_lists' ? 'selected' : '' ?>>صفحه مستقیم فعال، پنهان در فهرست‌ها</option>
                <option value="inactive" <?= $curVis === 'inactive' ? 'selected' : '' ?>>غیرفعال (عدم نمایش عمومی)</option>
              </select>
            </div>
            <div class="col-md-6 d-flex flex-column justify-content-end gap-1">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= (!$editing || !empty($editing['is_active'])) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="isActive">موضوع فعال است</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_featured" id="isFeat" <?= !empty($editing['is_featured']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="isFeat">نمایش در موضوعات منتخب صفحه اصلی</label>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold">محل‌های نمایش این موضوع در سایت:</label>
            <input type="hidden" name="placements_present" value="1">
            <div class="row g-1">
              <?php
              $selectedPlacements = $editDisplay['placements'] ?? ['home', 'topics_archive', 'articles', 'books', 'lessons'];
              foreach ($placementLabels as $plKey => $plLabel):
              ?>
                <div class="col-12 col-sm-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="placements[]" value="<?= sanitize($plKey) ?>" id="pl_<?= sanitize($plKey) ?>" <?= in_array($plKey, $selectedPlacements, true) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="pl_<?= sanitize($plKey) ?>"><?= sanitize($plLabel) ?></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <hr class="my-3">
          <h6 class="fw-bold mb-2"><i class="bi bi-layout-wtf ms-1"></i>نحوه نمایش محتواهای داخل صفحه موضوع</h6>

          <div class="row g-2 mb-3">
            <div class="col-md-5">
              <label class="form-label small">حالت نمایش کارت‌ها</label>
              <select name="display_mode" class="form-select form-select-sm">
                <?php foreach ($displayModes as $mKey => $mLabel): ?>
                  <option value="<?= sanitize($mKey) ?>" <?= ($editDisplay['mode'] ?? 'featured') === $mKey ? 'selected' : '' ?>><?= sanitize($mLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small">ترتیب نمایش محتوا</label>
              <select name="content_sort" class="form-select form-select-sm">
                <?php
                $sortOpts = ['newest' => 'جدیدترین', 'oldest' => 'قدیمی‌ترین', 'popular' => 'پربازدیدترین', 'featured' => 'شاخص‌ها اول', 'manual' => 'ترتیب دستی'];
                foreach ($sortOpts as $sKey => $sLabel):
                ?>
                  <option value="<?= sanitize($sKey) ?>" <?= ($editDisplay['sort'] ?? 'newest') === $sKey ? 'selected' : '' ?>><?= sanitize($sLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small">تعداد در هر بخش</label>
              <input type="number" name="content_limit" class="form-control form-control-sm" min="1" max="24" value="<?= (int)($editDisplay['limit'] ?? 12) ?>">
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6 col-sm-3">
              <label class="form-label small">تصویر/ویدیو</label>
              <select name="show_media" class="form-select form-select-sm">
                <option value="1" <?= !empty($editDisplay['show_media']) ? 'selected' : '' ?>>نمایش</option>
                <option value="0" <?= empty($editDisplay['show_media']) ? 'selected' : '' ?>>پنهان</option>
              </select>
            </div>
            <div class="col-6 col-sm-3">
              <label class="form-label small">خلاصه متن</label>
              <select name="show_excerpt" class="form-select form-select-sm">
                <option value="1" <?= !empty($editDisplay['show_excerpt']) ? 'selected' : '' ?>>نمایش</option>
                <option value="0" <?= empty($editDisplay['show_excerpt']) ? 'selected' : '' ?>>پنهان</option>
              </select>
            </div>
            <div class="col-6 col-sm-3">
              <label class="form-label small">تاریخ انتشار</label>
              <select name="show_date" class="form-select form-select-sm">
                <option value="1" <?= !empty($editDisplay['show_date']) ? 'selected' : '' ?>>نمایش</option>
                <option value="0" <?= empty($editDisplay['show_date']) ? 'selected' : '' ?>>پنهان</option>
              </select>
            </div>
            <div class="col-6 col-sm-3">
              <label class="form-label small">نویسنده/متا</label>
              <select name="show_meta" class="form-select form-select-sm">
                <option value="1" <?= !empty($editDisplay['show_meta']) ? 'selected' : '' ?>>نمایش</option>
                <option value="0" <?= empty($editDisplay['show_meta']) ? 'selected' : '' ?>>پنهان</option>
              </select>
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small">ترتیب بخش‌های داخل صفحه موضوع</label>
              <select name="section_order" class="form-select form-select-sm">
                <?php
                $curSecOrder = implode(',', $editDisplay['section_order'] ?? ['posts', 'books', 'lessons', 'media']);
                $secOrderOptions = [
                    'posts,books,lessons,media' => 'مطالب ← کتاب‌ها ← درس‌ها ← رسانه‌ها',
                    'lessons,books,posts,media' => 'درس‌ها ← کتاب‌ها ← مطالب ← رسانه‌ها',
                    'books,posts,lessons,media' => 'کتاب‌ها ← مطالب ← درس‌ها ← رسانه‌ها',
                    'media,posts,lessons,books' => 'رسانه‌ها ← مطالب ← درس‌ها ← کتاب‌ها',
                ];
                foreach ($secOrderOptions as $soVal => $soLabel):
                ?>
                  <option value="<?= sanitize($soVal) ?>" <?= $curSecOrder === $soVal ? 'selected' : '' ?>><?= sanitize($soLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">رفتار در صورت خالی بودن موضوع</label>
              <select name="empty_behavior" class="form-select form-select-sm">
                <option value="message" <?= ($editDisplay['empty_behavior'] ?? 'message') === 'message' ? 'selected' : '' ?>>نمایش پیام استاندارد «محتوایی یافت نشد»</option>
                <option value="hide_from_home" <?= ($editDisplay['empty_behavior'] ?? '') === 'hide_from_home' ? 'selected' : '' ?>>پنهان‌شدن خودکار از صفحه اصلی تا زمان افزودن محتوا</option>
                <option value="custom_message" <?= ($editDisplay['empty_behavior'] ?? '') === 'custom_message' ? 'selected' : '' ?>>نمایش پیام راهنمای سفارشی در صفحه موضوع</option>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small">پیام تکمیلی حالت خالی (اختیاری)</label>
            <input type="text" name="empty_message" class="form-control form-control-sm" value="<?= sanitize($editDisplay['empty_message'] ?? '') ?>" placeholder="مثلاً: به‌زودی مقالات و دروس این محور بارگذاری می‌شود.">
          </div>

          <div class="mb-3">
            <label class="form-label small">موضوعات علمی مرتبط (برای پیوند متقابل در پایین صفحه موضوع)</label>
            <select name="related_topic_ids[]" class="form-select form-select-sm" multiple size="3">
              <?php
              $relIds = $editDisplay['related_topic_ids'] ?? [];
              foreach ($allTopics as $rt):
                  if ($editing && (int)$rt['id'] === (int)$editing['id']) continue;
              ?>
                <option value="<?= (int)$rt['id'] ?>" <?= in_array((int)$rt['id'], $relIds, true) ? 'selected' : '' ?>><?= sanitize($rt['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">برای انتخاب چند مورد کلید Ctrl (یا Cmd) را نگه دارید.</div>
          </div>

          <details class="mb-3">
            <summary class="small text-muted" style="cursor:pointer">تنظیم ترتیب دستی شناسه‌ها (ویژه حالت مرتب‌سازی دستی)</summary>
            <div class="pt-2 row g-2">
              <div class="col-12">
                <label class="form-label small mb-1">شناسه‌های مطالب به ترتیب دلخواه (با ویرگول، مثلاً 12,5,8)</label>
                <input type="text" name="manual_post_ids" dir="ltr" class="form-control form-control-sm" value="<?= sanitize(implode(',', $editDisplay['manual_post_ids'] ?? [])) ?>">
              </div>
              <div class="col-6">
                <label class="form-label small mb-1">شناسه‌های کتاب‌ها</label>
                <input type="text" name="manual_book_ids" dir="ltr" class="form-control form-control-sm" value="<?= sanitize(implode(',', $editDisplay['manual_book_ids'] ?? [])) ?>">
              </div>
              <div class="col-6">
                <label class="form-label small mb-1">شناسه‌های درس‌ها</label>
                <input type="text" name="manual_lesson_ids" dir="ltr" class="form-control form-control-sm" value="<?= sanitize(implode(',', $editDisplay['manual_lesson_ids'] ?? [])) ?>">
              </div>
            </div>
          </details>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-check-lg ms-1"></i><?= $editing ? 'ذخیره تغییرات موضوع' : 'ثبت موضوع جدید' ?></button>
            <?php if ($editing): ?>
              <a href="<?= sanitize(adminUrl('topics')) ?>" class="btn btn-outline-secondary">انصراف</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <?php if ($editing): ?>
    <!-- مدیریت سریع اتصال محتوا به این موضوع بدون ترک صفحه -->
    <div class="admin-card mt-4" id="topic-content-manager">
      <div class="admin-card-header">
        <span><i class="bi bi-link-45deg me-1"></i>مدیریت محتواهای متصل به «<?= sanitize($editing['name']) ?>»</span>
      </div>
      <div class="p-3">
        <form method="POST" action="<?= sanitize(adminUrl('topics')) ?>" class="mb-3">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="quick_link_content" value="1">
          <input type="hidden" name="topic_id" value="<?= (int)$editing['id'] ?>">

          <div class="mb-2">
            <label class="form-label small fw-bold">افزودن مقالات، اخبار و مطالب موجود به این موضوع:</label>
            <select name="link_post_ids[]" class="form-select form-select-sm" multiple size="4">
              <?php
              $linkedPostIds = array_map('intval', array_column($linkedPosts, 'id'));
              foreach ($candidatePosts as $cp):
                  if (in_array((int)$cp['id'], $linkedPostIds, true)) continue;
              ?>
                <option value="<?= (int)$cp['id'] ?>">[<?= sanitize(postTypeLabel((string)$cp['post_type'])) ?> #<?= (int)$cp['id'] ?>] <?= sanitize($cp['title']) ?><?= $cp['status'] !== 'published' ? ' (پیش‌نویس)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label class="form-label small fw-bold">افزودن کتاب‌ها:</label>
              <select name="link_book_ids[]" class="form-select form-select-sm" multiple size="3">
                <?php
                $linkedBookIds = array_map('intval', array_column($linkedBooks, 'id'));
                foreach ($candidateBooks as $cb):
                    if (in_array((int)$cb['id'], $linkedBookIds, true)) continue;
                ?>
                  <option value="<?= (int)$cb['id'] ?>">[#<?= (int)$cb['id'] ?>] <?= sanitize($cb['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-bold">افزودن درس‌ها:</label>
              <select name="link_lesson_ids[]" class="form-select form-select-sm" multiple size="3">
                <?php
                $linkedLessonIds = array_map('intval', array_column($linkedLessons, 'id'));
                foreach ($candidateLessons as $cl):
                    if (in_array((int)$cl['id'], $linkedLessonIds, true)) continue;
                ?>
                  <option value="<?= (int)$cl['id'] ?>">[#<?= (int)$cl['id'] ?>] <?= sanitize($cl['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus-circle ms-1"></i>اتصال موارد انتخاب‌شده به موضوع</button>
        </form>

        <div class="small fw-bold mb-2">فهرست آیتم‌های متصل فعلی (<?= jhd_persian_digits(count($linkedPosts) + count($linkedBooks) + count($linkedLessons)) ?> مورد):</div>
        <?php if (!$linkedPosts && !$linkedBooks && !$linkedLessons): ?>
          <div class="text-muted small">هنوز محتوایی به این موضوع متصل نشده است.</div>
        <?php else: ?>
          <div class="list-group list-group-flush small" style="max-height:260px;overflow-y:auto">
            <?php foreach ($linkedPosts as $lp): ?>
              <div class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                <div>
                  <span class="badge bg-light text-dark border"><?= sanitize(postTypeLabel((string)$lp['post_type'])) ?></span>
                  <a href="<?= sanitize(adminUrl('posts/edit', ['id' => (int)$lp['id']])) ?>"><?= sanitize($lp['title']) ?></a>
                  <?php if ($lp['status'] !== 'published'): ?><span class="badge bg-warning text-dark">پیش‌نویس</span><?php endif; ?>
                </div>
                <form method="POST" action="<?= sanitize(adminUrl('topics')) ?>" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="quick_unlink_content" value="1">
                  <input type="hidden" name="topic_id" value="<?= (int)$editing['id'] ?>">
                  <input type="hidden" name="entity_type" value="post">
                  <input type="hidden" name="item_id" value="<?= (int)$lp['id'] ?>">
                  <button type="submit" class="btn btn-sm text-danger py-0 px-1" title="قطع ارتباط از این موضوع"><i class="bi bi-x-circle"></i></button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php foreach ($linkedBooks as $lb): ?>
              <div class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                <div>
                  <span class="badge bg-success bg-opacity-10 text-success border">کتاب</span>
                  <a href="<?= sanitize(adminUrl('books/edit', ['id' => (int)$lb['id']])) ?>"><?= sanitize($lb['title']) ?></a>
                </div>
                <form method="POST" action="<?= sanitize(adminUrl('topics')) ?>" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="quick_unlink_content" value="1">
                  <input type="hidden" name="topic_id" value="<?= (int)$editing['id'] ?>">
                  <input type="hidden" name="entity_type" value="book">
                  <input type="hidden" name="item_id" value="<?= (int)$lb['id'] ?>">
                  <button type="submit" class="btn btn-sm text-danger py-0 px-1" title="قطع ارتباط از این موضوع"><i class="bi bi-x-circle"></i></button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php foreach ($linkedLessons as $ll): ?>
              <div class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                <div>
                  <span class="badge bg-info bg-opacity-10 text-dark border">درس</span>
                  <a href="<?= sanitize(adminUrl('lessons/edit', ['id' => (int)$ll['id']])) ?>"><?= sanitize($ll['title']) ?></a>
                </div>
                <form method="POST" action="<?= sanitize(adminUrl('topics')) ?>" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="quick_unlink_content" value="1">
                  <input type="hidden" name="topic_id" value="<?= (int)$editing['id'] ?>">
                  <input type="hidden" name="entity_type" value="lesson">
                  <input type="hidden" name="item_id" value="<?= (int)$ll['id'] ?>">
                  <button type="submit" class="btn btn-sm text-danger py-0 px-1" title="قطع ارتباط از این موضوع"><i class="bi bi-x-circle"></i></button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ستون جدول فهرست، جستجو، فیلتر، تغییر ترتیب و ادغام موضوعات -->
  <div class="col-lg-7">
    <div class="admin-card mb-3">
      <div class="admin-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-list-ul me-2"></i>فهرست موضوعات علمی (<?= jhd_persian_digits($totalFiltered) ?> مورد)</span>
        <div class="d-flex gap-1">
          <a href="<?= sanitize(url('topics')) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye ms-1"></i>صفحه عمومی موضوعات
          </a>
        </div>
      </div>

      <!-- نوار جستجو و فیلتر موضوعات -->
      <div class="p-3 border-bottom bg-light bg-opacity-50">
        <form method="GET" action="<?= sanitize(adminUrl('topics')) ?>" class="row g-2 align-items-end">
          <div class="col-12 col-sm-4">
            <label class="form-label small mb-1">جستجو در نام یا اسلاگ</label>
            <input type="search" name="q" class="form-control form-control-sm" value="<?= sanitize($searchQuery) ?>" placeholder="جستجوی موضوع...">
          </div>
          <div class="col-6 col-sm-3">
            <label class="form-label small mb-1">وضعیت</label>
            <select name="status" class="form-select form-select-sm">
              <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>همه موضوعات</option>
              <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>فقط فعال‌ها</option>
              <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>فقط غیرفعال‌ها</option>
              <option value="featured" <?= $statusFilter === 'featured' ? 'selected' : '' ?>>منتخب صفحه اصلی</option>
              <option value="non_empty" <?= $statusFilter === 'non_empty' ? 'selected' : '' ?>>دارای محتوا</option>
              <option value="empty" <?= $statusFilter === 'empty' ? 'selected' : '' ?>>بدون محتوا (خالی)</option>
              <option value="duplicate" <?= $statusFilter === 'duplicate' ? 'selected' : '' ?>>عناوین مشابه / تکراری</option>
            </select>
          </div>
          <div class="col-6 col-sm-3">
            <label class="form-label small mb-1">سطح / والد</label>
            <select name="parent" class="form-select form-select-sm">
              <option value="" <?= $parentFilter === '' ? 'selected' : '' ?>>همه سطوح</option>
              <option value="root" <?= $parentFilter === 'root' ? 'selected' : '' ?>>فقط موضوعات اصلی</option>
              <?php foreach ($allTopics as $pt): if (!empty($pt['parent_id'])) continue; ?>
                <option value="<?= (int)$pt['id'] ?>" <?= $parentFilter === (string)$pt['id'] ? 'selected' : '' ?>>زیرموضوع‌های «<?= sanitize($pt['name']) ?>»</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary flex-grow-1">فیلتر</button>
            <?php if ($searchQuery !== '' || $statusFilter !== 'all' || $parentFilter !== ''): ?>
              <a href="<?= sanitize(adminUrl('topics')) ?>" class="btn btn-sm btn-outline-secondary" title="پاک‌کردن فیلتر"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table admin-table mb-0 align-middle">
          <thead>
            <tr>
              <th>عنوان و نشانی</th>
              <th>تعداد محتوا</th>
              <th>محل و حالت نمایش</th>
              <th style="width:90px">ترتیب</th>
              <th>وضعیت</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$pagedTopics): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">موضوعی مطابق فیلتر انتخابی یافت نشد.</td></tr>
          <?php else: ?>
            <?php foreach ($pagedTopics as $n):
              $tid = (int)$n['id'];
              $c = $countsById[$tid] ?? ['posts_total' => 0, 'books_total' => 0, 'lessons_total' => 0, 'total' => 0, 'children_count' => 0];
              $disp = jhd_topic_display($tid);
              $depth = (int)($n['depth'] ?? 0);
              $plCount = count($disp['placements'] ?? []);
              $modeLabel = $displayModes[$disp['mode'] ?? 'featured'] ?? ($disp['mode'] ?? 'featured');
            ?>
            <tr>
              <td>
                <div class="fw-semibold">
                  <?= str_repeat('<span class="text-muted ms-1">↳</span>', $depth) ?>
                  <?php if (!empty($n['cover_image'])): ?>
                    <img src="<?= sanitize(imgUrl((string)$n['cover_image'])) ?>" alt="" style="width:28px;height:28px;object-fit:cover;border-radius:6px" class="ms-1">
                  <?php endif; ?>
                  <a href="<?= sanitize(adminUrl('topics', ['edit' => $tid])) ?>" class="text-decoration-none text-dark"><?= sanitize($n['name']) ?></a>
                  <?php if (!empty($n['is_featured'])): ?>
                    <span class="badge bg-warning text-dark ms-1">ویژه</span>
                  <?php endif; ?>
                </div>
                <div class="small text-muted" dir="ltr">/topic/<?= sanitize($n['slug']) ?></div>
              </td>
              <td>
                <?php if ($c['total'] > 0): ?>
                  <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                    <?= jhd_persian_digits($c['total']) ?> مورد
                  </span>
                  <div class="small text-muted mt-1" style="font-size:.73rem">
                    <?= jhd_persian_digits($c['posts_total']) ?> مطلب · <?= jhd_persian_digits($c['books_total']) ?> کتاب · <?= jhd_persian_digits($c['lessons_total']) ?> درس
                  </div>
                <?php else: ?>
                  <span class="badge bg-light text-muted border">بدون محتوا</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="small">
                  <span class="badge bg-secondary bg-opacity-10 text-dark border"><?= sanitize( strtok($modeLabel, '(') ?: $modeLabel ) ?></span>
                </div>
                <div class="small text-muted mt-1" style="font-size:.73rem">
                  <?= in_array('home', $disp['placements'] ?? [], true) ? 'صفحه اصلی + ' : '' ?><?= jhd_persian_digits($plCount) ?> جایگاه
                </div>
              </td>
              <td>
                <div class="d-flex align-items-center gap-1">
                  <input type="number" name="orders[<?= $tid ?>]" form="bulkReorderForm" class="form-control form-control-sm text-center px-1" style="width:52px" value="<?= (int)$n['sort_order'] ?>">
                  <div class="d-flex flex-column">
                    <form method="POST" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                      <input type="hidden" name="move_topic" value="<?= $tid ?>">
                      <input type="hidden" name="move_dir" value="up">
                      <button type="submit" class="btn btn-link btn-sm p-0 text-secondary" title="بالاتر" style="line-height:1"><i class="bi bi-caret-up-fill"></i></button>
                    </form>
                    <form method="POST" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                      <input type="hidden" name="move_topic" value="<?= $tid ?>">
                      <input type="hidden" name="move_dir" value="down">
                      <button type="submit" class="btn btn-link btn-sm p-0 text-secondary" title="پایین‌تر" style="line-height:1"><i class="bi bi-caret-down-fill"></i></button>
                    </form>
                  </div>
                </div>
              </td>
              <td>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="toggle_active" value="<?= $tid ?>">
                  <button type="submit" class="btn btn-sm <?= !empty($n['is_active']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>">
                    <?= !empty($n['is_active']) ? 'فعال' : 'غیرفعال' ?>
                  </button>
                </form>
              </td>
              <td>
                <div class="d-flex flex-wrap gap-1">
                  <a href="<?= sanitize(topicUrl($n)) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="پیش‌نمایش صفحه اختصاصی موضوع">
                    <i class="bi bi-eye"></i>
                  </a>
                  <a href="<?= sanitize(adminUrl('topics', ['edit' => $tid])) ?>" class="btn btn-sm btn-outline-primary" title="ویرایش موضوع و محتوا">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <?php if ($c['total'] > 0 || $c['children_count'] > 0): ?>
                    <a href="<?= sanitize(adminUrl('topics', ['confirm_delete' => $tid])) ?>" class="btn btn-sm btn-outline-danger" title="حذف یا انتقال محتوای موضوع">
                      <i class="bi bi-trash"></i>
                    </a>
                  <?php else: ?>
                    <form method="POST" class="d-inline" data-confirm="آیا از حذف موضوع «<?= sanitize($n['name']) ?>» مطمئن هستید؟">
                      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                      <input type="hidden" name="delete" value="<?= $tid ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف موضوع">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="p-3 border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
        <form method="POST" id="bulkReorderForm" action="<?= sanitize(adminUrl('topics')) ?>" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="reorder_topics" value="1">
          <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-sort-numeric-down ms-1"></i>ذخیره ترتیب موضوعات
          </button>
        </form>
        <div>
          <?= paginate($totalFiltered, $perPage, $currentPage, adminUrl('topics', array_filter([
              'q' => $searchQuery,
              'status' => $statusFilter !== 'all' ? $statusFilter : '',
              'parent' => $parentFilter,
              'page' => '%d',
          ]))) ?>
        </div>
      </div>
    </div>

    <!-- ابزار ادغام موضوعات تکراری یا انتقال یک‌جا -->
    <div class="admin-card">
      <div class="admin-card-header">
        <span><i class="bi bi-intersect me-2"></i>ادغام موضوعات تکراری و انتقال یکپارچه محتوا</span>
      </div>
      <div class="p-3">
        <p class="small text-muted mb-2">
          اگر دو موضوع با عنوان مشابه ایجاد شده‌اند یا می‌خواهید تمام مطالب، کتاب‌ها، درس‌ها و زیرموضوع‌های یک موضوع را بدون ایجاد رکورد تکراری به موضوع دیگری منتقل کنید، از این ابزار استفاده نمایید:
        </p>
        <form method="POST" action="<?= sanitize(adminUrl('topics')) ?>" class="row g-2 align-items-end">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="merge_topic" value="1">
          <div class="col-12 col-sm-4">
            <label class="form-label small mb-1">موضوع مبدأ (تکراری / قدیمی)</label>
            <select name="source_topic_id" class="form-select form-select-sm" required>
              <option value="">— انتخاب موضوع مبدأ —</option>
              <?php foreach ($allTopics as $st): ?>
                <option value="<?= (int)$st['id'] ?>"><?= sanitize($st['name']) ?> (<?= jhd_persian_digits($countsById[(int)$st['id']]['total'] ?? 0) ?> مورد)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-4">
            <label class="form-label small mb-1">موضوع مقصد (اصلی)</label>
            <select name="target_topic_id" class="form-select form-select-sm" required>
              <option value="">— انتخاب موضوع مقصد —</option>
              <?php foreach ($allTopics as $tt): ?>
                <option value="<?= (int)$tt['id'] ?>"><?= sanitize($tt['name']) ?> (/topic/<?= sanitize($tt['slug']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-4">
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="delete_source_after_merge" value="1" id="delSourceMerge" checked>
              <label class="form-check-label small" for="delSourceMerge">حذف موضوع مبدأ پس از انتقال</label>
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-arrow-left-right ms-1"></i>انتقال و ادغام موضوع</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
