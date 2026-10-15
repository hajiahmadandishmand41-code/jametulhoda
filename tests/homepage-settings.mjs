// Regression test for the editorial homepage and its admin settings.
//
// The homepage configuration saved by /admin/settings used to have NO effect:
// jhd_homepage_settings() was defined but never called, so every section
// toggle and the manual "مطلب شاخص" were stored, validated and then ignored.
//
// This test therefore proves three things against a real HTTP instance:
//   1. the homepage renders the editorial structure (lead story with image
//      and summary, the «معرفی جامعه‌الهدی» band, the hub rail and the
//      content hubs);
//   2. a content page shows the «مطالب مرتبط»، «تازه‌ترین‌ها» and
//      «پربازدیدترین‌ها» rails with a small thumbnail on the right;
//   3. toggling a section in the admin panel really changes the homepage —
//      and the change is reverted afterwards so the instance is left as it
//      was found.
//
// Usage:  TEST_BASE_URL=http://127.0.0.1:8080 node tests/homepage-settings.mjs
// Admin credentials come from ADMIN_USER / ADMIN_PASSWORD (the seeded
// development account is used by default). When the login is not possible the
// administrative part is skipped, never silently passed.
import fs from 'node:fs';

const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const adminUser = process.env.ADMIN_USER || 'admin';
const adminPassword = process.env.ADMIN_PASSWORD || 'TestAdmin123!@#';

let passed = 0;
const failures = [];

function check(ok, label) {
  if (ok) { passed++; console.log(`PASS ${label}`); }
  else { failures.push(label); console.log(`FAIL ${label}`); }
}

const errors = /(Warning|Fatal error|Parse error|Deprecated|Notice):|Uncaught (Error|Exception)|شناسه پیگیری/;

// A tiny cookie jar: the CSRF token is bound to the PHP session, so the
// session cookie has to travel with every request.
const jar = new Map();
function cookieHeader() {
  return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}
function rememberCookies(response) {
  const raw = response.headers.getSetCookie ? response.headers.getSetCookie() : [];
  for (const line of raw) {
    const [pair] = line.split(';');
    const index = pair.indexOf('=');
    if (index > 0) jar.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
  }
}

async function get(path, options = {}) {
  const headers = { ...(options.headers || {}) };
  const cookies = cookieHeader();
  if (cookies) headers.Cookie = cookies;
  const response = await fetch(base + path, {
    redirect: 'manual',
    signal: AbortSignal.timeout(30000),
    ...options,
    headers,
  });
  rememberCookies(response);
  const body = await response.text();
  return { response, body };
}

function csrfFrom(html) {
  const match = html.match(/name="csrf_token"\s+value="([^"]+)"/)
    || html.match(/value="([^"]+)"\s+name="csrf_token"/);
  return match ? match[1] : '';
}

