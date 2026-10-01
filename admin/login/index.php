<?php
/**
 * Makes /admin/login work on hosts without mod_rewrite (DirectoryIndex).
 * /admin/login.php remains the canonical physical controller.
 */
require dirname(__DIR__) . '/login.php';
