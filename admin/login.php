<?php
/**
 * admin/login.php — نشانی قدیمی ورود مدیر
 *
 * از این پس فقط یک صفحهٔ ورود وجود دارد: `pages/login.php`.
 * این فایل همان کنترلر را با زمینهٔ «پنل» اجرا می‌کند تا POST قدیمی‌ها هم
 * بی‌خطا کار کند و رفتار دو نشانی هرگز متفاوت نباشد.
 */
$jhdLoginInAdminContext = true;
require dirname(__DIR__) . '/pages/login.php';
