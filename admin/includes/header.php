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

<link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
<link rel="icon" href="<?= canonicalUrl('favicon.ico') ?>" type="image/x-icon">
<meta name="theme-color" content="#0f241a">
<script src="<?= asset('js/theme.js') ?>"></script>
<script src="<?= asset('js/supabase-direct-upload.js') ?>"></script>
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
    <div class="d-flex align-items-center gap-2 gap-md-3" style="min-width:0">
      <button id="sidebarToggle" type="button" class="btn btn-sm btn-outline-secondary" data-admin-drawer="adminSidebar" aria-controls="adminSidebar" aria-expanded="false" aria-label="منوی مدیریت">
        <i class="bi bi-list"></i>
      </button>
      <span class="topbar-title" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= isset($adminTitle) ? sanitize($adminTitle) : 'پنل مدیریت' ?></span>
    </div>
    <div class="topbar-user admin-topbar-actions">
      <?php if (jhd_can('content')): ?>
      <a class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" href="<?= sanitize(adminUrl('content/create')) ?>" title="افزودن مطلب تازه">
        <i class="bi bi-plus-lg" aria-hidden="true"></i><span class="d-none d-sm-inline">مطلب تازه</span>
      </a>
      <?php endif; ?>
      <button class="jhd-icon-btn" data-theme-toggle aria-label="تغییر پوسته" aria-pressed="false"><i class="bi bi-moon"></i></button>
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
