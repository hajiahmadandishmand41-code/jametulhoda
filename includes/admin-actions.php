<?php
// Preserve old action links as confirmation pages, but never mutate on GET.
$actionPath = $_SERVER['SCRIPT_NAME'] ?? '';
$isDeletePage = str_ends_with($actionPath, '/delete.php');
$actionKey = isset($_GET['delete']) ? 'delete' : (isset($_GET['read']) ? 'read' : ($isDeletePage ? 'id' : null));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $actionKey !== null) {
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>تأیید عملیات</title><main style="max-width:32rem;margin:15vh auto;font-family:Tahoma;line-height:2;padding:1rem"><h1>تأیید عملیات</h1><p>این عملیات تنها پس از تأیید شما انجام می‌شود.</p><form method="post" action="'.sanitize($actionPath).'">'.csrfField().'<input type="hidden" name="'.sanitize($actionKey).'" value="'.sanitize((string)$_GET[$actionKey]).'"><button type="submit">تأیید</button></form><a href="'.siteUrl('admin/').'">انصراف</a></main></html>';
    exit;
}
if ($isDeletePage || isset($_POST['delete']) || isset($_POST['read'])) requirePostCsrf();
