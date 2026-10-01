<?php
/** admin/research.php — پژوهش‌ها (نمای فیلترشدهٔ مدیریت مطالب) */
$_GET['type'] = $_GET['type'] ?? 'research';
$_REQUEST['type'] = $_GET['type'];
require __DIR__ . '/posts/index.php';
