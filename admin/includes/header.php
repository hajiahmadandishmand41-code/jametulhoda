<?php
/**
 * admin/includes/header.php — هدر و سایدبار پنل مدیریت جامعه‌الهدی
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
// نگهبان پنل: ورود لازم است و نقش باید از نوع کارکنان باشد.
requireLogin();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$currentAdminPage = basename($_SERVER['PHP_SELF']);
$currentAdminDir  = basename(dirname($_SERVER['PHP_SELF']));
$currentRoutePath = current_path();
$currentAdminPath = '/' . trim($currentRoutePath, '/');
if ($currentAdminPath === '') $currentAdminPath = '/';
$currentAdminType = is_string($_GET['type'] ?? null) ? trim($_GET['type']) : '';
if ($currentAdminType === '' && is_string($_GET['post_type'] ?? null)) $currentAdminType = trim($_GET['post_type']);
$adminPathIs = static function (string $route) use ($currentAdminPath): bool {
    $route = '/' . trim($route, '/');
    return $currentAdminPath === $route || str_starts_with($currentAdminPath, $route . '/');
};
$adminPathMatches = static function (array $routes) use ($adminPathIs): bool {
    foreach ($routes as $route) if ($adminPathIs($route)) return true;
    return false;
};

// تا زمانی که رمز اولیهٔ نصب تغییر نکرده باشد، فقط پروفایل باز است.
$adminAccount = currentUser();
if ((int)($adminAccount['must_change_password'] ?? 0) === 1
    && !in_array($currentAdminPage, ['profile.php', 'change-password.php'], true)) {
    redirect(adminProfileUrl() . '?force=1');
}

// کنترل دسترسی بر پایهٔ «قابلیت» (includes/roles.php)، نه مقایسهٔ رشتهٔ نقش.
$sectionCapabilities = [
    'settings'    => 'settings',
    'messages'    => 'messages',
    'media'       => 'media',
    'users'       => 'users',
    'members'     => 'users',
    'diagnostics' => 'diagnostics',
    'banners'     => 'messages',
];
$currentSection = $currentAdminDir === 'admin'
    ? preg_replace('/\.php$/', '', $currentAdminPage)
    : $currentAdminDir;
if (isset($sectionCapabilities[$currentSection]) && !jhd_can($sectionCapabilities[$currentSection])) {
    http_response_code(403);
    jhd_render_403();
}

require_once __DIR__ . '/../../includes/admin-actions.php';
$admin = currentAdmin();
$isSuperAdmin = jhd_can('users');
$isAdminRole  = jhd_can('settings');
$adminUnreadMessages = 0;
try { $adminUnreadMessages = (int)getDB()->query("SELECT COUNT(*) FROM contact_messages WHERE is_read=0")->fetchColumn(); } catch (Throwable) { $adminUnreadMessages = 0; }

$adminNewsActive = $adminPathIs('/admin/news') || $currentAdminDir === 'news' || $currentAdminType === 'news';
$adminArticlesActive = $adminPathIs('/admin/articles') || $currentAdminDir === 'articles' || $currentAdminType === 'article';
$adminReportsActive = $adminPathIs('/admin/reports') || $currentAdminPage === 'reports.php' || $currentAdminType === 'report';
$adminResearchActive = $adminPathIs('/admin/research') || $currentAdminPage === 'research.php' || $currentAdminType === 'research';
$adminBooksActive = $adminPathIs('/admin/books') || $currentAdminDir === 'books';
$adminCoursesActive = $adminPathIs('/admin/courses') || in_array($currentAdminDir, ['lessons', 'lesson-collections'], true) || $currentAdminPage === 'courses.php';
$adminSpeechesActive = $adminPathIs('/admin/speeches') || $currentAdminDir === 'speeches' || $currentAdminType === 'speech';
$adminPostsActive = ($adminPathMatches(['/admin/content', '/admin/posts']) || $currentAdminDir === 'posts') && $currentAdminType === '';
$adminTopicsActive = $adminPathIs('/admin/topics') || $currentAdminDir === 'topics';
$adminCategoriesActive = $adminPathIs('/admin/categories') || $currentAdminDir === 'categories';
$adminVideosActive = $adminPathIs('/admin/videos') || $currentAdminPage === 'videos.php';
$adminAudiosActive = $adminPathIs('/admin/audios') || $currentAdminPage === 'audios.php';
$adminUploadsActive = $adminPathMatches(['/admin/uploads', '/admin/media']) || $currentAdminDir === 'media';
$adminMessagesActive = $adminPathIs('/admin/messages') || $currentAdminDir === 'messages' || $currentAdminPage === 'messages.php';
$adminBannersActive = $adminPathIs('/admin/banners') || $currentAdminDir === 'banners';
$adminUsersActive = $adminPathIs('/admin/users') || $adminPathIs('/admin/members') || in_array($currentAdminDir, ['users', 'members'], true);
$adminSettingsActive = $adminPathIs('/admin/settings') || $currentAdminPage === 'settings.php';
$adminDiagnosticsActive = $adminPathIs('/admin/diagnostics') || $currentAdminPage === 'diagnostics.php';
$adminProfileActive = $adminPathIs('/admin/profile') || $currentAdminPage === 'profile.php';
$adminPasswordActive = $adminPathIs('/admin/change-password') || $currentAdminPage === 'change-password.php';
$adminDashboardActive = $adminPathIs('/admin/dashboard') || $currentAdminPath === '/admin' || ($currentAdminPage === 'index.php' && $currentAdminDir === 'admin') || $currentAdminPage === 'dashboard.php';

$adminNavigationGroups = [
    [
        'key' => 'content', 'label' => 'محتوا و انتشارات', 'icon' => 'bi-journal-text',
        'active' => $adminNewsActive || $adminArticlesActive || $adminReportsActive || $adminResearchActive || $adminBooksActive || $adminCoursesActive || $adminSpeechesActive || $adminPostsActive,
        'items' => [
            ['label' => 'اخبار', 'href' => url('admin/news'), 'icon' => 'bi-newspaper', 'active' => $adminNewsActive],
            ['label' => 'مقالات علمی', 'href' => url('admin/articles'), 'icon' => 'bi-file-text', 'active' => $adminArticlesActive],
            ['label' => 'گزارش‌ها', 'href' => url('admin/reports'), 'icon' => 'bi-card-text', 'active' => $adminReportsActive],
            ['label' => 'پژوهش‌ها', 'href' => url('admin/research'), 'icon' => 'bi-journal-richtext', 'active' => $adminResearchActive],
            ['label' => 'کتاب‌ها', 'href' => url('admin/books'), 'icon' => 'bi-book', 'active' => $adminBooksActive],
            ['label' => 'دوره‌ها و درس‌ها', 'href' => url('admin/courses'), 'icon' => 'bi-mortarboard', 'active' => $adminCoursesActive],
            ['label' => 'سخنرانی‌ها', 'href' => url('admin/speeches'), 'icon' => 'bi-mic', 'active' => $adminSpeechesActive],
            ['label' => 'همهٔ مطالب', 'href' => url('admin/content'), 'icon' => 'bi-collection', 'active' => $adminPostsActive],
        ],
    ],
    [
        'key' => 'taxonomy', 'label' => 'موضوعات و دسته‌بندی', 'icon' => 'bi-diagram-3',
        'active' => $adminTopicsActive || $adminCategoriesActive,
        'items' => [
            ['label' => 'موضوعات', 'href' => url('admin/topics'), 'icon' => 'bi-diagram-3', 'active' => $adminTopicsActive],
            ['label' => 'دسته‌بندی‌ها', 'href' => url('admin/categories'), 'icon' => 'bi-folder', 'active' => $adminCategoriesActive],
        ],
    ],
    [
        'key' => 'media', 'label' => 'رسانه و فایل‌ها', 'icon' => 'bi-images',
        'active' => $adminVideosActive || $adminAudiosActive || $adminUploadsActive,
        'items' => [
            ['label' => 'ویدیوها', 'href' => url('admin/videos'), 'icon' => 'bi-play-circle', 'active' => $adminVideosActive],
            ['label' => 'صوت‌ها', 'href' => url('admin/audios'), 'icon' => 'bi-headphones', 'active' => $adminAudiosActive],
            ['label' => 'رسانه و آپلودها', 'href' => url('admin/uploads'), 'icon' => 'bi-cloud-arrow-up', 'active' => $adminUploadsActive],
        ],
    ],
    [
        'key' => 'communications', 'label' => 'پیام‌ها و اعلان‌ها', 'icon' => 'bi-chat-left-text',
        'active' => $adminMessagesActive || $adminBannersActive,
        'items' => [
            ['label' => 'پیام‌های تماس', 'href' => url('admin/messages'), 'icon' => 'bi-envelope', 'active' => $adminMessagesActive, 'badge' => $adminUnreadMessages],
            ['label' => 'بنر و اعلان ویژه', 'href' => url('admin/banners'), 'icon' => 'bi-megaphone', 'active' => $adminBannersActive],
        ],
    ],
];
if ($isSuperAdmin) {
    $adminNavigationGroups[] = [
        'key' => 'system', 'label' => 'کاربران و سامانه', 'icon' => 'bi-gear',
        'active' => $adminUsersActive || $adminSettingsActive || $adminDiagnosticsActive,
        'items' => [
            ['label' => 'کاربران و اعضا', 'href' => url('admin/users'), 'icon' => 'bi-people', 'active' => $adminUsersActive],
            ['label' => 'تنظیمات سایت', 'href' => url('admin/settings'), 'icon' => 'bi-sliders', 'active' => $adminSettingsActive],
            ['label' => 'وضعیت سامانه', 'href' => url('admin/diagnostics'), 'icon' => 'bi-heart-pulse', 'active' => $adminDiagnosticsActive],
        ],
    ];
}
$adminNavigationGroups[] = [
    'key' => 'account', 'label' => 'حساب من', 'icon' => 'bi-person-circle',
    'active' => $adminProfileActive || $adminPasswordActive,
    'items' => [
        ['label' => 'پروفایل من', 'href' => adminProfileUrl(), 'icon' => 'bi-person-gear', 'active' => $adminProfileActive],
        ['label' => 'تغییر گذرواژه', 'href' => url('admin/change-password'), 'icon' => 'bi-key', 'active' => $adminPasswordActive],
    ],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($adminTitle) ? sanitize($adminTitle) . ' — ' : '' ?>پنل مدیریت | <?= SITE_NAME ?></title>
<link rel="stylesheet" href="<?= asset('vendor/bootstrap.rtl.min.css') ?>">
<link rel="stylesheet" href="<?= asset('vendor/icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= asset('css/design-system.css') ?>">

<style>
:root {
  --admin-sidebar: #0f241a;
  --admin-sidebar-hover: #183d2c;
  --admin-sidebar-active: #23543d;
  --admin-gold: #c9a84c;
  --admin-green: #2d6a4f;
  --admin-bg: #f4f6f5;
  --admin-white: #ffffff;
  --admin-border: #e2e8e5;
  --admin-text: #1b2e24;
  --admin-muted: #6b7d74;
  --admin-unread-bg: #f5f8f6;
  --admin-stat-green-bg: #e4f3e8;
  --admin-stat-green: #206b3b;
  --admin-stat-gold-bg: #fff3cd;
  --admin-stat-gold: #856404;
  --admin-stat-blue-bg: #dbeafe;
  --admin-stat-blue: #1d4ed8;
  --admin-stat-violet-bg: #e8e5ff;
  --admin-stat-violet: #5145cd;
  --admin-stat-teal-bg: #ccfbf1;
  --admin-stat-teal: #0f766e;
  --admin-stat-rose-bg: #fce7f3;
  --admin-stat-rose: #be185d;
}
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Vazirmatn', sans-serif; background: var(--admin-bg); color: var(--admin-text); margin: 0; }
/* Guard: the panel must never scroll sideways on phones. `clip` (not `hidden`)
   keeps the fixed sidebar and sticky topbar working. */
