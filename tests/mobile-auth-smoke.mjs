/**
 * Safe contract checks for the native auth API. This script never creates users
 * or sends real credentials; the stateful flow belongs in tests/mobile-auth-flows.mjs.
 */
const base = (process.env.TEST_BASE_URL || 'https://jametulhoda.vercel.app').replace(/\/$/, '');
const endpoint = base + '/api/mobile-auth';
const checks = [];
function check(name, ok, detail = '') {
  checks.push({ name, ok, detail });
  console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : ''));
}
async function request(method, options = {}) {
  const headers = { Accept: 'application/json' };
  if (options.token) headers.Authorization = 'Bearer ' + options.token;
  if (options.contentType) headers['Content-Type'] = options.contentType;
  const response = await fetch(endpoint, {
    method, headers, body: options.body, redirect: 'manual', signal: AbortSignal.timeout(15000),
  });
  let data = null;
  try { data = await response.json(); } catch {}
  return { response, data };
}
const unauth = await request('GET');
check('GET profile requires a bearer token', unauth.response.status === 401 && unauth.data?.ok === false, String(unauth.response.status));
check('Unauthenticated profile returns no user', !unauth.data?.user, '');
const invalid = await request('GET', { token: 'f'.repeat(64) });
check('Unknown token cannot read an account', invalid.response.status === 401, String(invalid.response.status));
const method = await request('DELETE');
check('Unsupported methods are rejected', method.response.status === 405, String(method.response.status));
const badType = await request('POST', { contentType: 'text/plain', body: '{}' });
check('Requests must send JSON', badType.response.status === 415, String(badType.response.status));
const emptyLogin = await request('POST', { contentType: 'application/json', body: JSON.stringify({ action: 'login' }) });
check('Empty login fields receive a validation response', emptyLogin.response.status === 422, String(emptyLogin.response.status));
console.log('');
console.log((checks.filter(x => x.ok).length) + '/' + checks.length + ' native auth contract checks passed');
process.exit(checks.every(x => x.ok) ? 0 : 1);
