<?php
/** Focused regression tests. All database writes below are in sqlite::memory:. */
declare(strict_types=1);
putenv('APP_ENV=local');
putenv('DB_DRIVER=sqlite');
putenv('SESSION_DRIVER=files');
if (getenv('SITE_URL') === false) putenv('SITE_URL=https://example.test');
if (getenv('JHD_PRETTY_URLS') === false) putenv('JHD_PRETTY_URLS=true');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sitemap.php';
require_once __DIR__ . '/../includes/cards.php';

$checks = 0;
function expect(bool $condition, string $label): void {
    global $checks;
    $checks++;
    if (!$condition) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
}
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (Throwable) { expect(true, $label); return; }
    expect(false, $label);
}
function htmlDoc(string $html): DOMDocument {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $old = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    return $doc;
}

$plain = safeRichText("بند نخست\r\nسطر دوم\r\n\r\nبند دوم & پایان");
expect(substr_count($plain, '<p>') === 2 && substr_count($plain, '<br>') === 1, 'plain CRLF paragraphs and single breaks');
expect(str_contains($plain, 'بند دوم &amp; پایان'), 'Dari UTF-8 and plain-text escaping');
expect(safeRichText('  ') === '', 'empty body has no empty frame');
expect(str_contains(safeRichText('۲ < ۳ و ۵ > ۴'), '۲ &lt; ۳'), 'plain text is not mistaken for markup');
$structured = '<h2 id="مقدمه">مقدمه</h2><p>بند <strong>اول</strong> و <em>تأکید</em></p><p>بند دوم<br>ادامه</p><ul><li>اول</li><li>دوم</li></ul><ol start="3"><li>سوم</li></ol><blockquote><p>نقل‌قول</p></blockquote>';
$html = safeRichText($structured);
foreach (['<h2 id="مقدمه">','<p>بند <strong>','<em>تأکید</em>','<p>بند دوم<br>','<ul>','<ol start="3">','<blockquote>'] as $part) expect(str_contains($html, $part), 'safe semantic element ' . $part);
expect(!str_contains($html, '<p><br>') && !str_contains($html, '<?xml'), 'no duplicate breaks or leaked encoding instruction');
expect(safeRichText($html) === $html, 'HTML sanitization is idempotent');
$legacy = safeRichText("متن <b>مهم</b>\n\nبند دوم<div>بند سوم\nادامه</div>");
$doc = htmlDoc($legacy); $xp = new DOMXPath($doc);
expect($xp->query('//body//p')->length === 3, 'loose inline and legacy div content become paragraphs');
expect($xp->query('//p/p|//p/h2|//p/ul|//p/div')->length === 0, 'no invalid nested paragraph blocks');
$code = safeRichText("<pre><code>if (x &lt; 2) {\n  return x;\n}</code></pre>");
expect(str_contains($code, "{\n  return") && !str_contains($code, '<br'), 'preformatted code is not nl2br-processed');
$semantic = safeRichText('<figure><img src="/assets/img/logo.png" alt="نشان" width="320" height="180"><figcaption>شرح</figcaption></figure><table><caption>جدول</caption><thead><tr><th scope="col">سرستون</th></tr></thead><tbody><tr><td colspan="2">داده</td></tr></tbody></table>');
foreach (['<figure>','<figcaption>شرح</figcaption>','scope="col"','colspan="2"','width="320"'] as $part) expect(str_contains($semantic, $part), 'safe figure/table ' . $part);
$hostile = safeRichText('<script>alert(1)</script><iframe src="https://evil.test"></iframe><p onclick="x()" style="color:red">متن</p><a href="jav&#x61;script:alert(1)" target="_blank">بد</a><img src="javascript:alert(1)" onerror="alert(1)"><svg onload="x()"><script>x()</script></svg>');
foreach (['<script','<iframe','onclick','style=','javascript:','onerror','<svg'] as $part) expect(!str_contains(strtolower($hostile), $part), 'unsafe markup removed ' . $part);
expect(!str_contains($hostile, '<img'), 'unsafe images do not leave broken frames');
$links = safeRichText('<p><a href="#مقدمه">فهرست</a> <a href="https://example.test/read?a=1&amp;b=2" target="_blank">منبع</a> <a href="//evil.test">بدون پروتکل</a></p>');
expect(str_contains(rawurldecode($links), 'href="#مقدمه"'), 'heading fragment links survive');
expect(str_contains($links, 'rel="noopener noreferrer"') && str_contains($links, 'a=1&amp;b=2'), 'safe external links and XML-sensitive text');
expect(!str_contains($links, 'href="//'), 'protocol-relative rich text links rejected');
expect(excerpt('<p>اول &amp; دوم</p><p>سوم</p>') === 'اول & دوم سوم', 'excerpt word boundaries/entities');

