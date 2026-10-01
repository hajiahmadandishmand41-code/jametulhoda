<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../../includes/content-delete.php';
requirePostCsrf();
if (!deleteContentRecord('posts', (int)($_POST['id'] ?? 0), null)) {
    http_response_code(404); exit('محتوا یافت نشد.');
}
$_SESSION['flash_msg'] = 'محتوا حذف شد.';
$_SESSION['flash_type'] = 'success';
redirect(siteUrl('admin/posts/'));
