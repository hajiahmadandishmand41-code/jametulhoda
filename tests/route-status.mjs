// tests/route-status.mjs — Real HTTP status + body assertions for every route
// (spec stages 2-9: architecture, status codes, deep links, search, pagination).
//
// Usage: TEST_BASE_URL=http://127.0.0.1:8080 node tests/route-status.mjs
// Optional: TEST_ADMIN_USERNAME/TEST_ADMIN_PASSWORD for admin checks.
// Output: PASS/FAIL lines + test-results/route-status.json. Exit 1 on failure.
import fs from 'node:fs';

const origin = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
// Pretty URLs are optional: without mod_rewrite the router serves the query
// spelling.  Assertions on canonical URLs and internal links accept both.
let prettyUrls = true;
const canonicalOk = (rawCanon, suffix) => {
  const canon = String(rawCanon).replaceAll('&amp;', '&');
  const [, route] = suffix.match(/^\/([^/]+)\//) || [];
  return canon.endsWith(suffix)
    || (route && canon.endsWith(suffix.replace(/^\/[^/]+\//, `/index.php?p=${route}&slug=`)))
    || (route && canon.endsWith(suffix.replace(/^\/[^/]+\//, `/index.php?p=${route}&id=`)));
};
const locationOk = (loc, expect) => loc.includes(expect) || loc.includes('p=' + expect.replace(/^\//, ''));
const deepLinkRe = () => prettyUrls
  ? /href="(\/(?:article|news|research|post|book|lesson|topic|speech|video|audio)\/[^"]*)"/g
  : /href="(\/index\.php\?p=(?:article|news|research|post|book|lesson|topic|speech|video|audio)&[^"]*)"/g;
fs.mkdirSync('test-results', { recursive: true });
const results = [];
const check = (label, ok, detail = '') => {
  results.push({ label, ok, detail: String(detail).slice(0, 300) });
  console.log(ok ? 'PASS' : 'FAIL', label, ok ? '' : detail);
};
const PHP_ERROR_RE = /(Warning|Fatal error|Parse error|Deprecated|Notice):|شناسه پیگیری|Uncaught (Error|Exception)/;

const jar = new Map();
function storeCookies(headers) {
  for (const c of (headers.getSetCookie ? headers.getSetCookie() : [])) {
    const pair = c.split(';')[0];
    const eq = pair.indexOf('=');
    if (eq > 0) jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
  }
}
async function get(path, { follow = true } = {}) {
  const headers = {};
  if (jar.size) headers.cookie = [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  const res = await fetch(origin + path, { headers, redirect: follow ? 'follow' : 'manual', signal: AbortSignal.timeout(30000) });
  storeCookies(res.headers);
  const text = await res.text();
  return { status: res.status, text, location: res.headers.get('location') || '' };
}
async function postForm(path, fields) {
  const headers = { 'content-type': 'application/x-www-form-urlencoded' };
  if (jar.size) headers.cookie = [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  const res = await fetch(origin + path, {
    method: 'POST', headers, body: new URLSearchParams(fields).toString(),
    redirect: 'manual', signal: AbortSignal.timeout(30000),
  });
  storeCookies(res.headers);
  return { status: res.status, text: await res.text(), location: res.headers.get('location') || '' };
}
const clean = (t) => !PHP_ERROR_RE.test(t);

// ─── 1. List pages: 200 + real content ────────────────────────────────────
for (const [path, marker] of [
  ['/', 'جامعه‌الهدی'],
  ['/articles', 'seed-article-10'],   // paginated listing shows fixtures
  ['/news', 'seed-news-10'],
  ['/books', 'seed-book-01'],
  ['/videos', 'ویدیو'],
  ['/audios', 'صوت'],
  ['/video', 'ویدیو'],
  ['/audio', 'صوت'],
  ['/lessons', 'دروس فقه'],
  ['/research', 'روش‌شناسی تحقیق'],
  ['/topics', 'مهدویت'],
  ['/search', 'جستجو در آرشیو'],
  ['/search?q=قرآن', 'نتیجه'],
  ['/about', 'جامعه‌الهدی'],
  ['/contact', 'تماس'],
  ['/speeches', 'سخنرانی'],
  ['/speeches?year=2026', 'سخنرانی درباره'],
  ['/reports', 'میلاد'],
  ['/qa', 'نماز جمعه'],
  ['/programs', 'حفظ قرآن'],
  ['/religious-activities', 'عزاداری'],
  ['/announcements', 'ثبت‌نام دوره'],
  ['/media-library', 'رسانه'],
  ['/category/fiqh-osul', 'فقه'],
  ['/lessons/fiqh-dars', 'طهارت'],
  ['/lessons/fiqh-dars/taharat-1', 'وضو'],
]) {
  const r = await get(path);
  check(`GET ${path} → 200`, r.status === 200, r.status);
  if (r.status === 200) {
    check(`GET ${path} body has content`, r.text.includes(marker) && clean(r.text), r.text.slice(0, 120));
  }
}

// ─── 2. Detail pages: 200 + canonical + related ───────────────────────────
for (const [path, marker, canonicalSuffix] of [
  ['/article/aql-in-religion', 'جایگاه عقل', '/article/aql-in-religion'],
  ['/news/new-school-year', 'سال تحصیلی', '/news/new-school-year'],
  ['/research/research-method', 'روش‌شناسی', '/research/research-method'],
  ['/post/milad-report', 'میلاد', '/report/milad-report'],
  ['/speech/ramadan-speech', 'رمضان', '/speech/ramadan-speech'],
  ['/book/usul-aqaid', 'اصول عقاید', '/book/usul-aqaid'],
  ['/book/1', 'اصول عقاید', '/book/usul-aqaid'],
  ['/lesson/fiqh-lesson-1', 'مقدمات فقه', '/lesson/fiqh-lesson-1'],
  ['/topic/mahdaviat', 'امام مهدی', '/topic/mahdaviat'],
  ['/video/1', 'ویدیو تبیینی', '/video/1'],
  ['/audio/2', 'صوت مقاله', '/audio/2'],
]) {
  const r = await get(path);
  check(`GET ${path} → 200`, r.status === 200, r.status);
  if (r.status !== 200) continue;
  check(`GET ${path} body`, r.text.includes(marker) && clean(r.text), 'missing marker or PHP error');
  const canon = r.text.match(/<link rel="canonical" href="([^"]+)"/)?.[1] || '';
  prettyUrls = prettyUrls && canon.includes('/' + path.split('/')[1] + '/');
  check(`GET ${path} canonical`, canonicalOk(canon, canonicalSuffix), canon);
}
// Unicode slug detail
{
  const uni = '/article/' + encodeURIComponent('مقاله-نمونه-فارسی');
  const r = await get(uni);
  check('GET unicode-slug article → 200', r.status === 200, r.status);
  const canonHref = r.text.match(/<link rel="canonical" href="([^"]+)"/)?.[1] || '';
  check('unicode article canonical single-encoded', canonHref.includes('%D9%85') && !canonHref.includes('%25'), canonHref);
}
// Legacy spellings keep working (backward compatibility)
for (const [path, marker] of [
  ['/articles.php', 'seed-article-10'],
  ['/articles/', 'seed-article-10'],
  ['/post.php?slug=aql-in-religion', 'جایگاه عقل'],
  ['/post?slug=aql-in-religion', 'جایگاه عقل'],
  ['/book.php?id=1', 'اصول عقاید'],
  ['/lesson.php?slug=fiqh-lesson-1', 'مقدمات فقه'],
  ['/topic.php?slug=mahdaviat', 'امام مهدی'],
  ['/library', 'کتابخانه'],
  ['/files', 'کتابخانه'],
]) {
  const r = await get(path);
  check(`GET legacy ${path} → 200`, r.status === 200, r.status);
  if (r.status === 200) check(`legacy ${path} body`, r.text.includes(marker) && clean(r.text), '');
}
// Legacy cross-canonicalizes to pretty URL
{
  const r = await get('/post?slug=aql-in-religion');
  const canon = r.text.match(/<link rel="canonical" href="([^"]+)"/)?.[1] || '';
  check('legacy /post?slug canonical → /article/…', canonicalOk(canon, '/article/aql-in-religion'), canon);
}

// ─── 3. Redirects: 301/302 with Location ─────────────────────────────────
for (const [path, expectLoc] of [
  ['/post', '/articles'],
  ['/lesson', '/lessons'],
  ['/topic', '/topics'],
  ['/article/', '/articles'],
  ['/admin/', '/login'],
  ['/book/usul-aqaid?download=pdf', '/uploads/documents/seed-doc.pdf'],
]) {
  const r = await get(path, { follow: false });
  check(`GET ${path} → redirect`, [301, 302, 303].includes(r.status), r.status);
  check(`GET ${path} location`, locationOk(r.location, expectLoc), r.location);
}

// ─── 4. Not found: real 404 status + 404 page (never empty 200) ──────────
for (const path of [
  '/missing-page-xyz',
  '/article/no-such-slug',
  '/news/aql-in-religion',      // article slug under /news/ → 404
  '/research/new-school-year',  // news slug under /research/ → 404
  '/post/no-such-slug',
  '/speech/aql-in-religion',    // article slug under /speech/ → 404
  '/book/999999',
  '/book/draft-book',           // draft slug → 404
  '/book.php?id=7',             // draft id → 404
  '/lesson/no-such-slug',
  '/topic/no-such-slug',
  '/category/no-such-slug',
  '/video/999999',
  '/audio/1',                   // id 1 is a video, not audio → 404
  '/video/abc',
  '/audio/abc',
  '/lessons/no-collection',
  '/.env', '/.git/config', '/config/database.php', '/config/local.php',
  '/pages/about.php', '/includes/auth.php', '/content/home-intro.php',
  '/bin/migrate.php', '/uploads/test.php', '/uploads/images/test.php',
  '/ajax/like.php',
]) {
  const r = await get(path);
  const looks404 = r.text.includes('۴۰۴') || r.text.includes('یافت نشد') || r.text.includes('در دسترس نیست');
  check(`GET ${path} → 404`, r.status === 404 && looks404 && clean(r.text), `${r.status} len=${r.text.length}`);
}

// ─── 5. Detail pages: related content links resolve ──────────────────────
{
  const r = await get('/article/aql-in-religion');
  check('article has related-posts block', r.text.includes('مطالب مرتبط'), '');
  check('article has related books block', r.text.includes('کتاب‌های مرتبط'), '');
  const links = [...new Set([...r.text.matchAll(deepLinkRe())].map((m) => m[1].replaceAll('&amp;', '&')))];
  check('article exposes deep links', links.length >= 5, `found ${links.length}`);
  for (const link of links.slice(0, 12)) {
    const d = await get(link);
    check(`deep link ${link.slice(0, 60)} → 200`, d.status === 200 && clean(d.text), d.status);
  }
}
{
  // Lesson prev/next chain resolves from the database.
  const r = await get('/lesson/fiqh-lesson-2');
  const prev = r.text.match(/href="([^"]*lesson[^"]*)"[^>]*rel="prev/)?.[1];
  const next = (r.text.match(/href="([^"]*lesson[^"]*)"[^>]*rel="next/)?.[1]) || null;
  check('lesson-2 has prev link', !!prev, '');
  check('lesson-2 has next link', !!next, '');
  for (const link of [prev, next].filter(Boolean)) {
    const d = await get(link);
    check(`lesson chain ${link} → 200`, d.status === 200, d.status);
  }
}

// ─── 6. Search: every result links to a real detail page ─────────────────
for (const q of ['قرآن', 'فقه', 'test-does-not-exist-xyz']) {
  const r = await get('/search?q=' + encodeURIComponent(q));
  check(`GET /search?q=${q.slice(0, 8)} → 200`, r.status === 200, r.status);
  const links = [...new Set([...r.text.matchAll(/class="[^"]*result[^"]*"[\s\S]{0,400}?href="([^"]+)"/g)].map((m) => m[1]))];
  const detailLinks = [...new Set([...r.text.matchAll(deepLinkRe())].map((m) => m[1].replaceAll('&amp;', '&')))];
  if (q.includes('test-does-not-exist')) {
    check('empty search shows no-results state', r.text.includes('پیدا نشد') && clean(r.text), '');
  } else {
    check(`search "${q}" returns detail links`, detailLinks.length > 0, `found ${detailLinks.length}`);
    for (const link of detailLinks.slice(0, 10)) {
      const d = await get(link);
      check(`search result ${link.slice(0, 50)} → 200`, d.status === 200, d.status);
    }
  }
  void links;
}

// ─── 7. Pagination: real pages, prev/next, no 404 ────────────────────────
for (const [base, page2marker] of [
  ['/articles', 'seed-article-01'],
  ['/news', 'seed-news-01'],
  ['/books', 'احکام نماز'],   // page 2 holds the oldest books
  ['/videos', null],
  ['/audios', null],
]) {
  const p1 = await get(base);
  const p2 = await get(base + '?page=2');
  check(`GET ${base}?page=2 → 200`, p2.status === 200, p2.status);
  if (page2marker) check(`${base} page 2 has older items`, p2.text.includes(page2marker), '');
  const bad = await get(base + '?page=999');
  check(`GET ${base}?page=999 → 200 (empty, no crash)`, bad.status === 200 && clean(bad.text), bad.status);
  void p1;
}
{
  // Pagination links themselves resolve.
  const r = await get('/articles');
  const pageLinks = [...new Set([...r.text.matchAll(/href="([^"]*(?:\/articles\?|p=articles&)[^"]*page=\d+)"/g)].map((m) => m[1].replaceAll('&amp;', '&')))];
  check('articles pagination links exist', pageLinks.length >= 2, pageLinks.join(','));
  for (const link of pageLinks.slice(0, 4)) {
    const d = await get(link);
    check(`pagination ${link} → 200`, d.status === 200, d.status);
  }
}

// ─── 8. Sitemap + robots: every sitemap URL is 200 ───────────────────────
{
  const sm = await get('/sitemap.xml');
  check('GET /sitemap.xml → 200', sm.status === 200, sm.status);
  const locs = [...sm.text.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].trim().replaceAll('&amp;', '&'));
  const typedOk = prettyUrls
    ? locs.some((u) => u.includes('/article/')) && locs.some((u) => u.includes('/video/'))
    : locs.some((u) => /p=article(&|$)/.test(u)) && locs.some((u) => /p=video(&|$)/.test(u));
  check('sitemap has typed post URLs', typedOk, `${locs.length} urls`);
  let sitemapBad = 0;
  // The sitemap is written with the configured SITE_URL (absolute), which is
  // not the test origin when the fixture runs on 127.0.0.1.  Compare paths.
  const siteUrl = new URL(locs[0] || origin).origin;
  for (const u of locs) {
    const p = u.startsWith(origin) ? u.slice(origin.length)
      : (u.startsWith(siteUrl) ? u.slice(siteUrl.length) : null);
    if (!p) { sitemapBad++; check(`sitemap URL host ${u.slice(0, 60)}`, false, 'wrong host'); continue; }
    const d = await get(p);
    if (d.status !== 200 || !clean(d.text)) { sitemapBad++; check(`sitemap URL ${p.slice(0, 70)} → 200`, false, d.status); }
  }
  check(`all ${locs.length} sitemap URLs → 200`, sitemapBad === 0, `${sitemapBad} bad`);
  const rb = await get('/robots.txt');
  check('GET /robots.txt → 200', rb.status === 200, rb.status);
}

// ─── 9. Static assets + uploads ──────────────────────────────────────────
for (const path of [
  '/assets/css/design-system.css', '/assets/js/main.js', '/assets/js/gallery.js', '/assets/img/logo.jpg',
  '/assets/img/placeholder.svg', '/assets/vendor/plyr.css',
  '/assets/fonts/Vazirmatn-Regular.woff2',
  '/uploads/images/seed-cover.png', '/uploads/audios/seed-audio.mp3',
  '/uploads/videos/seed-video.mp4', '/uploads/documents/seed-doc.pdf',
]) {
  const res = await fetch(origin + path, { signal: AbortSignal.timeout(30000) });
  const buf = Buffer.from(await res.arrayBuffer());
  check(`GET ${path} → 200 + bytes`, res.status === 200 && buf.length > 20, `${res.status} len=${buf.length}`);
}

// ─── 10. Admin + security spot checks ────────────────────────────────────
{
  const user = process.env.TEST_ADMIN_USERNAME;
  const pass = process.env.TEST_ADMIN_PASSWORD;
  if (!user || !pass) {
    check('admin checks skipped (no credentials)', true);
  } else {
    const loginPage = await get('/admin/login');
    const csrf = loginPage.text.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
    check('admin login page has CSRF', !!csrf, '');
    const bad = await postForm('/admin/login', { identifier: user, password: 'wrong-' + pass, csrf_token: csrf || '' });
    check('wrong password rejected (no 303)', bad.status !== 303, bad.status);
    const fresh = await get('/admin/login');
    const csrf2 = fresh.text.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
    const ok = await postForm('/admin/login', { identifier: user, password: pass, csrf_token: csrf2 || '' });
    check('admin login → 303', ok.status === 303, `${ok.status} → ${ok.location}`);
    const dash = await get('/admin/');
    check('dashboard → 200', dash.status === 200, dash.status);
    const noCsrf = await postForm('/admin/posts/delete.php', { id: '1' });
    check('POST delete without CSRF → 403', noCsrf.status === 403, noCsrf.status);
    const settings = await get('/admin/settings');
    check('superadmin settings → 200', settings.status === 200, settings.status);
  }
}

fs.writeFileSync('test-results/route-status.json', JSON.stringify(results, null, 2));
const failed = results.filter((r) => !r.ok);
console.log(`\n===== ROUTE STATUS: ${results.length - failed.length}/${results.length} passed =====`);
if (failed.length) {
  console.log('Failures:');
  for (const f of failed) console.log(' -', f.label, '|', f.detail);
  process.exitCode = 1;
}