$card = ['title' => 'عنوان مقاله', 'slug' => 'sample', 'post_type' => 'article', 'summary' => 'خلاصهٔ خوانا', 'author_name' => 'نویسنده', 'published_at' => '2026-01-01'];
$without = renderEditorialRow($card);
expect(str_contains($without, 'jhd-editorial-row--noimage') && !str_contains($without, '<img') && !str_contains($without, 'jhd-editorial-thumb'), 'image-less archive has no media column/frame');
$with = renderEditorialRow($card + ['featured_image' => 'assets/img/founder.jpg']);
expect(str_contains($with, 'jhd-editorial-thumb') && str_contains($with, 'width="640" height="360"') && str_contains($with, 'نویسنده'), 'archive image dimensions and author metadata');

expect(jhd_sitemap_lastmod(['updated_at'=>'2025-02-29','created_at'=>'2024-02-29']) === '2024-02-29', 'invalid rolled-over date falls back');
expect(jhd_sitemap_lastmod(['updated_at'=>'tomorrow']) === null, 'relative date rejected');
expect(jhd_sitemap_lastmod(['updated_at'=>'2099-01-01']) === null, 'future lastmod omitted');
expect(jhd_sitemap_lastmod(['updated_at'=>'2025-05-01 24:00:00']) === null, 'invalid time rejected');
expect(jhd_sitemap_lastmod(['updated_at'=>'2025-05-01T12:30:20.123456+00:00']) === '2025-05-01', 'PostgreSQL/ISO persisted date accepted');
expect(jhd_sitemap_lastmod([]) === null, 'no invented modification date');
$origin = jhd_sitemap_origin();
foreach ([url('login'), url('register'), url('search'), url('articles', ['q'=>'test']), 'https://evil.test/post'] as $bad) rejects(fn() => jhd_sitemap_url($bad, $origin), 'exclude private/filter/foreign URL');
$collection = collectionUrl('public-course', 'volume-one');
expect(str_contains(jhd_sitemap_url($collection, $origin), 'collection=public-course'), 'working legacy collection canonical supported');
$xml = jhd_sitemap_xml([['loc'=>jhd_sitemap_url($collection, $origin)]]);
$xmlDoc = new DOMDocument();
expect($xmlDoc->loadXML($xml, LIBXML_NONET), 'valid XML parses with ampersand query');
expect($xmlDoc->getElementsByTagName('loc')->item(0)->textContent === jhd_sitemap_url($collection, $origin), 'XML URL round-trip');
expect(!str_contains($xml, '<lastmod>'), 'undated entries omit lastmod');

