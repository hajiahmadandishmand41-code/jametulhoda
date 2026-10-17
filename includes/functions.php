<?php
/**
 * functions.php — Jametulhoda Content-Centered helpers
 * Production-oriented: SEO, topics, lesson collections, books, search, sanitization
 * Published content queries, persistent settings and deduplicated view counters.
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

function csrfToken(): string {
    return generateCsrfToken();
}

function verifyCsrfToken(mixed $token): bool {
    return is_string($token)
        && isset($_SESSION[CSRF_TOKEN_NAME])
        && is_string($_SESSION[CSRF_TOKEN_NAME])
        && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function verifyCsrf(mixed $token): bool {
    return verifyCsrfToken($token);
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
function jhd_legacy_listing_target(string $name): ?string {
    return ['school-news' => 'news', 'all-news' => 'news',
        'scientific-articles' => 'articles', 'scientific-papers' => 'articles'][$name] ?? null;
}

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

    // Preserve non-routing state (for example a topic section), but do not
    // append the old route's p/slug/id/kind to a mode-aware canonical URL.
    // Doing so would create duplicate query keys when a legacy Query URL is redirected.
    $requestParams = [];
    parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $requestParams);
    unset($requestParams['p'], $requestParams['slug'], $requestParams['id'], $requestParams['kind']);

    $canonicalParts = parse_url($canonicalUrl);
    $canonicalParams = [];
    if (is_array($canonicalParts) && !empty($canonicalParts['query'])) {
        parse_str((string)$canonicalParts['query'], $canonicalParams);
    }
    foreach (array_keys($canonicalParams) as $key) unset($requestParams[$key]);

    $preservedQuery = http_build_query($requestParams);
    if ($preservedQuery !== '') {
        $canonicalUrl .= (str_contains($canonicalUrl, '?') ? '&' : '?') . $preservedQuery;
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
    $monthNames = ['حمل','ثور','جوزا','سرطان','اسد','سنبله','میزان','عقرب','قوس','جدی','دلو','حوت'];
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
    $monthNames = ['حمل','ثور','جوزا','سرطان','اسد','سنبله','میزان','عقرب','قوس','جدی','دلو','حوت'];
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
    if (isset($opts['min_views'])) {
        // آستانه از تنظیمات مدیریت می‌آید (پیش‌فرض ۵۰)؛ خودِ getPosts عدد
        // مدیر را بی‌صدا بازنویسی نمی‌کند، فقط منفی را صفر می‌کند.
        $where[] = "p.view_count >= ?";
        $params[] = max(0, (int)$opts['min_views']);
    }
    if (!empty($opts['exclude_ids'])) {
        $ids = array_values(array_map('intval', $opts['exclude_ids']));
        $where[] = "p.id NOT IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_merge($params, $ids);
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
    // Homepage feeds opt into chronological order; all existing calls retain
    // their established featured-first ordering unless they request otherwise.
    $sort = (string)($opts['sort'] ?? '');
    if ($sort === 'newest') {
        $orderBy = 'COALESCE(p.published_at, p.created_at) DESC, p.id DESC';
    } elseif ($sort === 'oldest') {
        $orderBy = 'COALESCE(p.published_at, p.created_at) ASC, p.id ASC';
    } elseif ($sort === 'popular') {
        $orderBy = 'p.view_count DESC, COALESCE(p.published_at, p.created_at) DESC, p.id DESC';
    } else {
        $orderBy = 'p.is_featured DESC, p.published_at DESC, p.id DESC';
    }
    $sql = "SELECT p.*, c.name AS cat_name, COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name FROM posts p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.author_id WHERE $whereStr ORDER BY $orderBy LIMIT $limit OFFSET $offset";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('getPosts failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Record one view for a published post.
 *
 * The counter is what the "پربازدیدترین‌ها" rail is built from. It is written
 * with a single indexed UPDATE and never inside the renderer's critical path:
 * a failure here must not break the page, so the error is logged and ignored.
 *
 * Editors and administrators are not counted — their own previews would
 * otherwise dominate the ranking.
 */
