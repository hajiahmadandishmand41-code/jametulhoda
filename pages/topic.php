<?php
/**
 * Legacy topic URLs are retired from the public interface.
 * Preserve old links with a permanent redirect; taxonomy data and mobile APIs
 * remain untouched for backward compatibility.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Cache-Control: public, max-age=3600');
header('Location: ' . url('articles'), true, 301);
exit;
