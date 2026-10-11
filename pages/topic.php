<?php
/**
 * Public topic detail routes are retired. Keep existing links valid with a
 * permanent redirect to the combined public articles archive.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Location: ' . url('articles'), true, 301);
exit;
