// tests/route-matrix.mjs — Comprehensive dual-mode routing + link audit (spec 26/34).
//
// Verifies that EVERY public route resolves over HTTP in BOTH URL modes and that
// they converge on the same controller:
//   • Query mode  : /index.php?p=<route>[&slug=…|&id=…|&kind=…]
//   • Pretty mode : /<route>[/<slug>]   (requires mod_rewrite; skipped gracefully
//                   if the server does not resolve it — Query mode is the contract)
// Plus: real detail routes (topic/news/article/book/lesson/media) discovered from
// the live listings, true-404 behaviour for unknown slugs/routes, a canonical
// check, and an internal-link audit that forbids links to internal PHP files.
//
// Usage: TEST_BASE_URL=http://127.0.0.1:8080 node tests/route-matrix.mjs
import fs from 'node:fs';

const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const results = [];
const failures = [];
const errors = /(Warning|Fatal error|Parse error|Deprecated|Notice):|Uncaught (Error|Exception)|شناسه پیگیری/;
const leaksInternal = (u) => /(^|\/)(pages|admin|includes|config|bin|tests|database|storage)\/.+\.php|router\.php/i.test(u);
const check = (label, ok, detail = '') => { results.push({ label, ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${label}${detail ? ' | ' + detail : ''}`); if (!ok) failures.push({ label, detail }); };

async function get(path) {
  const url = base + path;
  let res = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(30000) });
  if (res.status === 301 || res.status === 302 || res.status === 308) {
    const location = res.headers.get('location') || '';
    try {
      const next = new URL(location, url);
      const current = new URL(url);
      const slashFixup = next.pathname.replace(/\/+$/, '') === current.pathname.replace(/\/+$/, '')
        && next.origin === current.origin;
      if (slashFixup) {
        res = await fetch(next, { redirect: 'manual', signal: AbortSignal.timeout(30000) });
      }
    } catch { /* keep original */ }
  }
  const body = res.status === 200 ? await res.text() : '';
  return { status: res.status, body, headers: res.headers };
}

// Listing routes: [queryP, prettyPath, marker]
const listings = [
  ['news', '/news', 'اخبار'],
  ['articles', '/articles', 'مقالات'],
  ['reports', '/reports', 'گزارش'],
  ['research', '/research', 'پژوهش'],
  ['books', '/books', 'کتاب'],
  ['lessons', '/lessons', 'درس'],
  ['topics', '/topics', 'موضوع'],
  ['media', '/media', 'ویدیو'],
  ['videos', '/videos', 'ویدیو'],
  ['video', '/video', 'ویدیو'], // singular pretty path is a listing without an id
  ['audios', '/audios', 'صوت'],
  ['audio', '/audio', 'صوت'], // singular pretty path is a listing without an id
  ['events', '/events', 'رویداد'],
  ['speeches', '/speeches', 'سخنرانی'],
  ['qa', '/qa', 'پرسش'],
  ['about', '/about', 'درباره'],
  ['contact', '/contact', 'تماس'],
  ['search', '/search', 'جستجو'],
  ['login', '/login', 'ورود'],
  ['register', '/register', 'ثبت‌نام'],
];

let prettySupported = true;
for (const [p, prettyPath, marker] of listings) {
  // Query mode is the guaranteed contract.
  const q = await get(`/index.php?p=${p}`);
  check(`QUERY /index.php?p=${p}`, q.status === 200 && q.body.includes(marker) && !errors.test(q.body), `HTTP ${q.status}`);

  // Pretty mode is optional (needs mod_rewrite). Record whether it works.
  const pr = await get(prettyPath);
  const prettyOk = pr.status === 200 && pr.body.includes(marker) && !errors.test(pr.body);
  if (!prettyOk) prettySupported = false;
  check(`PRETTY ${prettyPath} (optional)`, prettyOk, `HTTP ${pr.status}`);
}

// ── Detail routes discovered from live listings ─────────────────────────────
async function firstDetailSlug(listingQueryPath, re) {
  const r = await get(listingQueryPath);
  const m = r.body.match(re);
  return m ? decodeURIComponent(m[1]) : null;
}

async function testDetail(label, queryUrl, prettyUrl, marker) {
  const q = await get(queryUrl);
  check(`QUERY detail ${label}`, q.status === 200 && (!marker || q.body.includes(marker)) && !errors.test(q.body), `HTTP ${q.status}`);
  if (prettyUrl) {
    const pr = await get(prettyUrl);
    check(`PRETTY detail ${label} (optional)`, pr.status === 200 && !errors.test(pr.body), `HTTP ${pr.status}`);
  }
}

// Topic detail (core deliverable). Discovered from the live /topics hub; the
// topic route is still validated by the 404 + listing checks even when the
// database has no published topic yet.
const topicSlug = await firstDetailSlug('/index.php?p=topics', /(?:topic\/|\bp=topic&(?:amp;)?slug=)([^"'&>]+)/);
if (topicSlug) {
  await testDetail('topic', `/index.php?p=topic&slug=${encodeURIComponent(topicSlug)}`, `/topic/${encodeURIComponent(topicSlug)}`);
} else {
  check('topic detail discovery', true, 'no topic published yet (skipped)');
}

// News / article / report / research detail. Articles & reports are discovered
// from their plural listings (article/report are detail-only routes); news &
// research double as listing + detail routes.
for (const [listingP, detailP, prettyPrefix] of [
  ['news', 'news', '/news'],
  ['articles', 'article', '/article'],
  ['reports', 'report', '/report'],
  ['research', 'research', '/research'],
]) {
  const slug = await firstDetailSlug(`/index.php?p=${listingP}`, new RegExp(`(?:${prettyPrefix.slice(1)}\\/|\\bp=${detailP}&(?:amp;)?slug=)([^"'&>]+)`));
  if (slug) {
    await testDetail(`${detailP}`, `/index.php?p=${detailP}&slug=${encodeURIComponent(slug)}`, `${prettyPrefix}/${encodeURIComponent(slug)}`);
  } else {
    check(`${detailP} detail discovery`, true, `no ${detailP} item published (skipped)`);
  }
}

// Book detail (slug or id)
const bookSlug = await firstDetailSlug('/index.php?p=books', /(?:book\/|\bp=book&(?:amp;)?(?:slug|id)=)([^"'&>]+)/);
if (bookSlug) {
  const param = /^\d+$/.test(bookSlug) ? `id=${bookSlug}` : `slug=${encodeURIComponent(bookSlug)}`;
  await testDetail('book', `/index.php?p=book&${param}`, `/book/${bookSlug}`);
}

// Lesson detail
const lessonSlug = await firstDetailSlug('/index.php?p=lessons', /(?:lesson\/|\bp=lesson&(?:amp;)?slug=)([^"'&>]+)/);
if (lessonSlug) {
  await testDetail('lesson', `/index.php?p=lesson&slug=${encodeURIComponent(lessonSlug)}`, `/lesson/${encodeURIComponent(lessonSlug)}`);
}

// Media detail (video/audio by id)
for (const kind of ['video', 'audio']) {
  const mediaId = await firstDetailSlug(`/index.php?p=${kind}s`, new RegExp(`(?:${kind}\\/|\\bp=${kind}&(?:amp;)?id=)(\\d+)`));
  if (mediaId) {
    await testDetail(`${kind} media`, `/index.php?p=${kind}&id=${mediaId}`, `/${kind}/${mediaId}`);
  } else {
    check(`${kind} media detail discovery`, true, `no ${kind} media published (skipped)`);
  }
}

// ── True 404 (no soft-404) ──────────────────────────────────────────────────
const notFound = [
  ['/index.php?p=topic&slug=this-does-not-exist-xyz', 'QUERY unknown topic slug'],
  ['/topic/this-does-not-exist-xyz', 'PRETTY unknown topic slug'],
  ['/index.php?p=bogus-route-xyz', 'QUERY unknown route'],
  ['/index.php?p=article&slug=this-does-not-exist-xyz', 'QUERY unknown article slug'],
];
for (const [path, label] of notFound) {
  const r = await get(path);
  check(`404 ${label}`, r.status === 404, `HTTP ${r.status} (${path})`);
}

// ── Canonical must be a public URL, never an internal PHP file ──────────────
for (const path of ['/index.php?p=news', '/index.php?p=topics', '/news', '/topics']) {
  const r = await get(path);
  const canonical = r.body.match(/<link rel="canonical" href="([^"]+)"/)?.[1] || '';
  check(`canonical public ${path}`, r.status === 200 && canonical !== '' && !leaksInternal(canonical), canonical || '(none)');
}

// ── Internal-link audit: crawl the home + listings, forbid internal PHP links ─
const seeds = ['/', '/index.php?p=news', '/index.php?p=topics', '/index.php?p=books', '/index.php?p=lessons'];
const seen = new Set();
let internalLeaks = 0;
let linksChecked = 0;
for (const seed of seeds) {
  const r = await get(seed);
  if (r.status !== 200) continue;
  for (const m of r.body.matchAll(/(?:href|action)=(["'])(.*?)\1/gi)) {
    let href = m[2];
    if (/^(https?:|mailto:|tel:|#|javascript:)/i.test(href)) continue;
    linksChecked++;
    let abs;
    try { abs = new URL(href, base + '/'); } catch { continue; }
    if (abs.origin !== new URL(base).origin) continue;
    const key = abs.pathname + abs.search;
    if (leaksInternal(abs.pathname + abs.search)) {
      internalLeaks++;
      if (internalLeaks <= 10) console.log(`  internal-php link: ${href}  (from ${seed})`);
    }
    if (!seen.has(key) && seen.size < 120) {
      seen.add(key);
      const rr = await get(abs.pathname + abs.search);
      if (rr.status >= 500) { check(`link 5xx ${abs.pathname + abs.search}`, false, `HTTP ${rr.status} (from ${seed})`); }
    }
  }
}
check('no internal PHP links in public HTML', internalLeaks === 0, `${linksChecked} internal links scanned, ${internalLeaks} leaks`);

fs.mkdirSync('test-results', { recursive: true });
fs.writeFileSync('test-results/route-matrix.json', JSON.stringify({ base, prettySupported, results, failures }, null, 2));
console.log(`\nRoute matrix: ${results.length - failures.length}/${results.length} passed; ${failures.length} failed. Pretty mode ${prettySupported ? 'supported' : 'NOT resolved by this server (Query mode is the contract)'}.`);
if (failures.length) process.exitCode = 1;
