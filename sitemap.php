<?php
/** Dynamic XML endpoint; no template, session, fabricated URLs or partial success. */
declare(strict_types=1);
require_once __DIR__ . '/includes/sitemap.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    $db = jhd_db();
    if ($db === null) throw new RuntimeException('Sitemap database unavailable');
    // Build the entire response before sending any XML. A failed query must not
    // become a cached HTTP 200 with an apparently complete but partial index.
    $xml = jhd_sitemap_xml(jhd_sitemap_entries($db));
    http_response_code(200);
    header('Cache-Control: public, max-age=0, s-maxage=300, must-revalidate');
} catch (Throwable $e) {
    error_log('Sitemap unavailable: ' . get_class($e));
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 300');
    $xml = jhd_sitemap_xml([]);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $xml;