$db = new JametulhodaSqlitePDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__ . '/fixtures/schema.sqlite.sql'));
$db->exec("INSERT INTO topics (id,parent_id,name,slug,sort_order,is_active,is_featured) VALUES (201,NULL,'Mahdaviat','mahdaviat',1,1,1),(202,201,'Occultation','occultation',2,1,0),(203,NULL,'Inactive topic','inactive-topic',3,0,0),(204,NULL,'Hidden detail','hidden-detail',4,1,0),(205,NULL,'No archive topic','no-archive-topic',5,1,0)");
$topicSetting = $db->prepare('INSERT INTO settings (setting_key, value) VALUES (?, ?)');
$topicSetting->execute(['topic_display_204', json_encode(['visibility' => 'inactive'])]);
$topicSetting->execute(['topic_display_205', json_encode(['placements' => ['articles']])]);
$insert = $db->prepare('INSERT INTO posts (title,slug,post_type,status,category_id) VALUES (?,?,?,?,?)');
foreach (['news','article','research','report','announcement','program','religious','qa','speech','book_note'] as $type) $insert->execute([$type, 'public-'.$type, $type, 'published', 1]);
$insert->execute(['private', 'private-post', 'article', 'draft', 1]);
$db->exec("INSERT INTO books(title,slug,status) VALUES ('book','public-book','published'),('legacy',NULL,'published'),('draft','private-book','draft')");
$db->exec("INSERT INTO lesson_collections(id,title,slug,is_active) VALUES (101,'course','public-course',1),(102,'empty','empty-course',1),(103,'hidden','hidden-course',0)");
$db->exec("INSERT INTO lesson_volumes(id,collection_id,title,slug) VALUES(101,101,'volume','volume-one'),(102,101,'empty','empty-volume')");
$db->exec("INSERT INTO lessons(id,title,slug,status,collection_id,volume_id) VALUES(101,'lesson','public-lesson','published',101,101),(102,'hidden','private-lesson','draft',102,NULL)");
$db->exec("INSERT INTO media_files(ref_type,ref_id,kind,title,file_path) VALUES('post',1,'video','video','uploads/videos/example.mp4'),('lesson',101,'audio','audio','uploads/audios/example.mp3'),('post',11,'video','draft parent','uploads/videos/private.mp4'),('post',999999,'video','missing parent','uploads/videos/orphan.mp4'),('post',1,'audio','empty path','')");
$entries = jhd_sitemap_entries($db);
$locs = array_column($entries, 'loc'); $all = implode("\n", $locs);
expect(count($locs) === count(array_unique($locs)), 'canonical URL deduplication');
foreach (['public-news','public-article','public-research','public-report','public-announcement','public-program','public-religious','public-qa','public-speech','public-book_note','public-book','public-lesson','collection=public-course','volume=volume-one'] as $slug) expect(str_contains($all, $slug), 'published coverage ' . $slug);
foreach (['private-post','private-book','private-lesson','empty-course','hidden-course','empty-volume','type=research','inactive-topic','hidden-detail'] as $slug) expect(!str_contains($all, $slug), 'no private/empty/redirect surface ' . $slug);
$topicRows = [];
foreach ($db->query('SELECT * FROM topics ORDER BY id')->fetchAll() as $topicRow) $topicRows[(int)$topicRow['id']] = $topicRow;
jhd_prime_topic_cache(array_values($topicRows));
expect(in_array(jhd_sitemap_url(url('topics'), $origin), $locs, true), 'scientific topics archive included');
expect(in_array(jhd_sitemap_url(topicUrl($topicRows[201]), $origin), $locs, true), 'root topic canonical URL included');
expect(in_array(jhd_sitemap_url(topicUrl($topicRows[202]), $origin), $locs, true), 'nested topic canonical URL included');
expect(in_array(jhd_sitemap_url(topicUrl($topicRows[205]), $origin), $locs, true), 'public topic detail included even when hidden from archive placements');
expect(!in_array(jhd_sitemap_url(topicUrl($topicRows[203]), $origin), $locs, true), 'inactive database topic excluded');
expect(!in_array(jhd_sitemap_url(topicUrl($topicRows[204]), $origin), $locs, true), 'topic marked inactive by admin display settings excluded');
expect(in_array(jhd_sitemap_url(bookUrl(['id'=>2]), $origin), $locs, true), 'slug-less old book ID remains discoverable');
expect(in_array(jhd_sitemap_url(mediaUrl('video', 1), $origin), $locs, true), 'published video included');
expect(in_array(jhd_sitemap_url(mediaUrl('audio', 2), $origin), $locs, true), 'published audio included');
foreach ([3,4,5] as $id) expect(!in_array(jhd_sitemap_url(mediaUrl($id === 5 ? 'audio' : 'video', $id), $origin), $locs, true), 'media publication/path guard ' . $id);
$before = count($entries);
$db->beginTransaction();
for ($i=0; $i<5100; $i++) $insert->execute(['bulk', 'bulk-'.$i, 'article', 'published', null]);
$db->commit();
$after = count(jhd_sitemap_entries($db));
expect($after === $before + 5100, 'no per-table LIMIT silently drops published URLs: before=' . $before . ' after=' . $after);
$unavailableSchema = new JametulhodaSqlitePDO('sqlite::memory:');
rejects(fn() => jhd_sitemap_entries($unavailableSchema), 'query failure propagates, never partial successful sitemap');

echo "$checks focused polish checks passed (in-memory database)\n";
