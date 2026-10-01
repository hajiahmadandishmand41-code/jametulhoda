<?php
/**
 * admin/logout.php — نشانی قدیمی خروج
 *
 * فقط یک کنترلر خروج وجود دارد: `pages/logout.php` (POST + CSRF).
 */
$jhdLogoutInAdminContext = true;
require dirname(__DIR__) . '/pages/logout.php';
