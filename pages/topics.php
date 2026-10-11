<?php
/**
 * Public topic directory retired in favour of the articles archive.
 * Individual topic-detail pages remain available for existing content links.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Location: ' . url('articles'), true, 301);
exit;
