/**
 * tests/seo-probe.mjs — end-to-end SEO / URL / branding probe.
 *
 * Requests real URLs (local dev server or the Production deployment) and
 * reports, with evidence, what a crawler would actually see:
 *
 *   • HTTP status and content type
 *   • <title>, meta description, <link rel=canonical>, meta robots
 *   • every JSON-LD block (types, Organization name/url/logo/sameAs)
 *   • Open Graph + Twitter card tags
 *   • whether the URL is listed in /sitemap.xml
 *   • whether the legacy/non-canonical spelling forwards to the canonical one
 *   • /robots.txt, /sitemap.xml, /favicon.ico and the real logo
 *   • whether /favicon.ico and /assets/img/logo.png are byte-identical
 *
 * It asserts nothing about ranking and nothing about how Google chooses to
 * display a logo. It only verifies the technical signals exist and agree.
 *
 * Usage:
 *   TEST_BASE_URL=https://jametulhoda.vercel.app node tests/seo-probe.mjs
 *
 * Exit code 0 on success, 1 when any check fails.
 */
import { createHash } from 'node:crypto';

const BASE = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const TIMEOUT_MS = Number(process.env.SEO_PROBE_TIMEOUT_MS || 60000);
const EXPECTED_BRAND = process.env.SEO_PROBE_BRAND || 'مدرسه جامعه‌الهدی';
const LOGO_PATH = '/assets/img/logo.png';

const results = [];
const record = (label, ok, detail = '') => {
  results.push({ label, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);
};

const abs = (url) => new URL(url, BASE).toString();

async function get(url, { redirect = 'follow' } = {}) {
  const started = Date.now();
  const res = await fetch(abs(url), { redirect, signal: AbortSignal.timeout(TIMEOUT_MS) });
  const body = Buffer.from(await res.arrayBuffer());
  return {
    status: res.status,
    headers: res.headers,
    location: res.headers.get('location') || '',
    text: body.toString('utf8'),
    bytes: body,
    ms: Date.now() - started,
  };
}

const metaContent = (html, attr, key) => {
  const re = new RegExp(`<meta[^>]+${attr}=["']${key}["'][^>]*>`, 'i');
  const m = html.match(re);
  if (!m) return '';
  const c = m[0].match(/content=["']([^"']*)["']/i);
  return c ? c[1] : '';
};
const title = (html) => (html.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1] || '').trim();
const canonical = (html) => (html.match(/<link[^>]+rel=["']canonical["'][^>]*>/i)?.[0].match(/href=["']([^"']*)["']/i)?.[1] || '');
const jsonLd = (html) => {
  const out = [];
  for (const m of html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)) {
    try { out.push(JSON.parse(m[1])); } catch { /* ignore malformed */ }
  }
  return out;
};
const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');

// ─────────────────────────────────────────────────────────────────────────────
console.log(`\n=== SEO probe — ${BASE} ===\n`);

// 1. Core endpoints -----------------------------------------------------------
console.log('--- Core endpoints ---');
const home = await get('/');
record('GET / → 200', home.status === 200, `${home.status} (${home.ms}ms, ${home.headers.get('content-type')})`);
record('GET / → HTML', (home.headers.get('content-type') || '').includes('text/html'), home.headers.get('content-type') || '');

const robots = await get('/robots.txt');
record('GET /robots.txt → 200', robots.status === 200, String(robots.status));
record('/robots.txt declares the sitemap', /Sitemap:\s*\S+/i.test(robots.text), (robots.text.match(/Sitemap:.*/i) || [''])[0].trim());

const sitemap = await get('/sitemap.xml');
record('GET /sitemap.xml → 200', sitemap.status === 200, String(sitemap.status));
record('/sitemap.xml is XML', (sitemap.headers.get('content-type') || '').includes('xml'), sitemap.headers.get('content-type') || '');
const locs = [...sitemap.text.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].trim());
record('/sitemap.xml has URLs', locs.length > 0, `${locs.length} URLs`);
record('/sitemap.xml has no duplicates', new Set(locs).size === locs.length, `${new Set(locs).size} unique`);

const forbidden = /^https?:\/\/[^/]+\/(admin|login|logout|register|account|profile|password-change|search|install|php\/|migrate|api\/|config\/|includes\/|bin\/|storage\/|tests\/|uploads\/)/i;
const leaked = locs.filter((u) => forbidden.test(u.replace(/^https?:\/\/[^/]+/, '')) || /[?]p=/.test(u));
record('/sitemap.xml contains no private/legacy URLs', leaked.length === 0, leaked.slice(0, 5).join(', '));

// 2. Favicon / logo identity --------------------------------------------------
console.log('\n--- Logo & favicon identity ---');
const logo = await get(LOGO_PATH);
record(`GET ${LOGO_PATH} → 200`, logo.status === 200, `${logo.status} ${logo.headers.get('content-type')}`);
record(`${LOGO_PATH} is a PNG`, logo.bytes.slice(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])), `${logo.bytes.length} bytes`);
const logoW = logo.bytes.length > 24 ? logo.bytes.readUInt32BE(16) : 0;
const logoH = logo.bytes.length > 24 ? logo.bytes.readUInt32BE(20) : 0;
record(`${LOGO_PATH} dimensions`, logoW > 0 && logoH > 0, `${logoW}×${logoH}`);

