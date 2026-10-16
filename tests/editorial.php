<?php
/** Isolated regression suite: never connects to, replaces or resets a real database. */
declare(strict_types=1);
putenv('APP_ENV=local');
putenv('DB_DRIVER=sqlite');
putenv('SESSION_DRIVER=files');
$testDb = tempnam(sys_get_temp_dir(), 'jhd-editorial-');
putenv('SQLITE_PATH=' . $testDb);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cards.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
$db->exec(file_get_contents(__DIR__ . '/fixtures/schema.sqlite.sql'));
$db->exec(file_get_contents(__DIR__ . '/fixtures/seed.sqlite.sql'));
$db->exec("INSERT INTO posts(title,slug,post_type,status) VALUES ('Private test','private-test','article','draft')");
$checks = 0;
function verify(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { error_log('FAIL ' . $label); throw new RuntimeException('FAIL ' . $label); }
    $checks++;
}
$before = (int)$db->query('SELECT COUNT(*) FROM posts')->fetchColumn();
verify(getMostViewedPosts() === [], 'zero views: no popular fallback');
$db->exec("UPDATE posts SET view_count=49 WHERE post_type='news'");
verify(getMostViewedPosts() === [], '49 is not eligible');
$db->exec("UPDATE posts SET view_count=50 WHERE post_type='article'");
$db->exec("UPDATE posts SET view_count=200 WHERE status='draft'");
$popular = getMostViewedPosts(99);
verify(count($popular) > 0 && count($popular) <= 3, 'max three popular');
foreach ($popular as $p) verify($p['status'] === 'published' && $p['view_count'] >= 50, 'published and >=50');
$db->exec("UPDATE posts SET view_count=75 WHERE post_type='research'");
$popular = getMostViewedPosts();
verify($popular[0]['view_count'] == 75, 'descending real counters');
$latest = getLatestPosts(99);
verify(count($latest) === 3, 'max three latest');
foreach ($latest as $p) verify($p['status'] === 'published', 'no draft latest');
$excluded = getPosts(['sort'=>'newest','limit'=>3,'exclude_ids'=>array_column($popular,'id')]);
verify(!array_intersect(array_column($excluded,'id'),array_column($popular,'id')), 'SQL exclusion fills latest without duplicates');
$cfg = jhd_normalize_home_config(['sections'=>['books'=>false], 'hero_post_id'=>1,
    'historical_key'=>'keep', 'layout'=>['popular'=>['limit'=>200], 'latest'=>['limit'=>200], 'news'=>['title'=>'خبرها','order'=>-1,'limit'=>400]]]);
