/**
 * Native token API integration flow.
 * Run only against an isolated local SQLite fixture:
 * TEST_BASE_URL=http://127.0.0.1:8081 node tests/mobile-auth-flows.mjs
 *
 * Creates one disposable test user. Never point this test at Production.
 */
const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
if (/vercel\.app$/i.test(new URL(base).hostname) || process.env.APP_ENV === 'production') {
  throw new Error('Refusing to create test accounts on a production host.');
}
const endpoint = base + '/api/mobile-auth';
const suffix = (Date.now().toString() + Math.floor(Math.random() * 100000).toString()).slice(-14);
const email = 'mobile-' + suffix + '@example.test';
const phone = '70' + suffix.slice(-8);
const password = 'Mobile!' + suffix + 'Aa';
const nextPassword = 'Changed!' + suffix + 'Zz';
const results = [];
let failures = 0;

function check(label, ok, detail = '') {
  results.push({ label, ok, detail });
  console.log((ok ? 'PASS ' : 'FAIL ') + label + (detail ? ' — ' + detail : ''));
  if (!ok) failures++;
}
async function call(method, options = {}) {
  const headers = { Accept: 'application/json' };
  if (options.token) headers.Authorization = 'Bearer ' + options.token;
  const contentType = options.contentType || 'application/json';
  if (options.body !== undefined && options.body !== null) headers['Content-Type'] = contentType;
  const body = options.body === undefined || options.body === null ? undefined
    : contentType === 'application/json' ? JSON.stringify(options.body) : String(options.body);
  const response = await fetch(endpoint, {
    method, headers, body, redirect: 'manual', signal: AbortSignal.timeout(15000),
  });
  let json = null;
  try { json = await response.json(); } catch {}
  return { response, json };
}

// Contract and validation checks before creating any account.
{
  const { response, json } = await call('GET');
  check('GET without bearer token returns 401 JSON', response.status === 401 && json?.ok === false, String(response.status));
  const invalid = await call('GET', { token: 'a'.repeat(64) });
  check('Unknown access token cannot authenticate', invalid.response.status === 401, String(invalid.response.status));
  const wrongMethod = await fetch(endpoint, { method: 'PUT', redirect: 'manual' });
  check('Unsupported method returns 405', wrongMethod.status === 405, String(wrongMethod.status));
  const badType = await call('POST', { body: '{}', contentType: 'text/plain' });
  check('Non-JSON request body returns 415', badType.response.status === 415, String(badType.response.status));
  const emptyLogin = await call('POST', { body: { action: 'login', identifier: '', password: '' } });
  check('Login validates required fields', emptyLogin.response.status === 422, String(emptyLogin.response.status));
}

// Registration must create only a normal user and return tokens without a browser session cookie.
const registration = await call('POST', { body: {
  action: 'register', full_name: 'عضو آزمون API موبایل', country: 'AF', phone, email,
  password, password_confirm: password, agreed_terms: true,
}});
const registered = registration.json;
check('Valid registration returns 201', registration.response.status === 201 && registered?.ok === true, String(registration.response.status));
check('Registration role stays user', registered?.user?.role === 'user', String(registered?.user?.role));
check('Registration never returns a password/hash', !!registered?.user && !('password' in registered.user) && !('password_hash' in registered.user), '');
check('Registration issues short-lived access and refresh tokens',
  /^[a-f0-9]{64}$/.test(registered?.access_token ?? '') &&
  /^[a-f0-9]{64}$/.test(registered?.refresh_token ?? '') &&
  registered?.expires_in === 900 && registered?.refresh_expires_in === 2592000, '');

let access = registered?.access_token ?? '';
let refresh = registered?.refresh_token ?? '';
{
  const { response, json } = await call('GET', { token: access });
  check('Bearer access token reads only public profile', response.status === 200 && json?.user?.email === email && json?.user?.role === 'user', String(response.status));
  check('Profile excludes password fields', !!json?.user && !('password' in json.user) && !('auth_version' in json.user), '');
}

// Refresh is one-use. Replaying the old refresh token revokes the entire device family.
const refreshResult = await call('POST', { body: { action: 'refresh', refresh_token: refresh } });
check('Refresh rotates both tokens', refreshResult.response.status === 200 && refreshResult.json?.ok === true &&
  refreshResult.json?.refresh_token !== refresh && refreshResult.json?.access_token !== access, String(refreshResult.response.status));
const oldRefreshReplay = await call('POST', { body: { action: 'refresh', refresh_token: refresh } });
check('Replaying a rotated refresh token is rejected', oldRefreshReplay.response.status === 401, String(oldRefreshReplay.response.status));
const familyRevoked = await call('GET', { token: refreshResult.json?.access_token ?? '' });
check('Refresh-token reuse revokes the family', familyRevoked.response.status === 401, String(familyRevoked.response.status));

// Login creates a fresh family and password changes revoke old credentials via auth_version.
const login = await call('POST', { body: { action: 'login', identifier: email, password } });
check('Correct credentials log in without a browser flow', login.response.status === 200 && login.json?.ok === true, String(login.response.status));
access = login.json?.access_token ?? '';
const oldAccess = access;
const changed = await call('POST', { token: access, body: {
  action: 'change_password', current_password: password, new_password: nextPassword, password_confirm: nextPassword,
}});
check('Password change returns a fresh token pair', changed.response.status === 200 &&
  /^[a-f0-9]{64}$/.test(changed.json?.access_token ?? '') &&
  changed.json?.access_token !== oldAccess, String(changed.response.status));
const stale = await call('GET', { token: oldAccess });
check('Password change invalidates the old access token', stale.response.status === 401, String(stale.response.status));
access = changed.json?.access_token ?? '';
const afterChange = await call('GET', { token: access });
check('New access token works after password change', afterChange.response.status === 200, String(afterChange.response.status));

// Logout is idempotent and revokes the current family.
const logout = await call('POST', { token: access, body: { action: 'logout' } });
check('Logout reports success', logout.response.status === 200 && logout.json?.ok === true, String(logout.response.status));
const afterLogout = await call('GET', { token: access });
check('Logged-out access token is rejected', afterLogout.response.status === 401, String(afterLogout.response.status));

// The new password is the only valid password for the account.
const oldPasswordLogin = await call('POST', { body: { action: 'login', identifier: email, password } });
check('Old password fails after password change', oldPasswordLogin.response.status === 401, String(oldPasswordLogin.response.status));
const newPasswordLogin = await call('POST', { body: { action: 'login', identifier: email, password: nextPassword } });
check('New password succeeds after password change', newPasswordLogin.response.status === 200 && newPasswordLogin.json?.ok === true, String(newPasswordLogin.response.status));
if (newPasswordLogin.json?.access_token) {
  await call('POST', { token: newPasswordLogin.json.access_token, body: { action: 'logout' } });
}

console.log('');
console.log((results.length - failures) + '/' + results.length + ' mobile auth integration checks passed');
process.exit(failures ? 1 : 0);