html, body { max-width: 100%; }
body { overflow-x: clip; }

/* Sidebar */
.admin-sidebar {
  width: 256px; background: var(--admin-sidebar); min-height: 100vh;
  position: fixed; top: 0; right: 0; bottom: 0; z-index: 100;
  display: flex; flex-direction: column;
  transition: transform .3s ease;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,.15) transparent;
}
.sidebar-brand { padding: 18px 16px; border-bottom: 1px solid rgba(255,255,255,.08); background: rgba(0,0,0,.15); }
.sidebar-brand img { width: 42px; height: 42px; border-radius: 50%; border: 2px solid var(--admin-gold); object-fit: contain; }
.sidebar-brand .name { color: var(--admin-gold); font-weight: 700; font-size: .88rem; margin-right: 10px; }
.sidebar-brand .sub { color: rgba(255,255,255,.45); font-size: .72rem; display: block; margin-right: 10px; }
.sidebar-nav { flex: 1; padding: 12px 0; }
.sidebar-link {
  display: flex; align-items: center; gap: 10px;
  min-height: 42px;
  padding: 8px 14px;
  color: rgba(255,255,255,.76);
  font-size: .84rem;
  text-decoration: none;
  transition: background-color .16s ease, color .16s ease, border-color .16s ease;
  border-right: 3px solid transparent;
  position: relative;
  border-radius: 9px;
}
.sidebar-link i { flex: 0 0 20px; font-size: .96rem; width: 20px; text-align: center; color: var(--admin-gold); }
.sidebar-link:hover { background: var(--admin-sidebar-hover); color: #fff; }
.sidebar-link.active { background: var(--admin-sidebar-active); color: #fff; border-right-color: var(--admin-gold); font-weight: 700; }
.sidebar-link-dashboard { margin: 0 10px 8px; }
.sidebar-group { margin: 2px 10px 6px; border-radius: 11px; }
.sidebar-group-summary {
  display: flex; align-items: center; gap: 10px;
  min-height: 43px; padding: 8px 12px;
  color: rgba(255,255,255,.78); font-size: .82rem; font-weight: 700;
  border-radius: 10px; cursor: pointer; list-style: none; user-select: none;
  transition: background-color .16s ease, color .16s ease;
}
.sidebar-group-summary::-webkit-details-marker { display: none; }
.sidebar-group-summary::marker { content: ''; }
.sidebar-group-summary:hover,
.sidebar-group[open] > .sidebar-group-summary { background: var(--admin-sidebar-hover); color: #fff; }
.sidebar-group-icon { flex: 0 0 20px; width: 20px; text-align: center; color: var(--admin-gold); font-size: .98rem; }
.sidebar-group-chevron { margin-inline-start: auto; color: rgba(255,255,255,.48); font-size: .7rem; transition: transform .16s ease; }
.sidebar-group[open] > .sidebar-group-summary .sidebar-group-chevron { transform: rotate(180deg); }
.sidebar-group.has-active > .sidebar-group-summary { color: #fff; }
.sidebar-group-links { padding: 3px 0 5px; }
.sidebar-group .sidebar-link { margin-inline: 5px; min-height: 39px; padding-block: 7px; font-size: .81rem; }
.sidebar-group .sidebar-link.active { border-right-color: var(--admin-gold); }
.sidebar-link-badge { margin-inline-start: auto; font-size: .68rem; }
.sidebar-footer { padding: 13px 16px; border-top: 1px solid rgba(255,255,255,.08); }
.sidebar-footer .sidebar-site-link { display: flex; align-items: center; gap: .25rem; color: rgba(255,255,255,.72); font-size: .82rem; text-decoration: none; }
.sidebar-footer .sidebar-site-link:hover { color: #fff; }

/* Main content */
.admin-main { margin-right: 256px; min-height: 100vh; display: flex; flex-direction: column; }

/* Topbar */
.admin-topbar {
  background: var(--admin-white);
  border-bottom: 1px solid var(--admin-border);
  padding: 12px 24px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 50;
  box-shadow: 0 1px 6px rgba(0,0,0,.03);
}
.topbar-title { font-weight: 700; font-size: 1rem; color: var(--admin-text); }
.topbar-user { display: flex; align-items: center; gap: 12px; }
.topbar-user .avatar {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, var(--admin-green), #1b4332);
  color: #fff; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: .88rem;
}

/* Content */
.admin-content { flex: 1; padding: 24px; }

/* Cards */
.admin-card { background: var(--admin-white); border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.04); border: 1px solid var(--admin-border); }
.admin-card-header { padding: 14px 20px; border-bottom: 1px solid var(--admin-border); font-weight: 700; font-size: .92rem; display: flex; align-items: center; justify-content: space-between; }
.admin-card-body { padding: 20px; }

/* Table */
.admin-table th { background: #f8faf9; font-weight: 600; font-size: .82rem; color: var(--admin-muted); border-bottom: 2px solid var(--admin-border); }
.admin-table td { font-size: .86rem; vertical-align: middle; }
.admin-table tr:hover td { background: #f6fcf8; }

/* Responsive */
#sidebarToggle, #sidebarClose { display: none; }
@media (max-width: 991px) {
  .admin-sidebar { transform: translateX(100%); visibility: hidden; pointer-events: none; display: flex; transition: transform .24s ease, visibility .24s ease; box-shadow: -12px 0 36px rgba(0,0,0,.18); }
  .admin-sidebar.open { transform: translateX(0); visibility: visible; pointer-events: auto; }
  .admin-sidebar-overlay:not([hidden]) { display: block; position: fixed; inset: 0; z-index: 90; width: 100%; height: 100%; border: 0; background: rgba(7, 18, 12, .46); opacity: 0; transition: opacity .24s ease; }
  .admin-sidebar-overlay.show { opacity: 1; }
  .admin-main { margin-right: 0; }
  #sidebarToggle { display: inline-flex; }
  #sidebarClose { display: inline-flex; margin-inline-start: auto; }
  .admin-content { padding: 16px; }
}
.admin-sidebar-overlay { display: none; }
.admin-sidebar-overlay[hidden] { display: none !important; }
/* Shared finishing touches for every administration screen. */
.admin-content h1, .admin-content h2, .admin-content h3, .admin-content h4 { color: var(--admin-text); font-weight: 800; letter-spacing: -.02em; }
.admin-card { border-radius: 16px; box-shadow: 0 8px 28px rgba(18, 43, 29, .055); transition: border-color .18s ease, box-shadow .18s ease; }
.admin-card-header { min-height: 52px; background: linear-gradient(100deg, rgba(45,106,79,.045), transparent 75%); }
.admin-table { --bs-table-color: var(--admin-text); --bs-table-bg: var(--admin-white); --bs-table-border-color: var(--admin-border); }
.admin-table th { white-space: nowrap; }
.admin-table td { line-height: 1.65; }
.admin-content .form-control, .admin-content .form-select { min-height: 42px; border-radius: 9px; border-color: var(--admin-border); }
.admin-content textarea.form-control { min-height: 120px; }
.admin-content .form-control:focus, .admin-content .form-select:focus { border-color: var(--admin-green); box-shadow: 0 0 0 4px rgba(45,106,79,.12); }
.admin-content .btn { border-radius: 9px; font-weight: 650; }
.admin-content .alert { border: 0; border-radius: 12px; box-shadow: 0 3px 12px rgba(18,43,29,.06); }
.admin-content .badge { font-weight: 650; letter-spacing: .01em; }
.admin-content :focus-visible, .admin-sidebar :focus-visible { outline: 3px solid var(--admin-gold); outline-offset: 2px; }
.admin-sidebar { scrollbar-gutter: stable; }
.admin-topbar { min-height: 64px; backdrop-filter: blur(14px); }
.admin-page-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; }
.admin-page-heading p { font-size: .84rem; line-height: 1.7; }
.admin-settings-layout { align-items: flex-start; }
.admin-settings-aside { position: sticky; top: 82px; }
.admin-settings-help { color: var(--admin-muted); font-size: .82rem; margin-bottom: 1rem; }
.admin-settings-logo { display: block; width: min(100%, 180px); height: 150px; object-fit: contain; padding: .6rem; margin-inline: auto; border: 1px solid var(--admin-border); border-radius: 12px; background: var(--admin-bg); }
.admin-settings-save { box-shadow: 0 10px 25px rgba(18,43,29,.08); }
.admin-settings-save .admin-card-body { padding: .85rem; }
html[data-theme="dark"] .admin-settings-logo { background: #111c16; border-color: var(--admin-border); }
@media (max-width: 991px) { .admin-settings-aside { position: static; } }
@media (max-width: 575.98px) { .admin-page-heading { align-items: flex-start; } }
html[data-theme="dark"] {
  --admin-sidebar: #0b1711;
  --admin-sidebar-hover: #183327;
  --admin-sidebar-active: #234d38;
  --admin-bg: #101814;
  --admin-white: #17231c;
  --admin-border: #2b3b31;
  --admin-text: #e8f0eb;
  --admin-muted: #a0b2a8;
}
html[data-theme="dark"] body { background: var(--admin-bg); color: var(--admin-text); }
html[data-theme="dark"] .admin-topbar,
html[data-theme="dark"] .admin-card { background: var(--admin-white); color: var(--admin-text); border-color: var(--admin-border); }
html[data-theme="dark"] .admin-card-header { border-color: var(--admin-border); background: linear-gradient(100deg, rgba(92,203,163,.08), transparent 75%); }
html[data-theme="dark"] .sidebar-group-summary:hover,
html[data-theme="dark"] .sidebar-group[open] > .sidebar-group-summary { background: var(--admin-sidebar-hover); }
html[data-theme="dark"] .admin-table th { background: #1d2c23; color: var(--admin-muted); }
html[data-theme="dark"] .admin-table td { background: var(--admin-white); color: var(--admin-text); border-color: var(--admin-border); }
html[data-theme="dark"] .admin-table tr:hover td { background: #1c2d23; }
html[data-theme="dark"] .admin-content .form-control,
html[data-theme="dark"] .admin-content .form-select { background-color: #111c16; color: var(--admin-text); border-color: var(--admin-border); }
html[data-theme="dark"] .admin-content .form-control::placeholder { color: var(--admin-muted); }
html[data-theme="dark"] .admin-content .text-muted { color: var(--admin-muted) !important; }

.admin-dashboard-section { margin: 0 0 1.5rem; }
.admin-dashboard-section-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: .6rem 1rem; margin-bottom: .75rem; }
.admin-dashboard-section-heading h2 { margin: 0 0 .12rem; font-size: 1rem; }
.admin-dashboard-section-heading p { margin: 0; color: var(--admin-muted); font-size: .78rem; line-height: 1.65; }
.admin-stat-copy { display: block; min-width: 0; }
.admin-stat-note { display: block; margin-top: .2rem; color: var(--admin-muted); font-size: .7rem; line-height: 1.45; }
.stat-icon--green { background: var(--admin-stat-green-bg); color: var(--admin-stat-green); }
.stat-icon--gold { background: var(--admin-stat-gold-bg); color: var(--admin-stat-gold); }
.stat-icon--blue { background: var(--admin-stat-blue-bg); color: var(--admin-stat-blue); }
.stat-icon--violet { background: var(--admin-stat-violet-bg); color: var(--admin-stat-violet); }
.stat-icon--teal { background: var(--admin-stat-teal-bg); color: var(--admin-stat-teal); }
.stat-icon--rose { background: var(--admin-stat-rose-bg); color: var(--admin-stat-rose); }
.admin-quick-group { height: 100%; }
.admin-quick-actions { display: flex; flex-wrap: wrap; align-content: flex-start; gap: .55rem; }
.admin-quick-action { display: inline-flex; align-items: center; justify-content: center; gap: .12rem; min-height: 42px; }
.admin-recent-title { color: var(--admin-text) !important; }
.admin-recent-category, .admin-recent-date { font-size: .75rem; }
.admin-recent-message { background: var(--admin-white); color: var(--admin-text); border-color: var(--admin-border); padding: .85rem 1rem; }
.admin-recent-message.is-unread { border-inline-start: 3px solid var(--admin-gold); background: var(--admin-unread-bg); }
.admin-recent-message-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .4rem; font-size: .82rem; }
.admin-recent-message-subject { margin-top: .3rem; color: var(--admin-muted); font-size: .78rem; }
.admin-recent-message-date { display: block; margin-top: .22rem; color: var(--admin-muted); font-size: .7rem; }
html[data-theme="dark"] .admin-recent-message { background: var(--admin-white); color: var(--admin-text); border-color: var(--admin-border); }
html[data-theme="dark"] .admin-recent-message.is-unread { background: var(--admin-unread-bg); }
html[data-theme="dark"] .admin-content .badge.bg-success-subtle { background: #20392a !important; color: #94d2a5 !important; }
html[data-theme="dark"] {
  --admin-unread-bg: #1b2a21;
  --admin-stat-green-bg: #20392a;
  --admin-stat-green: #94d2a5;
  --admin-stat-gold-bg: #3b321d;
  --admin-stat-gold: #f4d17b;
  --admin-stat-blue-bg: #1e324d;
  --admin-stat-blue: #a9c7ff;
  --admin-stat-violet-bg: #2b2547;
  --admin-stat-violet: #c2b5ff;
  --admin-stat-teal-bg: #173a35;
  --admin-stat-teal: #89e0c9;
  --admin-stat-rose-bg: #3d2428;
  --admin-stat-rose: #f5a8b0;
}
@media (max-width: 575.98px) {
  .admin-dashboard-section { margin-bottom: 1.2rem; }
  .admin-dashboard-section-heading { align-items: flex-start; }
  .admin-quick-actions { align-items: stretch; }
  .admin-quick-action { flex: 1 1 8.5rem; }
  .admin-summary-stats { gap: .8rem; }
  .admin-summary-stats div { min-width: 4rem; }
}
@media (prefers-reduced-motion: reduce) {
  .admin-sidebar, .sidebar-group-summary, .sidebar-group-chevron, .sidebar-link { transition: none !important; }
}
@media (max-width: 575.98px) {
  .admin-topbar { padding: 10px 14px; }
  .admin-content { padding: 12px; }
  .admin-card-body { padding: 15px; }
}
</style>
<script src="<?= asset('js/theme.js') ?>"></script>
<script src="<?= asset('js/interface.js') ?>" defer></script>
<!-- تصویر را پیش از ارسال در مرورگر کوچک می‌کند (سرور روی Vercel افزونهٔ GD ندارد). -->
<script src="<?= asset('js/upload-optimize.js') ?>" defer></script>
</head>
<body class="jhd-admin-site">

<!-- سایدبار مدیریت -->
<aside class="admin-sidebar" id="adminSidebar" aria-label="نوار کناری مدیریت">
  <div class="sidebar-brand d-flex align-items-center">
    <img src="<?= imgUrl(getSetting('site_logo', 'assets/img/logo.png')) ?>" alt="لوگوی جامعة‌الهدی" width="42" height="42">
    <div>
      <span class="name">جامعه‌الهدی</span>
      <span class="sub">سامانهٔ مدیریت</span>
    </div>
    <button type="button" id="sidebarClose" class="btn btn-sm btn-outline-light" aria-label="بستن منوی مدیریت"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>

  <nav class="sidebar-nav" aria-label="بخش‌های پنل مدیریت">
    <a href="<?= adminDashboardUrl() ?>" class="sidebar-link sidebar-link-dashboard <?= $adminDashboardActive ? 'active' : '' ?>"<?= $adminDashboardActive ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-speedometer2" aria-hidden="true"></i><span>داشبورد</span>
    </a>

    <?php foreach ($adminNavigationGroups as $group): ?>
    <details class="sidebar-group<?= $group['active'] ? ' has-active' : '' ?>" id="admin-nav-<?= sanitize($group['key']) ?>" data-sidebar-group="<?= sanitize($group['key']) ?>"<?= $group['active'] ? ' open' : '' ?>>
      <summary class="sidebar-group-summary">
        <i class="bi <?= sanitize($group['icon']) ?> sidebar-group-icon" aria-hidden="true"></i>
        <span><?= sanitize($group['label']) ?></span>
        <i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
      </summary>
      <div class="sidebar-group-links">
        <?php foreach ($group['items'] as $item): ?>
        <a href="<?= sanitize($item['href']) ?>" class="sidebar-link<?= !empty($item['active']) ? ' active' : '' ?>"<?= !empty($item['active']) ? ' aria-current="page"' : '' ?>>
          <i class="bi <?= sanitize($item['icon']) ?>" aria-hidden="true"></i><span><?= sanitize($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="badge bg-danger sidebar-link-badge" aria-label="<?= number_format((int)$item['badge']) ?> پیام خوانده‌نشده"><?= number_format((int)$item['badge']) ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a class="sidebar-site-link" href="<?= url() ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-left ms-1" aria-hidden="true"></i>بازگشت به وب‌سایت</a>
  </div>
</aside>
<div class="admin-sidebar-overlay" id="adminSidebarOverlay" aria-hidden="true" hidden></div>

<!-- Main -->
<main class="admin-main" id="adminMain">
  <header class="admin-topbar">
    <div class="d-flex align-items-center gap-3">
      <button id="sidebarToggle" class="btn btn-sm btn-outline-secondary" aria-controls="adminSidebar" aria-expanded="false" aria-label="منوی مدیریت">
        <i class="bi bi-list"></i>
      </button>
      <span class="topbar-title"><?= isset($adminTitle) ? sanitize($adminTitle) : 'پنل مدیریت' ?></span>
    </div>
    <div class="topbar-user">
      <button class="jhd-icon-btn" data-theme-toggle aria-label="تغییر پوسته" aria-pressed="false" style="width:34px;height:34px;font-size:15px"><i class="bi bi-moon"></i></button>
      <a href="<?= adminProfileUrl() ?>" class="avatar" title="پروفایل من" aria-label="پروفایل <?= sanitize($admin['name'] ?: ($admin['user'] ?: 'مدیر')) ?>"><?= sanitize(mb_substr($admin['name'] ?: ($admin['user'] ?: 'م'), 0, 1)) ?></a>
      <div class="d-none d-md-block">
        <div class="fw-bold small"><?= sanitize($admin['name'] ?: ($admin['user'] ?: 'مدیر')) ?></div>
        <div class="text-muted" style="font-size:.73rem"><?= sanitize(jhd_role_label($admin['role'])) ?></div>
      </div>
      <a href="<?= adminLogoutUrl() ?>" class="btn btn-sm btn-outline-danger" title="خروج از پنل" aria-label="خروج از پنل"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></a>
    </div>
  </header>
  <div class="admin-content">
    <?php if (isset($_SESSION['flash_msg'])): ?>
    <div class="alert alert-<?= $_SESSION['flash_type'] ?? 'success' ?> alert-auto-dismiss alert-dismissible fade show mb-3">
      <i class="bi bi-<?= ($_SESSION['flash_type'] ?? 'success') === 'success' ? 'check-circle' : 'exclamation-triangle' ?> ms-2"></i>
      <?= sanitize($_SESSION['flash_msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); endif; ?>
