// tests/deployment-smoke.mjs — full HTTP smoke test of a real deployment.
//
// Enumerates EVERY route from config/routes.php (static routes + aliases) and
// requests it in both spellings — pretty (/articles) and query
// (/index.php?p=articles) — plus dynamic detail URLs discovered from the live
// listings, and prints a Route | HTTP | Result table.
//
// Usage:
//   TEST_BASE_URL=https://jametulhoda1.vercel.app node tests/deployment-smoke.mjs
//
// Exit code 1 when any route answers 4xx/5xx where it should not, when a
// redirect loops, or when a response body leaks a PHP error / the database
// failure page.
import fs from 'node:fs';
import path from 'node:path';

const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const root = path.resolve(new URL('..', import.meta.url).pathname);

const ERROR_RE = /(Warning|Fatal error|Parse error|Deprecated|Notice):|Uncaught (Error|Exception)|شناسه پیگیری/;
const DB_FAILURE_RE = /اتصال به دیتابیس برقرار نشد|سرویس موقتاً در دسترس نیست|سایت هنوز نصب نشده است/;

/** Routes are read from the single source of truth, not from a hand-written list. */
function routesFromConfig() {
  const php = fs.readFileSync(path.join(root, 'config/routes.php'), 'utf8');
  const routesBlock = php.slice(php.indexOf("'routes' => ["), php.indexOf("'aliases' =>"));
  const aliasBlock = php.slice(php.indexOf("'aliases' =>"), php.indexOf("'patterns' =>"));
  const grab = (block) => [...block.matchAll(/'(\/[^']*)'\s*=>/g)].map((m) => m[1]);
  return { routes: grab(routesBlock), aliases: grab(aliasBlock) };
}

const rows = [];
let failures = 0;

async function hit(label, url, { expect = [200], allowRedirectTo = null } = {}) {
  let status = 0;
  let note = '';
  let body = '';
  let hops = 0;
  let target = url;
  try {
    while (hops < 5) {
      const res = await fetch(base + target, { redirect: 'manual', signal: AbortSignal.timeout(45000) });
      status = res.status;
      if ([301, 302, 303, 307, 308].includes(status)) {
        const location = res.headers.get('location') || '';
        note = `→ ${location}`;
        const next = new URL(location, base + target);
        if (next.origin !== new URL(base).origin) break;
        target = next.pathname + next.search;
        hops++;
        continue;
      }
      body = await res.text();
      break;
    }
    if (hops >= 5) {
      note = 'redirect loop';
      status = 508;
    }
  } catch (e) {
    note = `request failed: ${e.message}`;
    status = 0;
  }

  let ok = expect.includes(status);
  if (ok && ERROR_RE.test(body)) { ok = false; note = 'PHP error/trace in body'; }
  if (ok && DB_FAILURE_RE.test(body)) { ok = false; note = 'database failure page'; }
  if (!ok && allowRedirectTo && note.includes(allowRedirectTo)) ok = true;
  if (!ok) failures++;
  rows.push({ label, status, ok, note });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${String(status).padEnd(3)} ${label}${note ? ' | ' + note : ''}`);
  return { status, body, note };
}

const { routes, aliases } = routesFromConfig();

// 1) Every static route in its pretty spelling. A route's method/detail
// contract takes precedence over a blanket 200 expectation.
const postOnlyRoutes = new Set([
  '/admin/content/gallery', '/admin/content/media',
  '/admin/posts/gallery.php', '/admin/posts/media-manage.php',
]);
const bearerProtectedRoutes = new Set(['/api/mobile-auth']);
const detailRoutesWithoutId = new Set(['/book', '/speech']);
for (const route of routes) {
  if (route === '/php/migrate' || route === '/php/migrate.php') continue; // token-protected by design
  const expect = postOnlyRoutes.has(route) ? [405]
    : detailRoutesWithoutId.has(route) ? [404]
    : bearerProtectedRoutes.has(route) ? [401]
    : [200];
  await hit(`GET ${route}`, route, { expect });
}

// 2) Trailing-slash spelling for the main public listings.
for (const route of ['/articles/', '/topics/', '/news/', '/books/', '/lessons/', '/media/']) {
  await hit(`GET ${route}`, route, { expect: [200] });
}

// 3) Aliases.
for (const alias of aliases) await hit(`GET ${alias}`, alias, { expect: [200] });

// 4) Query URLs (mod_rewrite-free spelling).
for (const p of ['articles', 'topics', 'news', 'reports', 'books', 'lessons', 'research',
                 'media', 'videos', 'audios', 'video', 'audio', 'search', 'login', 'register', 'account',
                 'about', 'contact', 'speeches', 'events', 'qa']) {
  await hit(`GET /index.php?p=${p}`, `/index.php?p=${p}`, { expect: [200] });
}

// 5) Dynamic detail pages discovered from the live listings.
const discover = async (listing, re, limit = 2) => {
  const res = await fetch(base + listing, { signal: AbortSignal.timeout(45000) }).catch(() => null);
  if (!res || !res.ok) return [];
  const html = await res.text();
  return [...new Set([...html.matchAll(re)].map((m) => m[0]))].slice(0, limit);
};
const dynamic = [
  ...await discover('/articles', /\/(article|post)\/[^"'\s?#]+/g),
  ...await discover('/news', /\/news\/[^"'\s?#]+/g),
  ...await discover('/reports', /\/report\/[^"'\s?#]+/g),
  ...await discover('/topics', /\/topic\/[^"'\s?#]+/g),
  ...await discover('/books', /\/book\/[^"'\s?#]+/g),
  ...await discover('/lessons', /\/lesson\/[^"'\s?#]+/g),
  ...await discover('/videos', /\/video\/\d+/g),
  ...await discover('/audios', /\/audio\/\d+/g),
];
if (dynamic.length === 0) {
  rows.push({ label: 'dynamic detail pages', status: 0, ok: true, note: 'no content published yet (empty database)' });
  console.log('SKIP     dynamic detail pages | no content published yet');
}
for (const url of dynamic) await hit(`GET ${url}`, url, { expect: [200] });
await hit('GET /search/تست', '/search/' + encodeURIComponent('تست'), { expect: [200] });

// 6) A genuinely unknown URL must still be a real 404 (not a 503 and not a redirect).
await hit('GET /this-route-does-not-exist', '/this-route-does-not-exist', { expect: [404] });

fs.mkdirSync(path.join(root, 'test-results'), { recursive: true });
fs.writeFileSync(path.join(root, 'test-results/deployment-smoke.json'), JSON.stringify({ base, rows }, null, 2));

console.log('\n| Route | HTTP | Result |');
console.log('|---|---|---|');
for (const r of rows) console.log(`| ${r.label} | ${r.status || '-'} | ${r.ok ? 'OK' : 'FAIL'}${r.note ? ' — ' + r.note : ''} |`);
console.log(`\n${rows.length - failures}/${rows.length} passed against ${base}`);
process.exit(failures ? 1 : 0);
