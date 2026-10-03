/**
 * tests/production-probe.mjs — unauthenticated health probe of a live deployment.
 *
 * Needs no credentials and creates no data: it only performs GETs, plus two
 * POSTs that are deliberately inert (an empty login form, which the controller
 * rejects before any authentication attempt is recorded, and an oversized body
 * that the front controller answers with 413 before routing).
 *
 * It exists so the deployment can be verified from CI even when the admin
 * credentials are not available as secrets.
 *
 * Usage: TEST_BASE_URL=https://example.vercel.app node tests/production-probe.mjs
 */
import { request } from '@playwright/test';

const base = (process.env.TEST_BASE_URL || 'https://jametulhoda.vercel.app').replace(/\/$/, '');
const api = await request.newContext({ baseURL: base });
const results = [];
const info = [];
const check = (label, ok, detail = '') => {
  results.push({ label, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  ' + detail : ''}`);
};
const note = (label, value) => { info.push(`${label}=${value}`); console.log(`INFO  ${label} = ${value}`); };

console.log(`\n=== Unauthenticated probe: ${base} ===`);

// ── 1. Homepage and security headers ────────────────────────────────────────
const home = await api.get('/');
const homeHtml = await home.text();
check('homepage responds 200', home.status() === 200, String(home.status()));
check('homepage renders the site identity', homeHtml.includes('جامعة‌الهدی'));
check('no PHP error text leaks on the homepage',
  !/(Warning|Fatal error|Parse error|Deprecated|TypeError):/.test(homeHtml));
const hh = home.headers();
note('server', hh.server || '(none)');
note('x-vercel-id present', hh['x-vercel-id'] ? 'yes' : 'no');
for (const header of ['x-content-type-options', 'referrer-policy', 'permissions-policy', 'content-security-policy', 'strict-transport-security']) {
  check(`security header ${header}`, Boolean(hh[header]), hh[header] || '(missing)');
}

// ── 2. Admin area is closed without a session ───────────────────────────────
for (const path of ['/admin/', '/admin/diagnostics', '/admin/posts/create.php', '/admin/media/', '/admin/settings.php', '/admin/users/']) {
  const r = await api.get(path, { maxRedirects: 0 });
  const location = r.headers()['location'] || '';
  check(`admin route closed without session ${path}`, r.status() === 302 && location.includes('/login'), `${r.status()} ${location}`);
}

// ── 3. Source and configuration files are not reachable ─────────────────────
for (const path of ['/.env', '/.git/config', '/config/local.php', '/config/database.php', '/config/config.php',
  '/database/database.postgres.sql', '/includes/auth.php', '/includes/storage.php', '/bin/migrate.php',
  '/tests/http.mjs', '/uploads/test.php', '/api/index.php', '/router.php']) {
  const r = await api.get(path, { maxRedirects: 0 });
  check(`protected path ${path}`, r.status() === 404, String(r.status()));
}

// ── 4. Public routes ────────────────────────────────────────────────────────
for (const path of ['/news', '/articles', '/reports', '/books', '/lessons', '/media', '/videos', '/audios',
  '/topics', '/research', '/speeches', '/programs', '/search?q=test', '/sitemap.xml', '/robots.txt', '/login', '/register']) {
  const r = await api.get(path);
  check(`public route ${path}`, r.status() === 200, String(r.status()));
}
const missing = await api.get('/definitely-not-a-page');
const missingHtml = await missing.text();
check('unknown page is a real 404', missing.status() === 404 && missingHtml.includes('۴۰۴'), String(missing.status()));

// ── 5. Static assets are served by the front controller ─────────────────────
for (const [path, type] of [['/assets/css/design-system.css', 'text/css'], ['/assets/img/logo.png', 'image/png'],
  ['/assets/fonts/Vazirmatn-Regular.woff2', 'font/woff2'], ['/assets/js/main.js', 'javascript']]) {
  const r = await api.get(path);
  const ct = r.headers()['content-type'] || '';
  check(`asset ${path}`, r.status() === 200 && ct.includes(type), `${r.status()} ${ct}`);
}

// ── 6. Upload folder serves nothing on a serverless deployment ──────────────
const noUpload = await api.get('/uploads/images/does-not-exist.png');
check('unknown /uploads path is 404', noUpload.status() === 404, String(noUpload.status()));

// ── 7. Installer state ──────────────────────────────────────────────────────
const install = await api.get('/php/install');
const installHtml = await install.text();
note('installer page status', String(install.status()));
note('installer locked', installHtml.includes('قفل شده') ? 'yes' : 'no (form reachable)');

// ── 8. Request-size ceiling of the platform ─────────────────────────────────
const csrfOf = (html) => html.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
const loginCsrf = csrfOf(await (await api.get('/login')).text());

// 8a. An inert multipart POST: empty credentials are rejected by the controller
// before jhd_login(), so no login attempt is recorded. A 200 with this exact
// message proves the serverless PHP runtime received and parsed the multipart
// body (including the attached file part).
const small = await api.post('/login', {
  multipart: {
    csrf_token: loginCsrf || '',
    identifier: '',
    password: '',
    attachment: { name: 'probe.png', mimeType: 'image/png', buffer: Buffer.alloc(1024, 9) },
  },
  maxRedirects: 0,
});
const smallHtml = await small.text();
check('multipart body is accepted and parsed by the PHP runtime',
  small.status() === 200 && smallHtml.includes('شناسه و رمز عبور را وارد کنید'),
  `${small.status()} ${smallHtml.includes('شناسه و رمز عبور را وارد کنید') ? 'expected message' : 'unexpected body'}`);

// 8b. 3 MB stays under the front controller's guard.
const threeMb = await api.post('/login', {
  multipart: {
    csrf_token: loginCsrf || '',
    identifier: '',
    password: '',
    attachment: { name: 'probe.bin', mimeType: 'application/octet-stream', buffer: Buffer.alloc(3 * 1024 * 1024, 7) },
  },
  maxRedirects: 0,
  failOnStatusCode: false,
});
note('3 MB multipart status', String(threeMb.status()));
check('3 MB request is not rejected as too large', threeMb.status() !== 413, String(threeMb.status()));

// 8c. 6 MB exceeds both the front controller guard and the platform ceiling.
const sixMb = await api.post('/login', {
  multipart: {
    csrf_token: loginCsrf || '',
    identifier: '',
    password: '',
    attachment: { name: 'probe.bin', mimeType: 'application/octet-stream', buffer: Buffer.alloc(6 * 1024 * 1024, 7) },
  },
  maxRedirects: 0,
  failOnStatusCode: false,
});
note('6 MB multipart status', String(sixMb.status()));
check('6 MB request is rejected with an explicit 413', sixMb.status() === 413, String(sixMb.status()));

const failed = results.filter((r) => !r.ok);
console.log(`\n=== ${results.length - failed.length}/${results.length} probe checks passed ===`);
if (failed.length) console.log('FAILED:\n - ' + failed.map((f) => `${f.label} (${f.detail})`).join('\n - '));
console.log(JSON.stringify({ base, passed: results.length - failed.length, total: results.length, info }));
await api.dispose();
process.exit(failed.length ? 1 : 0);
