<?php
/**
 * research.php — نشانی قدیمی فهرست پژوهش‌ها.
 *
 * «مقالات» و «پژوهش‌ها» یک بخش واحد شده‌اند: همهٔ مطالب علمی در /articles
 * فهرست می‌شوند و فیلتر «پژوهش» همان نمای قبلی است. این فایل فقط نشانی
 * قدیمی را به‌صورت دائمی (301) به آن نمای یکپارچه می‌فرستد؛ صفحه‌های جزئیات
 * پژوهش (/research/<slug>) دست‌نخورده باقی می‌مانند.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$query = [];
foreach (['q', 'page'] as $key) {
    $value = $_GET[$key] ?? '';
    if (is_string($value) && trim($value) !== '') $query[$key] = trim($value);
}
$query['type'] = 'research';
$target = url('articles', $query);

header('Location: ' . $target, true, 301);
exit;
