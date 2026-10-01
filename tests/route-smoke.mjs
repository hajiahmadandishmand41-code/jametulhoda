// Route smoke test against a running Jametulhoda HTTP server.
// This exercises actual HTTP routing (including Apache/.htaccess when TEST_BASE_URL
// points to the Apache container), unlike tests/verify_routes.py which is static.
//
// The site supports two URL modes controlled by JHD_PRETTY_URLS:
//   • Query mode  (default, InfinityFree-safe): index.php?p=news
//   • Pretty mode (optional, needs mod_rewrite): /news
// The router RESOLVES both regardless of the flag. This test accepts a canonical
// in either spelling but forbids leaking any internal PHP file (pages/*.php,
// router.php, admin/*.php) as the public canonical.
import fs from 'node:fs';

const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const cases = [
  ['/', 'lang="fa"'],
  ['/news', 'اخبار'],
  ['/articles', 'مقالات'],
  ['/reports', 'گزارش'],
  ['/events', 'رویداد'],
  ['/books', 'کتابخانه'],
  ['/lessons', 'درس'],
  ['/research', 'پژوهش'],
  ['/media', 'ویدیو'],
  ['/videos', 'ویدیو'],
  ['/audios', 'صوت'],
  ['/topics', 'موضوع'],
  ['/search', 'جستجو'],
  ['/about', 'درباره'],
  ['/contact', 'تماس'],
  ['/qa', 'پرسش'],
  ['/login', 'ورود'],
];
const failures = [];
const errors = /(Warning|Fatal error|Parse error|Deprecated|Notice):|Uncaught (Error|Exception)|شناسه پیگیری/;
const leaksInternal = (u) => /(^|\/)(pages|admin|includes|config|bin|tests)\/.+\.php|router\.php/i.test(u);

async function fetchPublic(path) {
  const url = base + path;
  let response = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(30000) });
  // Apache DirectorySlash may 301 /login → /login/ when a physical stub directory
  // exists. Follow only that same-path slash fixup so the pretty URL still 200s.
  if (response.status === 301 || response.status === 302 || response.status === 308) {
    const location = response.headers.get('location') || '';
    try {
      const next = new URL(location, url);
      const current = new URL(url);
      const slashFixup = next.pathname.replace(/\/+$/, '') === current.pathname.replace(/\/+$/, '')
        && next.origin === current.origin;
      if (slashFixup) {
        response = await fetch(next, { redirect: 'manual', signal: AbortSignal.timeout(30000) });
      }
    } catch { /* keep the original response */ }
  }
  return response;
}

for (const [path, marker] of cases) {
  let response;
  try {
    response = await fetchPublic(path);
    const body = await response.text();
    const canonical = body.match(/<link rel="canonical" href="([^"]+)"/)?.[1] || '';
    const description = body.match(/<meta name="description" content="([^"]+)"/)?.[1] || '';
    const expectedPath = new URL(path.replace(/^\//, ''), base.replace(/\/$/, '') + '/').pathname;
    const canonicalPath = canonical ? new URL(canonical).pathname : '';
    const canonicalQuery = canonical ? new URL(canonical).searchParams : null;
    // Canonical may be the pretty path or the Query-mode index.php?p=… form,
    // but never an internal PHP file.
    const canonicalOk = Boolean(canonical)
      && !leaksInternal(canonical)
      && (canonicalPath === expectedPath || (canonicalPath === '/index.php' && canonicalQuery && canonicalQuery.get('p')));
    const seoOk = canonicalOk && description.length > 20;
    const ok = response.status === 200 && body.includes(marker) && !errors.test(body) && seoOk;
    console.log(`${ok ? 'PASS' : 'FAIL'} ${path} | expected 200 | actual ${response.status} | ${ok ? 'HTTP + marker + canonical + description' : 'status/content/SEO/PHP error assertion failed'}`);
    if (!ok) failures.push({ path, expected: 200, actual: response.status, markerFound: body.includes(marker), canonical, expectedPath, descriptionLength: description.length, phpError: errors.test(body) });
  } catch (error) {
    console.log(`FAIL ${path} | expected 200 | actual NETWORK ERROR | ${error.message}`);
    failures.push({ path, expected: 200, actual: 'network error', error: error.message });
  }
}

// Assert the homepage's visible news navigation targets the news section, then
// verify that exact destination over HTTP. The link may be a pretty /news URL
// or a Query-mode index.php?p=news URL — both must resolve to HTTP 200.
try {
  const homeResponse = await fetch(base + '/', { signal: AbortSignal.timeout(30000) });
  const homeHtml = await homeResponse.text();
  const newsAnchor = [...homeHtml.matchAll(/<a\b[^>]*href=(["'])(.*?)\1[^>]*>([\s\S]*?)<\/a>/gi)]
    .map(match => ({ href: match[2], label: match[3].replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() }))
    .find(link => {
      try {
        const u = new URL(link.href, base + '/');
        const isNewsPath = u.pathname.replace(/\/$/, '').endsWith('/news');
        const isNewsQuery = (u.pathname === '/index.php' || u.pathname.endsWith('/index.php')) && u.searchParams.get('p') === 'news';
        return (isNewsPath || isNewsQuery) && /اخبار/.test(link.label);
      } catch { return false; }
    });
  if (!newsAnchor) {
    failures.push({ path: '/', actual: homeResponse.status, error: 'homepage has no visible اخبار link targeting the news section' });
    console.log('FAIL homepage news link | expected visible اخبار link to the news section');
  } else {
    const newsResponse = await fetch(new URL(newsAnchor.href, base + '/'), { redirect: 'manual', signal: AbortSignal.timeout(30000) });
    const ok = homeResponse.status === 200 && newsResponse.status === 200;
    console.log(`${ok ? 'PASS' : 'FAIL'} homepage news link | ${newsAnchor.href} | HTTP ${newsResponse.status}`);
    if (!ok) failures.push({ path: newsAnchor.href, expected: 200, actual: newsResponse.status, homepageStatus: homeResponse.status });
  }
} catch (error) {
  failures.push({ path: '/', error: `homepage news-link assertion: ${error.message}` });
  console.log(`FAIL homepage news link | ${error.message}`);
}

const negativeCases = ['/__jhd_route_missing__', '/install.php', '/config/database.php', '/pages/news.php', '/includes/functions.php', '/.env'];
for (const path of negativeCases) {
  try {
    const response = await fetch(base + path, { redirect: 'manual', signal: AbortSignal.timeout(30000) });
    const body = await response.text();
    const ok = response.status === 404 && !errors.test(body);
    console.log(`${ok ? 'PASS' : 'FAIL'} ${path} | expected 404 | actual ${response.status}`);
    if (!ok) failures.push({ path, expected: 404, actual: response.status, phpError: errors.test(body) });
  } catch (error) {
    console.log(`FAIL ${path} | expected 404 | actual NETWORK ERROR | ${error.message}`);
    failures.push({ path, expected: 404, actual: 'network error', error: error.message });
  }
}

fs.mkdirSync('test-results', { recursive: true });
fs.writeFileSync('test-results/route-smoke.json', JSON.stringify({ base, cases: cases.length, negativeCases: negativeCases.length, failures }, null, 2));
const total = cases.length + 1 + negativeCases.length;
console.log(`\nRoute smoke: ${total - failures.length}/${total} passed; ${failures.length} failed.`);
if (failures.length) process.exitCode = 1;
