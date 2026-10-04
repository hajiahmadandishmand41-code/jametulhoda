/**
 * tests/auth-session-smoke.mjs — guards the lazy-session change.
 *
 * The optimisation stopped creating a database session for anonymous page
 * views. These checks assert the two halves of that contract:
 *   • a public page really does NOT issue a session cookie, and
 *   • everything that needs session state (CSRF forms, login, POST) still
 *     gets a real session and still rejects what it used to reject.
 *
 * Usage: TEST_BASE_URL=http://127.0.0.1:8099 node tests/auth-session-smoke.mjs
 */
const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8099').replace(/\/$/, '');

let failures = 0;
const check = (label, ok, detail = '') => {
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);
};

const get = (path, headers = {}) =>
  fetch(base + path, { redirect: 'manual', headers });

// ─── public pages must not create a session ─────────────────────────────────
for (const path of ['/', '/news', '/articles', '/books', '/topics', '/about']) {
  const res = await get(path);
  const setCookie = res.headers.get('set-cookie') || '';
  check(`${path} responds 200`, res.status === 200, `status=${res.status}`);
  check(`${path} issues no session cookie`, !/jamiat_session/.test(setCookie), setCookie.slice(0, 80));
}

// ─── a page that renders a CSRF-protected form still gets a session ─────────
const contact = await get('/contact');
const contactBody = await contact.text();
check('/contact responds 200', contact.status === 200, `status=${contact.status}`);
check('/contact still renders a csrf_token field', /name="csrf_token"\s+value="[a-f0-9]{64}"/.test(contactBody));
check('/contact issues a session cookie', /jamiat_session/.test(contact.headers.get('set-cookie') || ''));

// ─── login: GET gives a form + session, POST still enforces CSRF ────────────
const loginGet = await get('/login');
const loginBody = await loginGet.text();
const cookie = (loginGet.headers.get('set-cookie') || '').split(';')[0];
const token = (loginBody.match(/name="csrf_token"\s+value="([a-f0-9]{64})"/) || [])[1];
check('/login responds 200', loginGet.status === 200, `status=${loginGet.status}`);
check('/login issues a session cookie', /jamiat_session/.test(cookie), cookie);
check('/login renders a CSRF token', Boolean(token));

const postLogin = async (body, withCookie = true) =>
  fetch(base + '/login', {
    method: 'POST',
    redirect: 'manual',
    headers: {
      'content-type': 'application/x-www-form-urlencoded',
      ...(withCookie && cookie ? { cookie } : {}),
    },
    body: new URLSearchParams(body).toString(),
  });

const noCsrf = await postLogin({ identifier: 'nobody@example.com', password: 'wrong-password' });
check('POST /login without a CSRF token is refused', [400, 403, 419].includes(noCsrf.status) || (await noCsrf.clone().text()).includes('نامعتبر'), `status=${noCsrf.status}`);

const badCreds = await postLogin({ csrf_token: token || '', identifier: 'nobody@example.com', password: 'definitely-wrong' });
const badBody = await badCreds.text();
check('POST /login with wrong credentials does not 5xx', badCreds.status < 500, `status=${badCreds.status}`);
check('POST /login with wrong credentials does not sign anyone in', !/\/admin\/dashboard/.test(badCreds.headers.get('location') || ''), badCreds.headers.get('location') || 'no redirect');
check('POST /login with wrong credentials shows an error, not a stack trace', !/Fatal error|Uncaught|SQLSTATE/.test(badBody));

// ─── admin stays closed, and redirects exactly once ─────────────────────────
const admin = await get('/admin');
check('/admin redirects anonymous visitors', admin.status === 302, `status=${admin.status}`);
check('/admin redirects to the login page', /\/login/.test(admin.headers.get('location') || ''), admin.headers.get('location') || '');

const adminDash = await get('/admin/dashboard');
check('/admin/dashboard is closed to anonymous visitors', [301, 302, 303, 307].includes(adminDash.status), `status=${adminDash.status}`);

// ─── a returning visitor who carries a cookie still gets their session ──────
const withCookie = await get('/', cookie ? { cookie } : {});
check('/ with a session cookie still responds 200', withCookie.status === 200, `status=${withCookie.status}`);
check('/ with a session cookie is not shared-cached', /no-cache/.test(withCookie.headers.get('cache-control') || ''), withCookie.headers.get('cache-control') || '');

console.log('');
console.log(failures === 0 ? 'ALL AUTH/SESSION CHECKS PASSED' : `${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
