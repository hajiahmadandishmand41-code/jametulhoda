<?php
/**
 * admin/messages.php — نشانی قدیمی مدیریت پیام‌ها
 *
 * منطق این صفحه به `admin/messages/index.php` منتقل شد (حذف تکراری‌ها).
 * این فایل فقط نشانی `/admin/messages.php` را برای بوک‌مارک‌ها و پیوندهای
 * قدیمی زنده نگه می‌دارد و به مسیر جدید هدایت می‌کند.
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
redirect(siteUrl('admin/messages'));
