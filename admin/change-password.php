<?php
/**
 * admin/change-password.php — نشانی قدیمی تغییر رمز مدیر
 *
 * تغییر رمز مدیر (و هر کاربر) از این پس در صفحهٔ پروفایل انجام می‌شود تا یک
 * مسیر واحد و امن وجود داشته باشد. این فایل فقط برای نشانی‌های قدیمی است.
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLogin();
redirect(adminProfileUrl());
