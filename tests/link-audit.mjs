// tests/link-audit.mjs — Automated internal link checker (spec stage 23).
//
// 1. Opens the homepage (+ sitemap URLs as extra seeds).
// 2. Extracts every internal link (href/action/src/canonical/...).
// 3. Requests each link (manual redirect handling, loop detection).
// 4. Recursively discovers new links, deduplicated.
// 5. Reports 404/500/PHP-error pages together with the source page.
//
// Usage:
//   TEST_BASE_URL=http://127.0.0.1:8080 node tests/link-audit.mjs
//   TEST_BASE_URL=... TEST_ADMIN_USERNAME=admin TEST_ADMIN_PASSWORD=... node tests/link-audit.mjs
// Output: console report + test-results/link-audit.json. Exit code 1 on broken links.
import fs from 'node:fs';

const origin = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const MAX_PAGES = parseInt(process.env.LINK_AUDIT_MAX || '2000', 10);
const TIMEOUT_MS = 30000;

fs.mkdirSync('test-results', { recursive: true });

// ─── Cookie jar ──────────────────────────────────────────────────────────
const jar = new Map();
function storeCookies(headers, url) {
  const setCookies = headers.getSetCookie ? headers.getSetCookie() : [];
  for (const c of setCookies) {
    const pair = c.split(';')[0];
    const eq = pair.indexOf('=');
    if (eq > 0) jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
  }
}
function cookieHeader() {
  return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}

// ─── Fetch with manual redirect handling ─────────────────────────────────
async function fetchManual(url, { method = 'GET', body = undefined, headers = {} } = {}) {
  const chain = [];
  let current = url;
  for (let i = 0; i < 6; i++) {
    const h = { ...headers };
    const cookies = cookieHeader();
    if (cookies) h.cookie = cookies;
    const res = await fetch(current, {
      method, headers: h, body, redirect: 'manual',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    storeCookies(res.headers, current);
    chain.push({ url: current, status: res.status });
    if ([301, 302, 303, 307, 308].includes(res.status)) {
      const loc = res.headers.get('location');
      if (res.body) { try { await res.arrayBuffer(); } catch { /* ignore */ } }
      if (!loc) return { chain, finalUrl: current, status: res.status, headers: res.headers, body: Buffer.alloc(0), loop: false, noLocation: true };
      const next = new URL(loc, current).toString();
      if (chain.some((c) => c.url === next)) {
        return { chain, finalUrl: next, status: res.status, headers: res.headers, body: Buffer.alloc(0), loop: true };
      }
      current = next;
      if (res.status === 303) { method = 'GET'; body = undefined; }
      continue;
    }
    const buf = Buffer.from(await res.arrayBuffer());
    return { chain, finalUrl: current, status: res.status, headers: res.headers, body: buf, loop: false };
  }
  return { chain, finalUrl: current, status: 0, headers: new Headers(), body: Buffer.alloc(0), loop: true };
}

// ─── URL helpers ─────────────────────────────────────────────────────────
function normalizePath(path) {
  // Keep query (it selects content), drop fragment.
  const hash = path.indexOf('#');
  if (hash >= 0) path = path.slice(0, hash);
  return path || '/';
}
function isSkippable(raw) {
  const u = raw.trim().toLowerCase();
  return u === '' || u.startsWith('#') || u.startsWith('javascript:') ||
    u.startsWith('mailto:') || u.startsWith('tel:') || u.startsWith('data:');
}
function toInternalPath(raw, base) {
  if (!raw || isSkippable(raw)) return null;
  raw = raw.replaceAll('&amp;', '&').trim();
  try {
    const abs = new URL(raw, base);
    const originUrl = new URL(origin);
    if (abs.origin !== originUrl.origin) return null; // external
    return normalizePath(abs.pathname + abs.search);
  } catch { return null; }
}

// Placeholder/empty links are never acceptable (spec: no "#" or fake links).
function findPlaceholders(html, pagePath) {
  const out = [];
  for (const m of html.matchAll(/href\s*=\s*["']#["']/gi)) {
    out.push({ path: pagePath + ' [href="#"]', source: pagePath, status: 200, reason: 'placeholder href="#" link' });
  }
  for (const m of html.matchAll(/(?:href|action)\s*=\s*["']([^"']*)["']/gi)) {
    const raw = m[1].replaceAll('&amp;', '&');
    // An empty slug/id/collection/volume selects nothing (wrong destination).
    // An empty q/type/status is a harmless cleared filter — not flagged.
    if (/[?&](slug|id|collection|volume)=(&|$)/.test(raw)) {
      out.push({ path: raw, source: pagePath, status: 200, reason: 'link with empty slug/id parameter' });
    }
  }
  return out;
}

// Extract candidate links from HTML.
function extractLinks(html, base) {
  const found = new Set();
  const patterns = [
    /href\s*=\s*"([^"]*)"/gi,
    /href\s*=\s*'([^']*)'/gi,
    /action\s*=\s*"([^"]*)"/gi,
    /action\s*=\s*'([^']*)'/gi,
    /src\s*=\s*"([^"]*)"/gi,
    /poster\s*=\s*"([^"]*)"/gi,
    /data-src\s*=\s*"([^"]*)"/gi,
    /data-video\s*=\s*"([^"]*)"/gi,
    /content\s*=\s*"([^"]*\/search[^"]*)"/gi, // JSON-LD SearchAction target
  ];
  for (const re of patterns) {
    for (const m of html.matchAll(re)) {
      const p = toInternalPath(m[1], base);
      if (p) found.add(p);
    }
  }
  // srcset="a 1x, b 2x"
  for (const m of html.matchAll(/srcset\s*=\s*"([^"]*)"/gi)) {
    for (const part of m[1].split(',')) {
      const p = toInternalPath(part.trim().split(/\s+/)[0] || '', base);
      if (p) found.add(p);
    }
  }
  return [...found];
}