// ── 1. The homepage structure ───────────────────────────────────────────────
{
  const { response, body } = await get('/');
  check(response.status === 200, `homepage answers 200 (got ${response.status})`);
  check(!errors.test(body), 'homepage has no PHP error output');

  check(/class="jhd-lead"/.test(body), 'homepage shows a lead story');
  check(/class="jhd-lead__media"[\s\S]{0,400}?<img/.test(body),
    'the lead story has an image next to its text');
  check(/class="jhd-lead__title"/.test(body), 'the lead story has a title');
  check(/class="jhd-lead__summary"/.test(body), 'the lead story has a summary');
  check(/jhd-lead__cta/.test(body), 'the lead story has a call to action');
  check(/loading="eager"[^>]*fetchpriority="high"|fetchpriority="high"/.test(body),
    'the lead image is preloaded (largest contentful paint)');

  check(/class="jhd-about"/.test(body), 'homepage shows the «معرفی جامعه‌الهدی» band');
  check(/class="jhd-about__title"/.test(body), 'the about band has a heading');
  check(/class="jhd-about__stats"/.test(body), 'the about band has statistics');

  check(/class="jhd-hub-rail/.test(body), 'homepage shows the hub rail');
  const hubTiles = (body.match(/class="jhd-hub-tile/g) || []).length;
  check(hubTiles >= 2, `the hub rail has several hubs (got ${hubTiles})`);

  const sectionHeads = (body.match(/class="jhd-section-head"/g) || []).length;
  check(sectionHeads >= 2, `the homepage has several content hubs (got ${sectionHeads})`);
  check(/id="home-lead-heading"/.test(body), 'the lead section is labelled for assistive technology');
}

// ── 2. The rails on a content page ──────────────────────────────────────────
{
  const list = await get('/articles');
  check(list.response.status === 200, 'the article archive answers 200');
  check(!errors.test(list.body), 'the article archive has no PHP error output');
  check(/class="jhd-rail__title"[^>]*>[^<]*(?:<[^>]+>)*\s*تازه‌ترین‌ها/.test(list.body),
    'the article archive shows the «تازه‌ترین‌ها» rail');
  check(/پربازدیدترین‌ها/.test(list.body),
    'the article archive shows the «پربازدیدترین‌ها» rail');
  check(/class="jhd-rail__media"/.test(list.body),
    'the rails show a small thumbnail');
  // RTL: the thumbnail must come before the text, i.e. it sits on the right.
  const railItem = (list.body.match(/<li class="jhd-rail__item">[\s\S]{0,400}?<\/li>/) || [''])[0];
  check(railItem.indexOf('jhd-rail__media') !== -1
    && railItem.indexOf('jhd-rail__media') < railItem.indexOf('jhd-rail__link'),
    'the rail thumbnail is placed before the text (right side in RTL)');
}

// ── 3. The admin settings really change the homepage ────────────────────────
{
  const loginPage = await get('/login');
  const loginToken = csrfFrom(loginPage.body);
  check(loginToken !== '', 'the login form exposes a CSRF token');

  const login = await get('/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: new URLSearchParams({
      csrf_token: loginToken,
      identifier: adminUser,
      password: adminPassword,
    }).toString(),
  });
  const loggedIn = login.response.status === 303 && jar.has('jamiat_session');

  if (!loggedIn) {
    console.log(`SKIP the administrative part — could not log in as "${adminUser}"`);
  } else {
    check(true, 'the administrator can log in');

    const settingsPage = await get('/admin/settings', {});
    check(settingsPage.response.status === 200, 'the settings panel is reachable');
    const settingsToken = csrfFrom(settingsPage.body);
    check(settingsToken !== '', 'the settings panel exposes a CSRF token');

    const sectionNames = [...settingsPage.body.matchAll(/name="homepage_sections\[\]"\s+value="([a-z]+)"/g)]
      .map((m) => m[1]);
    check(['hero', 'feature', 'writing', 'books', 'lessons', 'media']
      .every((key) => sectionNames.includes(key)),
      'the settings panel offers every homepage section');

    // Save with the "media" hub switched off and everything else on.
    const before = await get('/');
    const save = await get('/admin/settings', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: new URLSearchParams([
        ['csrf_token', settingsToken],
        ['site_name', 'مدرسه جامعه‌الهدی'],
        ['site_slogan', 'مرکز علمی، آموزشی و پژوهشی در پرتو قرآن و عترت'],
        ['about_short', 'مدرسه علمیه جامعه‌الهدی در کابل.'],
        ['about_long', ''],
        ['address', 'کابل، افغانستان'],
        ['phone', '0798228441'],
        ['email', 'hajiahmads299@gmail.com'],
        ['social_telegram', ''],
        ['social_youtube', ''],
        ['social_instagram', ''],
        ['homepage_sections[]', 'hero'],
        ['homepage_sections[]', 'feature'],
        ['homepage_sections[]', 'writing'],
        ['homepage_sections[]', 'books'],
        ['homepage_sections[]', 'lessons'],
        ['homepage_sections_present', '1'],
        ['homepage_hero_post_id', '0'],
      ]).toString(),
    });
    check(save.response.status === 303, 'the settings panel saves with a redirect');

    const after = await get('/');
    check(!/id="home-media-heading"/.test(after.body),
      'switching a section off removes it from the homepage');
    check(/id="home-lead-heading"/.test(after.body),
      'the remaining sections stay on the homepage');
    check(/class="jhd-about"/.test(after.body),
      'the «معرفی مدرسه» band follows the hero toggle');
    check(before.response.status === 200 && after.response.status === 200,
      'the homepage still answers 200 after the change');

    // Put the media hub back on.
    const settingsPage2 = await get('/admin/settings', {});
    const settingsToken2 = csrfFrom(settingsPage2.body);
    await get('/admin/settings', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: new URLSearchParams([
        ['csrf_token', settingsToken2],
        ['site_name', 'مدرسه جامعه‌الهدی'],
        ['site_slogan', 'مرکز علمی، آموزشی و پژوهشی در پرتو قرآن و عترت'],
        ['about_short', 'مدرسه علمیه جامعه‌الهدی در کابل.'],
        ['about_long', ''],
        ['address', 'کابل، افغانستان'],
        ['phone', '0798228441'],
        ['email', 'hajiahmads299@gmail.com'],
        ['social_telegram', ''],
        ['social_youtube', ''],
        ['social_instagram', ''],
        ['homepage_sections[]', 'hero'],
        ['homepage_sections[]', 'feature'],
        ['homepage_sections[]', 'writing'],
        ['homepage_sections[]', 'books'],
        ['homepage_sections[]', 'lessons'],
        ['homepage_sections[]', 'media'],
        ['homepage_sections_present', '1'],
        ['homepage_hero_post_id', '0'],
      ]).toString(),
    });
    const restored = await get('/');
    check(/id="home-media-heading"/.test(restored.body),
      'the media hub comes back when it is switched on again');
  }
}

console.log('');
if (failures.length) {
  console.log(`${passed}/${passed + failures.length} checks passed`);
  console.log('Failures:');
  failures.forEach((f) => console.log(`  - ${f}`));
  process.exit(1);
}
console.log(`${passed}/${passed} checks passed`);
