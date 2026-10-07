<?php
/**
 * functions.php — Jametulhoda Content-Centered helpers
 * Production-oriented: SEO, topics, lesson collections, books, search, sanitization
 * All Like/View systems removed completely per spec 17.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/storage.php';

// ─── Database readiness (shared by every controller) ─────────────────────────
//
// The whole site — not only the homepage — must survive a deployment where the
// database is not reachable yet: every page renders its real design and shows
// its own empty state, plus one honest notice in the header. As soon as a
// database is configured and migrated, the very same code reads real data.

/** Core tables the public site needs before it can query anything. */
const JHD_CORE_TABLES = ['users', 'settings', 'posts', 'topics', 'post_topics', 'books', 'lessons', 'media_files'];

/**
 * Which of $tables exist in the connected database?
 *
 * One round trip for the whole list. The previous one-query-per-table loop
 * cost eight sequential `information_schema` lookups on every single request;
 * against a pooled PostgreSQL endpoint that is eight network round trips
 * before the page has read a single row of content.
 *
 * @param string[] $tables
 * @return array<string,bool> table name ⇒ exists
 */
function jhd_core_tables_exist(PDO $db, array $tables): array {
    $result = array_fill_keys($tables, false);
    if (!$tables) return $result;
    try {
        $driver = databaseDriver();
        $marks = implode(',', array_fill(0, count($tables), '?'));
        if ($driver === 'mysql') {
            $sql = "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)";
        } elseif ($driver === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name IN ($marks)";
        } else {
            $sql = "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ANY(current_schemas(false)) AND table_name IN ($marks)";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($tables));
        foreach ($stmt->fetchAll() as $row) {
            $name = (string)($row['name'] ?? $row['NAME'] ?? '');
            if ($name !== '' && array_key_exists($name, $result)) $result[$name] = true;
        }
    } catch (Throwable $e) {
        return array_fill_keys($tables, false);
    }
    return $result;
}

/** Does $table exist in the connected database? (name-spaced away from includes/identity.php's jhd_table_exists) */
function jhd_core_table_exists(PDO $db, string $table): bool {
    return jhd_core_tables_exist($db, [$table])[$table] ?? false;
}

/**
 * Connection that is proven usable (reachable + migrated), or null.
 * Evaluated once per request and shared by controllers, header and footer.
 */
function jhd_db(): ?PDO {
    static $resolved = false;
    static $db = null;
    if ($resolved) return $db;
    $resolved = true;
    $candidate = tryGetDB();
    if ($candidate === null) return $db = null;
    foreach (jhd_core_tables_exist($candidate, JHD_CORE_TABLES) as $table => $exists) {
        if (!$exists) {
            error_log('Database reachable but schema incomplete: missing table ' . $table);
            return $db = null;
        }
    }
    return $db = $candidate;
}

/** True when real data can be read/written. */
function jhd_db_ready(): bool {
    return jhd_db() instanceof PDO;
}

/** Short, honest explanation for the degraded-mode notice (empty when ready). */
function jhd_db_notice(): string {
    if (jhd_db_ready()) return '';
    $status = databaseStatus();
    if (!$status['configured']) {
        return 'دیتابیس این استقرار هنوز پیکربندی نشده است؛ صفحه‌ها نمایش داده می‌شوند ولی محتوایی برای خواندن وجود ندارد.';
    }
    if (!$status['ok']) {
        return 'اتصال به دیتابیس در این لحظه ممکن نیست؛ صفحه‌ها نمایش داده می‌شوند ولی محتوا به‌روزرسانی نمی‌شود.';
    }
    return 'جدول‌های دیتابیس هنوز ساخته نشده‌اند (migration اجرا نشده است).';
}

/**
 * Detail pages (a single post, book, lesson, media item …) cannot tell a
 * missing record from an unreadable database. When the database is not
 * available they render the real site template with an honest notice and a
 * 200 status instead of a misleading 404 or a fatal 503.
 */