const PHP_ERROR_RE = /(Warning|Fatal error|Parse error|Deprecated|Notice):|شناسه پیگیری|Uncaught (Error|Exception)/;

// ─── Crawl ───────────────────────────────────────────────────────────────
const seen = new Map(); // path -> record
const queue = [];
const broken = [];
const enqueue = (p, source) => {
  if (!seen.has(p) && !queue.some((q) => q.path === p)) queue.push({ path: p, source });
};

async function loginIfConfigured() {
  const user = process.env.TEST_ADMIN_USERNAME;
  const pass = process.env.TEST_ADMIN_PASSWORD;
  if (!user || !pass) {
    console.log('[audit] no admin credentials — auditing public pages only');
    return false;
  }
  const page = await fetchManual(origin + '/admin/login');
  const html = page.body.toString('utf8');
  const csrf = html.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
  if (!csrf) { console.log('[audit] admin login page has no CSRF token — skipping admin crawl'); return false; }
  const res = await fetchManual(origin + '/admin/login', {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ identifier: user, password: pass, csrf_token: csrf }).toString(),
  });
  const ok = res.chain.some((c) => c.status === 303 || c.status === 302);
  console.log(ok ? '[audit] admin login OK — admin pages included' : `[audit] admin login FAILED (status ${res.status})`);
  return ok;
}

async function seedFromSitemap() {
  try {
    const res = await fetchManual(origin + '/sitemap.xml');
    if (res.status !== 200) { console.log(`[audit] sitemap.xml status ${res.status} — skipped as seed`); return; }
    const xml = res.body.toString('utf8');
    let n = 0;
    for (const m of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) {
      const p = toInternalPath(m[1].trim(), origin + '/');
      if (p) { enqueue(p, 'sitemap.xml'); n++; }
    }
    console.log(`[audit] seeded ${n} URLs from sitemap.xml`);
  } catch (e) { console.log('[audit] sitemap fetch failed:', String(e).slice(0, 120)); }
}

const adminLoggedIn = await loginIfConfigured();
enqueue('/', '(start)');
if (adminLoggedIn) enqueue('/admin/', '(start)');
await seedFromSitemap();

let crawled = 0;
while (queue.length && crawled < MAX_PAGES) {
  const { path, source } = queue.shift();
  if (seen.has(path)) continue;
  const url = origin + path;
  let rec;
  try {
    const res = await fetchManual(url);
    const ctype = res.headers.get('content-type') || '';
    const isHtml = ctype.includes('text/html');
    const text = isHtml ? res.body.toString('utf8') : '';
    const phpError = isHtml && PHP_ERROR_RE.test(text) ? (text.match(PHP_ERROR_RE)?.[0] || 'php-error') : null;
    const empty200 = res.status === 200 && isHtml && res.body.length < 200;
    rec = {
      path, source, status: res.status, finalUrl: res.finalUrl,
      redirects: res.chain.length - 1, loop: res.loop, contentType: ctype.split(';')[0],
      bytes: res.body.length, phpError, empty200,
    };
    if (res.loop || res.status >= 400 || phpError) {
      broken.push({
        path, source, status: res.status, finalUrl: res.finalUrl,
        reason: res.loop ? 'redirect loop' : phpError ? `page contains "${phpError}"` : `HTTP ${res.status}`,
      });
    } else if (isHtml && res.status < 400) {
      for (const ph of findPlaceholders(text, path)) broken.push(ph);
      for (const link of extractLinks(text, url)) {
        enqueue(link, path);
      }
    }
  } catch (e) {
    rec = { path, source, status: 0, error: String(e).slice(0, 200) };
    broken.push({ path, source, status: 0, reason: `fetch failed: ${String(e).slice(0, 120)}` });
  }
  seen.set(path, rec);
  crawled++;
  if (crawled % 50 === 0) console.log(`[audit] crawled ${crawled}, queued ${queue.length}, broken ${broken.length}`);
}

// ─── Report ──────────────────────────────────────────────────────────────
const byStatus = {};
for (const r of seen.values()) byStatus[r.status] = (byStatus[r.status] || 0) + 1;
console.log('\n================ LINK AUDIT ================');
console.log(`Base URL:        ${origin}`);
console.log(`Pages checked:   ${seen.size}`);
console.log(`Status mix:      ${JSON.stringify(byStatus)}`);
console.log(`Broken:          ${broken.length}`);
for (const b of broken.slice(0, 100)) {
  console.log(`\nBROKEN LINK\n  Source: ${b.source}\n  Target: ${b.path}\n  Status: ${b.status}\n  Reason: ${b.reason}`);
}
if (broken.length > 100) console.log(`... and ${broken.length - 100} more`);
const report = { origin, checked: seen.size, byStatus, broken, pages: [...seen.values()] };
fs.writeFileSync('test-results/link-audit.json', JSON.stringify(report, null, 2));
console.log('\nFull report: test-results/link-audit.json');
if (broken.length) process.exitCode = 1;
else console.log('OK: no broken internal links.');