verify($cfg['historical_key']==='keep' && !$cfg['sections']['books'], 'legacy settings retained');
verify($cfg['hero_mode']==='manual', 'legacy manual id retained');
verify($cfg['layout']['popular']['limit']===3 && $cfg['layout']['latest']['limit']===3, 'server enforced limit');
verify($cfg['layout']['news']['limit']===12 && $cfg['layout']['news']['order']===0, 'layout range validation');
foreach (['news','report','article','research'] as $type) {
    $p = getPosts(['type'=>$type,'limit'=>1])[0];
    $cfg = jhd_normalize_home_config(['hero_mode'=>'manual','hero_post_id'=>$p['id']]);
    $lead = jhd_home_feature($cfg);
    verify($lead['id']===$p['id'] && $lead['post_type']===$type, 'manual '.$type);
    $html = jhd_lead_story($lead);
    verify(str_contains($html,postTypeLabel($type)) && str_contains($html,sanitize($p['title'])), 'lead real title/type '.$type);
}
foreach (['book'=>'books','lesson'=>'lessons'] as $kind=>$table) {
    $id = (int)$db->query("SELECT id FROM $table WHERE status='published' LIMIT 1")->fetchColumn();
    $cfg = jhd_normalize_home_config(['hero_mode'=>'manual','hero_kind'=>$kind,'hero_post_id'=>$id]);
    $lead = jhd_home_feature($cfg);
    verify($lead && $lead['post_type']===$kind && !empty($lead['url']), 'manual '.$kind);
}
$draftId = (int)$db->query("SELECT id FROM posts WHERE status='draft' LIMIT 1")->fetchColumn();
verify(jhd_home_feature(jhd_normalize_home_config(['hero_mode'=>'manual','hero_post_id'=>$draftId]))===null, 'draft lead rejected');
verify(jhd_home_feature(jhd_normalize_home_config(['hero_mode'=>'off']))===null, 'lead disabled');
$p = getPosts(['type'=>'news','limit'=>1])[0];
$cache =& jhd_post_images_cache();
$cache[$p['id']] = [['path'=>'assets/img/logo.png','alt'=>'اول'],['path'=>'assets/img/founder.jpg','alt'=>'دوم']];
$p['featured_image']='assets/img/logo.png';
$html = renderPostCard($p);
verify(substr_count($html,'<img ')===1 && !str_contains($html,'data-jhd-gallery'), 'single card image, no popup');
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 Editorial reader';
$_SERVER['REMOTE_ADDR']='192.0.2.10';
$id = (int)$p['id'];
$count = (int)$db->query('SELECT view_count FROM posts WHERE id='.$id)->fetchColumn();
for ($i=0;$i<10;$i++) recordContentView($id);
verify((int)$db->query('SELECT view_count FROM posts WHERE id='.$id)->fetchColumn()===$count+1, 'ten repeated requests count once');
$_SERVER['REMOTE_ADDR']='192.0.2.11'; recordContentView($id);
verify((int)$db->query('SELECT view_count FROM posts WHERE id='.$id)->fetchColumn()===$count+2, 'different reader counts');
$_SERVER['REMOTE_ADDR']='192.0.2.12'; $_SERVER['HTTP_USER_AGENT']='Googlebot'; recordContentView($id);
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0'; $_SERVER['REQUEST_METHOD']='HEAD'; recordContentView($id);
verify((int)$db->query('SELECT view_count FROM posts WHERE id='.$id)->fetchColumn()===$count+2, 'bot and HEAD ignored');
$_SERVER['REQUEST_METHOD']='GET'; recordContentView($draftId);
verify((int)$db->query('SELECT view_count FROM posts WHERE id='.$draftId)->fetchColumn()===200, 'draft counter unchanged');
$hash = $db->query('SELECT visitor_hash FROM content_view_receipts LIMIT 1')->fetchColumn();
verify(strlen($hash)===64 && !str_contains($hash,'192.0.2'), 'no raw IP in receipt');
$db->exec("UPDATE posts SET published_at='2026-01-01' WHERE post_type='news'");
$news = $db->query("SELECT id FROM posts WHERE post_type='news' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$adjacent = getAdjacentPosts((int)$news[1]);
verify($adjacent['prev']['id']==$news[0] && $adjacent['next']['id']==$news[2], 'same-date neighbours use id tie-break');
verify(getAdjacentPosts((int)$news[0])['prev']===null, 'no fake previous');
verify(getAdjacentPosts((int)$news[count($news)-1])['next']===null, 'no fake next');
$related = getRelatedPosts($p);
verify(count($related) <= 3 && !in_array($p['id'],array_column($related,'id')), 'related excludes current and capped');
foreach ($related as $row) {
    $shared = $db->prepare('SELECT COUNT(*) FROM post_topics a JOIN post_topics b ON a.topic_id=b.topic_id WHERE a.post_id=? AND b.post_id=?');
    $shared->execute([$p['id'],$row['id']]);
    verify(($p['category_id'] && $row['category_id']==$p['category_id']) || (int)$shared->fetchColumn()>0, 'related has actual shared category/topic');
}
// فهرست‌های خبر/مقاله: مطلب بزرگ ابتدا، و ریل‌ها هیچ مطلبی را که همان صفحه نشان داده تکرار نمی‌کنند.
verify(jhd_listing_lead_eyebrow('newest', 'خبر') === 'آخرین خبر' && jhd_listing_lead_eyebrow('oldest', 'مقاله') === 'قدیمی‌ترین مقاله' && jhd_listing_lead_eyebrow('popular', 'پژوهش') === 'پربازدیدترین پژوهش', 'listing lead labels follow sort');
$pageNews = getPosts(['types'=>['news','announcement'],'sort'=>'newest','limit'=>6]);
$railsForPage = jhd_listing_rails($pageNews);
$railIds = array_merge(array_column($railsForPage['latest'],'id'), array_column($railsForPage['popular'],'id'));
verify(!array_intersect($railIds, array_column($pageNews,'id')), 'listing rails never repeat items shown on the same page');
$homeCfg = jhd_normalize_home_config(['hero_mode'=>'auto']);
$lead = jhd_home_feature($homeCfg);
verify($lead !== null && $lead['post_type'] !== '', 'auto lead resolves for homepage de-duplication');
// Shared archive: lead once, no repeated records, compact rows on later pages/search.
$archiveItems = getPosts(['sort'=>'newest', 'limit'=>3]);
verify(count($archiveItems) === 3, 'archive fixture has three items');
$archive = jhd_post_archive($archiveItems);
verify(substr_count($archive, 'class="jhd-lead"') === 1, 'one archive banner');
verify(substr_count($archive, 'jhd-card--row') === 2, 'remaining stories are horizontal rows');
foreach ([['page'=>2], ['search'=>'test']] as $options) {
    $rows = jhd_post_archive($archiveItems, $options);
    verify(!str_contains($rows, 'class="jhd-lead"') && substr_count($rows, 'jhd-card--row') === 3, 'search and pagination keep every item compact');
}
verify(jhd_post_archive([]) === '', 'empty archive has no empty wrapper');
verify(!str_contains(jhd_post_archive([$archiveItems[0]]), 'jhd-archive-rows'), 'single item has only a banner');
$storedConfig = json_encode(jhd_normalize_home_config(['layout'=>['news'=>['title'=>'پایدار']]]));
$db->prepare("INSERT INTO settings(setting_key,value) VALUES ('homepage_config',?) ON CONFLICT(setting_key) DO UPDATE SET value=EXCLUDED.value")->execute([$storedConfig]);
$independent = newDatabaseConnection();
verify($independent->query("SELECT value FROM settings WHERE setting_key='homepage_config'")->fetchColumn()===$storedConfig, 'configuration survives a new database connection');
verify((int)$db->query('SELECT COUNT(*) FROM posts')->fetchColumn()===$before, 'all original fixture records retained');
echo "$checks editorial regression checks passed (isolated temporary SQLite)\n";
