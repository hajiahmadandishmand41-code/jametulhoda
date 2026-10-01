<?php
// Compatibility entrypoint: installation is CLI-only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/migrate.php';