function jhd_render_content_unavailable(string $title, string $backUrl, string $backLabel): void {
    global $pageTitle, $pageDesc;
    $pageTitle = $title;
    $pageDesc = 'این بخش موقتاً در دسترس نیست.';
    http_response_code(200);
    require_once __DIR__ . '/header.php';
    echo '<div class="container py-5 text-center">'
        . '<h1 class="h3 mb-3">' . sanitize($title) . '</h1>'
        . '<p class="text-muted">' . sanitize(jhd_db_notice()) . '</p>'
        . '<a href="' . sanitize($backUrl) . '" class="btn btn-primary mt-3">' . sanitize($backLabel) . '</a>'
        . '</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// ─── Security ────────────────────────────────────────────────────────────────

function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

function generateCsrfToken(): string {
    require_once __DIR__ . '/auth.php';
    startSecureSession();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCsrfToken(mixed $token): bool {
    return is_string($token)
        && isset($_SESSION[CSRF_TOKEN_NAME])
        && is_string($_SESSION[CSRF_TOKEN_NAME])
        && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/** Read a scalar string field without warnings or TypeErrors for array input. */
function jhd_string_field(array $source, string $key, string $default = ''): string {
    $value = $source[$key] ?? null;
    return is_string($value) ? $value : $default;
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generateCsrfToken() . '">';
}

// ─── URL ─────────────────────────────────────────────────────────────────────

/** Installation base path ('' for a domain-root install). */
function jhd_base_path(): string {
    return defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
}

/**
 * jhd_routes() — SINGLE SOURCE OF TRUTH for every public route.
 *
 * All internal links are generated from this table via url() and the typed
 * helpers (topicUrl, postUrl, articleUrl, newsUrl, reportUrl, researchUrl,
 * bookUrl, lessonUrl, mediaUrl, ...). The Query-URL front controller
 * (index.php / router.php) and the Pretty-URL router both resolve against the
 * SAME controllers listed here, so no route is ever defined in two places.
 *
 * Per-entry fields:
 *   file          primary controller (the listing page for listing routes, or
 *                 the detail page for detail-only routes)
 *   p             value of ?p= in Query mode (defaults to the route key)
 *   pretty        pretty-URL path segment (defaults to the route key)
 *   title         human label
 *   listing       true → ?p=<key> with no slug/id renders `file` as a listing
 *   detail        (array) optional detail variant used when ?p=<key> carries a
 *                 slug/id (news & research are both a listing AND a detail):
 *                   file, expected_type, kind, id_param
 *                 (bool true) marks a detail-only route whose file/expected_
 *                 type/kind/id_param live at the top level of the entry
 *   expected_type post_type guard forwarded to pages/post.php
 *   kind          'video'|'audio' forwarded to pages/media.php
 *   id_param      'slug' | 'id' | 'slug_or_id' — how the pretty path carries it
 *   get           default $_GET values applied on dispatch (e.g. media kind)
 *   paths         extra exact pretty paths that map to this route
 */
function jhd_routes(): array {
    static $table = null;
    if ($table !== null) return $table;
    $table = [
        'home'   => ['file' => 'index.php', 'p' => 'home', 'pretty' => '', 'title' => 'خانه'],

        // ── Listings ─────────────────────────────────────────────────────
        'news'     => ['file' => 'pages/news.php',     'p' => 'news',     'pretty' => 'news',     'title' => 'اخبار',     'listing' => true, 'detail' => ['file' => 'pages/post.php', 'expected_type' => 'news', 'id_param' => 'slug']],
        'articles' => ['file' => 'pages/articles.php', 'p' => 'articles', 'pretty' => 'articles', 'title' => 'مقالات',    'listing' => true],
        'reports'  => ['file' => 'pages/reports.php',  'p' => 'reports',  'pretty' => 'reports',  'title' => 'گزارش‌ها',   'listing' => true],
        'research' => ['file' => 'pages/research.php', 'p' => 'research', 'pretty' => 'research', 'title' => 'پژوهش',     'listing' => true, 'detail' => ['file' => 'pages/post.php', 'expected_type' => 'research', 'id_param' => 'slug']],
        'books'    => ['file' => 'pages/books.php',    'p' => 'books',    'pretty' => 'books',    'title' => 'کتابخانه',  'listing' => true],
        'lessons'  => ['file' => 'pages/lessons.php',  'p' => 'lessons',  'pretty' => 'lessons',  'title' => 'دروس',      'listing' => true],
        'topics'   => ['file' => 'pages/topics.php',   'p' => 'topics',   'pretty' => 'topics',   'title' => 'موضوعات',   'listing' => true],
        'media'    => ['file' => 'pages/media-library.php', 'p' => 'media', 'pretty' => 'media', 'title' => 'رسانه',     'listing' => true, 'paths' => ['/media-library']],
        'videos'   => ['file' => 'pages/media-library.php', 'p' => 'videos', 'pretty' => 'videos', 'title' => 'ویدیوها', 'listing' => true, 'get' => ['kind' => 'video']],
        'audios'   => ['file' => 'pages/media-library.php', 'p' => 'audios', 'pretty' => 'audios', 'title' => 'صوت‌ها',  'listing' => true, 'get' => ['kind' => 'audio']],
        'speeches' => ['file' => 'pages/speeches.php', 'p' => 'speeches', 'pretty' => 'speeches', 'title' => 'سخنرانی‌ها', 'listing' => true],
        'events'   => ['file' => 'pages/events.php',   'p' => 'events',   'pretty' => 'events',   'title' => 'رویدادها',   'listing' => true],
        'programs' => ['file' => 'pages/programs.php', 'p' => 'programs', 'pretty' => 'programs', 'title' => 'برنامه‌ها',   'listing' => true],
        'announcements' => ['file' => 'pages/announcements.php', 'p' => 'announcements', 'pretty' => 'announcements', 'title' => 'اطلاعیه‌ها', 'listing' => true],
        'religious-activities' => ['file' => 'pages/religious-activities.php', 'p' => 'religious-activities', 'pretty' => 'religious-activities', 'title' => 'فعالیت مذهبی', 'listing' => true],
        'qa'       => ['file' => 'pages/qa.php',     'p' => 'qa',     'pretty' => 'qa',     'title' => 'پرسش و پاسخ', 'listing' => true, 'detail' => ['file' => 'pages/post.php', 'expected_type' => 'qa', 'id_param' => 'slug']],
        'about'    => ['file' => 'pages/about.php',  'p' => 'about',  'pretty' => 'about',  'title' => 'درباره ما',  'listing' => true],
        'contact'  => ['file' => 'pages/contact.php','p' => 'contact','pretty' => 'contact','title' => 'تماس با ما', 'listing' => true],
        'search'   => ['file' => 'pages/search.php', 'p' => 'search', 'pretty' => 'search', 'title' => 'جستجو',      'listing' => true],
        'read'     => ['file' => 'pages/reader.php', 'p' => 'read', 'pretty' => 'read', 'title' => 'مطالعه سند', 'listing' => true],

        // ── Public authentication (never aliases to admin) ───────────────
        'login'            => ['file' => 'pages/login.php',            'p' => 'login',            'pretty' => 'login',            'title' => 'ورود',           'listing' => true],
        'register'         => ['file' => 'pages/register.php',         'p' => 'register',         'pretty' => 'register',         'title' => 'ثبت‌نام',         'listing' => true],
        'logout'           => ['file' => 'pages/logout.php',           'p' => 'logout',           'pretty' => 'logout',           'title' => 'خروج',           'listing' => true],
        'account'          => ['file' => 'pages/account.php',          'p' => 'account',          'pretty' => 'account',          'title' => 'حساب کاربری',    'listing' => true, 'paths' => ['/profile']],
        'profile'          => ['file' => 'pages/account.php',          'p' => 'profile',          'pretty' => 'profile',          'title' => 'حساب کاربری',    'listing' => true],
        'password-change'  => ['file' => 'pages/password-change.php',  'p' => 'password-change',  'pretty' => 'password-change',  'title' => 'تغییر رمز عبور', 'listing' => true],

        // ── Detail-only routes ───────────────────────────────────────────
        'article'  => ['file' => 'pages/post.php',   'p' => 'article',  'pretty' => 'articles',  'title' => 'مقاله',   'expected_type' => 'article', 'id_param' => 'slug', 'detail' => true],
        'report'   => ['file' => 'pages/post.php',   'p' => 'report',   'pretty' => 'reports',   'title' => 'گزارش',   'expected_type' => 'report',  'id_param' => 'slug', 'detail' => true],
        'event'    => ['file' => 'pages/post.php',   'p' => 'event',    'pretty' => 'event',    'title' => 'رویداد',   'expected_type' => 'event',   'id_param' => 'slug', 'detail' => true],
        // Every published post type owns one canonical prefix. Before these
        // existed, announcements, programs and religious activities all fell
        // back to the shared /event/<slug> namespace, so the same content was
        // reachable (and canonically declared) under several prefixes at once.
        'announcement' => ['file' => 'pages/post.php', 'p' => 'announcement', 'pretty' => 'announcements', 'title' => 'اطلاعیه', 'expected_type' => 'announcement', 'id_param' => 'slug', 'detail' => true],
        'program'      => ['file' => 'pages/post.php', 'p' => 'program',      'pretty' => 'programs',      'title' => 'برنامه',  'expected_type' => 'program',      'id_param' => 'slug', 'detail' => true],
        'religious'    => ['file' => 'pages/post.php', 'p' => 'religious',    'pretty' => 'religious-activities', 'title' => 'فعالیت مذهبی', 'expected_type' => 'religious', 'id_param' => 'slug', 'detail' => true],
        'post'     => ['file' => 'pages/post.php',   'p' => 'post',     'pretty' => 'post',     'title' => 'مطلب',    'id_param' => 'slug', 'detail' => true],
        'book'     => ['file' => 'pages/book.php',   'p' => 'book',     'pretty' => 'books',     'title' => 'کتاب',    'id_param' => 'slug_or_id', 'detail' => true],
        'lesson'   => ['file' => 'pages/lesson-route.php', 'p' => 'lesson',   'pretty' => 'lessons',   'title' => 'درس',     'id_param' => 'slug', 'detail' => true],
        'topic'    => ['file' => 'pages/topic.php',  'p' => 'topic',    'pretty' => 'topics',    'title' => 'موضوع',   'id_param' => 'slug', 'detail' => true],
        'category' => ['file' => 'pages/category.php','p' => 'category','pretty' => 'category','title' => 'دسته‌بندی', 'id_param' => 'slug', 'detail' => true],
        'speech'   => ['file' => 'pages/speech.php', 'p' => 'speech',   'pretty' => 'speech',   'title' => 'سخنرانی', 'id_param' => 'slug', 'detail' => true],
        // The singular URLs are both media-library listings and ID-based detail routes.
        'video'    => ['file' => 'pages/media-library.php', 'p' => 'video', 'pretty' => 'video', 'title' => 'ویدیو', 'listing' => true, 'kind' => 'video', 'get' => ['kind' => 'video'], 'detail' => ['file' => 'pages/media.php', 'kind' => 'video', 'id_param' => 'id']],
        'audio'    => ['file' => 'pages/media-library.php', 'p' => 'audio', 'pretty' => 'audio', 'title' => 'صوت', 'listing' => true, 'kind' => 'audio', 'get' => ['kind' => 'audio'], 'detail' => ['file' => 'pages/media.php', 'kind' => 'audio', 'id_param' => 'id']],
    ];
    return $table;
}

function jhd_route_meta(string $route, ?string $key = null, $default = null) {
    $meta = jhd_routes()[$route] ?? null;
    if ($meta === null) return $default;
    if ($key === null) return $meta;
    return $meta[$key] ?? $default;
}

function jhd_route_exists(string $route): bool {
    return isset(jhd_routes()[$route]);
}

/** True when a route renders a detail controller (carries a slug/id). */
function jhd_is_detail_route(array $meta): bool {
    return isset($meta['detail']) || isset($meta['id_param']);
}

/**
 * Logical pretty-style path for a route + request params (e.g. /topic/x,
 * /video/5, /news). Used for current_path(), active navigation and canonical
 * regardless of the active URL mode.
 */
function jhd_route_path(string $route, array $get): string {
    if ($route === '' || $route === 'home') return '/';
    $meta = jhd_routes()[$route] ?? null;
    if ($meta === null) {
        if (str_starts_with($route, 'admin')) return '/' . trim($route, '/');
        return '/' . $route;
    }
    $path = '/' . ($meta['pretty'] ?? $route);
    if (jhd_is_detail_route($meta)) {
        if (!empty($get['slug']) && is_string($get['slug'])) {
            $segments = preg_split('~/+~', trim(rawurldecode($get['slug']), '/')) ?: [];
            $clean = [];
            foreach ($segments as $seg) {
                if ($seg === '' || $seg === '.' || $seg === '..') continue;
                $clean[] = $seg;
            }
            if ($clean) $path .= '/' . implode('/', $clean);
        } elseif (!empty($get['id'])) {
            $path .= '/' . (int)$get['id'];
        }
    }
    return $path;
}

/**
 * Reverse lookup: the route name whose pretty path matches a resolved path.
 * Lets the Pretty router publish the same logical route (and therefore the
 * same Query-mode canonical / navigation state) as the Query front controller.
 */
function jhd_route_name_for_path(string $path): ?string {
    $path = '/' . trim($path, '/');
    if ($path === '/' || $path === '') return 'home';
    foreach (jhd_routes() as $name => $meta) {
        $pretty = '/' . trim((string)($meta['pretty'] ?? $name), '/');
        if ($pretty !== '/' && $path === $pretty) return $name;
        foreach (($meta['paths'] ?? []) as $alias) {
            if ($path === rtrim((string)$alias, '/')) return $name;
        }
        // Detail spelling: /<pretty>/<slug|id> (and the legacy plural /articles/X).
        if (jhd_is_detail_route($meta) && $pretty !== '/' && str_starts_with($path, $pretty . '/')) {
            return $name;
        }
        if ($name === 'article' && str_starts_with($path, '/articles/')) return 'article';
    }
    return null;
}

/**
 * Resolve a Query-URL (?p=<route>) into a controller dispatch descriptor.
 * Returns null for an unknown route (→ real 404, never a soft-404/home).
 *
 * @return array{file:string,route:string,get:array,expected_type?:string,kind?:string}|null
 */
function jhd_resolve_query(string $p, array $get): ?array {
    $p = trim($p, '/');
    $routes = jhd_routes();
    if (!isset($routes[$p])) {
        if ($p === 'admin' || str_starts_with($p, 'admin/')) {
            $file = jhd_admin_file_for($p);
            if ($file !== null) return ['file' => $file, 'route' => $p, 'get' => []];
        }
        return null;
    }
    $r = $routes[$p];
    $hasId = (isset($get['slug']) && (string)$get['slug'] !== '')
        || (isset($get['id']) && (int)$get['id'] > 0);

    $detail = $r['detail'] ?? null;

    // Detail-only route → always its detail controller.
    if ($detail === true) {
        $out = ['file' => $r['file'], 'route' => $p, 'get' => $r['get'] ?? []];
        if (!empty($r['expected_type'])) $out['expected_type'] = $r['expected_type'];
        if (!empty($r['kind'])) $out['kind'] = $r['kind'];
        return $out;
    }

    // Listing route with an optional detail (news / research): id → detail.
    if (is_array($detail) && $hasId) {
        $out = ['file' => $detail['file'], 'route' => $p, 'get' => $r['get'] ?? []];
        if (!empty($detail['expected_type'])) $out['expected_type'] = $detail['expected_type'];
        if (!empty($detail['kind'])) $out['kind'] = $detail['kind'];
        return $out;
    }

    // Plain listing / static page.
    return ['file' => $r['file'], 'route' => $p, 'get' => $r['get'] ?? []];
}

/**
 * Central URL helper for the entire application.
 *
 * Query mode (JHD_PRETTY_URLS = false, the InfinityFree-safe default):
 *   url('news')                  → /index.php?p=news
 *   url('topic', ['slug'=>'x'])  → /index.php?p=topic&slug=x
 *   url('video', ['id'=>5])      → /index.php?p=video&id=5
 *   url('login')                 → /index.php?p=login
 *   url('admin/login')           → /admin/login  (directory stub, no rewrite needed)
 * Pretty mode (JHD_PRETTY_URLS = true):
 *   url('news')                  → /news
 *   url('topic', ['slug'=>'x'])  → /topic/x
 *   url('video', ['id'=>5])      → /video/5
 *
 * Admin panel, auth, installer, sitemap/robots and any asset/upload/literal
 * file path always render as a plain path (they resolve through the router or
 * as a physical file) and never use ?p=.
 */
function url(string $route = '', array $query = []): string {
    if (preg_match('~^https?://~i', $route)) {
        if (filter_var($route, FILTER_VALIDATE_URL)) {
            if (!empty($query)) {
                $separator = str_contains($route, '?') ? '&' : '?';
                return $route . $separator . http_build_query($query);
            }
            return $route;
        }
        return '';
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $route) || str_contains($route, "\r") || str_contains($route, "\n")) {
        return '';
    }

    // Support an inline query string in the first argument (url('lessons?q=x')).
    if (str_contains($route, '?')) {
        [$route, $inlineQs] = explode('?', $route, 2);
        parse_str($inlineQs, $inline);
        if (is_array($inline) && $inline) $query = array_merge($inline, $query);
    }

    $route = ltrim($route, '/');

    // Home — always the site root. `/` works with or without mod_rewrite
    // (DirectoryIndex). Never emit a relative `index.php` (that would resolve
    // to /admin/index.php when the visitor is on /admin/login.php).
    if ($route === '' || $route === '/' || $route === 'home') {
        return jhd_web_path('');
    }

    // Literal files, assets and uploads — always a root-relative physical path.
    if (
        preg_match('~\.[a-z0-9]{1,6}$~i', $route)
        || str_starts_with($route, 'assets/')
        || str_starts_with($route, 'uploads/')
    ) {
        $result = jhd_web_path($route);
        if (!empty($query)) $result .= (str_contains($result, '?') ? '&' : '?') . http_build_query($query);
        return $result;
    }

    // Installer.
    if ($route === 'install' || $route === 'php/install' || $route === 'php/install.php') {
        return JHD_PRETTY_URLS ? jhd_web_path('php/install') : jhd_web_path('php/install.php');
    }

    // Admin panel: pretty paths when enabled; otherwise the physical controller
    // file from config/routes.php so InfinityFree works without mod_rewrite.
    if ($route === 'admin' || str_starts_with($route, 'admin/')) {
        return jhd_admin_url($route, $query);
    }

    // Legacy "route/slug" call style → split into route + slug/id param.
    $segments = explode('/', $route);
    $name = $segments[0];
    $embedded = $segments[1] ?? null;
    if ($embedded !== null && $embedded !== '' && !isset($query['slug']) && !isset($query['id'])) {
        if (ctype_digit($embedded)) $query['id'] = $embedded;
        else $query['slug'] = implode('/', array_slice($segments, 1));
    }

    $meta = jhd_routes()[$name] ?? null;
    if ($meta === null) {
        $result = jhd_web_path($route);
        if (!empty($query)) $result .= (str_contains($result, '?') ? '&' : '?') . http_build_query($query);
        return $result;
    }

    $p = $meta['p'] ?? $name;

    if (!JHD_PRETTY_URLS) {
        $q = ['p' => $p] + $query;
        return jhd_web_path('index.php?' . http_build_query($q));
    }

    // Pretty mode.
    $path = $meta['pretty'] ?? $name;
    if (jhd_is_detail_route($meta)) {
        if (isset($query['slug']) && (string)$query['slug'] !== '') {
            $parts = preg_split('~/+~', str_replace('\\', '/', (string)$query['slug'])) ?: [];
            $encoded = [];
            foreach ($parts as $seg) {
                $seg = trim($seg);
                if ($seg === '' || $seg === '.' || $seg === '..') continue;
                $encoded[] = rawurlencode($seg);
            }
            if ($encoded) $path .= '/' . implode('/', $encoded);
            unset($query['slug']);
        } elseif (isset($query['id']) && (int)$query['id'] > 0) {
            $path .= '/' . (int)$query['id'];
            unset($query['id']);
        }
    }
    $result = jhd_web_path($path);
    if (!empty($query)) $result .= '?' . http_build_query($query);
    return $result;
}

/** Root-relative path: `/x` or `/subdir/x`. Never a page-relative URL. */
function jhd_web_path(string $suffix = ''): string {
    $base = jhd_base_path();
    $suffix = ltrim($suffix, '/');
    if ($suffix === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $suffix;
}

/** Map of canonical admin paths → controller files from config/routes.php. */
function jhd_admin_routes(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    $definition = require BASE_DIR . '/config/routes.php';
    foreach ($definition['routes'] as $path => $target) {
        if ($path !== '/admin' && !str_starts_with((string)$path, '/admin/')) continue;
        $file = is_array($target) ? (string)($target['file'] ?? '') : (string)$target;
        if ($file === '') continue;
        $map[trim((string)$path, '/')] = $file;
    }
    return $map;
}

/**
 * نشانی عملیات محتوا (انتشار / پیش‌نویس / آرشیو / حذف) برای یک مطلب.
 *
 * مسیر پویای `/admin/content/{id}/{action}` روی هر میزبانی (با یا بدون
 * mod_rewrite) کار می‌کند، ولی در حالت Query URL نمی‌توان آن را از روی
 * رشتهٔ مسیر ساخت؛ بنابراین دو حالت را صریح می‌سازد:
 *   Pretty: /admin/content/12/publish
 *   Query : /admin/posts/action.php?id=12&action=publish
 */
function contentActionUrl(int $id, string $action): string {
    if (!in_array($action, ['publish', 'unpublish', 'archive', 'delete'], true) || $id < 1) {
        return adminUrl('content');
    }
    if (JHD_PRETTY_URLS) return jhd_web_path('admin/content/' . $id . '/' . $action);
    return jhd_web_path('admin/posts/action.php') . '?id=' . $id . '&action=' . $action;
}

/** Controller file for an admin route, or null when unknown. */
function jhd_admin_file_for(string $route): ?string {
    $key = trim($route, '/');
    if ($key === '') $key = 'admin';
    if (!str_starts_with($key, 'admin')) $key = 'admin/' . $key;
    $map = jhd_admin_routes();
    if (isset($map[$key])) return $map[$key];
    if (preg_match('~\.php$~i', $key) && is_file(BASE_DIR . '/' . $key)) return $key;
    return null;
}

function jhd_admin_url(string $route, array $query = []): string {
    $key = trim($route, '/');
    if ($key === '') $key = 'admin';
    // Login/logout have directory stubs so the pretty path works without
    // mod_rewrite and never appears in public HTML as admin/*.php.
    $prettyAlways = in_array($key, ['admin', 'admin/login', 'admin/logout'], true);
    if (JHD_PRETTY_URLS || $prettyAlways) {
        $result = jhd_web_path($key);
    } else {
        $file = jhd_admin_file_for($key);
        $result = jhd_web_path($file ?: ($key . (str_ends_with($key, '.php') ? '' : '.php')));
    }
    if (!empty($query)) $result .= (str_contains($result, '?') ? '&' : '?') . http_build_query($query);
    return $result;
}

/**
 * Backward-compatible alias for url().
 */
function siteUrl(string $path = ''): string {
    return url($path);
}

/**
 * Short, stable content fingerprint for a static asset.
 *
 * The token is derived from the file's modification time and size — both are
 * local filesystem facts, so the same file always yields the same token on a
 * given deployment, and any redeploy that touches the file produces a NEW
 * URL. Browsers and shared-host caches (InfinityFree) therefore key on a URL
 * that changes together with the file, and a redeploy can never be served a
 * stale copy from an old cache entry: the old URL simply stops being linked.
 *
 * Computed once per file per request (static cache). Files that do not exist
 * on disk (e.g. operator-provisioned uploads on a fresh Vercel container) get
 * no token and keep the plain URL.
 */
function jhd_asset_version(string $path): string {
    static $cache = [];
    if (array_key_exists($path, $cache)) return $cache[$path];
    $version = '';
    $file = BASE_DIR . '/' . ltrim($path, '/');
    if (is_file($file) && !is_link($file)) {
        $mtime = @filemtime($file);
        $size = @filesize($file);
        if ($mtime !== false && $size !== false && $mtime > 0) {
            $version = substr(md5($mtime . ':' . $size), 0, 10);
        }
    }
    return $cache[$path] = $version;
}

/**
 * Central asset helper for styles, scripts, fonts, and images.
 *
 * Every generated asset URL carries a ?v=<fingerprint> cache-busting token
 * (see jhd_asset_version) so caches on both hosts can never serve a stale
 * CSS/JS/font after a redeploy — the URL itself changes with the file.
 */
function asset(string $path): string {
    $clean = ltrim($path, '/');
    if (!str_starts_with($clean, 'assets/') && !str_starts_with($clean, 'uploads/')) {
        $clean = 'assets/' . $clean;
    }
    $version = jhd_asset_version($clean);
    if ($version === '') return url($clean);
    return url($clean, ['v' => $version]);
}

/**
 * Generate full absolute canonical URL including protocol and host.
 *
 * Accepts either a plain route/path (runs it through url()) or an
 * already-generated relative URL (contains '?' or starts with index.php) which
 * is prefixed as-is, so a Query-mode canonical stays a single stable URL.
 */
function absolute_url(string $path = '', array $query = []): string {
    $looksGenerated = preg_match('~^https?://~i', $path)
        || str_contains($path, '?')
        || str_starts_with($path, 'index.php')
        || str_starts_with($path, '/index.php');
    if ($looksGenerated) {
        $relative = $path;
        if (!empty($query)) {
            $separator = str_contains($path, '?') ? '&' : '?';
            $relative = $path . $separator . http_build_query($query);
        }
        return jhd_absolute_url($relative);
    }
    return jhd_absolute_url(url($path, $query));
}

/** Prefix a site-relative URL with the configured origin (no url() re-entry). */
function jhd_absolute_url(string $relative): string {
    if (preg_match('~^https?://~i', $relative)) return $relative;
    $host = '';
    if (defined('SITE_URL') && SITE_URL) {
        $host = rtrim(SITE_URL, '/');
        if (defined('BASE_PATH') && BASE_PATH !== '' && str_ends_with($host, BASE_PATH)) {
            $host = substr($host, 0, -strlen(BASE_PATH));
        }
    } else {
        $proto = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443)) ? 'https://' : 'http://';
        $httpHost = $_SERVER['HTTP_HOST'] ?? 'jametulhoda.gt.tc';
        $host = $proto . $httpHost;
    }
    $rel = ltrim($relative, '/');
    return $rel === '' ? $host . '/' : $host . '/' . $rel;
}

/** Canonical Query-mode URL for a resolved route (used when no page override). */
function jhd_query_canonical(string $routeName): string {
    if ($routeName === '' || $routeName === 'home') return '/';
    $params = [];
    foreach (['slug', 'id', 'kind', 'collection', 'volume'] as $k) {
        if (!empty($_GET[$k]) && is_string($_GET[$k])) $params[$k] = $_GET[$k];
    }
    return url($routeName, $params);
}

/**
 * Return current normalized route path without query string and without BASE_PATH.
 * Prefers the logical route path resolved by the front controller
 * ($_SERVER['JHD_ROUTE_PATH']) so navigation, canonical and active-state behave
 * identically in Query and Pretty URL modes; falls back to ?p= then the raw
 * request path (physical .php access, admin, assets).
 */
function current_path(): string {
    if (!empty($_SERVER['JHD_ROUTE_PATH']) && is_string($_SERVER['JHD_ROUTE_PATH'])) {
        $path = $_SERVER['JHD_ROUTE_PATH'];
        if (defined('BASE_PATH') && BASE_PATH !== '') {
            if ($path === BASE_PATH) return '/';
            if (str_starts_with($path, BASE_PATH . '/')) $path = substr($path, strlen(BASE_PATH));
        }
        return '/' . ltrim($path, '/');
    }
    $p = (isset($_GET['p']) && is_string($_GET['p'])) ? trim($_GET['p']) : '';
    if ($p !== '' && jhd_route_exists($p)) {
        return jhd_route_path($p, $_GET);
    }
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (defined('BASE_PATH') && BASE_PATH !== '') {
        if ($path === BASE_PATH) return '/';
        if (str_starts_with($path, BASE_PATH . '/')) $path = substr($path, strlen(BASE_PATH));
    }
    return '/' . ltrim($path, '/');
}

function redirect(string $url): void {
    if (strpbrk($url, "\r\n") !== false) throw new InvalidArgumentException('Unsafe redirect');
    $siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    $isSite = $siteUrl !== '' && (str_starts_with($url, $siteUrl . '/') || $url === $siteUrl);
    // Any URL without a scheme and not protocol-relative is a safe same-origin
    // relative redirect (covers Query-mode links like "index.php?p=...").
    $hasScheme = (bool)preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || str_starts_with($url, '//');
    $isRelative = !$hasScheme;
    if (!$isRelative && !$isSite) {
        throw new InvalidArgumentException('Unsafe redirect');
    }
    header('Location: ' . $url, true, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 303 : 302);
    exit;
}

function currentUrl(): string {
    return absolute_url(ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/'));
}

// ─── Canonical detail URLs ──────────────────────────────────────────────
// Single source for link generation. Every helper delegates to url() and the
// central jhd_routes() registry, so the SAME URL is produced in Query mode
// (index.php?p=topic&slug=x) and Pretty mode (/topic/x). Legacy query-style
// URLs (/post?slug=X, /book?id=N, ...) keep working forever for bookmarks and
// indexed links, but new links must use these helpers.
/**
 * Canonical content route for a post_type — ONE prefix per published type.
 *
 * This map is the single source of truth for content URLs. postUrl(), the
 * sitemap, breadcrumbs, internal cards and the canonical <link> all go through
 * it, so a newly published post automatically gets its own stable direct URL
 * with no manual code change.
 *
 *   news /nnews…  → /news/<slug>            article      → /articles/<slug>
 *   research      → /research/<slug>        report       → /reports/<slug>
 *   announcement  → /announcements/<slug>   program      → /programs/<slug>
 *   religious     → /religious-activities/<slug>
 *   speech        → /speech/<slug>          qa           → /qa/<slug>
 *   anything else → /post/<slug>
 *
 * Legacy spellings (/post/<slug>, /event/<slug>, /article/<slug>, ?p=…) keep
 * resolving for bookmarks and already-indexed links, but they are never
 * generated and never declared as canonical: see jhd_redirect_to_canonical().
 */
function jhd_post_route(string $postType): string {
    return match ($postType) {
        'news'         => 'news',
        'article'      => 'article',
        'research'     => 'research',
        'report'       => 'report',
        'announcement' => 'announcement',
        'program'      => 'program',
        'religious'    => 'religious',
        'speech'       => 'speech',
        'qa'           => 'qa',
        'event'        => 'event',
        default        => 'post',
    };
}

/**
 * Canonical logical path for a post (e.g. `/articles/x`).
 *
 * Deliberately URL-mode independent: it is the pretty-style path in both modes,
 * so it can be compared with current_path() — which the front controller also
 * publishes in pretty form — to detect a non-canonical spelling without
 * breaking Query mode (where url() itself renders `index.php?p=…`).
 */
function jhd_post_canonical_path(array|string $post, string $postType = ''): string {
    if (is_string($post)) return jhd_route_path(jhd_post_route($postType), ['slug' => $post]);
    $slug = (string)($post['slug'] ?? '');
    if ($slug === '') return '';
    return jhd_route_path(jhd_post_route((string)($post['post_type'] ?? '')), ['slug' => $slug]);
}

/**
 * Send a permanent redirect when a detail page was reached through a URL that
 * is not its canonical one (a legacy prefix, a singular/plural alias, a Query
 * URL while the site publishes Pretty URLs, a numeric id when a slug exists…).
 *
 * The request is only ever redirected to the URL that url() itself generates,
 * so in Query mode nothing changes: the canonical is the `?p=` form and the
 * request already carries it. Legacy URLs therefore keep working (they land on
 * the right page) while the canonical, the sitemap and every generated link
 * agree on exactly one address.
 */
function jhd_redirect_to_canonical(string $canonicalUrl, string $canonicalPath): void {
    if ($canonicalPath === '') return;
    // Only safe, idempotent requests are ever redirected.
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) return;
    if (strpbrk($canonicalUrl, "\r\n") !== false) return;

    $requestPath = '/' . trim((string)current_path(), '/');
    $canonicalPath = '/' . trim($canonicalPath, '/');
    if ($requestPath === $canonicalPath) return;

    // Keep meaningful query parameters (a book download, a topic section) so
    // the redirect lands on the same view of the same page.
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($query !== '') {
        $canonicalUrl .= (str_contains($canonicalUrl, '?') ? '&' : '?') . $query;
    }
    header('Location: ' . $canonicalUrl, true, 301);
    exit;
}

/** Detail URL for a post row (typed: /news/X, /articles/X, /research/X, /reports/X, /speech/X, …). */
function postUrl(array|string $post, string $fallbackType = 'post'): string {
    if (is_string($post)) {
        if ($post === '') return url('articles');
        return url(jhd_post_route($fallbackType), ['slug' => $post]);
    }
    $slug = $post['slug'] ?? '';
    if ($slug === '') return url('articles');
    return url(jhd_post_route((string)($post['post_type'] ?? '')), ['slug' => $slug]);
}
/** Detail URL for a news row (/news/X). */
function newsUrl(array|string $post): string {
    $slug = is_array($post) ? ($post['slug'] ?? '') : $post;
    return $slug !== '' ? url('news', ['slug' => $slug]) : url('news');
}
/** Detail URL for an article row (/article/X). */
function articleUrl(array|string $post): string {
    $slug = is_array($post) ? ($post['slug'] ?? '') : $post;
    return $slug !== '' ? url('article', ['slug' => $slug]) : url('articles');
}
/** Detail URL for a report row (/report/X). */
function reportUrl(array|string $post): string {
    $slug = is_array($post) ? ($post['slug'] ?? '') : $post;
    return $slug !== '' ? url('report', ['slug' => $slug]) : url('reports');
}
/** Detail URL for a research row (/research/X). */
function researchUrl(array|string $post): string {
    $slug = is_array($post) ? ($post['slug'] ?? '') : $post;
    return $slug !== '' ? url('research', ['slug' => $slug]) : url('research');
}
/** Detail URL for a speech row (/speech/X). */
function speechUrl(array|string $speech): string {
    $slug = is_array($speech) ? ($speech['slug'] ?? '') : $speech;
    if ($slug === '') return url('speeches');
    return url('speech', ['slug' => $slug]);
}
/** Detail URL for a book row (/book/slug, or /book/id when it has no slug). */
function bookUrl(array $book): string {
    $slug = trim($book['slug'] ?? '');
    if ($slug !== '') return url('book', ['slug' => $slug]);
    return url('book', ['id' => (int)($book['id'] ?? 0)]);
}
/** Detail URL for a lesson row (/lesson/X). */
function lessonUrl(array|string $lesson): string {
    $slug = is_array($lesson) ? ($lesson['slug'] ?? '') : $lesson;
    if ($slug === '') return url('lessons');
    return url('lesson', ['slug' => $slug]);
}
/** Hierarchical slug path parent/child for a topic row. */
function jhd_topic_slug_path(array $topic): string {
    $own = trim((string)($topic['slug'] ?? ''));
    if ($own === '') return '';
    if (empty($topic['id'])) return $own;
    $crumbs = getTopicBreadcrumbs((int)$topic['id']);
    if (!$crumbs) return $own;
    $parts = [];
    foreach ($crumbs as $crumb) {
        $s = trim((string)($crumb['slug'] ?? ''));
        if ($s !== '') $parts[] = $s;
    }
    return $parts ? implode('/', $parts) : $own;
}

/** Detail URL for a topic row (/topic/parent/child or ?p=topic&slug=…). */
function topicUrl(array|string $topic): string {
    if (is_string($topic)) {
        if ($topic === '') return url('topics');
        return url('topic', ['slug' => $topic]);
    }
    $path = jhd_topic_slug_path($topic);
    if ($path === '') return url('topics');
    return url('topic', ['slug' => $path]);
}
/** Detail URL for a category row (/category/X). */
function categoryUrl(array|string $category): string {
    $slug = is_array($category) ? ($category['slug'] ?? '') : $category;
    if ($slug === '') return url();
    return url('category', ['slug' => $slug]);
}
/** URL for a lesson collection, optionally with a volume (/lessons/X[/Y]). */
function collectionUrl(array|string $collection, array|string|null $volume = null): string {
    $slug = is_array($collection) ? ($collection['slug'] ?? '') : $collection;
    if ($slug === '') return url('lessons');
    $query = ['collection' => $slug];
    $volumeSlug = $volume === null ? '' : (is_array($volume) ? ($volume['slug'] ?? '') : $volume);
    if ($volumeSlug !== '') $query['volume'] = $volumeSlug;
    return url('lessons', $query);
}
/** Detail URL for a media_files record (/video/{id} or /audio/{id}). */
function mediaUrl(string $kind, int $id): string {
    $kind = $kind === 'audio' ? 'audio' : 'video';
    if ($id < 1) return url($kind === 'audio' ? 'audios' : 'videos');
    return url($kind, ['id' => $id]);
}

/** نشانی یکتای صفحهٔ مطالعهٔ یک PDF؛ خود فایل هرگز در فید عمومی نمایش داده نمی‌شود. */
function documentReaderUrl(string $type, string $slug, int $mediaId = 0): string {
    $type = in_array($type, ['book', 'lesson', 'post'], true) ? $type : 'post';
    $query = ['type' => $type];
    if ($slug !== '') $query['slug'] = $slug;
    if ($mediaId > 0) $query['media'] = $mediaId;
    return url('read', $query);
}

function loginUrl(): string { return url('login'); }
function registerUrl(): string { return url('register'); }
function logoutUrl(): string { return url('logout'); }
function accountUrl(): string { return url('account'); }
/** تنها صفحهٔ ورود سامانه؛ /admin/login هم به همین کنترلر می‌رسد. */
function adminLoginUrl(): string { return url('admin/login'); }
function adminUrl(string $path = '', array $query = []): string {
    $route = trim($path) === '' ? 'admin' : 'admin/' . ltrim($path, '/');
    return url($route, $query);
}
function adminDashboardUrl(): string { return url('admin/dashboard'); }
function adminProfileUrl(): string { return url('admin/profile'); }
function adminLogoutUrl(): string { return url('admin/logout'); }
/** نشانی ورود با بازگشت به صفحهٔ جاری (برای محتوای نیازمند ورود). */
function loginRedirectUrl(string $target = ''): string {
    $base = loginUrl();
    if ($target === '') return $base;
    return $base . (str_contains($base, '?') ? '&' : '?') . 'redirect=' . rawurlencode($target);
}
function searchUrl(string $q = '', array $query = []): string {
    if ($q !== '') $query['q'] = $q;
    return url('search', $query);
}
function videoUrl(int|string $id): string { return mediaUrl('video', (int)$id); }
function audioUrl(int|string $id): string { return mediaUrl('audio', (int)$id); }

/**
 * GET forms in Query mode must not rely on `action="index.php?p=search"`:
 * browsers replace the query string with the form fields and drop `p`.
 * Use formUrl() as action and formRouteFields() inside the form.
 */
function formUrl(string $route = '', array $query = []): string {
    if (JHD_PRETTY_URLS) return url($route, $query);
    if ($route === 'admin' || str_starts_with($route, 'admin/')) return jhd_admin_url($route, $query);
    return jhd_web_path('index.php');
}

function formRouteFields(string $route, array $extra = []): string {
    if (JHD_PRETTY_URLS) return '';
    if ($route === 'admin' || str_starts_with($route, 'admin/')) return '';
    $meta = jhd_routes()[$route] ?? null;
    $p = is_array($meta) ? (string)($meta['p'] ?? $route) : $route;
    $html = '<input type="hidden" name="p" value="' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '">';
    $defaults = is_array($meta) ? ($meta['get'] ?? []) : [];
    foreach ($defaults + $extra as $k => $v) {
        if ($k === 'p' || !is_scalar($v)) continue;
        $html .= '<input type="hidden" name="' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '">';
    }
    return $html;
}

/** Hidden `p` so listing-page GET filters keep the current Query-mode route. */
function queryKeepFields(): string {
    if (JHD_PRETTY_URLS) return '';
    $p = (isset($_GET['p']) && is_string($_GET['p'])) ? trim($_GET['p']) : (string)($_SERVER['JHD_ROUTE_NAME'] ?? '');
    if ($p === '' || $p === 'home') return '';
    return '<input type="hidden" name="p" value="' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '">';
}

// ─── Slug ─────────────────────────────────────────────────────────────────────

function makeSlug(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/\s+/', '-', $text);
    $text = preg_replace('/[^\p{L}\p{N}\-]/u', '', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    return $text ?: uniqid('post-');
}

function uniqueSlug(string $table, string $text, int $excludeId = 0): string {
    $allowed = ['posts','lessons','categories','topics','lesson_collections','lesson_volumes','books'];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Invalid slug table');
    $db   = getDB();
    $base = makeSlug($text);
    $slug = $base;
    $i    = 1;
    while (true) {
        $sql  = "SELECT COUNT(*) FROM $table WHERE slug = ? AND id != ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$slug, $excludeId]);
        if ((int)$stmt->fetchColumn() === 0) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

// ─── Date ─────────────────────────────────────────────────────────────────────

function persianDate(string $datetime): string {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($datetime);
    if (!$ts) return $datetime;
    $monthNames = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    $parts = explode('-', date('Y-m-d', $ts));
    $gy = (int)$parts[0] - 1600; $gm = (int)$parts[1]; $gd = (int)$parts[2] - 1;
    $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
    $monthDays = [31,28+($leap?1:0),31,30,31,30,31,31,30,31,30,31];
    $g_d_no = 365*$gy + (int)(($gy+3)/4) - (int)(($gy+99)/100) + (int)(($gy+399)/400);
    for ($i = 0; $i < $gm-1; $i++) $g_d_no += $monthDays[$i];
    $g_d_no += $gd;
    $j_d_no = $g_d_no - 79;
    $j_np   = (int)($j_d_no / 12053); $j_d_no %= 12053;
    $jy     = 979 + 33*$j_np + 4*(int)($j_d_no / 1461); $j_d_no %= 1461;
    if ($j_d_no >= 366) { $jy += (int)(($j_d_no-1)/365); $j_d_no = ($j_d_no-1) % 365; }
    $jMonthDays = [31,31,31,31,31,31,30,30,30,30,30];
    for ($i = 0; $i < 11 && $j_d_no >= $jMonthDays[$i]; $i++) $j_d_no -= $jMonthDays[$i];
    $jm = $i + 1; $jd = $j_d_no + 1;
    return $jd . ' ' . $monthNames[$jm-1] . ' ' . $jy;
}

/**
 * اجزای تاریخ شمسی برای جعبهٔ تاریخ رویدادها (روز / نام ماه / سال).
 * همان الگوریتم persianDate() — فقط به‌شکل ساخت‌یافته برگردانده می‌شود.
 * @return array{day:int,month:string,year:int}|null
 */
function persianDateParts(string $datetime): ?array {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return null;
    $ts = strtotime($datetime);
    if (!$ts) return null;
    $monthNames = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    $parts = explode('-', date('Y-m-d', $ts));
    $gy = (int)$parts[0] - 1600; $gm = (int)$parts[1]; $gd = (int)$parts[2] - 1;
    $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
    $monthDays = [31,28+($leap?1:0),31,30,31,30,31,31,30,31,30,31];
    $g_d_no = 365*$gy + (int)(($gy+3)/4) - (int)(($gy+99)/100) + (int)(($gy+399)/400);
    for ($i = 0; $i < $gm-1; $i++) $g_d_no += $monthDays[$i];
    $g_d_no += $gd;
    $j_d_no = $g_d_no - 79;
    $j_np   = (int)($j_d_no / 12053); $j_d_no %= 12053;
    $jy     = 979 + 33*$j_np + 4*(int)($j_d_no / 1461); $j_d_no %= 1461;
    if ($j_d_no >= 366) { $jy += (int)(($j_d_no-1)/365); $j_d_no = ($j_d_no-1) % 365; }
    $jMonthDays = [31,31,31,31,31,31,30,30,30,30,30];
    for ($i = 0; $i < 11 && $j_d_no >= $jMonthDays[$i]; $i++) $j_d_no -= $jMonthDays[$i];
    return ['day' => $j_d_no + 1, 'month' => $monthNames[$i], 'year' => $jy];
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)      return 'چند لحظه پیش';
    if ($diff < 3600)    return (int)($diff/60) . ' دقیقه پیش';
    if ($diff < 86400)   return (int)($diff/3600) . ' ساعت پیش';
    if ($diff < 2592000) return (int)($diff/86400) . ' روز پیش';
    return persianDate($datetime);
}

// ─── Upload ───────────────────────────────────────────────────────────────────

function imgUrl(string $path): string {
    // No stock image is substituted for absent editorial media. Callers that
    // render an image should test the real field first and show text if needed.
    if (!$path) return '';
    $key = storageKey($path);
    if ($key) return storageUrl($key);
    return siteUrl($path);
}

function uploadImage(array $file, string $subdir = 'posts'): string {
    return uploadFile($file, 'image', $subdir ?: UPLOAD_IMAGES);
}

function uploadAudio(array $file): string {
    return uploadFile($file, 'audio', UPLOAD_AUDIO);
}

function uploadFeaturedVideo(array $file): string {
    return uploadFile($file, 'video', UPLOAD_VIDEO);
}

function renderFeaturedVideo(string $videoPath, string $posterPath = '', string $size = 'card'): string {
    if (!$videoPath) return '';
    $url    = siteUrl($videoPath);
    $poster = $posterPath ? imgUrl($posterPath) : '';
    if ($size === 'card') {
        return sprintf(
            '<button type="button" class="featured-video-play" data-video="%s" data-poster="%s" title="پخش ویدیو"><i class="bi bi-play-fill"></i></button>',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($poster, ENT_QUOTES)
        );
    }
    return sprintf(
        '<div class="featured-video-wrap my-3"><video id="featuredPostVideo" controls playsinline preload="none" poster="%s" class="w-100" style="border-radius:12px;background:#000;max-height:500px"><source src="%s" type="video/mp4">مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.</video></div>',
        htmlspecialchars($poster, ENT_QUOTES),
        htmlspecialchars($url, ENT_QUOTES)
    );
}

function saveBase64Thumbnail(string $base64Data, string $subdir = 'posts'): string {
    if (strlen($base64Data) > MAX_FILE_SIZE * 1.4) return '';
    if (!preg_match('~^data:image/(?:jpeg|png|webp);base64,~', $base64Data)) return '';
    $data = base64_decode(substr($base64Data, strpos($base64Data, ',')+1), true);
    if (!$data) return '';
    $tmp = tempnam(sys_get_temp_dir(), 'jhd-thumb-');
    try { file_put_contents($tmp, $data); return storeValidatedFile($tmp, 'image', $subdir); }
    finally { @unlink($tmp); }
}

// ─── Stubs for legacy migrations (now handled by bin/migrate.php) ─────────────

function ensureFeaturedVideoColumn(): void {}
function ensureLessonsColumns(): void {}
function ensureSpeakerColumn(): void {}

// ─── Media helpers ────────────────────────────────────────────────────────────

function countMediaFor(string $refType, int $refId, string $kind = ''): int {
    try {
        $db = getDB();
        if ($kind) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM media_files WHERE ref_type=? AND ref_id=? AND kind=?");
            $stmt->execute([$refType, $refId, $kind]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM media_files WHERE ref_type=? AND ref_id=?");
            $stmt->execute([$refType, $refId]);
        }
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// ─── Posts ────────────────────────────────────────────────────────────────────

function getPosts(array $opts = []): array {
    $db = jhd_db();
    if ($db === null) return [];
    $where  = ["p.status = 'published'"];
    $params = [];
    if (!empty($opts['type'])) {
        $where[]  = "p.post_type = ?";
        $params[] = $opts['type'];
    }
    // Several post types in one round trip (used by the homepage events strip).
    if (!empty($opts['types']) && is_array($opts['types'])) {
        $types = array_values(array_filter($opts['types'], static fn($t): bool => is_string($t) && $t !== ''));
        if ($types) {
            $where[] = 'p.post_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            foreach ($types as $t) $params[] = $t;
        }
    }
    if (!empty($opts['search'])) {
        $where[]  = "(p.title ILIKE ? OR p.summary ILIKE ? OR p.content ILIKE ?)";
        $s        = '%' . $opts['search'] . '%';
        $params   = array_merge($params, [$s, $s, $s]);
    }
    if (!empty($opts['featured'])) {
        $where[]  = "p.is_featured = 1";
    }
    if (!empty($opts['cat'])) {
        $where[]  = "p.category_id = ?";
        $params[] = (int)$opts['cat'];
    }
    if (!empty($opts['topic'])) {
        // filter by topic via junction
        $where[]  = "EXISTS (SELECT 1 FROM post_topics pt WHERE pt.post_id=p.id AND pt.topic_id=?)";
        $params[] = (int)$opts['topic'];
    }
    if (!empty($opts['section'])) {
        $where[]  = "? = ANY(string_to_array(REPLACE(p.page_section, ' ', ''), ','))";
        $params[] = $opts['section'];
    }
    $limit  = isset($opts['limit'])  ? (int)$opts['limit']  : (int)POSTS_PER_PAGE;
    $offset = isset($opts['offset']) ? (int)$opts['offset'] : 0;
    if ($limit  < 1)   $limit  = 1;
    if ($limit  > 100) $limit  = 100;
    if ($offset < 0)   $offset = 0;
    $whereStr = implode(' AND ', $where);
    $sql = "SELECT p.*, c.name AS cat_name, COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name FROM posts p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.author_id WHERE $whereStr ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC LIMIT $limit OFFSET $offset";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('getPosts failed: ' . $e->getMessage());
        return [];
    }
}

function countPosts(array $opts = []): int {
    $db = jhd_db();
    if ($db === null) return 0;
    $where  = ["p.status = 'published'"];
    $params = [];
    if (!empty($opts['type'])) { $where[]="p.post_type = ?"; $params[]=$opts['type']; }
    if (!empty($opts['search'])) { $where[]="(p.title ILIKE ? OR p.summary ILIKE ? OR p.content ILIKE ?)"; $s='%'.$opts['search'].'%'; $params=array_merge($params,[$s,$s,$s]); }
    if (!empty($opts['cat'])) { $where[]="p.category_id = ?"; $params[]=(int)$opts['cat']; }
    if (!empty($opts['topic'])) { $where[]="EXISTS (SELECT 1 FROM post_topics pt WHERE pt.post_id=p.id AND pt.topic_id=?)"; $params[]=(int)$opts['topic']; }
    if (!empty($opts['section'])) { $where[]="? = ANY(string_to_array(REPLACE(p.page_section, ' ', ''), ','))"; $params[]=$opts['section']; }
    $whereStr = implode(' AND ', $where);
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM posts p WHERE $whereStr");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('countPosts failed: ' . $e->getMessage());
        return 0;
    }
}

function getPost(int $id): ?array {
    $db = jhd_db();
    if ($db === null) return null;
    try {
        $stmt = $db->prepare("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name FROM posts p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.author_id WHERE p.id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) { return null; }
}

function getPostBySlug(string $slug): ?array {
    $db = jhd_db();
    if ($db === null) return null;
    try {
        $stmt = $db->prepare("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name FROM posts p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.author_id WHERE p.slug = ? AND p.status = 'published' LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) { return null; }
}

// ─── Topics (core) ───────────────────────────────────────────────────────────

function getTopics(array $opts = []): array {
    // The same topic list is requested several times per page (header tree,
    // footer list, topic chips). Memoize per argument set for this request.
    static $memo = [];
    $memoKey = json_encode($opts);
    if (is_string($memoKey) && array_key_exists($memoKey, $memo)) return $memo[$memoKey];
    try {
        $db = getDB();
        $where = ["1=1"]; $params=[];
        if (isset($opts['active'])) { $where[]="is_active=?"; $params[]=(int)$opts['active']; }
        if (isset($opts['featured'])) { $where[]="is_featured=?"; $params[]=(int)$opts['featured']; }
        if (array_key_exists('parent', $opts)) {
            if ($opts['parent'] === null) $where[]="parent_id IS NULL";
            else { $where[]="parent_id=?"; $params[]=(int)$opts['parent']; }
        }
        $whereStr = implode(' AND ', $where);
        // Never silently truncate taxonomy rows: relation forms and topic hubs
        // must be able to reach every real topic. Callers that deliberately
        // render a preview pass their own explicit limit.
        $limitSql = '';
        if (array_key_exists('limit', $opts)) {
            $limit = (int)$opts['limit'];
            if ($limit > 0) $limitSql = ' LIMIT ' . $limit;
        }
        $sql = "SELECT * FROM topics WHERE $whereStr ORDER BY sort_order ASC, name ASC$limitSql";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        jhd_prime_topic_cache($rows);
        if (is_string($memoKey)) $memo[$memoKey] = $rows;
        return $rows;
    } catch (PDOException $e) { return []; }
}

/**
 * همهٔ موضوعات به شکل فهرست تختِ درختی (با کلید depth) برای فیلترها و
 * فهرست‌های کشویی مدیریت. `$maxDepth` سطح‌ها را محدود می‌کند.
 */
function getTopicFlatTree(?int $parent = null, int $maxDepth = 4): array {
    $all = getTopics();
    if (!$all) return [];
    $byParent = [];
    foreach ($all as $t) {
        $pid = $t['parent_id'] === null || $t['parent_id'] === '' ? 0 : (int)$t['parent_id'];
        $byParent[$pid][] = $t;
    }
    $out = [];
    $walk = function ($parentId, $depth) use (&$walk, &$out, $byParent, $maxDepth) {
        if ($depth > $maxDepth) return;
        foreach ($byParent[$parentId] ?? [] as $t) {
            $t['depth'] = $depth;
            $out[] = $t;
            $walk((int)$t['id'], $depth + 1);
        }
    };
    $walk($parent === null ? 0 : $parent, $parent === null ? 0 : 1);
    return $out;
}

function getTopicBySlug(string $slug): ?array {
    try {
        $slug = trim(str_replace('\\', '/', rawurldecode($slug)), '/');
        if ($slug === '') return null;
        if (str_contains($slug, '/')) {
            $parts = array_values(array_filter(explode('/', $slug), static fn($s) => $s !== '' && $s !== '.' && $s !== '..'));
            $leaf = $parts ? (string)end($parts) : '';
            $topic = $leaf !== '' ? getTopicBySlug($leaf) : null;
            if (!$topic) return null;
            if (count($parts) > 1) {
                $crumbs = getTopicBreadcrumbs((int)$topic['id']);
                $crumbSlugs = array_map(static fn($c) => (string)($c['slug'] ?? ''), $crumbs);
                if ($crumbSlugs && $crumbSlugs !== $parts) {
                    // Parent path is advisory: the leaf slug is canonical and unique.
                }
            }
            return $topic;
        }
        $db=getDB();
        $stmt=$db->prepare("SELECT * FROM topics WHERE slug=? LIMIT 1");
        $stmt->execute([$slug]);
        $row=$stmt->fetch(); return $row ?: null;
    } catch (PDOException $e) { return null; }
}

/**
 * Request-scoped identity map for topic rows.
 *
 * Measured reason: topicUrl() builds the /topics/parent/child path through
 * getTopicBreadcrumbs(), which walks the parent chain one getTopicById() query
 * at a time — for every topic link on the page, and the navigation tree is
 * rendered twice (desktop menu + mobile drawer). The profiler logged
 * `85x SELECT * FROM topics WHERE id=?` on the homepage and `72x` on every
 * other page. The rows are tiny and already fetched by getTopics(), so they
 * are cached here and primed in bulk below.
 *
 * @return array<int,array|null>
 */
function &jhd_topic_row_cache(): array {
    static $cache = [];
    return $cache;
}

/** Remember topic rows we already hold (called with every getTopics() result). */
function jhd_prime_topic_cache(array $rows): void {
    $cache =& jhd_topic_row_cache();
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = (int)($row['id'] ?? 0);
        if ($id > 0 && !array_key_exists($id, $cache)) $cache[$id] = $row;
    }
}

function getTopicById(int $id): ?array {
    if ($id < 1) return null;
    $cache =& jhd_topic_row_cache();
    if (array_key_exists($id, $cache)) return $cache[$id];
    try {
        $db=getDB(); $stmt=$db->prepare("SELECT * FROM topics WHERE id=?"); $stmt->execute([$id]); $row=$stmt->fetch();
        return $cache[$id] = ($row ?: null);
    } catch(PDOException $e){ return $cache[$id] = null; }
}

function getTopicTree(array $opts = []): array {
    $topicOpts = ['active' => array_key_exists('active', $opts) ? $opts['active'] : 1];
    if (array_key_exists('limit', $opts)) $topicOpts['limit'] = $opts['limit'];
    $all = getTopics($topicOpts);
    // map id => children
    $byParent = [];
    foreach ($all as $t) {
        $pid = $t['parent_id'] ?? null;
        $byParent[$pid === null ? 0 : (int)$pid][] = $t;
    }
    $build = function($parentId) use (&$build, $byParent) {
        $out=[];
        $key = $parentId === null ? 0 : $parentId;
        foreach ($byParent[$key] ?? [] as $node) {
            $node['children'] = $build((int)$node['id']);
            $out[]=$node;
        }
        return $out;
    };
    return $build(null);
}

function getTopicChildren(int $parentId): array {
    try {
        $stmt=getDB()->prepare("SELECT * FROM topics WHERE parent_id=? AND is_active=1 ORDER BY sort_order ASC, name ASC");
        $stmt->execute([$parentId]); return $stmt->fetchAll();
    } catch(PDOException $e){ return []; }
}

function getTopicBreadcrumbs(int $topicId): array {
    static $memo = [];
    if (array_key_exists($topicId, $memo)) return $memo[$topicId];
    $crumbs=[]; $current=getTopicById($topicId);
    $guard=0;
    while($current && $guard<10){
        array_unshift($crumbs,$current);
        if(empty($current['parent_id'])) break;
        $current=getTopicById((int)$current['parent_id']);
        $guard++;
    }
    return $memo[$topicId] = $crumbs;
}

function getTopicsForPost(int $postId): array {
    try{
        $db=getDB();
        $stmt=$db->prepare("SELECT t.* FROM topics t JOIN post_topics pt ON pt.topic_id=t.id WHERE pt.post_id=? ORDER BY t.sort_order, t.id");
        $stmt->execute([$postId]); return $stmt->fetchAll();
    }catch(PDOException $e){ return []; }
}
function getTopicsForLesson(int $lessonId): array {
    try{
        $db=getDB();
        $stmt=$db->prepare("SELECT t.* FROM topics t JOIN lesson_topics lt ON lt.topic_id=t.id WHERE lt.lesson_id=? ORDER BY t.sort_order");
        $stmt->execute([$lessonId]); return $stmt->fetchAll();
    }catch(PDOException $e){ return []; }
}
function getTopicsForBook(int $bookId): array {
    try{
        $db=getDB();
        $stmt=$db->prepare("SELECT t.* FROM topics t JOIN book_topics bt ON bt.topic_id=t.id WHERE bt.book_id=? ORDER BY t.sort_order");
        $stmt->execute([$bookId]); return $stmt->fetchAll();
    }catch(PDOException $e){ return []; }
}

function setPostTopics(int $postId, array $topicIds, ?int $primaryId = null): void {
    $db=getDB();
    $db->prepare("DELETE FROM post_topics WHERE post_id=?")->execute([$postId]);
    $ids = array_values(array_unique(array_filter(array_map('intval', $topicIds))));
    if ($primaryId && $primaryId > 0 && !in_array($primaryId, $ids, true)) {
        array_unshift($ids, $primaryId);
    }
    $first = true;
    foreach ($ids as $tid) {
        $isPrimary = $primaryId ? ($tid === $primaryId) : $first;
        try {
            $db->prepare("INSERT INTO post_topics (post_id, topic_id, is_primary) VALUES (?,?,?) ON CONFLICT DO NOTHING")->execute([$postId, $tid, $isPrimary ? 1 : 0]);
        } catch (PDOException $e) {
            $db->prepare("INSERT INTO post_topics (post_id, topic_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$postId, $tid]);
        }
        $first = false;
    }
}
function setLessonTopics(int $lessonId, array $topicIds): void {
    $db=getDB();
    $db->prepare("DELETE FROM lesson_topics WHERE lesson_id=?")->execute([$lessonId]);
    foreach(array_unique(array_filter(array_map('intval',$topicIds))) as $tid){
        $db->prepare("INSERT INTO lesson_topics (lesson_id, topic_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$lessonId,$tid]);
    }
}
function setBookTopics(int $bookId, array $topicIds): void {
    $db=getDB();
    $db->prepare("DELETE FROM book_topics WHERE book_id=?")->execute([$bookId]);
    foreach(array_unique(array_filter(array_map('intval',$topicIds))) as $tid){
        $db->prepare("INSERT INTO book_topics (book_id, topic_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$bookId,$tid]);
    }
}


function getTopicIdsForPost(int $postId): array {
    return array_map('intval', array_column(getTopicsForPost($postId),'id'));
}
function getTopicIdsForBook(int $bookId): array {
    return array_map('intval', array_column(getTopicsForBook($bookId),'id'));
}
function getTopicIdsForLesson(int $lessonId): array {
    return array_map('intval', array_column(getTopicsForLesson($lessonId),'id'));
}

/** Return this topic plus every descendant, with a cycle guard for legacy data. */
/**
 * نقشهٔ «والد → فرزندان» موضوعات، یک‌بار در هر درخواست.
 * پیش از این هر بار که دامنهٔ یک موضوع لازم می‌شد کل جدول topics خوانده
 * می‌شد؛ در صفحهٔ اطلس این یعنی ده‌ها کوئری تکراری. اکنون یک کوئری کافی است.
 */
function jhd_topic_children_map(): array {
    static $children = null;
    if ($children !== null) return $children;
    $children = [];
    try {
        foreach (getDB()->query('SELECT id, parent_id FROM topics')->fetchAll() as $row) {
            if ($row['parent_id'] !== null) $children[(int)$row['parent_id']][] = (int)$row['id'];
        }
    } catch (Throwable) {
        $children = [];
    }
    return $children;
}

function getTopicScopeIds(int $topicId): array {
    if ($topicId < 1) return [];
    static $memo = [];
    if (isset($memo[$topicId])) return $memo[$topicId];
    try {
        $children = jhd_topic_children_map();
        $seen = []; $queue = [$topicId];
        while ($queue) {
            $id = array_shift($queue);
            if ($id < 1 || isset($seen[$id])) continue;
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $child) $queue[] = $child;
        }
        return $memo[$topicId] = array_map('intval', array_keys($seen));
    } catch (Throwable $e) { return [$topicId]; }
}

/** SQL fragment for content attached to a topic or one of its subtopics. */
function topicScopeWhere(string $junctionAlias, string $column, array $topicIds, array &$params): string {
    if (!$topicIds) return '1=0';
    $marks = implode(',', array_fill(0, count($topicIds), '?'));
    foreach ($topicIds as $id) $params[] = (int)$id;
    return "$junctionAlias.$column IN ($marks)";
}

function getPostsByTopic(int $topicId, array $opts = []): array {
    $scope = !empty($opts['exact']) ? [$topicId] : (array)($opts['scope_ids'] ?? getTopicScopeIds($topicId));
    $type = $opts['type'] ?? null;
    $limit = max(1, min(100, (int)($opts['limit'] ?? 6)));
    $offset = max(0, (int)($opts['offset'] ?? 0));
    $sort = ($opts['sort'] ?? 'newest') === 'oldest' ? 'ASC' : 'DESC';
    try {
        $params = [];
        $where = ["p.status='published'", topicScopeWhere('pt', 'topic_id', $scope, $params)];
        if ($type) { $where[] = 'p.post_type=?'; $params[] = $type; }
        $excludeTypes = array_values(array_filter((array)($opts['exclude_types'] ?? []), static fn($value): bool => is_string($value) && $value !== ''));
        if ($excludeTypes) {
            $marks = implode(',', array_fill(0, count($excludeTypes), '?'));
            $where[] = "p.post_type NOT IN ($marks)";
            foreach ($excludeTypes as $excludeType) $params[] = $excludeType;
        }
        $stmt = getDB()->prepare("SELECT DISTINCT p.*, c.name AS cat_name FROM posts p JOIN post_topics pt ON pt.post_id=p.id LEFT JOIN categories c ON c.id=p.category_id WHERE " . implode(' AND ', $where) . " ORDER BY p.published_at $sort, p.id $sort LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}
function countPostsByTopic(int $topicId, ?string $type = null, ?array $scopeIds = null): int {
    try {
        $params = [];
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        $where = ["p.status='published'", topicScopeWhere('pt', 'topic_id', $scope, $params)];
        if ($type) { $where[] = 'p.post_type=?'; $params[] = $type; }
        $stmt = getDB()->prepare('SELECT COUNT(DISTINCT p.id) FROM post_topics pt JOIN posts p ON p.id=pt.post_id WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}
function getLessonsByTopic(int $topicId, int $limit=6, int $offset=0, string $sort='newest', ?array $scopeIds = null): array {
    try {
        $params = [];
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        $where = ["l.status='published'", topicScopeWhere('lt', 'topic_id', $scope, $params)];
        $order = $sort === 'oldest' ? 'ASC' : 'DESC';
        $stmt = getDB()->prepare('SELECT DISTINCT l.* FROM lessons l JOIN lesson_topics lt ON lt.lesson_id=l.id WHERE ' . implode(' AND ', $where) . " ORDER BY l.created_at $order, l.id $order LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [max(1, min(100, $limit)), max(0, $offset)]));
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}
function countLessonsByTopic(int $topicId, ?array $scopeIds = null): int {
    try {
        $params = [];
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        $stmt = getDB()->prepare("SELECT COUNT(DISTINCT l.id) FROM lessons l JOIN lesson_topics lt ON lt.lesson_id=l.id WHERE l.status='published' AND " . topicScopeWhere('lt', 'topic_id', $scope, $params));
        $stmt->execute($params); return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}
function getBooksByTopic(int $topicId, int $limit=6, int $offset=0, string $sort='newest', ?array $scopeIds = null): array {
    try {
        $params = [];
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        $order = $sort === 'oldest' ? 'ASC' : 'DESC';
        $stmt = getDB()->prepare("SELECT DISTINCT b.* FROM books b JOIN book_topics bt ON bt.book_id=b.id WHERE b.status='published' AND " . topicScopeWhere('bt', 'topic_id', $scope, $params) . " ORDER BY b.created_at $order, b.id $order LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [max(1, min(100, $limit)), max(0, $offset)]));
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}
function countBooksByTopic(int $topicId, ?array $scopeIds = null): int {
    try {
        $params = [];
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        $stmt = getDB()->prepare("SELECT COUNT(DISTINCT b.id) FROM books b JOIN book_topics bt ON bt.book_id=b.id WHERE b.status='published' AND " . topicScopeWhere('bt', 'topic_id', $scope, $params));
        $stmt->execute($params); return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

// ─── Lessons with collections ─────────────────────────────────────────────────

function getLesson(int $id): ?array {
    $db = jhd_db();
    if ($db === null) return null;
    try {
        $stmt = $db->prepare("SELECT * FROM lessons WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) { return null; }
}

/** Published lesson with its collection/volume titles (detail-page resolver). */
function getLessonBySlug(string $slug): ?array {
    if($slug==='') return null;
    try{
        $stmt=getDB()->prepare("SELECT l.*, lc.title AS collection_title, lc.slug AS collection_slug, lv.title AS volume_title, lv.slug AS volume_slug FROM lessons l LEFT JOIN lesson_collections lc ON lc.id=l.collection_id LEFT JOIN lesson_volumes lv ON lv.id=l.volume_id WHERE l.slug=? AND l.status='published' LIMIT 1");
        $stmt->execute([$slug]);
        $row=$stmt->fetch(); return $row ?: null;
    }catch(PDOException $e){ return null; }
}

function getLessonCollections(array $opts=[]): array {
    // Requested once per rendered navigation (desktop menu + mobile drawer).
    static $memo = [];
    $memoKey = json_encode($opts);
    if (is_string($memoKey) && array_key_exists($memoKey, $memo)) return $memo[$memoKey];
    try{
        $db=getDB();
        $where=["1=1"]; $params=[];
        if(isset($opts['active'])){ $where[]="is_active=?"; $params[]=(int)$opts['active']; }
        if(isset($opts['featured'])){ $where[]="is_featured=?"; $params[]=(int)$opts['featured']; }
        $whereStr=implode(' AND ',$where);
        $stmt=$db->prepare("SELECT * FROM lesson_collections WHERE $whereStr ORDER BY sort_order ASC, title ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (is_string($memoKey)) $memo[$memoKey] = $rows;
        return $rows;
    }catch(PDOException $e){ return []; }
}
function getLessonCollectionBySlug(string $slug): ?array {
    try{ $stmt=getDB()->prepare("SELECT * FROM lesson_collections WHERE slug=? LIMIT 1"); $stmt->execute([$slug]); $row=$stmt->fetch(); return $row ?: null; }catch(PDOException $e){ return null; }
}
function getLessonVolumes(int $collectionId): array {
    try{ $stmt=getDB()->prepare("SELECT * FROM lesson_volumes WHERE collection_id=? ORDER BY sort_order ASC, title ASC"); $stmt->execute([$collectionId]); return $stmt->fetchAll(); }catch(PDOException $e){ return []; }
}
function getLessonVolumeById(int $id): ?array {
    try{ $stmt=getDB()->prepare("SELECT * FROM lesson_volumes WHERE id=?"); $stmt->execute([$id]); $row=$stmt->fetch(); return $row ?: null; }catch(PDOException $e){ return null; }
}
function getLessonsByCollection(int $collectionId, int $volumeId=0, int $limit=100): array {
    try{
        $db=getDB();
        if($volumeId){
            $stmt=$db->prepare("SELECT * FROM lessons WHERE collection_id=? AND volume_id=? AND status='published' ORDER BY lesson_number ASC NULLS LAST, sort_order ASC, id ASC LIMIT $limit");
            $stmt->execute([$collectionId,$volumeId]);
        } else {
            $stmt=$db->prepare("SELECT * FROM lessons WHERE collection_id=? AND status='published' ORDER BY volume_id ASC NULLS FIRST, lesson_number ASC NULLS LAST, sort_order ASC LIMIT $limit");
            $stmt->execute([$collectionId]);
        }
        return $stmt->fetchAll();
    }catch(PDOException $e){ return []; }
}
function getAdjacentLesson(array $lesson): array {
    // next / prev within same collection/volume ordered by lesson_number
    try{
        $db=getDB();
        $curNum = $lesson['lesson_number'] ?? null;
        $col = $lesson['collection_id']; $vol = $lesson['volume_id'];
        if(!$col || $curNum===null) return ['prev'=>null,'next'=>null];
        $prev = $db->prepare("SELECT * FROM lessons WHERE collection_id=? AND ".($vol?"volume_id=? AND ":"")." lesson_number < ? AND status='published' ORDER BY lesson_number DESC LIMIT 1");
        $next = $db->prepare("SELECT * FROM lessons WHERE collection_id=? AND ".($vol?"volume_id=? AND ":"")." lesson_number > ? AND status='published' ORDER BY lesson_number ASC LIMIT 1");
        if($vol){ $prev->execute([$col,$vol,$curNum]); $next->execute([$col,$vol,$curNum]);}
        else { $prev->execute([$col,$curNum]); $next->execute([$col,$curNum]);}
        return ['prev'=>$prev->fetch() ?: null, 'next'=>$next->fetch() ?: null];
    }catch(PDOException $e){ return ['prev'=>null,'next'=>null]; }
}

/**
 * مطلب قبلی/بعدی در همان نوع محتوا (بر اساس تاریخ انتشار).
 * برای ناوبری پایین صفحهٔ مطلب؛ هر دو طرف فقط مطالب منتشرشده را می‌بینند.
 */
function getAdjacentPosts(int $postId): array {
    if ($postId < 1) return ['prev' => null, 'next' => null];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT post_type, published_at FROM posts WHERE id = ? LIMIT 1");
        $stmt->execute([$postId]);
        $row = $stmt->fetch();
        if (!$row) return ['prev' => null, 'next' => null];
        $type = (string)$row['post_type'];
        $prev = $db->prepare("SELECT * FROM posts WHERE post_type = ? AND status = 'published' AND published_at < ? ORDER BY published_at DESC LIMIT 1");
        $next = $db->prepare("SELECT * FROM posts WHERE post_type = ? AND status = 'published' AND published_at > ? ORDER BY published_at ASC LIMIT 1");
        $prev->execute([$type, $row['published_at']]);
        $next->execute([$type, $row['published_at']]);
        return ['prev' => $prev->fetch() ?: null, 'next' => $next->fetch() ?: null];
    } catch (Throwable $e) {
        return ['prev' => null, 'next' => null];
    }
}

// ─── Categories (legacy) ──────────────────────────────────────────────────────

function getCategories(): array {
    // فهرست دسته‌ها در یک صفحه چندین‌بار لازم می‌شود (منو، فرم، چیپ‌ها)؛
    // یک‌بار خوانده و در همان درخواست بازاستفاده می‌شود.
    static $cache = null;
    if ($cache !== null) return $cache;
    $db = jhd_db();
    if ($db === null) return [];
    try {
        $stmt = $db->query("SELECT c.*, COUNT(p.id) AS post_count FROM categories c LEFT JOIN posts p ON p.category_id = c.id AND p.status = 'published' GROUP BY c.id ORDER BY c.sort_order ASC, c.name ASC");
        return $cache = $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}
function getCategoryBySlug(string $slug): ?array {
    $db = jhd_db();
    if ($db === null) return null;
    try { $stmt=$db->prepare("SELECT * FROM categories WHERE slug = ? LIMIT 1"); $stmt->execute([$slug]); $row=$stmt->fetch(); return $row ?: null; } catch (PDOException $e) { return null; }
}

// ─── Settings ─────────────────────────────────────────────────────────────────

function getSetting(string $key, string $default = ''): string {
    if (!isset($GLOBALS['jhd_setting_cache']) || !is_array($GLOBALS['jhd_setting_cache'])) {
        $cache = [];
        $db = jhd_db();
        // setting_key: "key" is a reserved word in MySQL.
        if ($db !== null) {
            try {
                $rows = $db->query("SELECT setting_key, value FROM settings")->fetchAll();
                $cache = array_column($rows, 'value', 'setting_key');
            } catch (PDOException $e) {
                $cache = [];
            }
        }
        $GLOBALS['jhd_setting_cache'] = $cache;
    }
    return (string)($GLOBALS['jhd_setting_cache'][$key] ?? $default);
}

/** Drop the per-request cache after settings are changed by the admin. */
function clearSettingCache(): void {
    unset($GLOBALS['jhd_setting_cache']);
}

/**
 * تنظیمات نمایش صفحهٔ اصلی — ذخیره‌شده در settings تا بدون migration جدید
 * در MySQL و PostgreSQL هر دو قابل مدیریت باشد.
 */
function jhd_homepage_settings(): array {
    $defaults = [
        'sections' => [
            'hero' => true,
            'editor_picks' => true,
            'latest' => false,
            'news' => true,
            'articles' => true,
            'reports' => true,
            'research' => true,
            'topics' => true,
            'events' => true,
            'books' => true,
            'lessons' => true,
        ],
        'hero_post_id' => 0,
    ];
    $raw = trim(getSetting('homepage_config', ''));
    if ($raw === '') return $defaults;
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) return $defaults;

    foreach ($defaults['sections'] as $key => $enabled) {
        if (array_key_exists($key, $cfg['sections'] ?? [])) {
            $defaults['sections'][$key] = (bool)$cfg['sections'][$key];
        }
    }
    $defaults['hero_post_id'] = max(0, (int)($cfg['hero_post_id'] ?? 0));
    return $defaults;
}

/**
 * دریافت چند مطلب منتشرشده با شناسهٔ مشخص، با همان شکل داده‌ای getPosts().
 * ترتیب شناسه‌های ورودی در نتیجه حفظ می‌شود تا انتخاب مدیر در صفحهٔ اصلی
 * دقیقاً همان ترتیبی را که ثبت شده نشان دهد.
 */
function getPostsByIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    try {
        $db = getDB();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
                    COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name
             FROM posts p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.status = 'published' AND p.id IN ($marks)"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
        $byId = [];
        foreach ($rows as $row) $byId[(int)$row['id']] = $row;
        $ordered = [];
        foreach ($ids as $id) if (isset($byId[$id])) $ordered[] = $byId[$id];
        return $ordered;
    } catch (Throwable) {
        return [];
    }
}

// ─── Banners ──────────────────────────────────────────────────────────────────

function getActiveBanners(int $limit=3): array {
    // Prefer featured_banners (new dynamic announcement) then fallback to site_banners
    try{
        $db=getDB();
        try{
            $stmt=$db->prepare("SELECT id, title, description AS content, image, link_url AS link, button_text AS link_text, is_active, sort_order, created_at FROM featured_banners WHERE is_active=1 ORDER BY sort_order ASC, id DESC LIMIT $limit");
            $stmt->execute(); $rows=$stmt->fetchAll();
            if($rows) return $rows;
        }catch(PDOException $e){}
        $stmt=$db->prepare("SELECT * FROM site_banners WHERE is_active=1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY sort_order ASC, id DESC LIMIT $limit");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        // Both legacy site_banners and featured_banners feed the same public
        // component. Normalize their historical link column names here.
        foreach ($rows as &$row) {
            $row['link'] = (string)($row['link'] ?? $row['link_url'] ?? '');
            $row['link_text'] = (string)($row['link_text'] ?? '');
        }
        unset($row);
        return $rows;
    }catch(PDOException $e){ return []; }
}
function getActiveBanner(): ?array {
    $banners=getActiveBanners(1);
    return $banners[0] ?? null;
}

/**
 * Render a real 404 for an out-of-range public archive page.
 *
 * Pagination pages are indexable only while they actually exist. This helper
 * is deliberately presentation-safe: it changes HTTP status and SEO metadata
 * but never touches database state.
 */
function jhd_pagination_not_found(string $sectionLabel, string $backUrl, string $backLabel = 'بازگشت'): never {
    http_response_code(404);
    $pageTitle = 'صفحه یافت نشد | ' . $sectionLabel;
    $pageDesc = 'صفحه درخواستی در بخش «' . $sectionLabel . '» وجود ندارد.';
    $noindexSeo = true;
    $canonical = '';
    require __DIR__ . '/header.php';
    echo '<main class="container py-5 text-center">';
    echo '<h1 class="h3 fw-bold">صفحه مورد نظر یافت نشد</h1>';
    echo '<p class="text-muted">شماره صفحه خارج از محدوده این بخش است.</p>';
    echo '<a class="btn btn-primary mt-3" href="' . sanitize($backUrl) . '">' . sanitize($backLabel) . '</a>';
    echo '</main>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Validate a public pagination number before any empty result page is rendered. */
function jhd_validate_pagination(int $page, int $total, int $limit, string $sectionLabel, string $backUrl, string $backLabel): void {
    $safeLimit = max(1, $limit);
    $pageCount = max(1, (int)ceil(max(0, $total) / $safeLimit));
    if ($page < 1 || $page > $pageCount) {
        jhd_pagination_not_found($sectionLabel, $backUrl, $backLabel);
    }
}

/**
 * Promote known, content-backed standalone section headings inside long article
 * bodies to H2. No new text is introduced: a heading is converted only when the
 * exact phrase already exists as its own separated line in the stored content.
 */
/**
 * Normalize a Persian title for conservative homepage duplicate detection.
 * Only editorially obvious duplicates are collapsed; the database is not changed.
 */
function jhd_story_key(string $text): string {
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, [
        'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه',
        'ة' => 'ه', 'ؤ' => 'و', 'إ' => 'ا', 'أ' => 'ا',
        '‌' => ' ',
    ]);
    $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
    $text = preg_replace('/[«»"“”\'،؛,:.!?؟()\[\]{}<>|\\\/\-_]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    $text = preg_replace('/^(خبر|گزارش|گزارش تصویری|اطلاعیه|رویداد|اخبار)\s+/u', '', $text) ?? $text;
    return trim($text);
}

/** True when two public post records are clearly the same editorial story. */
function jhd_posts_same_story(array $a, array $b): bool {
    if ((int)($a['id'] ?? 0) > 0 && (int)($a['id'] ?? 0) === (int)($b['id'] ?? 0)) return true;
    $ak = jhd_story_key((string)($a['title'] ?? ''));
    $bk = jhd_story_key((string)($b['title'] ?? ''));
    if ($ak !== '' && $ak === $bk) return true;

    $aWords = array_values(array_unique(array_filter(preg_split('/\s+/u', $ak) ?: [], static fn(string $w): bool => mb_strlen($w, 'UTF-8') >= 2)));
    $bWords = array_values(array_unique(array_filter(preg_split('/\s+/u', $bk) ?: [], static fn(string $w): bool => mb_strlen($w, 'UTF-8') >= 2)));
    if (count($aWords) < 5 || count($bWords) < 5) return false;

    $intersection = count(array_intersect($aWords, $bWords));
    $union = count(array_unique(array_merge($aWords, $bWords)));
    return $union > 0 && ($intersection / $union) >= 0.82;
}

/**
 * Filter homepage post candidates against already accepted stories.
 * Priority is determined by call order: earlier sections keep the story.
 */
function jhd_homepage_unique_posts(array $candidates, array &$accepted, int $limit = 10): array {
    $out = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) continue;
        $duplicate = false;
        foreach ($accepted as $kept) {
            if (jhd_posts_same_story($candidate, $kept)) {
                $duplicate = true;
                break;
            }
        }
        if ($duplicate) continue;
        $out[] = $candidate;
        $accepted[] = $candidate;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Build a small, evidence-backed internal-link map from real topics and
 * published content. Empty topics are ignored, so we never create dead links.
 */
function jhd_contextual_link_targets(): array {
    static $targets = null;
    if (is_array($targets)) return $targets;
    $targets = [];
    $db = jhd_db();
    if ($db === null) return $targets;

    try {
        $topicSql = "
            SELECT t.id, t.name, t.slug,
                   (
                     (SELECT COUNT(*) FROM post_topics pt JOIN posts p ON p.id = pt.post_id WHERE pt.topic_id=t.id AND p.status='published')
                     + (SELECT COUNT(*) FROM book_topics bt JOIN books b ON b.id = bt.book_id WHERE bt.topic_id=t.id AND b.status='published')
                     + (SELECT COUNT(*) FROM lesson_topics lt JOIN lessons l ON l.id = lt.lesson_id WHERE lt.topic_id=t.id AND l.status='published')
                   ) AS linked_count
            FROM topics t
            WHERE t.is_active = 1
            ORDER BY LENGTH(t.name) DESC, t.sort_order ASC, t.id ASC
        ";
        $stmt = $db->query($topicSql);
        foreach ($stmt->fetchAll() as $row) {
            if ((int)($row['linked_count'] ?? 0) < 1) continue;
            $label = trim((string)($row['name'] ?? ''));
            if (mb_strlen($label, 'UTF-8') < 3) continue;
            $targets[] = [
                'label' => $label,
                'url' => topicUrl($row),
                'kind' => 'topic',
                'hint' => 'موضوع: ' . $label,
            ];
        }

        $stmt = $db->query("
            SELECT id, title, slug, post_type
            FROM posts
            WHERE status='published'
              AND TRIM(COALESCE(title,'')) <> ''
            ORDER BY published_at DESC NULLS LAST, id DESC
            LIMIT 100
        ");
        foreach ($stmt->fetchAll() as $row) {
            $label = trim((string)($row['title'] ?? ''));
            if (mb_strlen($label, 'UTF-8') < 8) continue;
            $targets[] = [
                'label' => $label,
                'url' => postUrl($row),
                'kind' => 'post',
                'hint' => 'مطالعه: ' . $label,
                'post_id' => (int)($row['id'] ?? 0),
            ];
        }

        usort($targets, static function(array $a, array $b): int {
            $len = mb_strlen((string)$b['label'], 'UTF-8') <=> mb_strlen((string)$a['label'], 'UTF-8');
            if ($len !== 0) return $len;
            return strcmp((string)$a['kind'], (string)$b['kind']);
        });

        $unique = [];
        $seen = [];
        foreach ($targets as $target) {
            $key = (string)$target['label'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $target;
        }
        return $targets = $unique;
    } catch (Throwable $e) {
        return $targets = [];
    }
}

/**
 * Add a few contextual internal links inside rich article text.
 * Links are inserted only into ordinary text nodes — never into existing
 * anchors, headings, code, quotes or attributes — and each target is linked
 * at most once per page to avoid keyword stuffing.
 */
function jhd_add_contextual_internal_links(string $html, int $currentPostId = 0, int $maxLinks = 6): string {
    if ($html === '' || $maxLinks < 1) return $html;
    $targets = jhd_contextual_link_targets();
    if (!$targets) return $html;

    // Do not create a self-link to the article currently being read.
    if ($currentPostId > 0) {
        $targets = array_values(array_filter(
            $targets,
            static fn(array $target): bool => (int)($target['post_id'] ?? 0) !== $currentPostId
        ));
    }
    if (!$targets) return $html;

    $dom = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="UTF-8"?><div id="jhd-autolink-root">' . $html . '</div>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $dom->getElementById('jhd-autolink-root');
    if (!$root) return $html;

    $linkedTargetKeys = [];
    $linkedCount = 0;
    $blockedTags = ['a'=>true,'h1'=>true,'h2'=>true,'h3'=>true,'h4'=>true,'h5'=>true,'h6'=>true,'code'=>true,'pre'=>true,'script'=>true,'style'=>true,'button'=>true,'blockquote'=>true];

    // Re-scan after every insertion so multiple different concepts in the same
    // paragraph can receive links, while each target still appears only once.
    while ($linkedCount < $maxLinks) {
        $textNodes = [];
        $walk = function(DOMNode $node) use (&$walk,&$textNodes,$blockedTags): void {
            if ($node instanceof DOMElement && isset($blockedTags[strtolower($node->tagName)])) return;
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMText) {
                    $textNodes[] = $child;
                } elseif ($child instanceof DOMElement || $child->hasChildNodes()) {
                    $walk($child);
                }
            }
        };
        $walk($root);

        $linkedThisPass = false;
        foreach ($textNodes as $textNode) {
            if ($linkedCount >= $maxLinks) break;
            $text = $textNode->nodeValue;
            if (!is_string($text) || trim($text) === '') continue;

            $parent = $textNode->parentNode;
            if (!$parent || ($parent instanceof DOMElement && isset($blockedTags[strtolower($parent->tagName)]))) continue;

            foreach ($targets as $target) {
                if ($linkedCount >= $maxLinks) break;
                $label = trim((string)($target['label'] ?? ''));
                $url = trim((string)($target['url'] ?? ''));
                if ($label === '' || $url === '') continue;

                $targetKey = ($target['kind'] ?? '') . '|' . $label;
                if (isset($linkedTargetKeys[$targetKey])) continue;

                $pattern = '~' . preg_quote($label, '~') . '~u';
                if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;

                $offset = (int)$m[0][1];
                $matchText = (string)$m[0][0];
                if ($matchText === '') continue;

                $before = substr($text, 0, $offset);
                $after = substr($text, $offset + strlen($matchText));
                $fragment = $dom->createDocumentFragment();
                if ($before !== '') $fragment->appendChild($dom->createTextNode($before));

                $a = $dom->createElement('a');
                $a->setAttribute('href', $url);
                $a->setAttribute('class', 'jhd-context-link');
                $a->setAttribute('title', (string)($target['hint'] ?? $label));
                $a->appendChild($dom->createTextNode($matchText));
                $fragment->appendChild($a);

                if ($after !== '') $fragment->appendChild($dom->createTextNode($after));
                $parent->replaceChild($fragment, $textNode);

                $linkedTargetKeys[$targetKey] = true;
                $linkedCount++;
                $linkedThisPass = true;
                break;
            }
            if ($linkedThisPass) break;
        }

        if (!$linkedThisPass) break;
    }

    $out = '';
    foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
    return $out;
}

function jhd_promote_article_headings(string $html): string {
    if ($html === '') return '';
    $headings = [
        'مهدویت یعنی چه؟',
        'مهدویت؛ باور به پایان تاریخ یا ساختن آینده؟',
        'مهدویت در بستر اندیشه اسلامی',
        'مهدویت در اندیشه اسلامی',
        'مهدی موعود در نگاه اسلامی',
        'مهدی موعود کیست؟',
        'مهدویت در اندیشه شیعه امامیه',
        'مهدویت در نگاه شیعه',
        'مهدویت در نگاه اهل سنت',
        'غیبت چیست؟',
        'چرا غیبت اتفاق افتاد؟',
        'ظهور چیست؟',
        'انتظار چیست؟',
        'نشانه‌های ظهور',
        'وظیفه انسان منتظر',
        'پرسش‌های متداول درباره مهدویت',
    ];
    foreach ($headings as $heading) {
        $pattern = '~(?:^|(?:\r\n|\n|\r){2,})\s*' . preg_quote($heading, '~') . '\s*(?=(?:\r\n|\n|\r){2,}|$)~u';
        $replacement = '<h2>' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
        $html = (string)preg_replace($pattern, $replacement, $html, 1);
    }
    return $html;
}

// ─── Pagination ───────────────────────────────────────────────────────────────

function paginate(int $total, int $limit, int $current, string $urlPattern): string {
    if ($limit <= 0) $limit = 1;
    $pages = (int)ceil($total / $limit);
    if ($pages <= 1) return '';
    $range = 2;
    $start = max(1, $current - $range);
    $end   = min($pages, $current + $range);
    /**
     * Only the page placeholder is substituted. sprintf() would throw a
     * ValueError on percent-encoded query values that callers embed in the
     * pattern (urlencode('ا') → %D8%A7 → unknown format specifier "D").
     */
    $pageUrl = static function (int $number) use ($urlPattern): string {
        if (str_contains($urlPattern, '%d')) return str_replace('%d', (string)$number, $urlPattern);
        // url()'s http_build_query percent-encodes the literal placeholder to %25d.
        if (str_contains($urlPattern, '%25d')) return str_replace('%25d', (string)$number, $urlPattern);
        try { return sprintf($urlPattern, $number); } catch (Throwable) { return $urlPattern; }
    };
    $html  = '<nav aria-label="صفحه‌بندی"><ul class="pagination justify-content-center flex-wrap">';
    if ($current > 1) $html .= '<li class="page-item"><a class="page-link" href="' . $pageUrl($current - 1) . '">&#8250; قبلی</a></li>';
    if ($start > 1) { $html .= '<li class="page-item"><a class="page-link" href="' . $pageUrl(1) . '">1</a></li>'; if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
    for ($i=$start;$i<=$end;$i++){ $active=($i===$current)?' active':''; $html.='<li class="page-item'.$active.'"><a class="page-link" href="'.$pageUrl($i).'">'.$i.'</a></li>'; }
    if ($end<$pages){ if($end<$pages-1) $html.='<li class="page-item disabled"><span class="page-link">...</span></li>'; $html.='<li class="page-item"><a class="page-link" href="'.$pageUrl($pages).'">'.$pages.'</a></li>'; }
    if ($current<$pages) $html .= '<li class="page-item"><a class="page-link" href="' . $pageUrl($current + 1) . '">بعدی &#8249;</a></li>';
    return $html . '</ul></nav>';
}

// ─── Post Type Labels ─────────────────────────────────────────────────────────

function postTypeLabel(string $type): string {
    return match($type) {
        'news'         => 'خبر',
        'article'      => 'مقاله',
        'research'     => 'پژوهش',
        'report'       => 'گزارش',
        'announcement' => 'اطلاعیه',
        'speech'       => 'سخنرانی',
        'program'      => 'برنامه آموزشی',
        'religious'    => 'فعالیت مذهبی',
        'qa'           => 'پرسش و پاسخ',
        'book'         => 'کتاب',
        'lesson'       => 'درس',
        'topic'        => 'موضوع',
        'video'        => 'ویدیو',
        'audio'        => 'صوت',
        'media'        => 'رسانه',
        default        => 'مطلب',
    };
}
function postTypeBadge(string $type): string {
    $colors = ['news'=>'success','article'=>'primary','research'=>'dark','report'=>'warning','announcement'=>'warning','speech'=>'info','program'=>'secondary','religious'=>'danger','qa'=>'primary'];
    $color = $colors[$type] ?? 'dark';
    return '<span class="badge bg-' . $color . '">' . postTypeLabel($type) . '</span>';
}
function excerpt(string $text, int $chars = 150): string {
    $text = strip_tags($text);
    if (mb_strlen($text, 'UTF-8') <= $chars) return $text;
    return mb_substr($text, 0, $chars, 'UTF-8') . '...';
}

// ─── Media Table fallback ─────────────────────────────────────────────────────
if (!function_exists('ensureMediaTable')) {
    function ensureMediaTable(): void {}
}

// ─── Books ────────────────────────────────────────────────────────────────────

function ensureBooksTable(): void {}

function getBooks(array $opts = []): array {
    ensureBooksTable();
    try {
        $db=getDB(); $limit=(int)($opts['limit']??20); $offset=(int)($opts['offset']??0); $search=$opts['search']??''; $topic=$opts['topic']??null; $featured=$opts['featured']??null;
        $where=['b.status=\'published\'']; $params=[];
        if($search){ $where[]="(b.title ILIKE ? OR b.description ILIKE ? OR b.author ILIKE ?)"; $s='%'.$search.'%'; $params=array_merge($params,[$s,$s,$s]); }
        if($topic){ $where[]="EXISTS (SELECT 1 FROM book_topics bt WHERE bt.book_id=b.id AND bt.topic_id=?)"; $params[]=(int)$topic; }
        if($featured){ $where[]="b.is_featured=1"; }
        $whereStr=implode(' AND ',$where);
        $stmt=$db->prepare("SELECT b.* FROM books b WHERE $whereStr ORDER BY b.is_featured DESC, b.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params,[$limit,$offset])); return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}
function countBooks(array $opts = []): int {
    ensureBooksTable();
    try {
        $db=getDB(); $search=$opts['search']??''; $topic=$opts['topic']??null; $where=['b.status=\'published\'']; $params=[];
        if($search){ $where[]="(b.title ILIKE ? OR b.description ILIKE ? OR b.author ILIKE ?)"; $s='%'.$search.'%'; $params=array_merge($params,[$s,$s,$s]); }
        if($topic){ $where[]="EXISTS (SELECT 1 FROM book_topics bt WHERE bt.book_id=b.id AND bt.topic_id=?)"; $params[]=(int)$topic; }
        $whereStr=implode(' AND ',$where);
        $stmt=$db->prepare("SELECT COUNT(*) FROM books b WHERE $whereStr"); $stmt->execute($params); return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}
function getBookById(int $id): ?array {
    try{ $stmt=getDB()->prepare("SELECT * FROM books WHERE id=? LIMIT 1"); $stmt->execute([$id]); $row=$stmt->fetch(); return $row?:null; }catch(PDOException $e){ return null; }
}
function getBookBySlug(string $slug): ?array {
    if($slug==='') return null;
    try{ $stmt=getDB()->prepare("SELECT * FROM books WHERE slug=? LIMIT 1"); $stmt->execute([$slug]); $row=$stmt->fetch(); return $row?:null; }catch(PDOException $e){ return null; }
}
/** Published books sharing any topic with the given book (newest first). */
function getRelatedBooks(int $bookId, int $limit=6): array {
    if($bookId<1 || $limit<1) return [];
    try{
        $topics=getTopicsForBook($bookId);
        if(!$topics) return [];
        $ids=array_column($topics,'id');
        $in=implode(',', array_fill(0,count($ids),'?'));
        $stmt=getDB()->prepare("SELECT b.* FROM books b JOIN book_topics bt ON bt.book_id=b.id WHERE bt.topic_id IN ($in) AND b.id<>? AND b.status='published' GROUP BY b.id ORDER BY b.created_at DESC LIMIT $limit");
        $stmt->execute(array_merge($ids,[$bookId]));
        return $stmt->fetchAll();
    }catch(PDOException $e){ return []; }
}
function uploadBookFile(array $file, string $type = 'pdf', string $folder = ''): string {
    $kind = $type === 'pdf' ? 'pdf' : 'word';
    return uploadFile($file, $kind, $folder !== '' ? $folder : UPLOAD_DOCUMENTS);
}

/** Media stored below the persistent content row, never in a shared bucket. */
function uploadContentImage(array $file, string $entity, int $id, array $context = []): string {
    return uploadFile($file, 'image', contentStorageFolder($entity, $id, $context));
}
function uploadContentAudio(array $file, string $entity, int $id, array $context = []): string {
    return uploadFile($file, 'audio', contentStorageFolder($entity, $id, $context));
}
function uploadContentVideo(array $file, string $entity, int $id, array $context = []): string {
    return uploadFile($file, 'video', contentStorageFolder($entity, $id, $context));
}
function uploadContentDocument(array $file, string $entity, int $id, string $kind = 'pdf', array $context = []): string {
    return uploadFile($file, $kind === 'word' ? 'word' : 'pdf', contentStorageFolder($entity, $id, $context));
}

/**
 * Require the selected topic IDs to exist.  Silently accepting arbitrary IDs
 * leaves incomplete relation rows on installations without FK enforcement.
 */
function validatedTopicIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("SELECT id FROM topics WHERE id IN ($marks)");
    $stmt->execute($ids);
    $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_intersect($ids, $existing));
}

// ─── Search helper (unified) ──────────────────────────────────────────────────

function searchAll(string $q, int $limit=12, int $offset=0, string $filter='all'): array {
    if(!$q) return ['total'=>0,'results'=>[]];
    $allowed=['all','topic','media','audio','video','article','research','report','news','book','lesson'];
    $filter=in_array($filter,$allowed,true)?$filter:'all';
    $qLike='%'.$q.'%'; $sources=[];
    // Every search source exposes exactly the same shape, so pagination and
    // sorting cover the entire result set rather than only the first source.
    if(in_array($filter,['all','topic'],true)) $sources[]="SELECT id,name AS title,slug,description AS summary,intro AS content,'' AS featured_image,'topic' AS post_type,created_at AS published_at,created_at,'topic' AS target,'' AS media_kind,0 AS is_featured FROM topics WHERE is_active=1";
    if(in_array($filter,['all','media','audio','video'],true)) {
        $kindClause=$filter==='audio'?" AND m.kind='audio'":($filter==='video'?" AND m.kind='video'":'');
        $sources[]="SELECT m.id,COALESCE(NULLIF(m.title,''),p.title,l.title,'رسانه') AS title,COALESCE(p.slug,l.slug) AS slug,COALESCE(p.summary,l.summary,'') AS summary,'' AS content,m.file_path AS featured_image,'media' AS post_type,COALESCE(p.published_at,l.created_at,m.created_at) AS published_at,m.created_at,'media' AS target,m.kind AS media_kind,0 AS is_featured FROM media_files m LEFT JOIN posts p ON m.ref_type='post' AND p.id=m.ref_id LEFT JOIN lessons l ON m.ref_type='lesson' AND l.id=m.ref_id WHERE m.kind IN ('audio','video') AND ((p.status='published') OR (l.status='published'))$kindClause";
        // Older records kept primary files in lesson/post columns. Search them
        // too, but leave their destination as the real parent detail page.
        if ($filter !== 'video') $sources[]="SELECT l.id,l.title,l.slug,l.summary,'' AS content,'' AS featured_image,'lesson' AS post_type,l.created_at AS published_at,l.created_at,'lesson' AS target,'audio' AS media_kind,0 AS is_featured FROM lessons l WHERE l.status='published' AND l.audio_file IS NOT NULL AND l.audio_file<>'' AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='audio' AND m.file_path=l.audio_file)";
        if ($filter !== 'audio') {
            $sources[]="SELECT l.id,l.title,l.slug,l.summary,'' AS content,'' AS featured_image,'lesson' AS post_type,l.created_at AS published_at,l.created_at,'lesson' AS target,'video' AS media_kind,0 AS is_featured FROM lessons l WHERE l.status='published' AND l.video_file IS NOT NULL AND l.video_file<>'' AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='video' AND m.file_path=l.video_file)";
            $sources[]="SELECT p.id,p.title,p.slug,p.summary,'' AS content,'' AS featured_image,p.post_type,p.published_at,p.created_at,'post' AS target,'video' AS media_kind,p.is_featured FROM posts p WHERE p.status='published' AND p.featured_video IS NOT NULL AND p.featured_video<>'' AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='post' AND m.ref_id=p.id AND m.kind='video' AND m.file_path=p.featured_video)";
        }
    }
    $postTypes=['article','research','report','news'];
    if($filter==='all' || in_array($filter,$postTypes,true)) {
        $typeClause=$filter==='all'?'':" AND post_type='".$filter."'";
        $sources[]="SELECT id,title,slug,summary,content,featured_image,post_type,published_at,created_at,'post' AS target,'' AS media_kind,is_featured FROM posts WHERE status='published'$typeClause";
    }
    if(in_array($filter,['all','lesson'],true)) $sources[]="SELECT id,title,slug,summary,content,featured_image,'lesson',created_at,created_at,'lesson','',is_featured FROM lessons WHERE status='published'";
    if(in_array($filter,['all','book'],true)) $sources[]="SELECT id,title,slug,description,description,cover_image,'book',created_at,created_at,'book','',is_featured FROM books WHERE status='published'";
    if(!$sources) return ['total'=>0,'results'=>[]];
    try {
        $db=getDB(); $union=implode(' UNION ALL ',$sources);
        // LOWER(... LIKE LOWER(?)) is available in PostgreSQL, MySQL/MariaDB
        // and SQLite; unlike ILIKE it does not depend on a driver shim.
        $where="(LOWER(COALESCE(title, '')) LIKE LOWER(?) OR LOWER(COALESCE(summary, '')) LIKE LOWER(?) OR LOWER(COALESCE(content, '')) LIKE LOWER(?))";
        $count=$db->prepare("SELECT COUNT(*) FROM ($union) search_rows WHERE $where");$count->execute([$qLike,$qLike,$qLike]);
        $total=(int)$count->fetchColumn();
        $stmt=$db->prepare("SELECT * FROM ($union) search_rows WHERE $where ORDER BY is_featured DESC,published_at DESC,id DESC LIMIT ? OFFSET ?");
        $stmt->execute([$qLike,$qLike,$qLike,max(1,min(100,$limit)),max(0,$offset)]);
        return ['total'=>$total,'results'=>$stmt->fetchAll()];
    } catch(PDOException $e){ error_log('search failed: '.get_class($e)); return ['total'=>0,'results'=>[]]; }
}

// ─── SEO helpers ──────────────────────────────────────────────────────────────

function canonicalUrl(string $path): string {
    return absolute_url($path);
}
function breadcrumbsJsonLd(array $crumbs): string {
    $list = [];
    $pos = 1;
    foreach ($crumbs as $c) {
        $itemUrl = null;
        if (isset($c['url']) && is_string($c['url']) && $c['url'] !== '') {
            $itemUrl = preg_match('~^https?://~i', $c['url'])
                ? $c['url']
                : jhd_absolute_url($c['url']);
        }
        $list[] = [
            '@type' => 'ListItem',
            'position' => $pos++,
            'name' => (string)($c['name'] ?? ''),
            'item' => $itemUrl,
        ];
    }
    return json_encode(
        ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}
/**
 * Organization node — the school as a single, consistent entity.
 *
 * Emitted with a stable @id so every page can point at the same entity instead
 * of re-describing it. The `name` is the official brand and nothing else; the
 * Arabic spelling and the longer legal name are declared as alternateName so
 * search engines reconcile them with the brand rather than treating them as
 * separate organisations.
 *
 * Only facts that exist on this site are published: the real logo file, the
 * Kabul address, the contact details that are actually shown in the footer and
 * the official social profile configured in the settings. Nothing is invented.
 */
function organizationJsonLd(): array {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'EducationalOrganization',
        '@id' => rtrim(SITE_URL, '/') . '/#organization',
        'name' => SITE_NAME,
        'alternateName' => array_values(array_filter(
            defined('SITE_ALT_NAMES') ? SITE_ALT_NAMES : [],
            static fn($n): bool => is_string($n) && $n !== '' && $n !== SITE_NAME
        )),
        'url' => rtrim(SITE_URL, '/') . '/',
        'logo' => [
            '@type' => 'ImageObject',
            '@id' => rtrim(SITE_URL, '/') . '/#logo',
            'url' => canonicalUrl(SITE_LOGO_PATH),
            'contentUrl' => canonicalUrl(SITE_LOGO_PATH),
            'caption' => SITE_NAME,
        ],
        'description' => SITE_DESCRIPTION,
        'inLanguage' => 'fa-AF',
        'areaServed' => ['@type' => 'Country', 'name' => 'Afghanistan'],
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => 'کابل',
            'addressRegion' => 'کابل',
            'addressCountry' => 'AF',
        ],
        'knowsAbout' => [
            'فقه و اصول',
            'تفسیر قرآن',
            'حدیث شناسی',
            'کلام و فلسفه',
            'ادبیات عرب',
            'تاریخ اسلام',
        ],
    ];

    // Contact details come from the settings the site actually renders.
    if (function_exists('getSetting')) {
        $email = (string)getSetting('email', SITE_EMAIL);
        $phone = (string)getSetting('phone', SITE_PHONE);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $data['email'] = 'mailto:' . $email;
        if ($phone !== '') $data['telephone'] = $phone;

        // sameAs: only the official profiles configured for this school.
        $sameAs = [];
        foreach (['social_telegram', 'social_youtube', 'social_instagram'] as $key) {
            $profile = safeExternalUrl((string)getSetting($key, ''));
            if ($profile !== '' && !in_array($profile, $sameAs, true)) $sameAs[] = $profile;
        }
        if ($sameAs) $data['sameAs'] = $sameAs;
    }

    $founderName = defined('SITE_FOUNDER') ? SITE_FOUNDER : '';
    if ($founderName !== '') $data['founder'] = ['@type' => 'Person', 'name' => $founderName];

    return $data;
}

/** WebSite node wired to the Organization entity above. */
function websiteJsonLd(): array {
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => rtrim(SITE_URL, '/') . '/#website',
        'name' => SITE_NAME,
        'url' => rtrim(SITE_URL, '/') . '/',
        'inLanguage' => 'fa-AF',
        'publisher' => ['@id' => rtrim(SITE_URL, '/') . '/#organization'],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => rtrim(SITE_URL, '/') . '/search?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/** schema.org type that best describes a published post. */
function jhd_post_schema_type(string $postType): string {
    return match ($postType) {
        'news'     => 'NewsArticle',
        'research' => 'ScholarlyArticle',
        'report'   => 'Report',
        default    => 'Article',
    };
}

/** Return the actual MIME type implied by a public image URL/path extension. */
function imageMimeFromUrl(string $url): string {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'avif'        => 'image/avif',
        default       => 'image/png',
    };
}

function articleJsonLd(array $post): string {
    if (!SITE_URL) return '';
    // Structured data must use the same canonical pretty path as <link rel="canonical>.
    // Avoid query URLs here: encoded Persian slugs can otherwise become ?slug=%25...
    $canonicalPath = jhd_post_canonical_path($post);
    if ($canonicalPath === '') return '';
    $canonical = jhd_absolute_url($canonicalPath);
    $author = !empty($post['author_name'])
        ? ['@type' => 'Person', 'name' => $post['author_name']]
        : ['@id' => rtrim(SITE_URL, '/') . '/#organization'];
    $descriptionSource = (string)($post['summary'] ?? '');
    if ($descriptionSource === '') $descriptionSource = strip_tags((string)($post['content'] ?? ''));
    $data = [
        '@context' => 'https://schema.org',
        '@type' => jhd_post_schema_type((string)($post['post_type'] ?? '')),
        '@id' => $canonical . '#article',
        'isPartOf' => ['@id' => rtrim(SITE_URL, '/') . '/#website'],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
        'headline' => (string)$post['title'],
        'description' => excerpt($descriptionSource, 160),
        'url' => $canonical,
        'inLanguage' => 'fa-AF',
        'datePublished' => $post['published_at'] ?? $post['created_at'] ?? null,
        'dateModified' => $post['updated_at'] ?? $post['published_at'] ?? $post['created_at'] ?? null,
        'author' => $author,
        // Reference the one Organization node instead of re-declaring the brand.
        'publisher' => ['@id' => rtrim(SITE_URL, '/') . '/#organization'],
    ];
    $imagePath = trim((string)($post['featured_image'] ?? ''));
    // The school logo identifies the publisher; it is not an article image.
    if ($imagePath !== '') {
        $data['image'] = [jhd_absolute_url(imgUrl($imagePath))];
    }
    $category = trim((string)($post['cat_name'] ?? ''));
    if ($category !== '') $data['articleSection'] = $category;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * QAPage for the question-and-answer archive. The question and its answer are
 * taken from the stored title and body — nothing is invented.
 */
function qaJsonLd(array $post): string {
    if (!SITE_URL) return '';
    $canonicalPath = jhd_post_canonical_path($post);
    if ($canonicalPath === '') return '';
    $canonical = jhd_absolute_url($canonicalPath);
    $answer = trim(strip_tags((string)($post['content'] ?? '')));
    if ($answer === '') $answer = trim((string)($post['summary'] ?? ''));
    return json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'QAPage',
        '@id' => $canonical . '#qa',
        'isPartOf' => ['@id' => rtrim(SITE_URL, '/') . '/#website'],
        'mainEntity' => [
            '@type' => 'Question',
            'name' => (string)$post['title'],
            'answerCount' => 1,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => excerpt($answer, 500),
                'url' => $canonical,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
function bookJsonLd(array $book): string {
    if (!SITE_URL) return '';
    $slug = trim((string)($book['slug'] ?? ''));
    $canonicalPath = $slug !== ''
        ? jhd_route_path('book', ['slug' => $slug])
        : jhd_route_path('book', ['id' => (int)($book['id'] ?? 0)]);
    if ($canonicalPath === '') return '';
    $canonical = jhd_absolute_url($canonicalPath);
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'Book',
        '@id' => $canonical . '#book',
        'isPartOf' => ['@id' => rtrim(SITE_URL, '/') . '/#website'],
        'name' => (string)$book['title'],
        'url' => $canonical,
        'inLanguage' => 'fa',
        // Reference the one Organization node so the publisher name is never a
        // second, competing spelling of the brand.
        'publisher' => ['@id' => rtrim(SITE_URL, '/') . '/#organization'],
    ];
    if (!empty($book['author'])) $data['author'] = ['@type' => 'Person', 'name' => $book['author']];
    if (!empty($book['translator'])) $data['translator'] = ['@type' => 'Person', 'name' => $book['translator']];
    if (!empty($book['description'])) $data['description'] = excerpt($book['description'], 200);
    if (!empty($book['cover_image'])) {
        $data['image'] = [jhd_absolute_url(imgUrl($book['cover_image']))];
    }
    if (!empty($book['publish_year'])) $data['datePublished'] = (string)$book['publish_year'];
    if (!empty($book['pages'])) $data['numberOfPages'] = (int)$book['pages'];
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Course node for a published lesson.
 *
 * A lesson is taught by the school, so the provider is the same Organization
 * node as everywhere else; the description comes from the lesson itself.
 */
function lessonJsonLd(array $lesson): string {
    if (!SITE_URL) return '';
    $slug = trim((string)($lesson['slug'] ?? ''));
    $canonicalPath = $slug !== ''
        ? jhd_route_path('lesson', ['slug' => $slug])
        : '';
    if ($canonicalPath === '') return '';
    $canonical = jhd_absolute_url($canonicalPath);
    $summary = trim((string)($lesson['summary'] ?? ''));
    if ($summary === '') $summary = trim(strip_tags((string)($lesson['content'] ?? '')));
    return json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Course',
        '@id' => $canonical . '#course',
        'isPartOf' => ['@id' => rtrim(SITE_URL, '/') . '/#website'],
        'name' => (string)$lesson['title'],
        'description' => excerpt($summary, 200),
        'url' => $canonical,
        'inLanguage' => 'fa',
        'provider' => ['@id' => rtrim(SITE_URL, '/') . '/#organization'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

// ─── Safe rich text ───────────────────────────────────────────────────────────

function safeRichText(?string $html): string {
    if (!$html) return '';
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8\"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $allowed = ['p','div','span','strong','b','em','i','u','ul','ol','li','blockquote','h2','h3','h4','h5','br','hr','a','img','table','thead','tbody','tr','th','td'];
    $clean = function (DOMNode $node) use (&$clean,$allowed): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) { $node->removeChild($child); continue; }
            if (!($child instanceof DOMElement)) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag,['script','style','iframe','object','embed','svg','math','form','input','button','meta','link','base'],true)) { $node->removeChild($child); continue; }
            $clean($child);
            if (!in_array($tag,$allowed,true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild,$child);
                $node->removeChild($child); continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name=strtolower($attr->name); $value=$attr->value;
                $keep = in_array($name,['title','alt'],true);
                if (($tag==='a' && $name==='href') || ($tag==='img' && $name==='src')) {
                    $keep = safeExternalUrl($value)!=='' || (str_starts_with($value,'/') && !str_starts_with($value,'//') && !str_contains($value,'\\'));
                }
                if (!$keep) $child->removeAttribute($attr->name);
            }
            if ($tag==='a') $child->setAttribute('rel','noopener noreferrer');
            if ($tag==='img') { $child->setAttribute('loading','lazy'); $child->setAttribute('decoding','async'); }
        }
    };
    $body=$dom->getElementsByTagName('body')->item(0);
    if (!$body) return '';
    $clean($body); $out='';
    foreach ($body->childNodes as $child) $out.=$dom->saveHTML($child);
    return $out;
}
function safeExternalUrl(string $url): string {
    return preg_match('~^https://~i', $url) && filter_var($url,FILTER_VALIDATE_URL) && !preg_match('/[<>"\x00-\x20]/', $url) ? $url : '';
}

if (is_file(__DIR__ . '/cards.php')) {
    require_once __DIR__ . '/cards.php';
}

/* ══════════════════════════════════════════════════════════════════════════
 * شمارش دسته‌ای محتوای موضوعات — برای فهرست‌های بزرگ اطلس
 * ──────────────────────────────────────────────────────────────────────────
 * پیش از این هر کاشی موضوع سه کوئری جداگانه اجرا می‌کرد (مطلب، درس، کتاب).
 * در اطلسی با ۱۲ کاشی یعنی ۳۶ کوئری در هر بارگذاری صفحه. این تابع با سه
 * کوئری گروهی، شمارش همهٔ موضوعات (به‌همراه زیرموضوع‌ها) را یک‌جا می‌دهد.
 *
 * @param list<int> $topicIds شناسهٔ موضوع‌های ریشه‌ای که باید شمرده شوند
 * @return array<int,array{posts:int,lessons:int,books:int}>
 * ══════════════════════════════════════════════════════════════════════════ */
function jhd_topic_content_counts(array $topicIds): array {
    $roots = array_values(array_unique(array_filter(array_map('intval', $topicIds), static fn(int $id): bool => $id > 0)));
    $out = [];
    foreach ($roots as $id) $out[$id] = ['posts' => 0, 'lessons' => 0, 'books' => 0];
    if (!$roots) return $out;

    // شمارش خام هر موضوع (بدون زیرموضوع) با سه کوئری گروهی
    $raw = ['posts' => [], 'lessons' => [], 'books' => []];
    $queries = [
        'posts'   => "SELECT pt.topic_id AS tid, COUNT(DISTINCT p.id) AS total FROM post_topics pt JOIN posts p ON p.id = pt.post_id WHERE p.status='published' GROUP BY pt.topic_id",
        'lessons' => "SELECT lt.topic_id AS tid, COUNT(DISTINCT l.id) AS total FROM lesson_topics lt JOIN lessons l ON l.id = lt.lesson_id WHERE l.status='published' GROUP BY lt.topic_id",
        'books'   => "SELECT bt.topic_id AS tid, COUNT(DISTINCT b.id) AS total FROM book_topics bt JOIN books b ON b.id = bt.book_id WHERE b.status='published' GROUP BY bt.topic_id",
    ];
    foreach ($queries as $key => $sql) {
        try {
            foreach (getDB()->query($sql)->fetchAll() as $row) $raw[$key][(int)$row['tid']] = (int)$row['total'];
        } catch (Throwable) {
            // شمارش نمایشی است؛ نبودنش نباید صفحه را از کار بیندازد.
        }
    }
    foreach ($roots as $id) {
        foreach (getTopicScopeIds($id) as $scopeId) {
            $out[$id]['posts']   += $raw['posts'][$scopeId] ?? 0;
            $out[$id]['lessons'] += $raw['lessons'][$scopeId] ?? 0;
            $out[$id]['books']   += $raw['books'][$scopeId] ?? 0;
        }
    }
    return $out;
}
