<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/content-delete.php';
echo processStorageDeletions()." storage deletions completed.\n";
