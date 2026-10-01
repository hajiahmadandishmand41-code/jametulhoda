<?php
require_once __DIR__ . '/../includes/header.php';
$id=(int)($_GET['id'] ?? 0);
if($id) redirect(siteUrl('admin/topics/?edit='.$id));
redirect(siteUrl('admin/topics/'));
