<?php
/**
 * وضعیت حساب/دیتابیس/نشست — بدون نمایش رمز، هش یا اسرار.
 */
$adminTitle = 'تشخیص وضعیت سامانه';
require_once __DIR__ . '/includes/header.php';
requireRole(['superadmin', 'admin']);

$report = [
    'php' => PHP_VERSION,
    'app_env' => APP_ENV,
    'pretty_urls' => JHD_PRETTY_URLS ? 'on' : 'off (query mode)',
    'https' => sessionCookieSecure() ? 'yes' : 'no',
    'session_driver' => env_value('SESSION_DRIVER', 'database'),
    'session_name' => session_name(),
    'session_status' => session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive',
    'admin_session' => isLoggedIn() ? 'yes' : 'no',
];

$dbOk = false;
$tables = [];
$account = null;
try {
    $db = getDB();
    $dbOk = true;
    $driver = databaseDriver();
    $report['db_driver'] = $driver;
    foreach ([
        'users','categories','topics','posts','post_images','post_topics',
        'lesson_collections','lesson_volumes','lessons','lesson_topics',
        'contact_messages','media_files','settings','books','book_topics',
        'site_banners','featured_banners','app_sessions','login_limits',
        'stored_files','storage_deletions','pending_uploads'
    ] as $table) {
        try {
            $count = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
            $tables[$table] = ['ok' => true, 'rows' => $count];
        } catch (Throwable $e) {
            $tables[$table] = ['ok' => false, 'rows' => 0];
        }
    }
    $admin = currentAdmin();
    $stmt = $db->prepare('SELECT id, username, email, full_name, role, is_active, auth_version, last_login, created_at, password FROM users WHERE id=?');
    $stmt->execute([(int)$admin['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $info = password_get_info((string)$row['password']);
        $account = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'full_name' => $row['full_name'],
            'role' => $row['role'],
            'is_active' => (int)$row['is_active'] === 1 ? 'active' : 'inactive',
            'auth_version' => (int)$row['auth_version'],
            'last_login' => $row['last_login'] ?: 'never',
            'created_at' => $row['created_at'],
            'password_algo' => $info['algoName'] ?: 'invalid',
            'password_is_hash' => !empty($info['algo']) ? 'yes' : 'no',
        ];
    }
} catch (Throwable $e) {
    $report['db_error'] = get_class($e);
}
?>
<h1 class="h4 mb-3">تشخیص وضعیت سامانه</h1>
<p class="text-muted">این صفحه رمز عبور، هش یا اطلاعات محرمانه را نمایش نمی‌دهد. برای بررسی اینکه چرا ورود مدیر شکست می‌خورد از همین گزارش استفاده کنید.</p>

<div class="admin-card mb-3">
    <div class="admin-card-header">محیط اجرا</div>
    <div class="admin-card-body">
        <ul class="mb-0">
            <?php foreach ($report as $k => $v): ?>
            <li><strong><?= sanitize((string)$k) ?>:</strong> <?= sanitize((string)$v) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="admin-card mb-3">
    <div class="admin-card-header">حساب مدیر جاری</div>
    <div class="admin-card-body">
        <?php if ($account): ?>
        <ul class="mb-0">
            <?php foreach ($account as $k => $v): ?>
            <li><strong><?= sanitize((string)$k) ?>:</strong> <?= sanitize((string)$v) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="mb-0">حساب مدیر در نشست یافت نشد.</p>
        <?php endif; ?>
    </div>
</div>

<div class="admin-card mb-3">
    <div class="admin-card-header">جدول‌های اصلی</div>
    <div class="admin-card-body">
        <?php if (!$dbOk): ?>
        <p class="text-danger mb-0">اتصال به پایگاه داده برقرار نشد.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table mb-0">
                <thead><tr><th>جدول</th><th>وضعیت</th><th>تعداد ردیف</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $name => $meta): ?>
                <tr>
                    <td><?= sanitize($name) ?></td>
                    <td><?= $meta['ok'] ? 'موجود' : 'ناموجود' ?></td>
                    <td><?= $meta['ok'] ? number_format((int)$meta['rows']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
