<?php
/**
 * Directory stub used when mod_rewrite is unavailable (InfinityFree).
 * Set $jhdStubRoute to a registry route name before including this file.
 */
declare(strict_types=1);
if (!isset($jhdStubRoute) || !is_string($jhdStubRoute) || $jhdStubRoute === '') {
    http_response_code(404);
    exit;
}
if (!isset($_GET['p']) || !is_string($_GET['p']) || trim($_GET['p']) === '') {
    $_GET['p'] = $jhdStubRoute;
}
require dirname(__DIR__) . '/index.php';
