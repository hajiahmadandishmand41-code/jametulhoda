<?php
/**
 * Resolve the one-segment /lessons/{slug} namespace without discarding the
 * existing collection route. A real lesson wins; otherwise it is a collection.
 */
require_once __DIR__ . '/../includes/functions.php';
$slug = is_string($_GET['slug'] ?? null) ? trim((string)$_GET['slug']) : '';
if ($slug !== '' && jhd_db_ready() && getLessonBySlug($slug)) {
    require __DIR__ . '/lesson.php';
    return;
}
$_GET['collection'] = $slug;
require __DIR__ . '/lessons.php';
