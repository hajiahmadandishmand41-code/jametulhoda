<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
echo getDB()->query('SELECT 1')->fetchColumn() === 1 ? "Database OK\n" : "Database reachable\n";
