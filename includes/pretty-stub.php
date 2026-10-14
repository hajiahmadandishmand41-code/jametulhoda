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
// A physical /login[.php] entry point owns its route too. Never let a query
// parameter swap the login/register controllers when mod_rewrite is absent.
$_GET['p'] = $jhdStubRoute;
require dirname(__DIR__) . '/index.php';