const favicon = await get('/favicon.ico');
record('GET /favicon.ico → 200', favicon.status === 200, `${favicon.status} ${favicon.headers.get('content-type')}`);
record(
  '/favicon.ico is byte-identical to logo.png',
  favicon.bytes.length > 0 && sha256(favicon.bytes) === sha256(logo.bytes),
  `favicon ${sha256(favicon.bytes).slice(0, 16)}… vs logo ${sha256(logo.bytes).slice(0, 16)}… (${favicon.bytes.length} bytes)`
);

// No placeholder / alternate logo may be referenced by public HTML.
const bannedRefs = home.text.match(/(favicon\.svg|placeholder\.svg|logo\.jpg)/gi) || [];
record('public HTML references no placeholder/old logo', bannedRefs.length === 0, bannedRefs.join(', '));

// 3. Branding on the homepage -------------------------------------------------
console.log('\n--- Brand & structured data (homepage) ---');
const homeTitle = title(home.text);
record('homepage title starts with the brand', homeTitle.startsWith(EXPECTED_BRAND), homeTitle);
record('title does not use the bare Arabic variant as the brand', !/\| جامعة‌الهدی/.test(homeTitle), homeTitle);

const homeBlocks = jsonLd(home.text);
const org = homeBlocks.find((b) => b && /Organization$/i.test(b['@type'] || ''));
const site = homeBlocks.find((b) => b && (b['@type'] || '') === 'WebSite');
record('Organization JSON-LD present', Boolean(org), org ? org['@type'] : 'missing');
record(`Organization.name === "${EXPECTED_BRAND}"`, org?.name === EXPECTED_BRAND, org?.name ?? 'missing');
record('Organization.url points at the site root', typeof org?.url === 'string' && org.url.replace(/\/+$/, '') === BASE.replace(/\/+$/, ''), org?.url ?? 'missing');
const orgLogoUrl = typeof org?.logo === 'string' ? org.logo : org?.logo?.url;
record('Organization.logo is the absolute real logo', orgLogoUrl === abs(LOGO_PATH), orgLogoUrl ?? 'missing');
record('Organization.address is Kabul, Afghanistan', org?.address?.addressLocality === 'کابل' && org?.address?.addressCountry === 'AF', JSON.stringify(org?.address ?? {}));
if (org?.sameAs) record('Organization.sameAs entries are https', org.sameAs.every((u) => /^https:\/\//.test(u)), org.sameAs.join(', '));
if (org?.founder) record('Organization.founder present', Boolean(org.founder?.name), org.founder?.name ?? '');
record('WebSite JSON-LD present', Boolean(site), site ? site['@type'] : 'missing');
record(`WebSite.name === "${EXPECTED_BRAND}"`, site?.name === EXPECTED_BRAND, site?.name ?? 'missing');

const ogSite = metaContent(home.text, 'property', 'og:site_name');
record(`og:site_name === "${EXPECTED_BRAND}"`, ogSite === EXPECTED_BRAND, ogSite);
const ogImage = metaContent(home.text, 'property', 'og:image');
record('og:image is the absolute real logo', ogImage === abs(LOGO_PATH), ogImage);
const twImage = metaContent(home.text, 'name', 'twitter:image');
record('twitter:image is the absolute real logo', twImage === abs(LOGO_PATH), twImage);
record('apple-touch-icon is the real logo', /rel=["']apple-touch-icon["'][^>]*href=["'][^"']*logo\.png/.test(home.text), (home.text.match(/<link[^>]+apple-touch-icon[^>]*>/i) || [''])[0]);
record('icon link points at /favicon.ico', /rel=["'](shortcut )?icon["'][^>]*href=["'][^"']*favicon\.ico/.test(home.text), (home.text.match(/<link[^>]+rel=["']icon["'][^>]*>/i) || [''])[0]);

// 4. Content coverage: one canonical URL per published content type -----------
console.log('\n--- Content URLs (from the sitemap) ---');
const PREFIX_LABEL = [
  ['/news/', 'news'],
  ['/articles/', 'article'],
  ['/research/', 'research'],
  ['/reports/', 'report'],
  ['/announcements/', 'announcement'],
  ['/programs/', 'program'],
  ['/religious-activities/', 'religious activity'],
  ['/speech/', 'speech'],
  ['/lessons/', 'lesson'],
  ['/books/', 'book'],
  ['/topics/', 'topic'],
  ['/category/', 'category'],
  ['/video/', 'video'],
  ['/audio/', 'audio'],
];
const seenTypes = new Set();
const contentSamples = new Map();
for (const loc of locs) {
  const path = new URL(loc).pathname;
  for (const [prefix, label] of PREFIX_LABEL) {
    if (path.startsWith(prefix)) {
      seenTypes.add(label);
      if (!contentSamples.has(label)) contentSamples.set(label, new URL(loc).pathname + new URL(loc).search);
    }
  }
}
console.log(`  sitemap content types: ${[...seenTypes].sort().join(', ') || 'none'}`);

for (const [label, samplePath] of contentSamples) {
  const res = await get(samplePath, { redirect: 'manual' });
  if (res.status >= 300 && res.status < 400) {
    record(`${label}: ${samplePath} is canonical (no redirect)`, false, `${res.status} → ${res.location}`);
    continue;
  }
  const html = res.text;
  const t = title(html);
  const desc = metaContent(html, 'name', 'description');
  const can = canonical(html);
  const rob = metaContent(html, 'name', 'robots');
  const blocks = jsonLd(html);
  const types = blocks.map((b) => b?.['@type']).filter(Boolean);
  const og = metaContent(html, 'property', 'og:image');
  const inSitemap = locs.includes(can);

  record(`${label}: ${samplePath} → 200`, res.status === 200, String(res.status));
  record(`${label}: unique non-empty title`, t.length > 0 && !/^مدرسه جامعه‌الهدی \|/.test(t) && t !== homeTitle, t);
  record(`${label}: meta description present`, desc.length > 0, desc.slice(0, 80) + (desc.length > 80 ? '…' : ''));
  record(`${label}: canonical === requested URL`, can === abs(samplePath), can);
  record(`${label}: robots = index, follow`, /index/i.test(rob) && /follow/i.test(rob), rob);
  record(`${label}: JSON-LD present`, blocks.length > 0, types.join(', '));
  record(`${label}: BreadcrumbList present`, types.includes('BreadcrumbList'), types.join(', '));
  record(`${label}: Organization present`, types.some((x) => /Organization$/i.test(x)), types.join(', '));
  record(`${label}: og:image absolute`, /^https?:\/\//.test(og), og);
  record(`${label}: canonical is listed in the sitemap`, inSitemap, can);
}

// 5. Canonical consolidation of legacy spellings ------------------------------
console.log('\n--- Legacy / duplicate spellings ---');
const legacyPairs = [];
if (contentSamples.has('news')) legacyPairs.push(['/post/' + contentSamples.get('news').split('/').pop(), contentSamples.get('news')]);
if (contentSamples.has('article')) legacyPairs.push(['/article/' + contentSamples.get('article').split('/').pop(), contentSamples.get('article')]);
if (contentSamples.has('report')) legacyPairs.push(['/report/' + contentSamples.get('report').split('/').pop(), contentSamples.get('report')]);
if (contentSamples.has('topic')) legacyPairs.push([contentSamples.get('topic').replace(/^\/topics\//, '/topic/'), contentSamples.get('topic')]);
legacyPairs.push(['/library', '/books']);
legacyPairs.push(['/index.php?p=news', '/news']);

for (const [legacy, expected] of legacyPairs) {
  const res = await get(legacy, { redirect: 'manual' });
  if (res.status >= 300 && res.status < 400) {
    const target = new URL(res.location, BASE);
    record(`legacy ${legacy} → 301/308`, res.status === 301 || res.status === 308, String(res.status));
    record(`legacy ${legacy} → ${expected}`, target.pathname + target.search === expected, target.pathname + target.search);
  } else {
    // A 200 is acceptable only when the page declares the canonical URL.
    const can = canonical(res.text);
    record(`legacy ${legacy} returns 200 with canonical ${expected}`, res.status === 200 && can === abs(expected), `${res.status} canonical=${can}`);
  }
}

// 6. Pages that must stay out of the index ------------------------------------
console.log('\n--- Noindex surfaces ---');
for (const path of ['/search', '/login', '/register']) {
  const res = await get(path, { redirect: 'manual' });
  if (res.status === 200) {
    const rob = metaContent(res.text, 'name', 'robots');
    record(`${path} is noindex`, /noindex/i.test(rob), rob);
  } else {
    record(`${path} resolves`, [200, 301, 302].includes(res.status), String(res.status));
  }
}

// 7. Summary ------------------------------------------------------------------
const failed = results.filter((r) => !r.ok);
console.log(`\n=== ${results.length - failed.length}/${results.length} checks passed ===`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  - ${f.label}${f.detail ? ' — ' + f.detail : ''}`);
}
process.exit(failed.length ? 1 : 0);
