<?php
/**
 * api/mobile/index.php — dispatcher محتوای عمومی API موبایل
 *
 * هر درخواست از مسیر allowlist شده در config/routes.php با پارامتر `resource`
 * به همین فایل می‌رسد؛ هیچ فایل دیگری اجرا نمی‌شود. فقط GET/HEAD (و برای
 * تماس، POST) پذیرفته می‌شود. پاسخ‌ها فقط JSON است.
 */
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$resource = (string)($_GET['resource'] ?? '');
$viewer = null; // کاربر واردشده در صورت وجود؛ برای وضعیت لایک

switch ($resource) {
    // ─── سلامت سامانه ────────────────────────────────────────────────
    case 'health':
        jhd_api_method(['GET', 'HEAD']);
        $dbReady = false;
        try {
            $db = getDB();
            $dbReady = $db !== null && (int)$db->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable $e) {
            $dbReady = false;
        }
        jhd_api_data(['status' => 'ok', 'database' => $dbReady, 'time' => gmdate('c')]);

    // ─── صفحهٔ اصلی (بخش‌های تجمیعی) ─────────────────────────────────
    case 'home':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $sectionLimit = min(10, max(3, jhd_api_int($_GET, 'limit', 6)));

        $featured = array_map(static fn($p): array => jhd_api_post_item($p, false, $viewer),
            getPosts(['featured' => 1, 'limit' => $sectionLimit, 'offset' => 0, 'sort' => 'newest']));

        $banners = [];
        foreach (getActiveBanners(5) as $banner) {
            $link = trim((string)($banner['link'] ?? $banner['link_url'] ?? ''));
            $banners[] = [
                'id' => (int)($banner['id'] ?? 0),
                'title' => jhd_api_text((string)($banner['title'] ?? ''), 200),
                'content' => jhd_api_text((string)($banner['content'] ?? $banner['description'] ?? ''), 700),
                'image_url' => jhd_api_img($banner['image'] ?? null),
                'link_url' => $link !== '' ? (preg_match('~^https?://~i', $link) ? $link : jhd_api_abs($link)) : '',
                'link_text' => jhd_api_text((string)($banner['link_text'] ?? $banner['button_text'] ?? ''), 40),
                'banner_type' => (string)($banner['banner_type'] ?? 'info'),
            ];
        }

        $byType = static function (array $types, int $limit) use ($viewer): array {
            return array_map(static fn($p): array => jhd_api_post_item($p, false, $viewer),
                getPosts(['types' => $types, 'limit' => $limit, 'offset' => 0, 'sort' => 'newest']));
        };

        $sections = [
            'news' => $byType(['news'], $sectionLimit),
            'announcements' => $byType(['announcement'], $sectionLimit),
            'events' => $byType(['program', 'religious', 'announcement'], $sectionLimit),
            'articles' => $byType(['article'], $sectionLimit),
            'research' => $byType(['research', 'report'], $sectionLimit),
            'speeches' => $byType(['speech', 'qa', 'religious'], $sectionLimit),
            'books' => array_map(static fn($b): array => jhd_api_book_item($b, false, $viewer),
                getBooks(['limit' => $sectionLimit, 'offset' => 0])),
            'lessons' => array_map(static fn($l): array => jhd_api_lesson_item($l, false, $viewer),
                jhd_api_recent_lessons($sectionLimit)),
            'topics' => array_map(static fn($t): array => jhd_api_topic_item($t),
                getTopics(['active' => 1, 'limit' => $sectionLimit])),
        ];

        // رسانه‌های اخیر (صوت/ویدیو) با فیلتر منتشرشده — همان قاعدهٔ mobile-feed.
        $sections['media'] = [];
        try {
            $stmt = getDB()->prepare(
                "SELECT m.*, COALESCE(p.title, l.title, '') AS parent_title,
                        COALESCE(p.author_name, p.speaker, l.teacher, '') AS author,
                        COALESCE(p.featured_image, l.featured_image, '') AS featured_image,
                        COALESCE(p.slug, l.slug, '') AS parent_slug,
                        f.size AS size
                 FROM media_files m
                 LEFT JOIN posts p ON m.ref_type = 'post' AND p.id = m.ref_id AND p.status = 'published'
                 LEFT JOIN lessons l ON m.ref_type = 'lesson' AND l.id = m.ref_id AND l.status = 'published'
                 LEFT JOIN stored_files f ON f.url = m.file_path
                 WHERE m.kind IN ('audio', 'video')
                   AND ((m.ref_type = 'post' AND p.id IS NOT NULL) OR (m.ref_type = 'lesson' AND l.id IS NOT NULL))
                 ORDER BY m.created_at DESC, m.id DESC
                 LIMIT " . (int)min(12, $sectionLimit * 2)
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $media) {
                $sections['media'][] = jhd_api_media_item($media, false, $viewer);
            }
        } catch (Throwable $e) {
            error_log('Mobile home media unavailable: ' . get_class($e));
        }

        jhd_api_data([
            'site' => jhd_api_site_info(),
            'banners' => $banners,
            'featured' => $featured,
            'sections' => $sections,
        ], ['refreshed_at' => gmdate('c')]);

    // ─── فهرست مطالب (اخبار، مقاله، پژوهش، …) ─────────────────────────
    case 'posts':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $type = (string)($_GET['type'] ?? 'all');
        $allowedTypes = ['all', 'news', 'article', 'research', 'report', 'announcement', 'event', 'program', 'religious', 'qa', 'speech'];
        if (!in_array($type, $allowedTypes, true)) {
            jhd_api_error('invalid_type', 'نوع محتوای درخواستی معتبر نیست.', 422);
        }
        [$page, $perPage, $offset] = jhd_api_page_params($_GET);
        $search = trim((string)($_GET['search'] ?? ''));
        // نگاشت «رویداد» به همان ترکیب صفحهٔ /events وب (برنامه + فعالیت مذهبی + اطلاعیه).
        $typeMap = [
            'all' => ['news', 'article', 'research', 'report', 'announcement', 'program', 'religious', 'qa', 'speech'],
            'event' => ['program', 'religious', 'announcement'],
            'research' => ['research', 'report'],
        ];
        $opts = ['limit' => $perPage, 'offset' => $offset, 'sort' => 'newest'];
        if (isset($typeMap[$type])) {
            if ($type !== 'all') $opts['types'] = $typeMap[$type];
            else $opts['types'] = $typeMap['all'];
        } else {
            $opts['type'] = $type;
        }
        if ($search !== '') $opts['search'] = $search;
        $cat = jhd_api_int($_GET, 'category', 0);
        if ($cat > 0) $opts['cat'] = $cat;
        $topic = jhd_api_int($_GET, 'topic', 0);
        if ($topic > 0) $opts['topic'] = $topic;
        $items = array_map(static fn($p): array => jhd_api_post_item($p, false, $viewer), getPosts($opts));
        jhd_api_list($items, jhd_api_page_meta($page, $perPage, countPosts($opts)));

    // ─── جزئیات مطلب ─────────────────────────────────────────────────
    case 'post':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $id = jhd_api_int($_GET, 'id', 0);
        $slug = trim((string)($_GET['slug'] ?? ''));
        $row = $id > 0 ? getPost($id) : ($slug !== '' ? getPostBySlug($slug) : null);
        if (!$row || (string)($row['status'] ?? '') !== 'published') {
            jhd_api_error('not_found', 'مطلب درخواستی پیدا نشد.', 404);
        }
        jhd_api_data(jhd_api_post_item($row, true, $viewer));

    // ─── کتاب‌ها ─────────────────────────────────────────────────────
    case 'books':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        [$page, $perPage, $offset] = jhd_api_page_params($_GET);
        $search = trim((string)($_GET['search'] ?? ''));
        $opts = ['limit' => $perPage, 'offset' => $offset];
        if ($search !== '') $opts['search'] = $search;
        $topic = jhd_api_int($_GET, 'topic', 0);
        if ($topic > 0) $opts['topic'] = $topic;
        $items = array_map(static fn($b): array => jhd_api_book_item($b, false, $viewer), getBooks($opts));
        jhd_api_list($items, jhd_api_page_meta($page, $perPage, countBooks($opts)));

    case 'book':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $id = jhd_api_int($_GET, 'id', 0);
        $slug = trim((string)($_GET['slug'] ?? ''));
        $row = $id > 0 ? getBookById($id) : ($slug !== '' ? getBookBySlug($slug) : null);
        if (!$row || (string)($row['status'] ?? 'published') !== 'published') {
            jhd_api_error('not_found', 'کتاب درخواستی پیدا نشد.', 404);
        }
        jhd_api_data(jhd_api_book_item($row, true, $viewer));

    // ─── مجموعه‌های درسی ─────────────────────────────────────────────
    case 'collections':
        jhd_api_method(['GET', 'HEAD']);
        $collections = [];
        foreach (getLessonCollections(['active' => 1]) as $row) {
            $item = jhd_api_collection_item($row);
            try {
                $stmt = getDB()->prepare('SELECT COUNT(*) FROM lessons WHERE collection_id = ? AND status = \'published\'');
                $stmt->execute([(int)$row['id']]);
                $item['lesson_count'] = (int)$stmt->fetchColumn();
                $stmt = getDB()->prepare('SELECT id, title, slug, sort_order FROM lesson_volumes WHERE collection_id = ? ORDER BY sort_order ASC, id ASC');
                $stmt->execute([(int)$row['id']]);
                $item['volumes'] = array_map(static fn($v): array => [
                    'id' => (int)$v['id'],
                    'title' => jhd_api_text((string)$v['title'], 160),
                    'slug' => (string)$v['slug'],
                ], $stmt->fetchAll());
            } catch (Throwable $e) {
                $item['lesson_count'] = 0;
                $item['volumes'] = [];
            }
            $collections[] = $item;
        }
        jhd_api_list($collections, ['total' => count($collections)]);

    // ─── درس‌ها ──────────────────────────────────────────────────────
    case 'lessons':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        [$page, $perPage, $offset] = jhd_api_page_params($_GET);
        $collection = jhd_api_int($_GET, 'collection', 0);
        $volume = jhd_api_int($_GET, 'volume', 0);
        $search = trim((string)($_GET['search'] ?? ''));
        try {
            $db = getDB();
            $where = ["l.status = 'published'"];
            $params = [];
            if ($collection > 0) { $where[] = 'l.collection_id = ?'; $params[] = $collection; }
            if ($volume > 0) { $where[] = 'l.volume_id = ?'; $params[] = $volume; }
            if ($search !== '') {
                $where[] = '(l.title ILIKE ? OR l.summary ILIKE ? OR l.content ILIKE ?)';
                $s = '%' . $search . '%';
                array_push($params, $s, $s, $s);
            }
            $whereStr = implode(' AND ', $where);
            $countStmt = $db->prepare("SELECT COUNT(*) FROM lessons l WHERE $whereStr");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            $stmt = $db->prepare(
                "SELECT l.*, c.title AS collection_title, v.title AS volume_title
                 FROM lessons l
                 LEFT JOIN lesson_collections c ON c.id = l.collection_id
                 LEFT JOIN lesson_volumes v ON v.id = l.volume_id
                 WHERE $whereStr
                 ORDER BY l.is_featured DESC, l.collection_id ASC, l.lesson_number ASC, l.created_at DESC, l.id DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            $items = array_map(static fn($l): array => jhd_api_lesson_item($l, false, $viewer), $stmt->fetchAll());
        } catch (Throwable $e) {
            error_log('Mobile lessons listing failed: ' . get_class($e));
            $items = [];
            $total = 0;
        }
        jhd_api_list($items, jhd_api_page_meta($page, $perPage, $total));

    case 'lesson':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $id = jhd_api_int($_GET, 'id', 0);
        $slug = trim((string)($_GET['slug'] ?? ''));
        try {
            $db = getDB();
            if ($id > 0) {
                $stmt = $db->prepare(
                    "SELECT l.*, c.title AS collection_title, v.title AS volume_title
                     FROM lessons l
                     LEFT JOIN lesson_collections c ON c.id = l.collection_id
                     LEFT JOIN lesson_volumes v ON v.id = l.volume_id
                     WHERE l.id = ? LIMIT 1"
                );
                $stmt->execute([$id]);
            } elseif ($slug !== '') {
                $stmt = $db->prepare(
                    "SELECT l.*, c.title AS collection_title, v.title AS volume_title
                     FROM lessons l
                     LEFT JOIN lesson_collections c ON c.id = l.collection_id
                     LEFT JOIN lesson_volumes v ON v.id = l.volume_id
                     WHERE l.slug = ? LIMIT 1"
                );
                $stmt->execute([$slug]);
            } else {
                jhd_api_error('invalid_request', 'شناسه یا نامک درس لازم است.', 422);
            }
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            $row = false;
        }
        if (!$row || (string)($row['status'] ?? '') !== 'published') {
            jhd_api_error('not_found', 'درس درخواستی پیدا نشد.', 404);
        }
        jhd_api_data(jhd_api_lesson_item($row, true, $viewer));

    // ─── موضوع‌ها ────────────────────────────────────────────────────
    case 'topics':
        jhd_api_method(['GET', 'HEAD']);
        $topics = [];
        foreach (getTopics(['active' => 1, 'limit' => 100]) as $row) {
            $topics[] = jhd_api_topic_item($row);
        }
        jhd_api_list($topics, ['total' => count($topics)]);

    case 'topic':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $id = jhd_api_int($_GET, 'id', 0);
        $slug = trim((string)($_GET['slug'] ?? ''));
        $row = $id > 0 ? getTopicById($id) : ($slug !== '' ? getTopicBySlug($slug) : null);
        if (!$row || (int)($row['is_active'] ?? 0) !== 1) {
            jhd_api_error('not_found', 'موضوع درخواستی پیدا نشد.', 404);
        }
        $item = jhd_api_topic_item($row);
        $item['content_html'] = jhd_api_html((string)($row['intro'] ?? $row['description'] ?? ''));
        $children = [];
        foreach (getTopicChildren((int)$row['id']) as $child) {
            $children[] = jhd_api_topic_item($child);
        }
        $item['children'] = $children;
        // تازه‌ترین محتوای این موضوع (مطلب + درس + کتاب) برای نمایش در اپ.
        [$page, $perPage, $offset] = jhd_api_page_params($_GET);
        $posts = array_map(static fn($p): array => jhd_api_post_item($p, false, $viewer),
            getPostsByTopic((int)$row['id'], ['limit' => $perPage, 'offset' => $offset]));
        $item['posts'] = $posts;
        $item['lessons'] = array_map(static fn($l): array => jhd_api_lesson_item($l, false, $viewer),
            getLessonsByTopic((int)$row['id'], min(10, $perPage), 0));
        $item['books'] = array_map(static fn($b): array => jhd_api_book_item($b, false, $viewer),
            getBooksByTopic((int)$row['id'], min(10, $perPage), 0));
        jhd_api_data($item, jhd_api_page_meta($page, $perPage, count($posts)));

    // ─── رسانه (صوت/ویدیو) ──────────────────────────────────────────
    case 'media':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $kind = (string)($_GET['kind'] ?? 'all');
        if (!in_array($kind, ['all', 'audio', 'video'], true)) {
            jhd_api_error('invalid_kind', 'نوع رسانه معتبر نیست.', 422);
        }
        [$page, $perPage, $offset] = jhd_api_page_params($_GET);
        try {
            $db = getDB();
            $kindClause = $kind === 'all' ? '' : ' AND m.kind = ?';
            $params = $kind === 'all' ? [] : [$kind];
            $countStmt = $db->prepare(
                "SELECT COUNT(*) FROM media_files m
                 LEFT JOIN posts p ON m.ref_type = 'post' AND p.id = m.ref_id AND p.status = 'published'
                 LEFT JOIN lessons l ON m.ref_type = 'lesson' AND l.id = m.ref_id AND l.status = 'published'
                 WHERE m.kind IN ('audio', 'video')
                   AND ((m.ref_type = 'post' AND p.id IS NOT NULL) OR (m.ref_type = 'lesson' AND l.id IS NOT NULL))$kindClause"
            );
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            $stmt = $db->prepare(
                "SELECT m.*, COALESCE(p.title, l.title, '') AS parent_title,
                        COALESCE(p.author_name, p.speaker, l.teacher, '') AS author,
                        COALESCE(p.featured_image, l.featured_image, '') AS featured_image,
                        f.size AS size
                 FROM media_files m
                 LEFT JOIN posts p ON m.ref_type = 'post' AND p.id = m.ref_id AND p.status = 'published'
                 LEFT JOIN lessons l ON m.ref_type = 'lesson' AND l.id = m.ref_id AND l.status = 'published'
                 LEFT JOIN stored_files f ON f.url = m.file_path
                 WHERE m.kind IN ('audio', 'video')
                   AND ((m.ref_type = 'post' AND p.id IS NOT NULL) OR (m.ref_type = 'lesson' AND l.id IS NOT NULL))$kindClause
                 ORDER BY m.created_at DESC, m.id DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            $items = array_map(static fn($m): array => jhd_api_media_item($m, false, $viewer), $stmt->fetchAll());
        } catch (Throwable $e) {
            error_log('Mobile media listing failed: ' . get_class($e));
            $items = [];
            $total = 0;
        }
        jhd_api_list($items, jhd_api_page_meta($page, $perPage, $total));

    case 'media-item':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $kind = (string)($_GET['kind'] ?? '');
        $id = jhd_api_int($_GET, 'id', 0);
        if (!in_array($kind, ['audio', 'video'], true) || $id < 1) {
            jhd_api_error('invalid_request', 'شناسه و نوع رسانه لازم است.', 422);
        }
        $row = getMediaById($id, $kind);
        if (!$row) jhd_api_error('not_found', 'رسانهٔ درخواستی پیدا نشد.', 404);
        // والد باید منتشرشده باشد تا رسانه نشت نکند.
        $parentTitle = '';
        $parentUrl = '';
        $parentPublished = false;
        try {
            $db = getDB();
            if ((string)$row['ref_type'] === 'post') {
                $stmt = $db->prepare("SELECT title, slug, post_type, status FROM posts WHERE id = ?");
                $stmt->execute([(int)$row['ref_id']]);
                $parent = $stmt->fetch();
                if ($parent && (string)$parent['status'] === 'published') {
                    $parentPublished = true;
                    $parentTitle = jhd_api_text((string)$parent['title'], 200);
                    $parentUrl = jhd_api_post_url(['post_type' => $parent['post_type'], 'slug' => $parent['slug'], 'id' => (int)$row['ref_id']]);
                }
            } elseif ((string)$row['ref_type'] === 'lesson') {
                $stmt = $db->prepare("SELECT title, slug, status, teacher, featured_image FROM lessons WHERE id = ?");
                $stmt->execute([(int)$row['ref_id']]);
                $parent = $stmt->fetch();
                if ($parent && (string)$parent['status'] === 'published') {
                    $parentPublished = true;
                    $parentTitle = jhd_api_text((string)$parent['title'], 200);
                    $row['featured_image'] = $parent['featured_image'];
                    $row['author'] = $parent['teacher'];
                    $parentUrl = $parent['slug'] !== '' ? jhd_api_abs('lesson/' . rawurlencode((string)$parent['slug'])) : jhd_api_abs('lessons');
                }
            }
        } catch (Throwable $e) {
            $parentPublished = false;
        }
        if (!$parentPublished) jhd_api_error('not_found', 'رسانهٔ درخواستی پیدا نشد.', 404);
        $row['parent_title'] = $parentTitle;
        $row['parent_url'] = $parentUrl;
        try {
            $stmt = getDB()->prepare('SELECT size FROM stored_files WHERE url = ? LIMIT 1');
            $stmt->execute([(string)$row['file_path']]);
            $size = $stmt->fetchColumn();
            $row['size'] = $size !== false && $size !== null ? (int)$size : 0;
        } catch (Throwable $e) {
            $row['size'] = 0;
        }
        jhd_api_data(jhd_api_media_item($row, true, $viewer));

    // ─── جست‌وجوی کامل ───────────────────────────────────────────────
    case 'search':
        jhd_api_method(['GET', 'HEAD']);
        $viewer = jhd_api_user();
        $q = trim((string)($_GET['q'] ?? ''));
        $filter = trim((string)($_GET['type'] ?? 'all'));
        $sort = (string)($_GET['sort'] ?? 'relevance');
        $topicId = jhd_api_int($_GET, 'topic', 0);
        [$page, $perPage, $offset] = jhd_api_page_params($_GET);
        if (mb_strlen($q, 'UTF-8') > 200) {
            jhd_api_error('invalid_query', 'عبارت جست‌وجو بیش از حد طولانی است.', 422);
        }
        if ($q === '' && $filter === 'all' && $topicId < 1) {
            jhd_api_list([], jhd_api_page_meta($page, $perPage, 0));
        }
        $result = searchAll($q, $perPage, $offset, $filter, $topicId, $sort);
        $items = [];
        foreach ($result['results'] as $row) {
            $target = (string)($row['target'] ?? 'post');
            if ($target === 'media') {
                $items[] = jhd_api_media_item([
                    'id' => $row['id'],
                    'kind' => (string)($row['media_kind'] ?? 'audio'),
                    'title' => $row['title'],
                    'file_path' => $row['featured_image'],
                    'created_at' => $row['created_at'],
                    'ref_type' => 'post',
                    'ref_id' => 0,
                    'parent_title' => jhd_api_text((string)($row['summary'] ?? ''), 200),
                ], false, $viewer);
                continue;
            }
            if ($target === 'book') {
                $items[] = jhd_api_book_item([
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'description' => $row['summary'],
                    'cover_image' => $row['featured_image'],
                    'created_at' => $row['created_at'],
                    'status' => 'published',
                ], false, $viewer);
                continue;
            }
            if ($target === 'lesson') {
                $items[] = jhd_api_lesson_item([
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'summary' => $row['summary'],
                    'content' => $row['content'],
                    'featured_image' => $row['featured_image'],
                    'created_at' => $row['created_at'],
                    'status' => 'published',
                ], false, $viewer);
                continue;
            }
            if ($target === 'topic') {
                $items[] = jhd_api_topic_item([
                    'id' => $row['id'],
                    'name' => $row['title'],
                    'slug' => $row['slug'],
                    'intro' => $row['summary'],
                    'cover_image' => $row['featured_image'],
                ]);
                continue;
            }
            // post
            $items[] = jhd_api_post_item([
                'id' => $row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'summary' => $row['summary'],
                'content' => $row['content'],
                'featured_image' => $row['featured_image'],
                'post_type' => $row['post_type'],
                'published_at' => $row['published_at'],
                'created_at' => $row['created_at'],
                'status' => 'published',
            ], false, $viewer);
        }
        jhd_api_list($items, array_merge(jhd_api_page_meta($page, $perPage, (int)$result['total']), ['query' => $q]));

    // ─── دسته‌بندی‌ها ────────────────────────────────────────────────
    case 'categories':
        jhd_api_method(['GET', 'HEAD']);
        $items = [];
        foreach (getCategories() as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'name' => jhd_api_text((string)$row['name'], 120),
                'slug' => (string)$row['slug'],
                'description' => jhd_api_text((string)($row['description'] ?? ''), 400),
                'post_type' => (string)($row['post_type'] ?? 'all'),
            ];
        }
        jhd_api_list($items, ['total' => count($items)]);

    // ─── صفحه‌های درباره ما / تماس ───────────────────────────────────
    case 'pages':
        jhd_api_method(['GET', 'HEAD']);
        $page = (string)($_GET['page'] ?? 'about');
        if ($page === 'about') {
            // همان محتوای «درباره ما»ٔ وب: معرفی ثابت + بنیان‌گذار + راه‌های تماس.
            $hero = getPosts(['featured' => 1, 'limit' => 1, 'offset' => 0, 'sort' => 'newest'])[0] ?? null;
            jhd_api_data([
                'title' => 'درباره ما',
                'name' => SITE_NAME,
                'slogan' => SITE_SLOGAN,
                'description' => SITE_DESCRIPTION,
                'founder' => SITE_FOUNDER,
                'logo_url' => jhd_api_abs(SITE_LOGO_PATH),
                'highlight' => $hero !== null ? [
                    'id' => (int)$hero['id'],
                    'title' => jhd_api_text((string)$hero['title'], 200),
                    'summary' => jhd_api_text((string)($hero['summary'] ?? ''), 400),
                    'url' => jhd_api_post_url($hero),
                    'image_url' => jhd_api_img($hero['featured_image'] ?? null),
                ] : null,
                'contact' => [
                    'email' => getSetting('site_email', SITE_EMAIL),
                    'phone' => getSetting('phone', SITE_PHONE),
                    'address' => getSetting('address', SITE_ADDRESS),
                    'telegram' => getSetting('social_telegram', ''),
                    'youtube' => getSetting('social_youtube', ''),
                    'instagram' => getSetting('social_instagram', ''),
                ],
            ]);
        } elseif ($page === 'contact') {
            jhd_api_data([
                'title' => 'تماس با ما',
                'email' => getSetting('site_email', SITE_EMAIL),
                'phone' => getSetting('phone', SITE_PHONE),
                'address' => getSetting('address', SITE_ADDRESS),
                'form' => ['fields' => ['name', 'email', 'phone', 'subject', 'message']],
            ]);
        } else {
            jhd_api_error('not_found', 'صفحهٔ درخواستی پیدا نشد.', 404);
        }

    // ─── ارسال پیام تماس ────────────────────────────────────────────
    case 'contact':
        jhd_api_method(['POST']);
        $body = jhd_api_body();
        $name = jhd_api_str($body, 'name', 200);
        $email = jhd_api_str($body, 'email', 200);
        $phone = jhd_api_str($body, 'phone', 50);
        $subject = jhd_api_str($body, 'subject', 300);
        $message = jhd_api_str($body, 'message', 4000);
        if (mb_strlen($name, 'UTF-8') < 2) jhd_api_error('invalid_name', 'نام باید حداقل ۲ حرف باشد.', 422);
        if ($subject === '') jhd_api_error('invalid_subject', 'موضوع پیام الزامی است.', 422);
        if (mb_strlen($message, 'UTF-8') < 10) jhd_api_error('invalid_message', 'متن پیام باید حداقل ۱۰ کاراکتر باشد.', 422);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jhd_api_error('invalid_email', 'آدرس ایمیل معتبر نیست.', 422);
        $ip = clientIp();
        try {
            $db = getDB();
            // حداکثر ۳ پیام در ۳۰ دقیقه از هر IP — همان قاعدهٔ فرم وب.
            $rateSql = match (databaseDriver()) {
                'mysql' => 'SELECT COUNT(*) FROM contact_messages WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 30 MINUTE)',
                'sqlite' => "SELECT COUNT(*) FROM contact_messages WHERE ip_address = ? AND created_at > datetime('now', '-30 minutes')",
                default => "SELECT COUNT(*) FROM contact_messages WHERE ip_address = ? AND created_at > (CURRENT_TIMESTAMP - INTERVAL '30 minutes')",
            };
            $stmt = $db->prepare($rateSql);
            $stmt->execute([$ip]);
            if ((int)$stmt->fetchColumn() >= 3) {
                jhd_api_error('rate_limited', 'تعداد پیام‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 429);
            }
            $db->prepare('INSERT INTO contact_messages (name, email, phone, subject, message, ip_address) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $email, $phone, $subject, $message, $ip]);
        } catch (Throwable $e) {
            error_log('Mobile contact submit failed: ' . get_class($e));
            jhd_api_error('unavailable', 'ارسال پیام در این لحظه ممکن نیست. بعداً دوباره تلاش کنید.', 503);
        }
        jhd_api_data(['sent' => true]);

    default:
        jhd_api_error('not_found', 'مسیر درخواستی وجود ندارد.', 404);
}
