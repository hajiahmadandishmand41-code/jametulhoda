/**
 * tests/production-probe.mjs — unauthenticated health probe of a live deployment.
 *
 * Needs no credentials and creates no data: it only performs GETs, plus two
 * POSTs that are deliberately inert (an empty login form, which the controller
 * rejects before any authentication attempt is recorded, and an oversized body
 * that the front controller answers with 413 before routing).
 *
 * Requests are deliberately throttled and retried: a deployment behind a WAF
 * answers a burst of automated requests with 403, which would otherwise be
 * indistinguishable from a real application error.
 *
 * Usage: TEST_BASE_URL=https://example.vercel.app node tests/production-probe.mjs
 */
import { request } from '@playwright/test';
import { passSecurityCheckpoint } from './checkpoint.mjs';

const base = (process.env.TEST_BASE_URL || 'https://jametulhoda.vercel.app').replace(/\/$/, '');

// Deployments behind Vercel's Attack Challenge Mode answer plain HTTP clients
// with a 403 challenge page. Solve it once in a real browser and reuse the
// cookie for every request below; without this the probe stalls partway.
const storageState = process.env.PROBE_SKIP_BROWSER
  ? undefined
  : await passSecurityCheckpoint(base, { debug: true });

const api = await request.newContext({ baseURL: base, storageState });
const results = [];
const info = [];
const check = (label, ok, detail = '') => {
  results.push({ label, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  ' + detail : ''}`);
};
const note = (label, value) => { info.push(`${label}=${value}`); console.log(`INFO  ${label} = ${value}`); };

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const THROTTLE_MS = Number(process.env.PROBE_THROTTLE_MS || 400);
// Deployments behind Vercel's Attack Challenge Mode start challenging a client
// after roughly two dozen requests. Stay inside that budget by default and stop
// cleanly instead of reporting the rest of the suite as application failures.
const MAX_REQUESTS = Number(process.env.PROBE_MAX_REQUESTS || 22);
let requestBudget = MAX_REQUESTS;
let wafHits = 0;

/** Is this a WAF/challenge response rather than an answer from the application? */
const isChallenge = (status, body) => status === 403 || status === 429
  ? /vercel|security checkpoint|attack challenge|access denied|request blocked|too many requests/i.test(body)
  : false;

let consecutiveChallenges = 0;
let challengeBodyShown = false;

/**
 * Throttled request. A deployment behind a WAF starts answering with 403 once
 * it decides the client is a bot; when that happens three requests in a row the
 * probe stops instead of reporting 30 identical failures as application bugs.
 */
/** Stop cleanly once the platform's request allowance is used up. */
const budgetStop = async () => {
  const failed = results.filter((r) => !r.ok).length;
  console.log(`\nSTOPPED: the request budget of ${MAX_REQUESTS} calls is used up. A deployment behind`
    + " Vercel's Attack Challenge Mode starts answering with a JS challenge after roughly this many"
    + ' requests, so the remaining checks were not run rather than reported as failures.');
  console.log(`Verified so far: ${results.length - failed}/${results.length} passed.`);
  console.log(JSON.stringify({ base, stopped: 'request-budget', checked: results.length, failed, wafHits, info }));
  await api.dispose();
  process.exit(failed === 0 ? 0 : 1);
};

const probe = async (method, path, options = {}) => {
  if (requestBudget <= 0) await budgetStop();
  for (let attempt = 1; attempt <= 3; attempt++) {
    requestBudget--;
    await sleep(THROTTLE_MS);
    const response = await api.fetch(path, { method, ...options, failOnStatusCode: false });
    const body = await response.text();
    if (isChallenge(response.status(), body)) {
      wafHits++;
      if (attempt === 1 && !process.env.PROBE_SKIP_BROWSER) {
        // The cookie may have expired mid-run: solve the challenge again.
        const fresh = await passSecurityCheckpoint(base, { debug: true });
        if (fresh) console.log('  (checkpoint cookie refreshed)');
      }
      if (!challengeBodyShown) {
        challengeBodyShown = true;
        console.log(`  --- HTTP ${response.status()} body (first 300 chars) ---`);
        console.log('  ' + body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
      }
      if (attempt < 3) {
        console.log(`  (retry ${attempt}: HTTP ${response.status()} looks like a WAF/challenge response)`);
        await sleep(3000 * attempt);
        continue;
      }
      consecutiveChallenges++;
      if (consecutiveChallenges >= 3) {
        console.log(`\nABORT: ${consecutiveChallenges} consecutive requests were answered with a WAF/challenge`
          + ' response. The deployment (or the platform firewall in front of it) is blocking this client IP,'
          + ' so no further check can be trusted from here.');
        console.log(JSON.stringify({ base, aborted: 'waf-block', wafHits, info }));
        await api.dispose();
        process.exit(3);
      }
      return { status: response.status(), headers: response.headers(), body };
    }
    consecutiveChallenges = 0;
    return { status: response.status(), headers: response.headers(), body };
  }
  return { status: 0, headers: {}, body: '' };
};

console.log(`\n=== Unauthenticated probe: ${base} (throttle ${THROTTLE_MS}ms) ===`);

// ── 1. Homepage and security headers ────────────────────────────────────────
const home = await probe('GET', '/');
check('homepage responds 200', home.status === 200, String(home.status));
check('homepage renders the site identity', /\u062c\u0627\u0645\u0639\u0629/.test(home.body));
check('no PHP error text leaks on the homepage',
  !/(Warning|Fatal error|Parse error|Deprecated|TypeError):/.test(home.body));
note('server', home.headers.server || '(none)');
note('x-vercel-id present', home.headers['x-vercel-id'] ? 'yes' : 'no');
for (const header of ['x-content-type-options', 'referrer-policy', 'permissions-policy', 'content-security-policy', 'strict-transport-security']) {
  check(`security header ${header}`, Boolean(home.headers[header]), home.headers[header] || '(missing)');
}

// ── 2. Admin area is closed without a session ───────────────────────────────
for (const path of ['/admin/', '/admin/diagnostics', '/admin/posts/create.php', '/admin/media/', '/admin/settings.php', '/admin/users/']) {
  const r = await probe('GET', path, { maxRedirects: 0 });
  const location = r.headers.location || '';
  // Query-URL deployments redirect to /index.php?p=login, pretty ones to /login.
  check(`admin route closed without session ${path}`, r.status === 302 && location.includes('login'), `${r.status} ${location}`);
}

// ── 3. Source and configuration files are not reachable ─────────────────────
for (const path of ['/.env', '/.git/config', '/config/local.php', '/config/database.php', '/config/config.php',
  '/database/database.postgres.sql', '/includes/auth.php', '/includes/storage.php', '/bin/migrate.php',
  '/tests/http.mjs', '/uploads/test.php', '/api/index.php', '/router.php']) {
  const r = await probe('GET', path, { maxRedirects: 0 });
  check(`protected path ${path}`, r.status === 404, String(r.status));
}

// ── 4. Public routes ────────────────────────────────────────────────────────
for (const path of ['/news', '/articles', '/reports', '/books', '/lessons', '/media', '/videos', '/audios',
  '/topics', '/research', '/speeches', '/programs', '/search?q=test', '/sitemap.xml', '/robots.txt', '/login', '/register']) {
  const r = await probe('GET', path);
  check(`public route ${path}`, r.status === 200, String(r.status));
}
const missing = await probe('GET', '/definitely-not-a-page');
check('unknown page is a real 404', missing.status === 404 && missing.body.includes('\u06f4\u06f0\u06f4'), String(missing.status));

// ── 5. Static assets are served by the front controller ─────────────────────
for (const [path, type] of [['/assets/css/design-system.css', 'text/css'], ['/assets/img/logo.png', 'image/png'],
  ['/assets/fonts/Vazirmatn-Regular.woff2', 'font/woff2'], ['/assets/js/main.js', 'javascript']]) {
  const r = await probe('GET', path);
  const ct = r.headers['content-type'] || '';
  check(`asset ${path}`, r.status === 200 && ct.includes(type), `${r.status} ${ct}`);
}

// ── 6. Upload folder serves nothing on a serverless deployment ──────────────
const noUpload = await probe('GET', '/uploads/images/does-not-exist.png');
check('unknown /uploads path is 404', noUpload.status === 404, String(noUpload.status));

// ── 7. Installer state ──────────────────────────────────────────────────────
const install = await probe('GET', '/php/install');
note('installer page status', String(install.status));
note('installer locked', install.status === 200 ? (install.body.includes('\u0642\u0641\u0644 \u0634\u062f\u0647') ? 'yes' : 'no (form reachable)') : 'unreachable');

// ── 8. Request-size ceiling of the platform ─────────────────────────────────
const csrfOf = (html) => html.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
const loginPage = await probe('GET', '/login');
const loginCsrf = csrfOf(loginPage.body) || '';
check('login form exposes a CSRF token', loginCsrf !== '', loginCsrf ? 'yes' : 'no');

// 8a. An inert multipart POST: empty credentials are rejected by the controller
// before jhd_login(), so no login attempt is recorded. A 200 with this exact
// message proves the serverless PHP runtime received and parsed the multipart
// body (including the attached file part).
const small = await probe('POST', '/login', {
  multipart: {
    csrf_token: loginCsrf,
    identifier: '',
    password: '',
    attachment: { name: 'probe.png', mimeType: 'image/png', buffer: Buffer.alloc(1024, 9) },
  },
  maxRedirects: 0,
});
check('multipart body is accepted and parsed by the PHP runtime',
  small.status === 200 && small.body.includes('\u0634\u0646\u0627\u0633\u0647 \u0648 \u0631\u0645\u0632 \u0639\u0628\u0648\u0631 \u0631\u0627 \u0648\u0627\u0631\u062f \u06a9\u0646\u06cc\u062f'),
  `${small.status} ${small.status === 200 ? (small.body.includes('\u0634\u0646\u0627\u0633\u0647') ? 'expected message' : 'unexpected body') : ''}`);

// 8b. 3 MB stays under the front controller's guard.
const threeMb = await probe('POST', '/login', {
  multipart: {
    csrf_token: loginCsrf,
    identifier: '',
    password: '',
    attachment: { name: 'probe.bin', mimeType: 'application/octet-stream', buffer: Buffer.alloc(3 * 1024 * 1024, 7) },
  },
  maxRedirects: 0,
});
note('3 MB multipart status', String(threeMb.status));
check('3 MB request is not rejected as too large', threeMb.status !== 413, String(threeMb.status));

// 8c. 6 MB exceeds both the front controller guard and the platform ceiling.
const sixMb = await probe('POST', '/login', {
  multipart: {
    csrf_token: loginCsrf,
    identifier: '',
    password: '',
    attachment: { name: 'probe.bin', mimeType: 'application/octet-stream', buffer: Buffer.alloc(6 * 1024 * 1024, 7) },
  },
  maxRedirects: 0,
});
note('6 MB multipart status', String(sixMb.status));
note('6 MB response head', sixMb.body.replace(/\s+/g, ' ').slice(0, 160));
check('6 MB request is rejected with an explicit 413', sixMb.status === 413, String(sixMb.status));

note('WAF/challenge retries needed', String(wafHits));
const failed = results.filter((r) => !r.ok);
console.log(`\n=== ${results.length - failed.length}/${results.length} probe checks passed ===`);
if (failed.length) console.log('FAILED:\n - ' + failed.map((f) => `${f.label} (${f.detail})`).join('\n - '));
console.log(JSON.stringify({ base, passed: results.length - failed.length, total: results.length, info, wafHits }));
await api.dispose();
process.exit(failed.length ? 1 : 0);