function recordContentView(int $postId): void {
    if ($postId < 1 || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (function_exists('isLoggedIn') && isLoggedIn()) return;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawler|spider|preview|headless/i', $ua)) return;
    if (!empty($_SERVER['HTTP_PURPOSE']) || !empty($_SERVER['HTTP_SEC_PURPOSE'])) return;
    $db = jhd_db();
    if ($db === null) return;
    $secret = env_value('VIEW_HASH_KEY', '');
    if ($secret === '' && APP_ENV === 'production') return; // fail closed, never invent counts
    if ($secret === '') $secret = 'local-test-only';
    // Trust only the connection address. Forwarded headers must be normalized by
    // the trusted platform, not supplied by a visitor. No raw IP is stored.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // Vercel overwrites this platform header; never trust generic X-Forwarded-For.
    if (env_value('VERCEL', '') !== '') {
        $ip = trim(explode(',', (string)($_SERVER['HTTP_X_VERCEL_FORWARDED_FOR'] ?? $ip))[0]);
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
    $day = gmdate('Y-m-d');
    $key = hash_hmac('sha256', $day . '|' . $ip, $secret);
    try {
        $db->beginTransaction();
        $sql = "INSERT INTO content_view_receipts (post_id, visitor_hash, view_day) VALUES (?, ?, ?) ON CONFLICT DO NOTHING";
        if (databaseDriver() === 'mysql') $sql = "INSERT IGNORE INTO content_view_receipts (post_id, visitor_hash, view_day) VALUES (?, ?, ?)";
        $receipt = $db->prepare($sql);
        $receipt->execute([$postId, $key, $day]);
        if ($receipt->rowCount() === 1) {
            $db->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ? AND status = 'published'")->execute([$postId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('view counter unavailable: ' . $e->getMessage());
    }
}

/** Only genuinely eligible posts; never relabel fresh posts as popular. */
function getMostViewedPosts(int $limit = 3, array $types = []): array {
    return getPosts(['types' => $types, 'limit' => max(1, min(3, $limit)), 'sort' => 'popular', 'min_views' => jhd_popular_min_views()]);
}

function getLatestPosts(int $limit = 3, array $types = []): array {
    return getPosts(['types' => $types, 'limit' => max(1, min(3, $limit)), 'sort' => 'newest']);
}

/**
 * One published post by its id — the manual "مطلب شاخص" of the homepage.
 */
function getPublishedPostById(int $id): ?array {
    if ($id < 1) return null;
    $db = jhd_db();
    if ($db === null) return null;
    try {
        $stmt = $db->prepare(
            "SELECT p.*, c.name AS cat_name,
                    COALESCE(NULLIF(p.author_name,''), NULLIF(p.speaker,''), u.full_name) AS author_name
             FROM posts p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.id = ? AND p.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (PDOException $e) {
        error_log('getPublishedPostById failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * The newest published audio and video items for the homepage media hub.
 *
 * The list mirrors the public media library: items attached to a published
 * post or lesson, plus lessons and posts whose own media column points at a
 * file. Only published content is exposed.
 */
function getLatestMediaItems(int $limit = 6): array {
    $limit = max(1, min(24, $limit));
    $db = jhd_db();
    if ($db === null) return [];
    $sql = "SELECT m.id AS media_id, m.file_path AS path, m.kind AS media_kind, m.title AS media_title,
                   COALESCE(NULLIF(m.title,''), p.title, l.title, 'رسانه') AS title,
                   COALESCE(p.slug, l.slug, '') AS slug,
                   COALESCE(p.post_type, '') AS post_type,
                   p.featured_image AS featured_image,
                   COALESCE(p.published_at, l.created_at, m.created_at) AS published_at,
                   CASE WHEN p.id IS NOT NULL THEN 'post' WHEN l.id IS NOT NULL THEN 'lesson' ELSE 'media' END AS target,
                   p.id AS post_id, l.id AS lesson_id
            FROM media_files m
            LEFT JOIN posts p   ON p.id = m.ref_id   AND m.ref_type = 'post'   AND p.status = 'published'
            LEFT JOIN lessons l ON l.id = m.ref_id   AND m.ref_type = 'lesson' AND l.status = 'published'
            WHERE m.kind IN ('audio','video') AND (p.id IS NOT NULL OR l.id IS NOT NULL)
            ORDER BY published_at DESC, m.id DESC
            LIMIT $limit";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('getLatestMediaItems failed: ' . $e->getMessage());
        return [];
    }
}

function countPosts(array $opts = []): int {
    $db = jhd_db();
    if ($db === null) return 0;
    $where  = ["p.status = 'published'"];
    $params = [];
    if (!empty($opts['type'])) { $where[]="p.post_type = ?"; $params[]=$opts['type']; }
    // Several post types at once (the merged «مقالات و پژوهش» listing).
    if (!empty($opts['types']) && is_array($opts['types'])) {
        $types = array_values(array_filter($opts['types'], static fn($t): bool => is_string($t) && $t !== ''));
        if ($types) {
            $where[] = 'p.post_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            foreach ($types as $t) $params[] = $t;
        }
    }
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
        $stmt=$db->prepare("SELECT t.*, pt.is_primary FROM topics t JOIN post_topics pt ON pt.topic_id=t.id WHERE pt.post_id=? ORDER BY pt.is_primary DESC, t.sort_order, t.id");
        $stmt->execute([$postId]); return $stmt->fetchAll();
    }catch(PDOException $e){
        try {
            $db=getDB();
            $stmt=$db->prepare("SELECT t.* FROM topics t JOIN post_topics pt ON pt.topic_id=t.id WHERE pt.post_id=? ORDER BY t.sort_order, t.id");
            $stmt->execute([$postId]); return $stmt->fetchAll();
        } catch (PDOException) { return []; }
    }
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

function getPostTopics(int $postId): array { return getTopicsForPost($postId); }
function getLessonTopics(int $lessonId): array { return getTopicsForLesson($lessonId); }
function getBookTopics(int $bookId): array { return getTopicsForBook($bookId); }

function getTopicsFor(string $entityType, int $entityId): array {
    if ($entityId < 1) return [];
    return match ($entityType) {
        'post'   => getTopicsForPost($entityId),
        'lesson' => getTopicsForLesson($entityId),
        'book'   => getTopicsForBook($entityId),
        default  => [],
    };
}

function jhd_parse_id_list(mixed $raw): array {
    $items = is_array($raw) ? $raw : [$raw];
    $ids = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            foreach ($item as $sub) {
                foreach (preg_split('/[\s,،]+/', (string)$sub) ?: [] as $part) {
                    $v = (int)$part;
                    if ($v > 0) $ids[] = $v;
                }
            }
        } else {
            foreach (preg_split('/[\s,،]+/', (string)$item) ?: [] as $part) {
                $v = (int)$part;
                if ($v > 0) $ids[] = $v;
            }
        }
    }
    return array_values(array_unique($ids));
}

function setPostTopics(int $postId, array $topicIds, ?int $primaryId = null): void {
    $db=getDB();
    $db->prepare("DELETE FROM post_topics WHERE post_id=?")->execute([$postId]);
    $ids = validatedTopicIds(jhd_parse_id_list($topicIds));
    if ($primaryId && $primaryId > 0) {
        $validPrimary = validatedTopicIds([$primaryId]);
        if ($validPrimary && !in_array($validPrimary[0], $ids, true)) {
            array_unshift($ids, $validPrimary[0]);
        }
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
    foreach(validatedTopicIds(jhd_parse_id_list($topicIds)) as $tid){
        $db->prepare("INSERT INTO lesson_topics (lesson_id, topic_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$lessonId,$tid]);
    }
}
function setBookTopics(int $bookId, array $topicIds): void {
    $db=getDB();
    $db->prepare("DELETE FROM book_topics WHERE book_id=?")->execute([$bookId]);
    foreach(validatedTopicIds(jhd_parse_id_list($topicIds)) as $tid){
        $db->prepare("INSERT INTO book_topics (book_id, topic_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$bookId,$tid]);
    }
}

function setTopicsFor(string $entityType, int $entityId, array $topicIds, ?int $primaryId = null): void {
    if ($entityId < 1) return;
    if ($entityType === 'post') {
        setPostTopics($entityId, $topicIds, $primaryId);
    } elseif ($entityType === 'lesson') {
        setLessonTopics($entityId, $topicIds);
    } elseif ($entityType === 'book') {
        setBookTopics($entityId, $topicIds);
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
    $sortKey = (string)($opts['sort'] ?? 'newest');
    $manualIds = array_values(array_filter(array_map('intval', (array)($opts['manual_ids'] ?? [])), static fn(int $id): bool => $id > 0));
    try {
        $params = [];
        $where = ["p.status='published'", topicScopeWhere('pt', 'topic_id', $scope, $params)];
        if ($type) { $where[] = 'p.post_type=?'; $params[] = $type; }
        $types = array_values(array_filter((array)($opts['types'] ?? []), static fn($value): bool => is_string($value) && $value !== ''));
        if ($types) {
            $where[] = 'p.post_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            foreach ($types as $t) $params[] = $t;
        }
        $excludeTypes = array_values(array_filter((array)($opts['exclude_types'] ?? []), static fn($value): bool => is_string($value) && $value !== ''));
        if ($excludeTypes) {
            $marks = implode(',', array_fill(0, count($excludeTypes), '?'));
            $where[] = "p.post_type NOT IN ($marks)";
            foreach ($excludeTypes as $excludeType) $params[] = $excludeType;
        }
        $orderBy = match ($sortKey) {
            'oldest'   => 'COALESCE(p.published_at, p.created_at) ASC, p.id ASC',
            'popular'  => 'p.view_count DESC, COALESCE(p.published_at, p.created_at) DESC, p.id DESC',
            'featured' => 'p.is_featured DESC, COALESCE(p.published_at, p.created_at) DESC, p.id DESC',
            default    => 'COALESCE(p.published_at, p.created_at) DESC, p.id DESC',
        };
        $stmt = getDB()->prepare("SELECT DISTINCT p.*, c.name AS cat_name FROM posts p JOIN post_topics pt ON pt.post_id=p.id LEFT JOIN categories c ON c.id=p.category_id WHERE " . implode(' AND ', $where) . " ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $rows = $stmt->fetchAll();
        if ($sortKey === 'manual' && $manualIds && $offset === 0) {
            $rank = array_flip($manualIds);
            usort($rows, static function (array $a, array $b) use ($rank): int {
                $ra = $rank[(int)($a['id'] ?? 0)] ?? 999999;
                $rb = $rank[(int)($b['id'] ?? 0)] ?? 999999;
                return $ra <=> $rb;
            });
        }
        return $rows;
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
function getLessonsByTopic(int $topicId, int $limit=6, int $offset=0, string $sort='newest', ?array $scopeIds = null, array $manualIds = []): array {
    try {
        $params = [];
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        $where = ["l.status='published'", topicScopeWhere('lt', 'topic_id', $scope, $params)];
        $orderBy = match ($sort) {
            'oldest'       => 'l.created_at ASC, l.id ASC',
            'featured'     => 'l.is_featured DESC, l.sort_order ASC, l.id DESC',
            'lesson_order' => 'l.sort_order ASC, l.lesson_number ASC, l.id ASC',
            default        => 'l.created_at DESC, l.id DESC',
        };
        $stmt = getDB()->prepare('SELECT DISTINCT l.* FROM lessons l JOIN lesson_topics lt ON lt.lesson_id=l.id WHERE ' . implode(' AND ', $where) . " ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [max(1, min(100, $limit)), max(0, $offset)]));
        $rows = $stmt->fetchAll();
        $cleanManual = array_values(array_filter(array_map('intval', $manualIds), static fn(int $id): bool => $id > 0));
        if ($sort === 'manual' && $cleanManual && $offset === 0) {
            $rank = array_flip($cleanManual);
            usort($rows, static fn(array $a, array $b): int => ($rank[(int)($a['id'] ?? 0)] ?? 999999) <=> ($rank[(int)($b['id'] ?? 0)] ?? 999999));
        }
        return $rows;
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
function getBooksByTopic(int $topicId, int $limit=6, int $offset=0, string $sort='newest', ?array $scopeIds = null, array $manualIds = []): array {
    try {
        $params = [];
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        $orderBy = match ($sort) {
            'oldest'   => 'b.created_at ASC, b.id ASC',
            'featured' => 'b.is_featured DESC, b.sort_order ASC, b.id DESC',
            'title'    => 'b.title ASC, b.id DESC',
            default    => 'b.created_at DESC, b.id DESC',
        };
        $stmt = getDB()->prepare("SELECT DISTINCT b.* FROM books b JOIN book_topics bt ON bt.book_id=b.id WHERE b.status='published' AND " . topicScopeWhere('bt', 'topic_id', $scope, $params) . " ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params, [max(1, min(100, $limit)), max(0, $offset)]));
        $rows = $stmt->fetchAll();
        $cleanManual = array_values(array_filter(array_map('intval', $manualIds), static fn(int $id): bool => $id > 0));
        if ($sort === 'manual' && $cleanManual && $offset === 0) {
            $rank = array_flip($cleanManual);
            usort($rows, static fn(array $a, array $b): int => ($rank[(int)($a['id'] ?? 0)] ?? 999999) <=> ($rank[(int)($b['id'] ?? 0)] ?? 999999));
        }
        return $rows;
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

/**
 * رسانه‌های صوتی و تصویری متصل به مطالب یا درس‌های یک موضوع علمی (فقط منتشرشده).
 */
function getMediaByTopic(int $topicId, int $limit = 6, string $sort = 'newest', ?array $scopeIds = null): array {
    try {
        $scope = $scopeIds ?? getTopicScopeIds($topicId);
        if (!$scope) return [];
        $marks = implode(',', array_fill(0, count($scope), '?'));
        $order = $sort === 'oldest' ? 'ASC' : 'DESC';
        $lim = max(1, min(48, $limit));
        $sql = "SELECT DISTINCT m.id AS media_id, m.id, m.file_path AS path, m.file_path, m.kind AS media_kind, m.kind,
                       COALESCE(NULLIF(m.title,''), p.title, l.title, 'رسانه') AS title,
                       COALESCE(p.slug, l.slug, '') AS slug,
                       COALESCE(p.post_type, '') AS post_type,
                       p.featured_image AS featured_image, p.featured_image AS thumbnail,
                       COALESCE(p.published_at, l.created_at, m.created_at) AS published_at,
                       CASE WHEN p.id IS NOT NULL THEN 'post' WHEN l.id IS NOT NULL THEN 'lesson' ELSE 'media' END AS target,
                       p.id AS post_id, l.id AS lesson_id
                FROM media_files m
                LEFT JOIN posts p   ON p.id = m.ref_id AND m.ref_type = 'post'   AND p.status = 'published'
                LEFT JOIN lessons l ON l.id = m.ref_id AND m.ref_type = 'lesson' AND l.status = 'published'
                WHERE m.kind IN ('audio','video')
                  AND (
                    (p.id IS NOT NULL AND EXISTS (SELECT 1 FROM post_topics pt WHERE pt.post_id = p.id AND pt.topic_id IN ($marks)))
                    OR
                    (l.id IS NOT NULL AND EXISTS (SELECT 1 FROM lesson_topics lt WHERE lt.lesson_id = l.id AND lt.topic_id IN ($marks)))
                  )
                ORDER BY published_at $order, m.id $order
                LIMIT $lim";
        $stmt = getDB()->prepare($sql);
        $stmt->execute(array_merge($scope, $scope));
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

// ─── Lessons with collections ─────────────────────────────────────────────────

function getLessons(array $opts = []): array {
    $db = jhd_db();
    if ($db === null) return [];
    try {
        $where = ["l.status = 'published'"];
        $params = [];
        if (!empty($opts['collection_id'])) {
            $where[] = 'l.collection_id = ?';
            $params[] = (int)$opts['collection_id'];
        }
        if (!empty($opts['volume_id'])) {
            $where[] = 'l.volume_id = ?';
            $params[] = (int)$opts['volume_id'];
        }
        if (!empty($opts['topic'])) {
            $where[] = 'EXISTS (SELECT 1 FROM lesson_topics lt WHERE lt.lesson_id = l.id AND lt.topic_id = ?)';
            $params[] = (int)$opts['topic'];
        }
        if (!empty($opts['featured'])) {
            $where[] = 'l.is_featured = 1';
        }
        if (!empty($opts['search'])) {
            $where[] = '(l.title ILIKE ? OR l.summary ILIKE ?)';
            $s = '%' . $opts['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }
        $limit = max(1, min(100, (int)($opts['limit'] ?? 12)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $whereStr = implode(' AND ', $where);
        $sort = (string)($opts['sort'] ?? '');
        $orderBy = match ($sort) {
            'newest'       => 'l.created_at DESC, l.id DESC',
            'oldest'       => 'l.created_at ASC, l.id ASC',
            'lesson_order' => 'l.sort_order ASC, l.lesson_number ASC, l.id ASC',
            default        => 'l.is_featured DESC, l.created_at DESC, l.id DESC',
        };
        $stmt = $db->prepare(
            "SELECT l.*, lc.title AS collection_title, lc.slug AS collection_slug, lv.title AS volume_title, lv.slug AS volume_slug
             FROM lessons l
             LEFT JOIN lesson_collections lc ON lc.id = l.collection_id
             LEFT JOIN lesson_volumes lv ON lv.id = l.volume_id
             WHERE $whereStr
             ORDER BY $orderBy
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

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
/** Related content must share taxonomy, never just a random/same-type fallback. */
function getRelatedPosts(array $post, int $limit = 3): array {
    $db = jhd_db();
    if (!$db) return [];
    $limit = max(1, min(3, $limit));
    try {
        $stmt = $db->prepare("SELECT p.* FROM posts p WHERE p.status='published' AND p.id<>? AND
            ((p.category_id IS NOT NULL AND p.category_id=?) OR EXISTS
              (SELECT 1 FROM post_topics a JOIN post_topics b ON a.topic_id=b.topic_id WHERE a.post_id=? AND b.post_id=p.id))
            ORDER BY COALESCE(p.published_at,p.created_at) DESC,p.id DESC LIMIT $limit");
        $stmt->execute([$post['id'], $post['category_id'] ?? null, $post['id']]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Related posts unavailable: ' . $e->getMessage());
        return [];
    }
}

function getAdjacentPosts(int $postId): array {
    if ($postId < 1) return ['prev' => null, 'next' => null];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT post_type, COALESCE(published_at, created_at) AS published_at FROM posts WHERE id = ? AND status='published' LIMIT 1");
        $stmt->execute([$postId]);
        $row = $stmt->fetch();
        if (!$row) return ['prev' => null, 'next' => null];
        $type = (string)$row['post_type'];
        $prev = $db->prepare("SELECT * FROM posts WHERE post_type = ? AND status = 'published' AND (COALESCE(published_at, created_at) < ? OR (COALESCE(published_at, created_at) = ? AND id < ?)) ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 1");
        $next = $db->prepare("SELECT * FROM posts WHERE post_type = ? AND status = 'published' AND (COALESCE(published_at, created_at) > ? OR (COALESCE(published_at, created_at) = ? AND id > ?)) ORDER BY COALESCE(published_at, created_at) ASC, id ASC LIMIT 1");
        $prev->execute([$type, $row['published_at'], $row['published_at'], $postId]);
        $next->execute([$type, $row['published_at'], $row['published_at'], $postId]);
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
 * ذخیره یا به‌روزرسانی یک کلید در جدول settings با پاک‌سازی کش درخواست جاری.
 */
function setSetting(string $key, string $value): void {
    $key = trim($key);
    if ($key === '') return;
    $db = jhd_db();
    if ($db === null) return;
    $stmt = $db->prepare('INSERT INTO settings (setting_key, value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value');
    $stmt->execute([$key, $value]);
    clearSettingCache();
}

/**
 * تنظیمات نمایش صفحهٔ اصلی — ذخیره‌شده در settings تا بدون migration جدید
 * در MySQL و PostgreSQL هر دو قابل مدیریت باشد.
 */
function jhd_homepage_settings(): array {
    $cfg = json_decode((string)getSetting('homepage_config', ''), true);
    return jhd_normalize_home_config(is_array($cfg) ? $cfg : []);
}

/** فهرست حالت‌های نمایش پشتیبانی‌شده برای بخش‌های صفحهٔ اصلی و صفحات داخلی. */
function jhd_display_mode_options(): array {
    return [
        'auto'       => 'خودکار (ردیف فشرده دو ستونی)',
        'rows'       => 'ردیف‌های فشرده',
        'featured'   => 'نمایش شاخص (آیتم بزرگ + آیتم‌های فرعی)',
        'list'       => 'نمایش لیستی (تک‌ستونی)',
        'grid'       => 'نمایش شبکه‌ای / کارتی (۳ ستونه)',
        'horizontal' => 'نمایش افقی (رسانه در کنار متن)',
        'video'      => 'نمایش ویدیویی (قاب رسانه و پخش)',
        'slider'     => 'نمایش اسلایدر / کاروسل افقی',
        'minimal'    => 'نمایش فشرده (فقط عنوان و لینک)',
        'combined'   => 'نمایش ترکیبی (شاخص در کنار فهرست فرعی)',
    ];
}

/** Validated, backward-compatible JSON in the existing persistent settings table. */
function jhd_normalize_home_config(array $cfg): array {
    $labels = jhd_home_section_labels();
    $stored = is_array($cfg['sections'] ?? null) ? $cfg['sections'] : [];
    $out = $cfg; // retain unrecognized historical keys, never destroy old configuration
    $out['sections'] = $stored;
    $out['layout'] = [];
    $allowedModes = array_keys(jhd_display_mode_options());
    $allowedSelectionModes = ['auto', 'manual', 'topic', 'category', 'featured'];
    $allowedSorts = ['newest', 'oldest', 'popular', 'featured', 'manual'];
    $allowedMobileModes = ['same', 'compact', 'list', 'slider', 'minimal'];
    foreach ($labels as $key => $label) {
        $out['sections'][$key] = array_key_exists($key, $stored) ? (bool)$stored[$key] : true;
        if (!array_key_exists($key, $stored)) {
            $legacyValues = [];
            foreach (jhd_homepage_legacy_keys()[$key] ?? [] as $oldKey) {
                if (array_key_exists($oldKey, $stored)) $legacyValues[] = (bool)$stored[$oldKey];
            }
            if ($legacyValues) $out['sections'][$key] = in_array(true, $legacyValues, true);
        }
        $item = is_array($cfg['layout'][$key] ?? null) ? $cfg['layout'][$key] : [];
        $itemMode = is_string($item['mode'] ?? null) ? $item['mode'] : 'auto';
        $selectionMode = is_string($item['selection'] ?? null) ? $item['selection'] : (is_string($item['selection_mode'] ?? null) ? $item['selection_mode'] : 'auto');
        $sortMode = is_string($item['sort'] ?? null) ? $item['sort'] : ($key === 'popular' ? 'popular' : 'newest');
        $mobileMode = is_string($item['mobile_mode'] ?? null) ? $item['mobile_mode'] : 'same';
        $rawManualIds = $item['manual_ids'] ?? [];
        if (is_array($rawManualIds)) {
            $rawManualIds = implode(',', $rawManualIds);
        }
        $cleanManualIdsArr = array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,،]+/', (string)$rawManualIds) ?: []),
            static fn(int $v): bool => $v > 0
        )));
        $moreUrl = trim(is_string($item['more_url'] ?? null) ? $item['more_url'] : '');
        if ($moreUrl !== '' && !str_starts_with($moreUrl, '/') && safeExternalUrl($moreUrl) === '') {
            $moreUrl = '';
        }
        $moreLabel = mb_substr(trim(is_string($item['more_label'] ?? null) ? $item['more_label'] : (is_string($item['more_text'] ?? null) ? $item['more_text'] : 'مشاهده همه')), 0, 60) ?: 'مشاهده همه';
        $validSel = in_array($selectionMode, $allowedSelectionModes, true) ? $selectionMode : 'auto';
        $out['layout'][$key] = [
            'title'          => mb_substr(trim(is_string($item['title'] ?? null) ? $item['title'] : $label), 0, 100) ?: $label,
            'order'          => max(0, min(99, (int)($item['order'] ?? array_search($key, array_keys($labels))))),
            'limit'          => max(1, min(in_array($key, ['popular', 'latest'], true) ? 3 : 12, (int)($item['limit'] ?? 3))),
            'category'       => max(0, (int)($item['category'] ?? 0)),
            'topic_id'       => max(0, (int)($item['topic_id'] ?? 0)),
            'mode'           => in_array($itemMode, $allowedModes, true) ? $itemMode : 'auto',
            'selection'      => $validSel,
            'selection_mode' => $validSel,
            'sort'           => in_array($sortMode, $allowedSorts, true) ? $sortMode : 'newest',
            'manual_ids'     => $cleanManualIdsArr,
            'show_media'     => array_key_exists('show_media', $item) ? ((string)$item['show_media'] !== '0' && $item['show_media'] !== false ? 1 : 0) : 1,
            'show_excerpt'   => array_key_exists('show_excerpt', $item) ? ((string)$item['show_excerpt'] !== '0' && $item['show_excerpt'] !== false ? 1 : 0) : 1,
            'show_date'      => array_key_exists('show_date', $item) ? ((string)$item['show_date'] !== '0' && $item['show_date'] !== false ? 1 : 0) : 1,
            'show_meta'      => array_key_exists('show_meta', $item) ? ((string)$item['show_meta'] !== '0' && $item['show_meta'] !== false ? 1 : 0) : 1,
            'more_url'       => mb_substr($moreUrl, 0, 200),
            'more_label'     => $moreLabel,
            'more_text'      => $moreLabel,
            'mobile_mode'    => in_array($mobileMode, $allowedMobileModes, true) ? $mobileMode : 'same',
        ];
    }
    $out['hero_post_id'] = max(0, (int)($cfg['hero_post_id'] ?? 0));
    $mode = is_string($cfg['hero_mode'] ?? null) ? $cfg['hero_mode'] : ($out['hero_post_id'] ? 'manual' : 'auto');
    $out['hero_mode'] = in_array($mode, ['auto', 'manual', 'off'], true) ? $mode : 'auto';
    $kind = is_string($cfg['hero_kind'] ?? null) ? $cfg['hero_kind'] : 'post';
    $out['hero_kind'] = in_array($kind, ['post', 'book', 'lesson'], true) ? $kind : 'post';
    // تراکم چیدمان و اندازهٔ بندانگشتی — دو کلید سراسری برای صفحهٔ نخست.
    $density = is_string($cfg['density'] ?? null) ? $cfg['density'] : 'compact';
    $out['density'] = in_array($density, ['compact', 'cozy'], true) ? $density : 'compact';
    $thumb = is_string($cfg['thumb'] ?? null) ? $cfg['thumb'] : 'sm';
    $out['thumb'] = in_array($thumb, ['sm', 'md'], true) ? $thumb : 'sm';
    return $out;
}

/**
 * تنظیمات نمایش صفحات داخلی — مستقل از صفحهٔ اصلی، ذخیره‌شده در settings.
 */
function jhd_internal_pages_defaults(): array {
    return [
        'topics' => [
            'label'       => 'صفحه فهرست موضوعات علمی (/topics)',
            'mode'        => 'grid',
            'sort'        => 'manual',
            'per_page'    => 24,
            'show_media'  => 1,
            'show_excerpt'=> 1,
            'show_counts' => 1,
            'hide_empty'  => 0,
        ],
        'topic_detail' => [
            'label'               => 'صفحه اختصاصی هر موضوع علمی (/topic/...)',
            'mode'                => 'featured',
            'sort'                => 'newest',
            'per_page'            => 12,
            'show_media'          => 1,
            'show_excerpt'        => 1,
            'show_date'           => 1,
            'show_meta'           => 1,
            'show_related_topics' => 1,
            'show_subtopics'      => 1,
        ],
        'articles' => [
            'label'       => 'صفحه مقالات و پژوهش‌ها (/articles)',
            'mode'        => 'rows',
            'sort'        => 'newest',
            'per_page'    => 12,
            'show_media'  => 1,
            'show_excerpt'=> 1,
            'show_date'   => 1,
            'show_meta'   => 1,
            'show_topics' => 1,
        ],
        'news' => [
            'label'       => 'صفحه اخبار و رویدادها (/news)',
            'mode'        => 'rows',
            'sort'        => 'newest',
            'per_page'    => 12,
            'show_media'  => 1,
            'show_excerpt'=> 1,
            'show_date'   => 1,
            'show_meta'   => 1,
        ],
        'reports' => [
            'label'       => 'صفحه گزارش‌ها (/reports)',
            'mode'        => 'rows',
            'sort'        => 'newest',
            'per_page'    => 12,
            'show_media'  => 1,
            'show_excerpt'=> 1,
            'show_date'   => 1,
            'show_meta'   => 1,
        ],
        'books' => [
            'label'       => 'صفحه کتابخانه دیجیتال (/books)',
            'mode'        => 'grid',
            'sort'        => 'manual',
            'per_page'    => 12,
            'show_media'  => 1,
            'show_excerpt'=> 1,
            'show_meta'   => 1,
        ],
        'lessons' => [
            'label'            => 'صفحه دروس و دوره‌های حوزوی (/lessons)',
            'mode'             => 'grouped',
            'sort'             => 'lesson_order',
            'per_page'         => 12,
            'show_collections' => 1,
            'show_media'       => 1,
            'show_excerpt'     => 1,
            'show_meta'        => 1,
        ],
        'media' => [
            'label'       => 'صفحه ویدیوها و صوت‌ها (/media)',
            'mode'        => 'grid',
            'sort'        => 'newest',
            'per_page'    => 12,
            'show_media'  => 1,
            'show_date'   => 1,
        ],
        'speeches' => [
            'label'       => 'صفحه سخنرانی‌ها (/speeches)',
            'mode'        => 'grid',
            'sort'        => 'newest',
            'per_page'    => 12,
            'show_media'  => 1,
            'show_excerpt'=> 1,
            'show_date'   => 1,
        ],
        'qa' => [
            'label'       => 'صفحه پرسش و پاسخ (/qa)',
            'mode'        => 'rows',
            'sort'        => 'newest',
            'per_page'    => 12,
            'show_media'  => 1,
            'show_excerpt'=> 1,
            'show_date'   => 1,
            'show_meta'   => 1,
        ],
        'post_detail' => [
            'label'         => 'صفحه مطالعه مقاله / خبر / گزارش (/post/...)',
            'mode'          => 'rows',
            'sort'          => 'newest',
            'per_page'      => 12,
            'show_media'    => 1,
            'show_excerpt'  => 1,
            'show_date'     => 1,
            'show_meta'     => 1,
            'show_related'  => 1,
            'related_limit' => 4,
        ],
        'book_detail' => [
            'label'         => 'صفحه معرفی کتاب (/book/...)',
            'mode'          => 'grid',
            'sort'          => 'newest',
            'per_page'      => 12,
            'show_media'    => 1,
            'show_excerpt'  => 1,
            'show_date'     => 1,
            'show_meta'     => 1,
            'show_related'  => 1,
            'related_limit' => 4,
        ],
        'lesson_detail' => [
            'label'         => 'صفحه جلسه درس (/lesson/...)',
            'mode'          => 'rows',
            'sort'          => 'lesson_order',
            'per_page'      => 12,
            'show_media'    => 1,
            'show_excerpt'  => 1,
            'show_date'     => 1,
            'show_meta'     => 1,
            'show_related'  => 1,
            'related_limit' => 6,
        ],
    ];
}

function jhd_normalize_internal_pages_config(array $cfg): array {
    $defaults = jhd_internal_pages_defaults();
    $allowedModes = array_merge(array_keys(jhd_display_mode_options()), ['grouped']);
    $allowedSorts = ['newest', 'oldest', 'popular', 'featured', 'manual', 'lesson_order', 'title'];
    $out = [];
    foreach ($defaults as $pageKey => $def) {
        $in = is_array($cfg[$pageKey] ?? null) ? $cfg[$pageKey] : [];
        $mode = is_string($in['mode'] ?? null) ? $in['mode'] : $def['mode'];
        if (!in_array($mode, $allowedModes, true)) $mode = $def['mode'];
        if ($mode === 'grouped' && $pageKey !== 'lessons') $mode = $def['mode'];
        $sort = is_string($in['sort'] ?? null) ? $in['sort'] : $def['sort'];
        if (!in_array($sort, $allowedSorts, true)) $sort = $def['sort'];
        $row = [
            'label'         => $def['label'],
            'mode'          => $mode,
            'sort'          => $sort,
            'per_page'      => max(1, min(48, (int)($in['per_page'] ?? $def['per_page']))),
            'related_limit' => max(1, min(12, (int)($in['related_limit'] ?? ($def['related_limit'] ?? 4)))),
        ];
        foreach (['show_media', 'show_excerpt', 'show_date', 'show_meta', 'show_counts', 'hide_empty', 'show_related_topics', 'show_subtopics', 'show_topics', 'show_collections', 'show_related'] as $flag) {
            $defaultVal = array_key_exists($flag, $def) ? (int)$def[$flag] : 1;
            $row[$flag] = array_key_exists($flag, $in)
                ? ((string)$in[$flag] !== '0' && $in[$flag] !== false ? 1 : 0)
                : $defaultVal;
        }
        $out[$pageKey] = $row;
    }
    return $out;
}

function jhd_internal_pages_config(): array {
    $raw = json_decode((string)getSetting('internal_pages_config', ''), true);
    return jhd_normalize_internal_pages_config(is_array($raw) ? $raw : []);
}

function jhd_internal_page_config(string $pageKey): array {
    $all = jhd_internal_pages_config();
    return $all[$pageKey] ?? (jhd_internal_pages_defaults()[$pageKey] ?? [
        'mode' => 'rows', 'sort' => 'newest', 'per_page' => 12,
        'show_media' => 1, 'show_excerpt' => 1, 'show_date' => 1, 'show_meta' => 1,
    ]);
}

/**
 * تنظیمات ریل‌های «تازه‌ترین‌ها» و «پربازدیدترین‌ها» (انتهای مطلب و ستون کناری).
 * مانند چیدمان صفحهٔ اصلی، یک JSON معتبرسازی‌شده در همان جدول settings است؛
 * پس هیچ migration تازه‌ای لازم ندارد و داده‌های موجود دست‌نخورده می‌ماند.
 */
function jhd_content_rails_settings(): array {
    $cfg = json_decode((string)getSetting('content_rails_config', ''), true);
    return jhd_normalize_rails_config(is_array($cfg) ? $cfg : []);
}

function jhd_normalize_rails_config(array $cfg): array {
    return [
        'latest_enabled' => array_key_exists('latest_enabled', $cfg) ? (bool)$cfg['latest_enabled'] : true,
        'popular_enabled' => array_key_exists('popular_enabled', $cfg) ? (bool)$cfg['popular_enabled'] : true,
        'latest_count' => max(1, min(8, (int)($cfg['latest_count'] ?? 4))),
        'popular_count' => max(1, min(8, (int)($cfg['popular_count'] ?? 4))),
        // آستانهٔ «پربازدید»: فقط بازدیدهای واقعیِ دست‌کم این عدد فهرست می‌شوند.
        'min_views' => max(1, min(100000, (int)($cfg['min_views'] ?? 50))),
    ];
}

/** آستانهٔ سراسری پربازدیدشدن — یک عدد برای صفحهٔ اصلی و انتهای مطلب. */
function jhd_popular_min_views(): int {
    return jhd_content_rails_settings()['min_views'];
}

/**
 * دادهٔ دو ریل انتهای مطلب: تازه‌ترین و پربازدیدترین مطالب «هم‌نوع».
 *
 * - تازه‌ترین‌ها با تاریخ واقعی انتشار (published_at) مرتب می‌شوند.
 * - پربازدیدترین‌ها فقط از شمارندهٔ واقعی بازدید می‌آیند و به آستانهٔ
 *   تنظیم‌شده احترام می‌گذارند؛ اگر مطلب واجدی نباشد، فهرست خالی می‌ماند
 *   تا هیچ محتوای ساختگی نمایش داده نشود.
 * - مطلب جاری، پیش‌نویس‌ها و موارد تکراری هرگز در فهرست نیستند.
 */
function jhd_post_end_rail_items(array $post): array {
    $cfg = jhd_content_rails_settings();
    $id = (int)($post['id'] ?? 0);
    $type = trim((string)($post['post_type'] ?? ''));
    if ($id < 1 || $type === '') return ['latest' => [], 'popular' => []];
    $latest = $cfg['latest_enabled']
        ? getPosts(['type' => $type, 'sort' => 'newest', 'limit' => $cfg['latest_count'], 'exclude_ids' => [$id]])
        : [];
    $popular = [];
    if ($cfg['popular_enabled']) {
        $exclude = array_merge([$id], array_column($latest, 'id'));
        $popular = getPosts([
            'type' => $type, 'sort' => 'popular', 'min_views' => $cfg['min_views'],
            'limit' => $cfg['popular_count'], 'exclude_ids' => $exclude,
        ]);
    }
    return ['latest' => $latest, 'popular' => $popular];
}

function jhd_home_section_labels(): array {
    return ['feature' => 'مطلب شاخص', 'popular' => 'پربازدیدترین‌ها', 'latest' => 'تازه‌ترین‌ها',
        'news' => 'اخبار', 'announcements' => 'اطلاعیه‌ها', 'writing' => 'مقالات', 'research' => 'پژوهش‌ها',
        'books' => 'کتاب‌ها', 'lessons' => 'آموزش‌ها', 'topics' => 'موضوعات علمی', 'media' => 'رسانه‌ها'];
}

function jhd_home_feature(array $cfg): ?array {
    if ($cfg['hero_mode'] === 'off') return null;
    if ($cfg['hero_mode'] === 'auto') return getPosts(['limit' => 1, 'sort' => 'newest'])[0] ?? null;
    $id = $cfg['hero_post_id'];
    if ($cfg['hero_kind'] === 'post') return getPublishedPostById($id);
    $db = jhd_db();
    if (!$db || !$id) return null;
    $table = $cfg['hero_kind'] === 'book' ? 'books' : 'lessons';
    $stmt = $db->prepare("SELECT * FROM $table WHERE id=? AND status='published'");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $book = $cfg['hero_kind'] === 'book';
    $row['post_type'] = $book ? 'book' : 'lesson';
    $row['featured_image'] = $row['cover_image'] ?? $row['featured_image'] ?? '';
    $row['summary'] = $row['description'] ?? $row['summary'] ?? '';
    $row['url'] = $book ? bookUrl($row) : lessonUrl($row);
    return $row;
}

/** نگاشت کلیدهای قدیمی چیدمان صفحهٔ اصلی به هاب‌های تازه. */
function jhd_homepage_legacy_keys(): array {
    return [
        'hero'    => ['hero'],
        'feature' => ['editor_picks', 'latest', 'news', 'events'],
        'announcements' => ['announcements'],
        'lessons' => ['lessons'],
        'books'   => ['books'],
        'writing' => ['articles', 'research', 'reports', 'qa', 'speeches'],
        'media'   => ['media'],
        'topics'  => ['topics'],
    ];
}

/**
 * دوره‌های فعال درسی با شمار درس‌های منتشرشده — ستون فقرات هاب «دروس».
 * یک کوئری، بدون N+1؛ در دسترس‌نبودن دیتابیس فهرست خالی می‌دهد نه خطا.
 */
function jhd_home_lesson_collections(int $limit = 4): array {
    if (!jhd_db_ready()) return [];
    $limit = max(1, min(12, $limit));
    try {
        $stmt = getDB()->prepare(
            "SELECT c.id, c.title, c.slug, c.description, c.cover_image, c.is_featured,
                    COUNT(l.id) AS lesson_count
             FROM lesson_collections c
             LEFT JOIN lessons l ON l.collection_id = c.id AND l.status = 'published'
             WHERE c.is_active = 1
             GROUP BY c.id, c.title, c.slug, c.description, c.cover_image, c.is_featured
             ORDER BY c.is_featured DESC, c.sort_order ASC, lesson_count DESC, c.id DESC
             LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * موضوعات علمی منتخب صفحهٔ اصلی — فقط موضوعات «ویژه» با همان ترتیب مدیریتی. فقط دادهٔ واقعی جدول topics؛
 * اگر هیچ موضوع فعالی نباشد، فهرست خالی برمی‌گردد و بخش رندر نمی‌شود.
 */
function jhd_home_topics(int $limit = 6): array {
    $limit = max(1, min(24, $limit));
    if (!jhd_db_ready()) return [];
    $rows = getTopics(['active' => 1, 'featured' => 1]);
    $out = [];
    foreach ($rows as $row) {
        $tid = (int)($row['id'] ?? 0);
        $disp = jhd_topic_display($tid);
        if (($disp['visibility'] ?? 'public') !== 'public') continue;
        if (!in_array('home', $disp['placements'] ?? [], true)) continue;
        if (($disp['empty_behavior'] ?? 'message') === 'hide_from_home') {
            $counts = getTopicLinkedCounts($tid);
            if ($counts['total'] < 1) continue;
        }
        $out[] = $row;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * تازه‌ترین رسانهٔ منتشرشده (ویدیو یا صوت) برای هاب رسانه.
 * فقط فایل‌های متصل به مطلب منتشرشده؛ پیوند به صفحهٔ خود رسانه می‌رود تا
 * پخش‌کننده و اشتراک‌گذاری همان چیزی باشد که در آرشیو دیده می‌شود.
 */
function jhd_home_media(string $kind, int $limit = 2): array {
    $kind = $kind === 'audio' ? 'audio' : 'video';
    $limit = max(1, min(8, $limit));
    if (!jhd_db_ready()) return [];
    try {
        $stmt = getDB()->prepare(
            "SELECT m.id, m.kind, m.file_path, COALESCE(NULLIF(m.title, ''), p.title) AS title,
                    p.slug AS post_slug, p.post_type AS post_type, p.featured_image AS thumbnail,
                    p.speaker, COALESCE(p.published_at, m.created_at) AS published_at
             FROM media_files m
             JOIN posts p ON p.id = m.ref_id AND m.ref_type = 'post'
             WHERE m.kind = ? AND p.status = 'published'
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT $limit"
        );
        $stmt->execute([$kind]);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
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

function getBooksByIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    try {
        $db = getDB();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM books WHERE status = 'published' AND id IN ($marks)");
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll() as $row) $byId[(int)$row['id']] = $row;
        $ordered = [];
        foreach ($ids as $id) if (isset($byId[$id])) $ordered[] = $byId[$id];
        return $ordered;
    } catch (Throwable) {
        return [];
    }
}

function getLessonsByIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    try {
        $db = getDB();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT l.*, lc.title AS collection_title, lc.slug AS collection_slug, lv.title AS volume_title, lv.slug AS volume_slug
             FROM lessons l
             LEFT JOIN lesson_collections lc ON lc.id = l.collection_id
             LEFT JOIN lesson_volumes lv ON lv.id = l.volume_id
             WHERE l.status = 'published' AND l.id IN ($marks)"
        );
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll() as $row) $byId[(int)$row['id']] = $row;
        $ordered = [];
        foreach ($ids as $id) if (isset($byId[$id])) $ordered[] = $byId[$id];
        return $ordered;
    } catch (Throwable) {
        return [];
    }
}

function getTopicsByIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    try {
        $db = getDB();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM topics WHERE is_active = 1 AND id IN ($marks)");
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll() as $row) $byId[(int)$row['id']] = $row;
        $ordered = [];
        foreach ($ids as $id) if (isset($byId[$id])) $ordered[] = $byId[$id];
        return $ordered;
    } catch (Throwable) {
        return [];
    }
}

// ─── Topics Display & Safe Lifecycle Management ──────────────────────────────

function jhd_topic_placement_labels(): array {
    return [
        'home'           => 'صفحه اصلی (هاب موضوعات علمی)',
        'topics_archive' => 'فهرست موضوعات علمی (/topics)',
        'articles'       => 'صفحه مقالات و پژوهش‌ها (/articles)',
        'books'          => 'صفحه کتابخانه دیجیتال (/books)',
        'lessons'        => 'صفحه دروس و دوره‌ها (/lessons)',
        'news'           => 'صفحه اخبار و گزارش‌ها (/news)',
        'media'          => 'صفحه رسانه و ویدیوها (/media)',
        'sidebar'        => 'ستون کناری و نوار راهنمای صفحات داخلی',
    ];
}

/** Per-topic related-content and placement controls, stored additively in settings. */
function jhd_topic_display(int $id): array {
    $raw = json_decode(getSetting('topic_display_' . $id, ''), true);
    $raw = is_array($raw) ? $raw : [];
    $allowedModes = ['rows', 'grid', 'list', 'horizontal', 'minimal', 'featured', 'slider', 'video', 'combined'];
    $allowedSorts = ['newest', 'oldest', 'popular', 'featured', 'manual'];
    $allowedPlacements = array_keys(jhd_topic_placement_labels());
    $allowedSections = ['posts', 'books', 'lessons', 'media'];
    $allowedVisibilities = ['public', 'hidden_lists', 'inactive'];
    $allowedEmptyBehaviors = ['message', 'hide_from_home', 'custom_message'];

    $placements = [];
    if (isset($raw['placements'])) {
        $rawPl = is_array($raw['placements'])
            ? $raw['placements']
            : array_filter(array_map('trim', explode(',', (string)$raw['placements'])));
        foreach ($rawPl as $pl) {
            if (is_string($pl) && in_array($pl, $allowedPlacements, true)) {
                $placements[] = $pl;
            }
        }
        $placements = array_values(array_unique($placements));
    } else {
        $topicRow = $id > 0 ? getTopicById($id) : null;
        $isFeat = (int)($topicRow['is_featured'] ?? 0) === 1;
        $placements = $isFeat ? ['home', 'topics_archive', 'articles', 'books', 'lessons'] : ['topics_archive', 'articles', 'books', 'lessons'];
    }

    $sectionOrder = [];
    if (!empty($raw['section_order'])) {
        $parts = is_array($raw['section_order']) ? $raw['section_order'] : (preg_split('/[\s,،]+/', (string)$raw['section_order']) ?: []);
        foreach ($parts as $sec) {
            $sec = trim((string)$sec);
            if (in_array($sec, $allowedSections, true) && !in_array($sec, $sectionOrder, true)) {
                $sectionOrder[] = $sec;
            }
        }
    }
    foreach ($allowedSections as $sec) {
        if (!in_array($sec, $sectionOrder, true)) $sectionOrder[] = $sec;
    }

    $parseIdList = static function (mixed $val): array {
        if (is_array($val)) {
            return array_values(array_unique(array_filter(array_map('intval', $val), static fn(int $v): bool => $v > 0)));
        }
        if (is_string($val) && trim($val) !== '') {
            return array_values(array_unique(array_filter(
                array_map('intval', preg_split('/[\s,،]+/', $val) ?: []),
                static fn(int $v): bool => $v > 0
            )));
        }
        return [];
    };

    $defaultInternal = jhd_internal_page_config('topic_detail');
    $defaultMode = in_array($defaultInternal['mode'] ?? '', $allowedModes, true) ? (string)$defaultInternal['mode'] : 'featured';
    $defaultLimit = max(1, min(24, (int)($defaultInternal['per_page'] ?? 12)));

    return [
        'mode'               => in_array($raw['mode'] ?? '', $allowedModes, true) ? (string)$raw['mode'] : $defaultMode,
        'limit'              => max(1, min(24, (int)($raw['limit'] ?? $defaultLimit))),
        'sort'               => in_array($raw['sort'] ?? '', $allowedSorts, true) ? (string)$raw['sort'] : 'newest',
        'placements'         => $placements,
        'section_order'      => $sectionOrder,
        'show_media'         => array_key_exists('show_media', $raw) ? ((string)$raw['show_media'] !== '0' && $raw['show_media'] !== false ? 1 : 0) : 1,
        'show_excerpt'       => array_key_exists('show_excerpt', $raw) ? ((string)$raw['show_excerpt'] !== '0' && $raw['show_excerpt'] !== false ? 1 : 0) : 1,
        'show_date'          => array_key_exists('show_date', $raw) ? ((string)$raw['show_date'] !== '0' && $raw['show_date'] !== false ? 1 : 0) : 1,
        'show_meta'          => array_key_exists('show_meta', $raw) ? ((string)$raw['show_meta'] !== '0' && $raw['show_meta'] !== false ? 1 : 0) : 1,
        'related_topic_ids'  => array_values(array_filter($parseIdList($raw['related_topic_ids'] ?? []), static fn(int $tid): bool => $tid !== $id)),
        'empty_behavior'     => in_array($raw['empty_behavior'] ?? '', $allowedEmptyBehaviors, true) ? (string)$raw['empty_behavior'] : 'message',
        'empty_message'      => mb_substr(trim((string)($raw['empty_message'] ?? '')), 0, 240),
        'visibility'         => in_array($raw['visibility'] ?? '', $allowedVisibilities, true) ? (string)$raw['visibility'] : 'public',
        'manual_post_ids'    => $parseIdList($raw['manual_post_ids'] ?? []),
        'manual_book_ids'    => $parseIdList($raw['manual_book_ids'] ?? []),
        'manual_lesson_ids'  => $parseIdList($raw['manual_lesson_ids'] ?? []),
    ];
}

function jhd_save_topic_display(int $id, array $input): array {
    if ($id < 1) return [];
    $existing = json_decode(getSetting('topic_display_' . $id, ''), true);
    $existing = is_array($existing) ? $existing : [];
    $merged = array_merge($existing, $input);

    $allowedModes = ['rows', 'grid', 'list', 'horizontal', 'minimal', 'featured', 'slider', 'video', 'combined'];
    $allowedSorts = ['newest', 'oldest', 'popular', 'featured', 'manual'];
    $allowedPlacements = array_keys(jhd_topic_placement_labels());
    $allowedSections = ['posts', 'books', 'lessons', 'media'];
    $allowedVisibilities = ['public', 'hidden_lists', 'inactive'];
    $allowedEmptyBehaviors = ['message', 'hide_from_home', 'custom_message'];

    $parseIdList = static function (mixed $val): array {
        if (is_array($val)) {
            return array_values(array_unique(array_filter(array_map('intval', $val), static fn(int $v): bool => $v > 0)));
        }
        if (is_string($val) && trim($val) !== '') {
            return array_values(array_unique(array_filter(
                array_map('intval', preg_split('/[\s,،]+/', $val) ?: []),
                static fn(int $v): bool => $v > 0
            )));
        }
        return [];
    };

    $placements = [];
    if (isset($merged['placements'])) {
        $rawPl = is_array($merged['placements'])
            ? $merged['placements']
            : array_filter(array_map('trim', explode(',', (string)$merged['placements'])));
        foreach ($rawPl as $pl) {
            if (is_string($pl) && in_array($pl, $allowedPlacements, true)) {
                $placements[] = $pl;
            }
        }
        $placements = array_values(array_unique($placements));
    } else {
        $placements = ['home', 'topics_archive', 'articles', 'books', 'lessons'];
    }

    $sectionOrder = [];
    $secInput = $merged['section_order'] ?? ['posts', 'books', 'lessons', 'media'];
    $secParts = is_array($secInput) ? $secInput : (preg_split('/[\s,،]+/', (string)$secInput) ?: []);
    foreach ($secParts as $sec) {
        $sec = trim((string)$sec);
        if (in_array($sec, $allowedSections, true) && !in_array($sec, $sectionOrder, true)) {
            $sectionOrder[] = $sec;
        }
    }
    foreach ($allowedSections as $sec) {
        if (!in_array($sec, $sectionOrder, true)) $sectionOrder[] = $sec;
    }

    $clean = [
        'mode'               => in_array($merged['mode'] ?? '', $allowedModes, true) ? (string)$merged['mode'] : 'featured',
        'limit'              => max(1, min(24, (int)($merged['limit'] ?? 12))),
        'sort'               => in_array($merged['sort'] ?? '', $allowedSorts, true) ? (string)$merged['sort'] : 'newest',
        'placements'         => $placements,
        'section_order'      => $sectionOrder,
        'show_media'         => array_key_exists('show_media', $merged) ? ((string)$merged['show_media'] !== '0' && $merged['show_media'] !== false ? 1 : 0) : 1,
        'show_excerpt'       => array_key_exists('show_excerpt', $merged) ? ((string)$merged['show_excerpt'] !== '0' && $merged['show_excerpt'] !== false ? 1 : 0) : 1,
        'show_date'          => array_key_exists('show_date', $merged) ? ((string)$merged['show_date'] !== '0' && $merged['show_date'] !== false ? 1 : 0) : 1,
        'show_meta'          => array_key_exists('show_meta', $merged) ? ((string)$merged['show_meta'] !== '0' && $merged['show_meta'] !== false ? 1 : 0) : 1,
        'related_topic_ids'  => array_values(array_filter(validatedTopicIds($parseIdList($merged['related_topic_ids'] ?? [])), static fn(int $tid): bool => $tid !== $id)),
        'empty_behavior'     => in_array($merged['empty_behavior'] ?? '', $allowedEmptyBehaviors, true) ? (string)$merged['empty_behavior'] : 'message',
        'empty_message'      => mb_substr(trim((string)($merged['empty_message'] ?? '')), 0, 240),
        'visibility'         => in_array($merged['visibility'] ?? '', $allowedVisibilities, true) ? (string)$merged['visibility'] : 'public',
        'manual_post_ids'    => $parseIdList($merged['manual_post_ids'] ?? []),
        'manual_book_ids'    => $parseIdList($merged['manual_book_ids'] ?? []),
        'manual_lesson_ids'  => $parseIdList($merged['manual_lesson_ids'] ?? []),
    ];

    setSetting('topic_display_' . $id, (string)json_encode($clean, JSON_UNESCAPED_UNICODE));
    return $clean;
}

/**
 * شمارش دقیق همهٔ آیتم‌های متصل به یک موضوع (مستقیم، شامل پیش‌نویس و منتشرشده)
 * برای نمایش در جدول مدیریت و مرحلهٔ تأیید حذف/انتقال موضوع.
 */
function getTopicLinkedCounts(int $topicId): array {
    $out = [
        'posts_total'       => 0,
        'posts_published'   => 0,
        'books_total'       => 0,
        'books_published'   => 0,
        'lessons_total'     => 0,
        'lessons_published' => 0,
        'children_count'    => 0,
        'media_count'       => 0,
        'total'             => 0,
        'published_total'   => 0,
    ];
    if ($topicId < 1) return $out;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(DISTINCT p.id) AS total, SUM(CASE WHEN p.status='published' THEN 1 ELSE 0 END) AS pub FROM post_topics pt JOIN posts p ON p.id = pt.post_id WHERE pt.topic_id = ?");
        $stmt->execute([$topicId]);
        $r = $stmt->fetch() ?: [];
        $out['posts_total'] = (int)($r['total'] ?? 0);
        $out['posts_published'] = (int)($r['pub'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(DISTINCT b.id) AS total, SUM(CASE WHEN b.status='published' THEN 1 ELSE 0 END) AS pub FROM book_topics bt JOIN books b ON b.id = bt.book_id WHERE bt.topic_id = ?");
        $stmt->execute([$topicId]);
        $r = $stmt->fetch() ?: [];
        $out['books_total'] = (int)($r['total'] ?? 0);
        $out['books_published'] = (int)($r['pub'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(DISTINCT l.id) AS total, SUM(CASE WHEN l.status='published' THEN 1 ELSE 0 END) AS pub FROM lesson_topics lt JOIN lessons l ON l.id = lt.lesson_id WHERE lt.topic_id = ?");
        $stmt->execute([$topicId]);
        $r = $stmt->fetch() ?: [];
        $out['lessons_total'] = (int)($r['total'] ?? 0);
        $out['lessons_published'] = (int)($r['pub'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) FROM topics WHERE parent_id = ?");
        $stmt->execute([$topicId]);
        $out['children_count'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT m.id) FROM media_files m
             WHERE (m.ref_type='post' AND EXISTS (SELECT 1 FROM post_topics pt WHERE pt.post_id=m.ref_id AND pt.topic_id=?))
                OR (m.ref_type='lesson' AND EXISTS (SELECT 1 FROM lesson_topics lt WHERE lt.lesson_id=m.ref_id AND lt.topic_id=?))"
        );
        $stmt->execute([$topicId, $topicId]);
        $out['media_count'] = (int)$stmt->fetchColumn();

        $out['total'] = $out['posts_total'] + $out['books_total'] + $out['lessons_total'];
        $out['published_total'] = $out['posts_published'] + $out['books_published'] + $out['lessons_published'];
    } catch (Throwable) {
    }
    return $out;
}

/**
 * انتقال یا قطع ارتباط امن محتواهای متصل به یک موضوع علمی بدون حذف خود مقالات/کتاب‌ها/دروس/فایل‌ها.
 */
function jhd_transfer_or_unlink_topic_relations(int $sourceTopicId, string $strategy = 'unlink', int $targetTopicId = 0): array {
    if ($sourceTopicId < 1) return ['ok' => false, 'error' => 'شناسه موضوع نامعتبر است.'];
    $strategy = in_array($strategy, ['transfer', 'unlink'], true) ? $strategy : 'unlink';
    if ($strategy === 'transfer') {
        if ($targetTopicId < 1 || $targetTopicId === $sourceTopicId) {
            return ['ok' => false, 'error' => 'برای انتقال محتوا، باید یک موضوع مقصد معتبر انتخاب کنید.'];
        }
        $targetRow = getTopicById($targetTopicId);
        if (!$targetRow) {
            return ['ok' => false, 'error' => 'موضوع مقصد انتخاب‌شده در دیتابیس یافت نشد.'];
        }
    }

    $counts = getTopicLinkedCounts($sourceTopicId);
    $db = getDB();
    $ownTx = !$db->inTransaction();
    if ($ownTx) $db->beginTransaction();
    try {
        if ($strategy === 'transfer' && $targetTopicId > 0) {
            // 1. انتقال post_topics بدون ایجاد رکورد تکراری
            $stmt = $db->prepare('SELECT post_id, is_primary FROM post_topics WHERE topic_id = ?');
            $stmt->execute([$sourceTopicId]);
            $postLinks = $stmt->fetchAll();
            $insPost = $db->prepare('INSERT INTO post_topics (post_id, topic_id, is_primary) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
            foreach ($postLinks as $pl) {
                try {
                    $insPost->execute([(int)$pl['post_id'], $targetTopicId, (int)($pl['is_primary'] ?? 0)]);
                } catch (PDOException) {
                    $db->prepare('INSERT INTO post_topics (post_id, topic_id) VALUES (?, ?) ON CONFLICT DO NOTHING')
                       ->execute([(int)$pl['post_id'], $targetTopicId]);
                }
            }
            $db->prepare('DELETE FROM post_topics WHERE topic_id = ?')->execute([$sourceTopicId]);

            // 2. انتقال book_topics بدون ایجاد رکورد تکراری
            $stmt = $db->prepare('SELECT book_id FROM book_topics WHERE topic_id = ?');
            $stmt->execute([$sourceTopicId]);
            $bookLinks = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $insBook = $db->prepare('INSERT INTO book_topics (book_id, topic_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
            foreach ($bookLinks as $bid) {
                $insBook->execute([(int)$bid, $targetTopicId]);
            }
            $db->prepare('DELETE FROM book_topics WHERE topic_id = ?')->execute([$sourceTopicId]);

            // 3. انتقال lesson_topics بدون ایجاد رکورد تکراری
            $stmt = $db->prepare('SELECT lesson_id FROM lesson_topics WHERE topic_id = ?');
            $stmt->execute([$sourceTopicId]);
            $lessonLinks = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $insLesson = $db->prepare('INSERT INTO lesson_topics (lesson_id, topic_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
            foreach ($lessonLinks as $lid) {
                $insLesson->execute([(int)$lid, $targetTopicId]);
            }
            $db->prepare('DELETE FROM lesson_topics WHERE topic_id = ?')->execute([$sourceTopicId]);

            // 4. انتقال زیرموضوع‌ها (با جلوگیری از چرخه اگر مقصد فرزند مبدأ باشد)
            if ((int)($targetRow['parent_id'] ?? 0) === $sourceTopicId) {
                $sourceRow = getTopicById($sourceTopicId);
                $db->prepare('UPDATE topics SET parent_id = ? WHERE id = ?')
                   ->execute([$sourceRow['parent_id'] ?? null, $targetTopicId]);
            }
            $db->prepare('UPDATE topics SET parent_id = ? WHERE parent_id = ? AND id <> ?')
               ->execute([$targetTopicId, $sourceTopicId, $targetTopicId]);
        } else {
            // قطع ارتباط امن: فقط ردیف‌های جدول‌های رابط حذف می‌شوند؛ خود محتواها دست‌نخورده می‌مانند.
            $db->prepare('DELETE FROM post_topics WHERE topic_id = ?')->execute([$sourceTopicId]);
            $db->prepare('DELETE FROM book_topics WHERE topic_id = ?')->execute([$sourceTopicId]);
            $db->prepare('DELETE FROM lesson_topics WHERE topic_id = ?')->execute([$sourceTopicId]);
            $sourceRow = getTopicById($sourceTopicId);
            $db->prepare('UPDATE topics SET parent_id = ? WHERE parent_id = ?')
               ->execute([$sourceRow['parent_id'] ?? null, $sourceTopicId]);
        }

        if ($ownTx) $db->commit();
        return ['ok' => true, 'strategy' => $strategy, 'counts' => $counts];
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'عملیات انتقال/قطع ارتباط موضوع انجام نشد: ' . $e->getMessage()];
    }
}

/**
 * موضوعات علمی مرتبط با یک موضوع (بر اساس انتخاب صریح مدیر + اشتراک محتوا یا هم‌والدی).
 */
function getRelatedTopicsForTopic(int $topicId, array|int $explicitIds = [], int $limit = 6): array {
    if (is_int($explicitIds)) {
        $limit = $explicitIds;
        $disp = jhd_topic_display($topicId);
        $explicitIds = is_array($disp['related_topic_ids'] ?? null) ? $disp['related_topic_ids'] : [];
    } elseif (!$explicitIds) {
        $disp = jhd_topic_display($topicId);
        $explicitIds = is_array($disp['related_topic_ids'] ?? null) ? $disp['related_topic_ids'] : [];
    }
    if ($topicId < 1 || $limit < 1) return [];
    $out = [];
    $seen = [$topicId => true];
    if ($explicitIds) {
        foreach (getTopicsByIds($explicitIds) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) continue;
            $disp = jhd_topic_display($id);
            if (($disp['visibility'] ?? 'public') === 'inactive') continue;
            $seen[$id] = true;
            $out[] = $row;
            if (count($out) >= $limit) return $out;
        }
    }
    try {
        $db = getDB();
        $topic = getTopicById($topicId);
        $parentId = (int)($topic['parent_id'] ?? 0);
        if ($parentId > 0 && !isset($seen[$parentId])) {
            $parent = getTopicById($parentId);
            if ($parent && (int)($parent['is_active'] ?? 0) === 1) {
                $seen[$parentId] = true;
                $out[] = $parent;
            }
        }
        // موضوعاتی که با این موضوع مطلب مشترک دارند یا هم‌والد هستند
        $stmt = $db->prepare(
            "SELECT DISTINCT t.*
             FROM topics t
             LEFT JOIN post_topics pt2 ON pt2.topic_id = t.id
             LEFT JOIN post_topics pt1 ON pt1.post_id = pt2.post_id AND pt1.topic_id = ?
             WHERE t.is_active = 1 AND t.id <> ?
               AND (pt1.post_id IS NOT NULL OR (? > 0 AND t.parent_id = ?))
             ORDER BY t.is_featured DESC, t.sort_order ASC, t.id ASC
             LIMIT ?"
        );
        $stmt->execute([$topicId, $topicId, $parentId, $parentId, $limit * 2]);
        foreach ($stmt->fetchAll() as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) continue;
            $disp = jhd_topic_display($id);
            if (($disp['visibility'] ?? 'public') === 'inactive') continue;
            $seen[$id] = true;
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * فهرست موضوعات علمی فعال برای یک جایگاه مشخص (home, topics_archive, articles, books, lessons, news, media, sidebar).
 */
function getTopicsForPlacement(string $placement, int $limit = 12, bool $featuredOnly = false): array {
    $opts = ['active' => 1];
    if ($featuredOnly) $opts['featured'] = 1;
    $all = getTopics($opts);
    if (!$all) return [];
    $counts = jhd_topic_content_counts(array_column($all, 'id'));
    $filtered = [];
    foreach ($all as $t) {
        $tid = (int)($t['id'] ?? 0);
        if ($tid < 1) continue;
        $disp = jhd_topic_display($tid);
        if (($disp['visibility'] ?? 'public') !== 'public') continue;
        if (!in_array($placement, $disp['placements'] ?? [], true)) continue;
        $c = $counts[$tid] ?? ['posts' => 0, 'books' => 0, 'lessons' => 0];
        $totalPub = (int)$c['posts'] + (int)$c['books'] + (int)$c['lessons'];
        if ($placement === 'home' && ($disp['empty_behavior'] ?? 'message') === 'hide_from_home' && $totalPub === 0) {
            continue;
        }
        $t['_counts'] = $c;
        $t['_total_published'] = $totalPub;
        $t['_display'] = $disp;
        $filtered[] = $t;
        if ($limit > 0 && count($filtered) >= $limit) break;
    }
    return $filtered;
}

/** Banner placement/scheduling lives in settings; no live schema mutation. */
function jhd_banner_time(string $value): ?int {
    if($value==='') return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d\TH:i')===$value ? $date->getTimestamp() : null;
}

function jhd_banner_display(int $id): array {
    $raw = json_decode(getSetting('banner_display_' . $id, ''), true);
    $raw = is_array($raw) ? $raw : [];
    $placements = array_merge(['main'], array_keys(jhd_home_section_labels()));
    return [
        'placement'=>in_array($raw['placement'] ?? 'main', $placements, true) ? ($raw['placement'] ?? 'main') : 'main',
        'starts_at'=>(string)($raw['starts_at'] ?? ''),
        'ends_at'=>(string)($raw['ends_at'] ?? ''),
    ];
}

function getActiveBanners(int $limit=3, string $placement='main'): array {
    $limit=max(1,min(100,$limit));
    try {
        $db=getDB();
        $hasFeatured=false;
        try {
            // Do not resurrect legacy banners just because all managed banners
            // were disabled or scheduled for later by the administrator.
            $hasFeatured=(bool)$db->query('SELECT COUNT(*) FROM featured_banners')->fetchColumn();
            $rows=$db->query("SELECT id, title, description AS content, image, link_url AS link, button_text AS link_text, is_active, sort_order, created_at FROM featured_banners WHERE is_active=1 ORDER BY sort_order ASC, id DESC")->fetchAll();
            $rows=array_values(array_filter($rows, static function(array $row) use ($placement): bool {
                $cfg=jhd_banner_display((int)$row['id']);
                if($cfg['placement']!==$placement) return false;
                foreach(['starts_at','ends_at'] as $key) {
                    if($cfg[$key]==='') continue;
                    $at=jhd_banner_time($cfg[$key]);
                    if($at===null || ($key==='starts_at' ? $at>time() : $at<time())) return false;
                }
                return true;
            }));
            if($hasFeatured) return array_slice($rows,0,$limit);
        } catch(PDOException) {}
        if($placement!=='main') return [];
        $rows=$db->query("SELECT * FROM site_banners WHERE is_active=1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY sort_order ASC, id DESC LIMIT $limit")->fetchAll();
        foreach($rows as &$row) {
            $row['link']=(string)($row['link'] ?? $row['link_url'] ?? '');
            $row['link_text']=(string)($row['link_text'] ?? '');
        }
        unset($row);
        return $rows;
    } catch(PDOException) { return []; }
}

function jhd_render_home_banners(string $placement='main'): string {
    $rows=getActiveBanners(100,$placement);
    if(!$rows) return '';
    $html='<aside class="container jhd-home-banners my-3" aria-label="اعلان‌ها">';
    foreach($rows as $row) {
        $link=trim((string)($row['link'] ?? ''));
        $href=str_starts_with($link,'/') && !str_starts_with($link,'//') && !str_contains($link,'\\') && !preg_match('/[\x00-\x20]/',$link)
            ? jhd_web_path(ltrim($link,'/')) : safeExternalUrl($link);
        $html.='<article class="jhd-home-banner" data-banner-id="'.(int)$row['id'].'">';
        $image=trim((string)($row['image'] ?? ''));
        if($image!=='') $html.='<img loading="lazy" src="'.sanitize(imgUrl($image)).'" alt="'.sanitize($row['title']).'">';
        $html.='<div><h2 class="h4">'.sanitize($row['title']).'</h2>';
        if(!empty($row['content'])) $html.='<p>'.sanitize($row['content']).'</p>';
        if($href!=='') $html.='<a class="btn btn-outline-primary" href="'.sanitize($href).'">'.sanitize($row['link_text'] ?: 'مشاهده').'</a>';
        $html.='</div></article>';
    }
    return $html.'</aside>';
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
    $text = preg_replace('~[«»"“”\x{0027}،؛,:.!?؟()\[\]{}<>|\\/_-]+~u', ' ', $text) ?? $text;
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

/** Add stable in-page anchors and a compact outline to long, sanitized articles. */
function jhd_article_outline(string $html): array {
    if ($html === '' || !class_exists(DOMDocument::class)) return ['html' => $html, 'items' => []];

    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML(
        '<?xml encoding="UTF-8"?><div id="jhd-article-outline-root">' . $html . '</div>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return ['html' => $html, 'items' => []];

    $root = $dom->getElementById('jhd-article-outline-root');
    if (!$root) return ['html' => $html, 'items' => []];

    $headings = [];
    foreach (iterator_to_array($root->getElementsByTagName('*')) as $node) {
        if (!$node instanceof DOMElement) continue;
        $tag = strtolower($node->tagName);
        if (!in_array($tag, ['h2', 'h3'], true)) continue;
        $title = trim((string)preg_replace('/\s+/u', ' ', (string)$node->textContent));
        if ($title === '') continue;
        $headings[] = ['node' => $node, 'title' => $title, 'level' => $tag === 'h3' ? 2 : 1];
    }
    if (count($headings) < 3) return ['html' => $html, 'items' => []];

    $items = [];
    foreach ($headings as $index => $heading) {
        $id = 'jhd-article-heading-' . ($index + 1);
        $heading['node']->setAttribute('id', $id);
        $items[] = ['id' => $id, 'title' => $heading['title'], 'level' => $heading['level']];
    }

    $body = '';
    foreach ($root->childNodes as $child) $body .= $dom->saveHTML($child);
    return ['html' => $body, 'items' => $items];
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
        $pattern = '~<p>\s*' . preg_quote($heading, '~') . '\s*</p>~u';
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
    // Excerpts are text, unlike the rich body: preserve word boundaries
    // between HTML blocks and decode entities once before the caller escapes.
    $text = preg_replace('~<(?:br\b[^>]*|/(?:p|div|h[1-6]|li|blockquote|section))>~iu', ' ', $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
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
        $sort = (string)($opts['sort'] ?? '');
        $orderBy = match ($sort) {
            'newest'   => 'b.created_at DESC, b.id DESC',
            'oldest'   => 'b.created_at ASC, b.id ASC',
            'title'    => 'b.title ASC, b.id DESC',
            'manual'   => 'b.is_featured DESC, b.sort_order ASC, b.created_at DESC, b.id DESC',
            'featured' => 'b.is_featured DESC, b.created_at DESC, b.id DESC',
            default    => 'b.is_featured DESC, b.created_at DESC',
        };
        $stmt=$db->prepare("SELECT b.* FROM books b WHERE $whereStr ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($params,[$limit,$offset])); return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

/* ══════════════════════════════════════════════════════════════════════════
 * انتخاب هوشمند رسانهٔ شاخص (تصویر، ویدیو، صوت، سند)
 * ──────────────────────────────────────────────────────────────────────────
 * قواعد الزامی:
 *   • حالت ۱: تصویر شاخص معتبر دارد ← از تصویر انتخاب‌شده استفاده شود.
 *   • حالت ۲: تصویر ندارد ولی ویدیو معتبر دارد ← خود ویدیو به‌عنوان رسانهٔ
 *     شاخص (پیش‌نمایش ویدیو یا فریم ویدیو با دکمه پخش) استفاده شود. هرگز فریم
 *     اول ویدیو به صورت خودکار به‌عنوان تصویر استخراج نمی‌شود و محتوای ویدیویی
 *     به دلیل نداشتن تصویر از بخش شاخص حذف نمی‌شود.
 *   • حالت ۳: هم تصویر و هم ویدیو دارد ← انتخاب صریح ادمین اولویت دارد.
 *   • حالت ۴: فقط صوت یا فقط سند دارد ← قاب صوتی یا کارت سند مناسب بدون
 *     تصویر جعلی یا بی‌ربط نمایش داده شود.
 *   • مسیر فایل (file_path) هرگز با شناسهٔ عددی رکورد (media_id) اشتباه نشود.
 * ══════════════════════════════════════════════════════════════════════════ */

function jhd_is_usable_media_path(?string $path, string $expectedKind = ''): bool {
    if ($path === null) return false;
    $path = trim($path);
    if ($path === '' || $path === 'null' || $path === 'undefined' || $path === '0') return false;
    // یک شناسهٔ عددی خام هرگز مسیر فایل معتبر نیست
    if (ctype_digit($path)) return false;
    if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || str_contains($path, '..')) return false;
    if (preg_match('~^(?:javascript|data|vbscript|file):~i', $path)) return false;

    $urlPath = (string)(parse_url($path, PHP_URL_PATH) ?: '');
    if ($urlPath === '' || !str_contains($urlPath, '.')) return false;
    $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
    if ($ext === '') return false;

    $extMap = [
        'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'],
        'video'    => ['mp4', 'webm', 'mov', 'mkv', 'm4v', 'ogv'],
        'audio'    => ['mp3', 'ogg', 'wav', 'm4a', 'aac', 'flac', 'opus'],
        'document' => ['pdf', 'doc', 'docx'],
    ];
    if ($expectedKind !== '' && isset($extMap[$expectedKind])) {
        if (!in_array($ext, $extMap[$expectedKind], true)) return false;
    } else {
        $allExts = array_merge(...array_values($extMap));
        if (!in_array($ext, $allExts, true)) return false;
    }

    $rel = ltrim($urlPath, '/');
    if (!preg_match('~^https?://~i', $path) && str_starts_with($rel, 'uploads/')) {
        $seedFixtures = [
            'uploads/images/seed-cover.png',
            'uploads/videos/seed-video.mp4',
            'uploads/audios/seed-audio.mp3',
            'uploads/documents/seed-doc.pdf',
            'uploads/documents/seed-doc.docx',
        ];
        if (!in_array($rel, $seedFixtures, true) && (!function_exists('storageBackend') || storageBackend() === 'local')) {
            $rootDir = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            if (!is_file($rootDir . '/' . $rel)) {
                return false;
            }
        }
    }
    return true;
}

function jhd_featured_media_pref_options(): array {
    return [
        'auto'     => 'تشخیص هوشمند خودکار (تصویر ← ویدیو ← صوت ← سند)',
        'image'    => 'اولویت با تصویر شاخص',
        'video'    => 'اولویت با ویدیو شاخص (نمایش قاب ویدیو در کارت و بخش ویژه)',
        'audio'    => 'اولویت با فایل صوتی (نمایش قاب صوتی)',
        'document' => 'اولویت با سند پیوست (PDF / Word)',
        'none'     => 'بدون رسانه شاخص (فقط متن)',
    ];
}

function jhd_get_featured_media_pref(string $entityType, int $entityId): array {
    $entityType = in_array($entityType, ['post', 'book', 'lesson', 'topic'], true) ? $entityType : 'post';
    $default = ['mode' => 'auto', 'media_id' => 0, 'file_path' => ''];
    if ($entityId < 1) return $default;
    $raw = json_decode(getSetting('featured_media_' . $entityType . '_' . $entityId, ''), true);
    if (!is_array($raw)) return $default;
    $mode = is_string($raw['mode'] ?? null) ? $raw['mode'] : 'auto';
    if (!array_key_exists($mode, jhd_featured_media_pref_options())) $mode = 'auto';
    $mediaId = max(0, (int)($raw['media_id'] ?? 0));
    $filePath = is_string($raw['file_path'] ?? null) ? trim($raw['file_path']) : '';
    if ($filePath !== '' && !jhd_is_usable_media_path($filePath)) {
        $filePath = '';
    }
    return ['mode' => $mode, 'media_id' => $mediaId, 'file_path' => $filePath];
}

function jhd_save_featured_media_pref(string $entityType, int $entityId, array $pref): array {
    $entityType = in_array($entityType, ['post', 'book', 'lesson', 'topic'], true) ? $entityType : 'post';
    if ($entityId < 1) return ['mode' => 'auto', 'media_id' => 0, 'file_path' => ''];
    $mode = is_string($pref['mode'] ?? null) ? trim($pref['mode']) : 'auto';
    if (!array_key_exists($mode, jhd_featured_media_pref_options())) $mode = 'auto';
    $mediaId = max(0, (int)($pref['media_id'] ?? 0));
    $filePath = is_string($pref['file_path'] ?? null) ? trim($pref['file_path']) : '';
    if ($filePath !== '' && !jhd_is_usable_media_path($filePath)) {
        $filePath = '';
    }
    $clean = ['mode' => $mode, 'media_id' => $mediaId, 'file_path' => $filePath];
    setSetting('featured_media_' . $entityType . '_' . $entityId, (string)json_encode($clean, JSON_UNESCAPED_UNICODE));
    return $clean;
}

/**
 * تشخیص هوشمند رسانهٔ شاخص برای هر نوع محتوا (post, book, lesson, topic, media).
 *
 * @return array{
 *   kind: 'image'|'video'|'audio'|'document'|'none',
 *   url: string,
 *   path: string,
 *   poster: string,
 *   media_id: int,
 *   doc_type: string,
 *   has_image: bool,
 *   has_video: bool,
 *   has_audio: bool,
 *   has_document: bool,
 *   pref_mode: string
 * }
 */
function jhd_resolve_featured_media(array $item, string $entityType = 'post'): array {
    $id = (int)($item['id'] ?? ($item['post_id'] ?? ($item['lesson_id'] ?? 0)));
    $pref = ($id > 0 && in_array($entityType, ['post', 'book', 'lesson', 'topic'], true))
        ? jhd_get_featured_media_pref($entityType, $id)
        : ['mode' => 'auto', 'media_id' => 0, 'file_path' => ''];
    if (!empty($item['featured_media_pref']) && is_string($item['featured_media_pref'])) {
        $override = trim($item['featured_media_pref']);
        if (array_key_exists($override, jhd_featured_media_pref_options())) {
            $pref['mode'] = $override;
        }
    }

    $imgPath = '';
    $vidPath = '';
    $vidMediaId = 0;
    $audPath = '';
    $audMediaId = 0;
    $docPath = '';
    $docMediaId = 0;

    // ۱. بررسی تصویر شاخص روی رکورد یا گالری
    foreach (['featured_image', 'cover_image', 'thumbnail'] as $imgCol) {
        $cand = trim((string)($item[$imgCol] ?? ''));
        if ($cand !== '' && jhd_is_usable_media_path($cand, 'image')) {
            $imgPath = $cand;
            break;
        }
    }
    if ($imgPath === '' && $entityType === 'post' && $id > 0 && function_exists('getPostFeaturedImages')) {
        foreach (getPostFeaturedImages($id) as $fi) {
            $cand = trim((string)($fi['image_path'] ?? ''));
            if ($cand !== '' && jhd_is_usable_media_path($cand, 'image')) {
                $imgPath = $cand;
                break;
            }
        }
    }

    // ۲. بررسی ویدیو روی رکورد یا جدول media_files
    foreach (['featured_video', 'video_file'] as $vidCol) {
        $cand = trim((string)($item[$vidCol] ?? ''));
        if ($cand !== '' && jhd_is_usable_media_path($cand, 'video')) {
            $vidPath = $cand;
            break;
        }
    }
    if ($entityType === 'media') {
        $mKind = (string)($item['media_kind'] ?? ($item['kind'] ?? ''));
        $mPath = trim((string)($item['path'] ?? ($item['file_path'] ?? '')));
        if ($mKind === 'video' && jhd_is_usable_media_path($mPath, 'video')) {
            $vidPath = $mPath;
            $vidMediaId = (int)($item['media_id'] ?? ($item['id'] ?? 0));
        } elseif ($mKind === 'audio' && jhd_is_usable_media_path($mPath, 'audio')) {
            $audPath = $mPath;
            $audMediaId = (int)($item['media_id'] ?? ($item['id'] ?? 0));
        }
    }

    // ۳. بررسی صوت و سند روی رکورد
    $candAud = trim((string)($item['audio_file'] ?? ''));
    if ($candAud !== '' && jhd_is_usable_media_path($candAud, 'audio')) {
        $audPath = $candAud;
    }
    foreach (['pdf_file', 'word_file', 'document_file'] as $docCol) {
        $candDoc = trim((string)($item[$docCol] ?? ''));
        if ($candDoc !== '' && jhd_is_usable_media_path($candDoc, 'document')) {
            $docPath = $candDoc;
            break;
        }
    }

    // ۴. بررسی جدول media_files برای post/lesson/book در صورت نیاز
    if ($id > 0 && in_array($entityType, ['post', 'lesson', 'book'], true)) {
        if (!function_exists('getMediaFor')) {
            require_once __DIR__ . '/media.php';
        }
    }
    if ($id > 0 && in_array($entityType, ['post', 'lesson', 'book'], true) && function_exists('getMediaFor')) {
        if ($vidPath === '') {
            if ($entityType === 'post' && function_exists('getPostFeaturedVideos')) {
                foreach (getPostFeaturedVideos($id) as $fv) {
                    $cand = trim((string)($fv['file_path'] ?? ''));
                    if (jhd_is_usable_media_path($cand, 'video')) {
                        $vidPath = $cand;
                        $vidMediaId = (int)($fv['id'] ?? 0);
                        break;
                    }
                }
            }
            if ($vidPath === '') {
                foreach (getMediaFor($entityType, $id, 'video') as $mv) {
                    $cand = trim((string)($mv['file_path'] ?? ''));
                    if (jhd_is_usable_media_path($cand, 'video')) {
                        $vidPath = $cand;
                        $vidMediaId = (int)($mv['id'] ?? 0);
                        break;
                    }
                }
            }
        }
        if ($audPath === '') {
            foreach (getMediaFor($entityType, $id, 'audio') as $ma) {
                $cand = trim((string)($ma['file_path'] ?? ''));
                if (jhd_is_usable_media_path($cand, 'audio')) {
                    $audPath = $cand;
                    $audMediaId = (int)($ma['id'] ?? 0);
                    break;
                }
            }
        }
        if ($docPath === '') {
            foreach (getMediaFor($entityType, $id, 'document') as $md) {
                $cand = trim((string)($md['file_path'] ?? ''));
                if (jhd_is_usable_media_path($cand, 'document')) {
                    $docPath = $cand;
                    $docMediaId = (int)($md['id'] ?? 0);
                    break;
                }
            }
        }
    }

    $hasImage = $imgPath !== '';
    $hasVideo = $vidPath !== '';
    $hasAudio = $audPath !== '';
    $hasDocument = $docPath !== '';

    $imgUrl = $hasImage ? imgUrl($imgPath) : '';
    $vidUrl = $hasVideo ? imgUrl($vidPath) : '';
    $audUrl = $hasAudio ? imgUrl($audPath) : '';
    $docUrl = $hasDocument ? imgUrl($docPath) : '';
    $docExt = $hasDocument ? strtolower(pathinfo((string)(parse_url($docPath, PHP_URL_PATH) ?: $docPath), PATHINFO_EXTENSION)) : '';
    $docType = in_array($docExt, ['doc', 'docx'], true) ? 'Word' : ($hasDocument ? 'PDF' : '');

    $base = [
        'has_image'    => $hasImage,
        'has_video'    => $hasVideo,
        'has_audio'    => $hasAudio,
        'has_document' => $hasDocument,
        'pref_mode'    => $pref['mode'],
        'poster'       => $imgUrl,
        'doc_type'     => $docType,
    ];

    $mode = $pref['mode'];
    if ($mode === 'none') {
        return $base + ['kind' => 'none', 'url' => '', 'path' => '', 'media_id' => 0];
    }
    if ($mode === 'video' && $hasVideo) {
        return $base + ['kind' => 'video', 'url' => $vidUrl, 'path' => $vidPath, 'media_id' => $vidMediaId];
    }
    if ($mode === 'image' && $hasImage) {
        return $base + ['kind' => 'image', 'url' => $imgUrl, 'path' => $imgPath, 'media_id' => 0];
    }
    if ($mode === 'audio' && $hasAudio) {
        return $base + ['kind' => 'audio', 'url' => $audUrl, 'path' => $audPath, 'media_id' => $audMediaId];
    }
    if ($mode === 'document' && $hasDocument) {
        return $base + ['kind' => 'document', 'url' => $docUrl, 'path' => $docPath, 'media_id' => $docMediaId];
    }

    // اولویت خودکار: تصویر (حالت ۱) ← ویدیو (حالت ۲) ← صوت (حالت ۴ الف) ← سند (حالت ۴ ب)
    if ($hasImage) {
        return $base + ['kind' => 'image', 'url' => $imgUrl, 'path' => $imgPath, 'media_id' => 0];
    }
    if ($hasVideo) {
        return $base + ['kind' => 'video', 'url' => $vidUrl, 'path' => $vidPath, 'media_id' => $vidMediaId];
    }
    if ($hasAudio) {
        return $base + ['kind' => 'audio', 'url' => $audUrl, 'path' => $audPath, 'media_id' => $audMediaId];
    }
    if ($hasDocument) {
        return $base + ['kind' => 'document', 'url' => $docUrl, 'path' => $docPath, 'media_id' => $docMediaId];
    }

    return $base + ['kind' => 'none', 'url' => '', 'path' => '', 'media_id' => 0];
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

function searchAll(string $q, int $limit=12, int $offset=0, string $filter='all', int $topicId=0, string $sort='relevance'): array {
    $allowed = ['all','topic','media','audio','video','article','research','report','news','announcement','program','religious','event','speech','qa','book','lesson'];
    $filter = in_array($filter, $allowed, true) ? $filter : 'all';
    $topicId = max(0, $topicId);
    $sort = in_array($sort, ['relevance', 'newest'], true) ? $sort : 'relevance';

    // An empty phrase is useful when the visitor deliberately chooses a type or
    // topic: it browses published records in that scope. A completely empty
    // search remains the discovery state and does not scan every content table.
    if ($q === '' && $filter === 'all' && $topicId < 1) return ['total' => 0, 'results' => []];

    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $qLike = '%' . $q . '%';
    $scopeIds = $topicId > 0 ? getTopicScopeIds($topicId) : [];
    $scopeSql = $scopeIds ? implode(',', array_map('intval', $scopeIds)) : '0';
    $topicMatch = static function (string $junction, string $ownerColumn, string $ownerAlias) use ($scopeIds, $scopeSql): string {
        if (!$scopeIds) return '1';
        return "CASE WHEN EXISTS (SELECT 1 FROM $junction topic_filter WHERE topic_filter.$ownerColumn=$ownerAlias.id AND topic_filter.topic_id IN ($scopeSql)) THEN 1 ELSE 0 END";
    };
    $postTopicMatch = $topicMatch('post_topics', 'post_id', 'p');
    $lessonTopicMatch = $topicMatch('lesson_topics', 'lesson_id', 'l');
    $bookTopicMatch = $topicMatch('book_topics', 'book_id', 'b');
    $sources = [];
    $sourceParams = [];
    $addSource = static function (string $sql, array $params = []) use (&$sources, &$sourceParams): void {
        $sources[] = $sql;
        array_push($sourceParams, ...$params);
    };

    // Every source exposes the same columns so counts, sorting, and pagination
    // operate across the complete, published result set.
    if ($filter === 'topic') {
        $topicMatchExpression = $scopeIds ? "CASE WHEN id IN ($scopeSql) THEN 1 ELSE 0 END" : '1';
        $addSource("SELECT id,name AS title,slug,description AS summary,intro AS content,cover_image AS featured_image,'topic' AS post_type,created_at AS published_at,created_at,'topic' AS target,'' AS media_kind,0 AS is_featured,$topicMatchExpression AS topic_match FROM topics WHERE is_active=1");
    }

    if (in_array($filter, ['all', 'media', 'audio', 'video'], true)) {
        $kindClause = in_array($filter, ['audio', 'video'], true) ? ' AND m.kind=?' : '';
        $kindParams = in_array($filter, ['audio', 'video'], true) ? [$filter] : [];
        $mediaTopicMatch = $scopeIds
            ? "CASE WHEN (p.id IS NOT NULL AND EXISTS (SELECT 1 FROM post_topics topic_filter WHERE topic_filter.post_id=p.id AND topic_filter.topic_id IN ($scopeSql))) OR (l.id IS NOT NULL AND EXISTS (SELECT 1 FROM lesson_topics topic_filter WHERE topic_filter.lesson_id=l.id AND topic_filter.topic_id IN ($scopeSql))) THEN 1 ELSE 0 END"
            : '1';
        $addSource("SELECT m.id,COALESCE(NULLIF(m.title,''),p.title,l.title,'رسانه') AS title,COALESCE(p.slug,l.slug) AS slug,COALESCE(p.summary,l.summary,'') AS summary,'' AS content,m.file_path AS featured_image,'media' AS post_type,COALESCE(p.published_at,l.created_at,m.created_at) AS published_at,m.created_at,'media' AS target,m.kind AS media_kind,0 AS is_featured,$mediaTopicMatch AS topic_match FROM media_files m LEFT JOIN posts p ON m.ref_type='post' AND p.id=m.ref_id LEFT JOIN lessons l ON m.ref_type='lesson' AND l.id=m.ref_id WHERE m.kind IN ('audio','video') AND ((p.status='published') OR (l.status='published'))$kindClause", $kindParams);

        // Some installations still store a primary audio/video URL on the
        // published parent row. Include it only when no equivalent media row
        // exists, and keep the destination on that public parent page.
        if ($filter !== 'video') {
            $addSource("SELECT l.id,l.title,l.slug,l.summary,'' AS content,'' AS featured_image,'lesson' AS post_type,l.created_at AS published_at,l.created_at,'lesson' AS target,'audio' AS media_kind,0 AS is_featured,$lessonTopicMatch AS topic_match FROM lessons l WHERE l.status='published' AND l.audio_file IS NOT NULL AND l.audio_file<>'' AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='audio' AND m.file_path=l.audio_file)");
        }
        if ($filter !== 'audio') {
            $addSource("SELECT l.id,l.title,l.slug,l.summary,'' AS content,'' AS featured_image,'lesson' AS post_type,l.created_at AS published_at,l.created_at,'lesson' AS target,'video' AS media_kind,0 AS is_featured,$lessonTopicMatch AS topic_match FROM lessons l WHERE l.status='published' AND l.video_file IS NOT NULL AND l.video_file<>'' AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='lesson' AND m.ref_id=l.id AND m.kind='video' AND m.file_path=l.video_file)");
            $addSource("SELECT p.id,p.title,p.slug,p.summary,'' AS content,'' AS featured_image,p.post_type,p.published_at,p.created_at,'post' AS target,'video' AS media_kind,p.is_featured,$postTopicMatch AS topic_match FROM posts p WHERE p.status='published' AND p.featured_video IS NOT NULL AND p.featured_video<>'' AND NOT EXISTS(SELECT 1 FROM media_files m WHERE m.ref_type='post' AND m.ref_id=p.id AND m.kind='video' AND m.file_path=p.featured_video)");
        }
    }

    $postTypes = ['article','research','report','news','announcement','program','religious','event','speech','qa'];
    if ($filter === 'all') {
        $addSource("SELECT p.id,p.title,p.slug,p.summary,p.content,p.featured_image,p.post_type,p.published_at,p.created_at,'post' AS target,'' AS media_kind,p.is_featured,$postTopicMatch AS topic_match FROM posts p WHERE p.status='published'");
    } elseif (in_array($filter, $postTypes, true)) {
        $addSource("SELECT p.id,p.title,p.slug,p.summary,p.content,p.featured_image,p.post_type,p.published_at,p.created_at,'post' AS target,'' AS media_kind,p.is_featured,$postTopicMatch AS topic_match FROM posts p WHERE p.status='published' AND p.post_type=?", [$filter]);
    }

    if (in_array($filter, ['all', 'lesson'], true)) {
        $addSource("SELECT l.id,l.title,l.slug,l.summary,l.content,l.featured_image,'lesson' AS post_type,l.created_at AS published_at,l.created_at,'lesson' AS target,'' AS media_kind,l.is_featured,$lessonTopicMatch AS topic_match FROM lessons l WHERE l.status='published'");
    }
    if (in_array($filter, ['all', 'book'], true)) {
        $addSource("SELECT b.id,b.title,b.slug,b.description AS summary,b.description AS content,b.cover_image AS featured_image,'book' AS post_type,b.created_at AS published_at,b.created_at,'book' AS target,'' AS media_kind,b.is_featured,$bookTopicMatch AS topic_match FROM books b WHERE b.status='published'");
    }
    if (!$sources) return ['total' => 0, 'results' => []];

    try {
        $db = getDB();
        $union = implode(' UNION ALL ', $sources);
        $conditions = [];
        $whereParams = [];
        if ($q !== '') {
            $conditions[] = "(LOWER(COALESCE(title, '')) LIKE LOWER(?) OR LOWER(COALESCE(summary, '')) LIKE LOWER(?) OR LOWER(COALESCE(content, '')) LIKE LOWER(?))";
            array_push($whereParams, $qLike, $qLike, $qLike);
        }
        if ($topicId > 0) $conditions[] = 'topic_match=1';
        $where = $conditions ? implode(' AND ', $conditions) : '1=1';

        $count = $db->prepare("SELECT COUNT(*) FROM ($union) search_rows WHERE $where");
        $count->execute(array_merge($sourceParams, $whereParams));
        $total = (int)$count->fetchColumn();

        if ($q !== '' && $sort === 'relevance') {
            $orderBy = "CASE WHEN LOWER(COALESCE(title,''))=LOWER(?) THEN 3 WHEN LOWER(COALESCE(title,'')) LIKE LOWER(?) THEN 2 WHEN LOWER(COALESCE(summary,'')) LIKE LOWER(?) THEN 1 ELSE 0 END DESC,is_featured DESC,COALESCE(published_at,created_at) DESC,id DESC";
            $orderParams = [$q, $qLike, $qLike];
        } else {
            $orderBy = 'COALESCE(published_at,created_at) DESC,id DESC';
            $orderParams = [];
        }
        $stmt = $db->prepare("SELECT * FROM ($union) search_rows WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($sourceParams, $whereParams, $orderParams, [$limit, $offset]));
        return ['total' => $total, 'results' => $stmt->fetchAll()];
    } catch (PDOException $e) {
        error_log('search failed: ' . get_class($e));
        return ['total' => 0, 'results' => []];
    }
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
    // Keep structured-data URLs identical to the page canonical in both URL
    // modes. postUrl() handles Persian slugs and emits the configured route.
    if (trim((string)($post['slug'] ?? '')) === '') return '';
    $canonicalPath = postUrl($post);
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
    if (!SITE_URL || trim((string)($post['slug'] ?? '')) === '') return '';
    $canonicalPath = postUrl($post);
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
    $canonicalPath = bookUrl($book);
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
    if (!SITE_URL || trim((string)($lesson['slug'] ?? '')) === '') return '';
    $canonicalPath = lessonUrl($lesson);
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

/**
 * The admin textareas store their value verbatim (including CRLF/newlines).
 * Render legacy plain text as semantic paragraphs, but never run nl2br() over
 * structured HTML. Sanitize on read so old records are protected too, without
 * rewriting the database or losing the editor's original value.
 */
function safeRichText(?string $html): string {
    $html = trim(str_replace(["\r\n", "\r"], "\n", (string)$html));
    if ($html === '') return '';
    if (!preg_match('~</?[a-z][a-z0-9:-]*(?:\s[^<>]*|\s*/?)>~i', $html)) {
        $paragraphs = preg_split('/\n[ \t]*\n(?:[ \t]*\n)*/u', $html) ?: [$html];
        return implode("\n", array_map(static function (string $text): string {
            $text = trim($text);
            /*
             * تیترهای تایپ‌شده در ویرایشگر ساده: وقتی مدیر متن را بدون HTML
             * می‌نویسد، سطرِ «## موضوع» به تیتر تیرهٔ h2 (و ### به h3، #### به
             * h4) تبدیل می‌شود تا در نمایش عمومی همان استایل تیره و برجستهٔ
             * تیترهای مقاله را بگیرد و در فهرست مطالب هم بنشیند.
             */
            if (!str_contains($text, "\n") && preg_match('/^(#{2,4})[ \t]+(\S.*)$/u', $text, $match)) {
                $level = strlen($match[1]);
                return '<h' . $level . '>' . sanitize(trim($match[2])) . '</h' . $level . '>';
            }
            $escaped = sanitize($text);
            // «**بولد**» در متن ساده — بعد از escape، پس هیچ تزریقی رخ نمی‌دهد.
            $escaped = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/us', '<strong>$1</strong>', $escaped) ?? $escaped;
            return '<p>' . str_replace("\n", "<br>\n", $escaped) . '</p>';
        }, $paragraphs));
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        // Correct UTF-8 declaration; the old string contained a literal \".
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded) return '';
    $allowed = ['p','div','span','strong','b','em','i','u','s','del','sup','sub','ul','ol','li','dl','dt','dd','blockquote','h2','h3','h4','h5','h6','br','hr','a','img','pre','code','figure','figcaption','table','caption','thead','tbody','tfoot','tr','th','td'];
    $safeUrl = static function (string $value, bool $anchor): bool {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return false;
        return safeExternalUrl($value) !== ''
            || (str_starts_with($value, '/') && !str_starts_with($value, '//'))
            || ($anchor && str_starts_with($value, '#'));
    };
    $clean = function (DOMNode $node) use (&$clean, $allowed, $safeUrl): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) { $node->removeChild($child); continue; }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script','style','iframe','object','embed','svg','math','form','input','button','textarea','select','template','meta','link','base'], true)) { $node->removeChild($child); continue; }
            $clean($child);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $value = $attr->value;
                $keep = in_array($name, ['title','alt'], true);
                if (($tag === 'a' && $name === 'href') || ($tag === 'img' && $name === 'src')) $keep = $safeUrl($value, $tag === 'a');
                if ($name === 'dir') $keep = in_array($value, ['rtl','ltr','auto'], true);
                if ($name === 'id' && in_array($tag, ['h2','h3','h4','h5','h6'], true)) $keep = (bool)preg_match('/^[\p{L}\p{N}_][\p{L}\p{N}\p{M}_.:\x{200C}\x{200D}-]{0,199}$/uD', $value);
                if ($tag === 'img' && in_array($name, ['width','height'], true)) $keep = ctype_digit($value) && (int)$value > 0 && (int)$value <= 8192;
                if ($name === 'lang') $keep = (bool)preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/iD', $value);
                if (in_array($tag, ['td','th'], true) && in_array($name, ['colspan','rowspan'], true)) $keep = ctype_digit($value) && (int)$value > 0 && (int)$value <= 100;
                if ($tag === 'th' && $name === 'scope') $keep = in_array($value, ['row','col','rowgroup','colgroup'], true);
                if (($tag === 'ol' && $name === 'start') || ($tag === 'li' && $name === 'value')) $keep = (bool)preg_match('/^-?\d{1,6}$/D', $value);
                if ($tag === 'ol' && $name === 'reversed') $keep = true;
                if (!$keep) $child->removeAttribute($attr->name);
            }
            if ($tag === 'a') $child->setAttribute('rel', 'noopener noreferrer');
            if ($tag === 'img') {
                if (!$child->hasAttribute('src')) { $node->removeChild($child); continue; }
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('decoding', 'async');
            }
        }
    };
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) return '';
    $clean($body);
    // Browser/pasted editor HTML often uses divs or loose inline text. Group
    // those runs in paragraphs too, without adding breaks inside existing p,
    // headings, lists, tables or preformatted HTML.
    $blocks = ['p','div','ul','ol','dl','blockquote','h2','h3','h4','h5','h6','hr','pre','figure','table'];
    $paragraphize = function (DOMNode $parent) use (&$paragraphize, $dom, $blocks): void {
        $paragraph = null;
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), $blocks, true)) {
                $paragraph = null;
                if (strtolower($child->tagName) === 'div') $paragraphize($child);
                continue;
            }
            if ($child instanceof DOMText && trim($child->textContent) === '' && $paragraph === null) continue;
            if ($paragraph === null) {
                $paragraph = $dom->createElement('p');
                $parent->insertBefore($paragraph, $child);
            }
            if ($child instanceof DOMText) {
                $parts = preg_split('/(\n[ \t]*\n(?:[ \t]*\n)*)/u', $child->textContent, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                foreach ($parts as $i => $part) {
                    if ($i % 2 === 1) { $paragraph = null; continue; }
                    if ($part === '') continue;
                    if ($paragraph === null) { $paragraph = $dom->createElement('p'); $parent->insertBefore($paragraph, $child); }
                    foreach (explode("\n", $part) as $lineIndex => $line) {
                        if ($lineIndex > 0) $paragraph->appendChild($dom->createElement('br'));
                        $paragraph->appendChild($dom->createTextNode($line));
                    }
                }
                $parent->removeChild($child);
            } else {
                $paragraph->appendChild($child);
            }
        }
    };
    $paragraphize($body);
    $out = '';
    foreach ($body->childNodes as $child) $out .= $dom->saveHTML($child);
    return trim($out);
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
