<?php
putenv('SESSION_DRIVER=files');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/auth.php';
$checks=0;
putenv('JHD_EMPTY_TEST=');
function check(bool $ok,string $label): void { global $checks; if(!$ok)throw new RuntimeException($label);$checks++; }
check(env_value('JHD_EMPTY_TEST','default')==='default','empty environment uses safe defaults');
check(siteUrl('news')===(JHD_PRETTY_URLS ? BASE_PATH.'/news' : BASE_PATH.'/index.php?p=news'),'clean public URL');
check(siteUrl('admin/users')===(JHD_PRETTY_URLS ? BASE_PATH.'/admin/users' : BASE_PATH.'/admin/users/index.php'),'clean admin URL');
check(siteUrl('login')===(JHD_PRETTY_URLS ? BASE_PATH.'/login' : BASE_PATH.'/index.php?p=login'),'public login URL');
check(siteUrl('admin/login')===BASE_PATH.'/admin/login','admin login URL');
check(siteUrl('')=== (BASE_PATH==='' ? '/' : BASE_PATH.'/'),'home URL is root-relative');
check(siteUrl('//evil.example')==='','protocol relative URL rejected');
check(siteUrl("javascript:alert(1)")==='','unsafe scheme rejected');
check(siteUrl("news\r\nX-Test:bad")==='','header injection rejected');
check(safeExternalUrl('https://example.test/profile')==='https://example.test/profile','HTTPS social links are allowed');
check(safeExternalUrl('javascript:alert(1)')==='','unsafe social-link schemes are rejected');
$GLOBALS['jhd_setting_cache']=['cache_probe'=>'before'];
check(getSetting('cache_probe')==='before','cached settings are readable');
clearSettingCache();
check(!array_key_exists('jhd_setting_cache',$GLOBALS),'saved settings can invalidate the request cache');
check(storageKey('../config/config.php')==='','traversal rejected');
check(storageKey('uploads/../secret.pdf')==='','upload traversal rejected');
check(storageKey('https://untrusted.example/file.pdf')==='','foreign storage rejected');
check(storageKey('uploads/posts/test.jpg')==='posts/test.jpg','legacy key supported');
check(storageKey('assets/img/logo.jpg')==='', 'assets are not uploads');
check(storageKey('videos/clip.mp4')==='videos/clip.mp4','videos folder supported');
check(storageKey('audios/clip.mp3')==='audios/clip.mp3','audios folder supported');
check(storageKey('video/legacy.mp4')==='video/legacy.mp4','legacy video folder still resolves');
check(storageKey('audio/legacy.mp3')==='audio/legacy.mp3','legacy audio folder still resolves');
check(storageKey('assets/images/logo.jpg')==='', 'legacy asset path is not an upload');
check(storageKey('posts/shell.php')==='','executable rejected');
check(validateUpload(__DIR__.'/fixtures/image.png','image')['mime']==='image/png','image content inspected');
check(validateUpload(__FILE__,'image')===null,'PHP disguised as image rejected');
check(validateUpload(__FILE__,'pdf')===null,'PHP disguised as PDF rejected');
check(validateUpload(__DIR__.'/fixtures/audio.mp3','audio')['extension']==='mp3','MP3 content');
check(validateUpload(__DIR__.'/fixtures/video.mp4','video')['extension']==='mp4','MP4 content');
$html=safeRichText('<p onclick="evil()">Hello <strong>world</strong></p><script>evil()</script><a href="javascript:alert(1)">x</a><img src="x" onerror="evil()"><svg onload="evil()"/>');
check(!str_contains($html,'evil()') && !str_contains($html,'javascript:') && !str_contains($html,'<svg'),'rich text XSS');
check(str_contains($html,'<strong>world</strong>'),'safe formatting retained');
check(persianDate('2024-03-20')==='1 فروردین 1403','Jalali new year');
check(persianDate('2026-09-20')==='29 شهریور 1405','Jalali current date');
$vercelBefore=getenv('VERCEL'); putenv('VERCEL');
$_SERVER['REMOTE_ADDR']='192.0.2.1'; $_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.1';
check(clientIp()==='192.0.2.1','untrusted forwarded IP ignored');
putenv('VERCEL=1'); $_SERVER['HTTP_X_VERCEL_FORWARDED_FOR']='203.0.113.2';
check(clientIp()==='203.0.113.2','trusted platform client IP');
putenv($vercelBefore===false?'VERCEL':'VERCEL='.$vercelBefore);
unset($_SERVER['HTTP_X_FORWARDED_FOR'],$_SERVER['HTTP_X_VERCEL_FORWARDED_FOR']);
$token=generateCsrfToken();
check(verifyCsrfToken($token),'valid CSRF');
check(!verifyCsrfToken('bad'),'invalid CSRF');
check(!verifyCsrfToken(['tampered']),'array-shaped CSRF token is rejected safely');
check(jhd_safe_redirect_target(['//evil.example'], '/fallback') === '/fallback','array-shaped redirect target is rejected safely');
check(jhd_safe_redirect_target('/' . chr(92) . 'evil.example', '/fallback') === '/fallback','backslash URL normalization cannot bypass the open-redirect guard');
check(jhd_safe_redirect_target('/account', '/fallback') === '/account','safe internal redirect remains supported');
check(strlen($token)===64,'CSRF entropy');
$videoList=jhd_resolve_query('video',[]);
$audioList=jhd_resolve_query('audio',[]);
$videoDetail=jhd_resolve_query('video',['id'=>'1']);
$audioDetail=jhd_resolve_query('audio',['id'=>'2']);
check(($videoList['file']??'')==='pages/media-library.php' && ($videoList['get']['kind']??'')==='video','query-mode /video resolves to its video listing');
check(($audioList['file']??'')==='pages/media-library.php' && ($audioList['get']['kind']??'')==='audio','query-mode /audio resolves to its audio listing');
check(($videoDetail['file']??'')==='pages/media.php' && ($videoDetail['kind']??'')==='video','query-mode video ID resolves to video detail');
check(($audioDetail['file']??'')==='pages/media.php' && ($audioDetail['kind']??'')==='audio','query-mode audio ID resolves to audio detail');
// Regression: getDB() must discard a cached pre-installer failure when the
// effective settings are replaced in APP_LOCAL_CONFIG during the same request.
$originalLocalConfig = $GLOBALS['APP_LOCAL_CONFIG'] ?? [];
$envKeys = ['APP_ENV', 'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DATABASE_URL', 'SQLITE_PATH'];
$originalEnv = [];
foreach ($envKeys as $key) $originalEnv[$key] = getenv($key);
try {
    foreach ($envKeys as $key) putenv($key);
    $GLOBALS['APP_LOCAL_CONFIG'] = [
        'APP_ENV' => 'development',
        'DB_DRIVER' => 'mysql',
    ];
    check(tryGetDB() === null, 'database failure can be cached before installer settings arrive');

    $sqlitePath = sys_get_temp_dir() . '/jhd-getdb-cache-' . bin2hex(random_bytes(6)) . '.sqlite';
    $GLOBALS['APP_LOCAL_CONFIG'] = [
        'APP_ENV' => 'development',
        'DB_DRIVER' => 'sqlite',
        'SQLITE_PATH' => $sqlitePath,
    ];
    $dbAfterConfigChange = getDB();
    check($dbAfterConfigChange instanceof PDO, 'getDB retries after effective database config changes');
    $dbAfterConfigChange->exec('CREATE TABLE cache_regression (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $stmt = $dbAfterConfigChange->prepare('INSERT INTO cache_regression (value) VALUES (?)');
    $stmt->execute(['ok']);
    check((string)$dbAfterConfigChange->query('SELECT value FROM cache_regression LIMIT 1')->fetchColumn() === 'ok', 'fresh connection is usable after cache reset');
    @unlink($sqlitePath);
} finally {
    $GLOBALS['APP_LOCAL_CONFIG'] = $originalLocalConfig;
    foreach ($envKeys as $key) {
        if ($originalEnv[$key] === false) putenv($key);
        else putenv($key . '=' . $originalEnv[$key]);
    }
}

// Cross-host SQL contract: the shared settings upsert (admin/settings.php)
// must normalize to valid MySQL/MariaDB syntax — this regression guards the
// InfinityFree deployment, which CI's SQLite suite cannot see.
$upsertSql = 'INSERT INTO settings (setting_key,value) VALUES (?,?) ON CONFLICT (setting_key) DO UPDATE SET value=EXCLUDED.value';
$normalized = JametulhodaMySqlPDO::normalizeSql($upsertSql);
check($normalized === 'INSERT INTO settings (setting_key,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', 'settings upsert normalizes to MySQL ON DUPLICATE KEY UPDATE');
check(JametulhodaMySqlPDO::normalizeSql('INSERT INTO t (a,b) VALUES (?,?) ON CONFLICT (a) DO UPDATE SET b=EXCLUDED.b + 1') === 'INSERT INTO t (a,b) VALUES (?,?) ON CONFLICT (a) DO UPDATE SET b=EXCLUDED.b + 1', 'non-simple upsert expressions are never mis-translated');
check(str_contains(JametulhodaMySqlPDO::normalizeSql('SELECT 1 WHERE title ILIKE ? AND x > NOW() - INTERVAL \'30 minutes\''), 'LIKE'), 'ILIKE normalizes for MySQL');
check(str_contains(JametulhodaMySqlPDO::normalizeSql('SELECT 1 WHERE x > NOW() - INTERVAL \'30 minutes\''), 'INTERVAL 30 MINUTE'), 'PG interval literal normalizes for MySQL');

echo "$checks security checks passed\n";
