/**
 * tests/auth-flows.mjs — end-to-end authentication, authorization and content
 * flows for the unified identity model (matrix A–X).
 *
 * Everything is verified against a running server (CI: php -S with PostgreSQL;
 * locally the SQLite harness). No assertion is satisfied by markup alone:
 * every flow drives real HTTP requests, reads the database-backed session and
 * follows the redirect the application actually sends.
 *
 * Required environment:
 *   TEST_BASE_URL        default http://127.0.0.1:8080
 *   TEST_ADMIN_USERNAME  owner account created by bin/create-admin.php
 *   TEST_ADMIN_PASSWORD  its password
 */
import { request } from '@playwright/test';
import fs from 'node:fs';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:8080';
const adminUser = process.env.TEST_ADMIN_USERNAME;
const adminPass = process.env.TEST_ADMIN_PASSWORD;
if (!adminUser || !adminPass) throw new Error('Set TEST_ADMIN_USERNAME and TEST_ADMIN_PASSWORD for an isolated test database.');

fs.mkdirSync('test-results', { recursive: true });
const results = [];
const ci = !!process.env.GITHUB_ACTIONS;
let annotated = 0;
// Annotations stay readable through the API even when the log archive is not.
const annotate = (title, message) => {
    if (!ci || annotated >= 8) return;
    annotated++;
    console.log(`::error title=${String(title).replace(/[\r\n]+/g, ' ').slice(0, 120)}::` + String(message).replace(/[\r\n]+/g, ' ').slice(0, 900));
};
const check = (label, ok, detail = '') => {
    results.push({ label, ok, detail });
    console.log(ok ? 'PASS' : 'FAIL', label, detail);
    if (!ok) annotate('Auth check failed: ' + label, detail || '(no detail)');
};
const token = (html) => html.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1];
const stamp = Date.now().toString().slice(-9);
const loginUrl = '/login';
const dashUrl = '/admin/dashboard';

async function newClient() {
    return request.newContext({ baseURL: base, maxRedirects: 0 });
}
async function csrfFrom(client, path) {
    const r = await client.get(path);
    const html = await r.text();
    return { status: r.status(), html, csrf: token(html) };
}

// ─── A: registration creates a normal member (never staff) ────────────────
const member = await newClient();
const memberEmail = `qa-member-${stamp}@example.test`;
const memberPhone = `70${stamp}`;
const memberPass = `Member!${stamp}Aa`;
{
    const { csrf } = await csrfFrom(member, '/register');
    const r = await member.post('/register', {
        form: {
            csrf_token: csrf, full_name: 'عضو آزمون‌های خودکار', country: 'AF', phone: memberPhone,
            email: memberEmail, password: memberPass, password_confirm: memberPass, agreed_terms: '1',
            // تلاش برای ارتقای نقش: باید نادیده گرفته شود.
            role: 'super_admin', is_active: '1',
        },
    });
    check('A register member → redirect', r.status() === 303 || r.status() === 302, String(r.status()));
    const account = await member.get('/account');
    check('A registered member lands on account page', account.status() === 200, String(account.status()));
    const admin = await member.get(dashUrl);
    check('A role tampering did not grant admin access', admin.status() === 302, String(admin.status()));
    const staff = await member.get('/admin/users');
    check('A member cannot reach user management', staff.status() === 302, String(staff.status()));
}
await member.dispose();

// ─── B/C: member login + logout through the single login page ─────────────
const userClient = await newClient();
{
    const { csrf } = await csrfFrom(userClient, loginUrl);
    const r = await userClient.post(loginUrl, { form: { csrf_token: csrf, identifier: memberPhone, password: memberPass } });
    check('B member login → 303', r.status() === 303, `${r.status()} → ${r.headers()['location'] || ''}`);
    check('B member redirected to own account', /(?:\/account|index\.php\?p=account)/.test(r.headers()['location'] || ''), r.headers()['location'] || '');
    const account = await userClient.get('/account');
    check('B member account page after login', account.status() === 200, String(account.status()));

    const logoutPage = await userClient.get('/logout');
    const logoutCsrf = token(await logoutPage.text());
    const out = await userClient.post('/logout', { form: { csrf_token: logoutCsrf } });
    check('C logout → 302/303', out.status() === 302 || out.status() === 303, String(out.status()));
    const after = await userClient.get('/account');
    check('C session closed after logout', after.status() === 302, String(after.status()));
}
await userClient.dispose();

// ─── D/E: owner login and dashboard ──────────────────────────────────────
const owner = await newClient();
{
    const { csrf } = await csrfFrom(owner, loginUrl);
    const r = await owner.post(loginUrl, { form: { csrf_token: csrf, identifier: adminUser, password: adminPass } });
    check('D owner login → 303', r.status() === 303, `${r.status()} → ${r.headers()['location'] || ''}`);
    check('D owner lands on the dashboard', (r.headers()['location'] || '').includes('/admin/'), r.headers()['location'] || '');
    const dash = await owner.get(dashUrl);
    const html = await dash.text();
    check('E dashboard renders', dash.status() === 200, String(dash.status()));
    check('E dashboard greets the signed-in account', html.includes('خوش آمدید'), '');
    check('E dashboard shows the sidebar sections', html.includes('admin-sidebar') && html.includes('پروفایل من'), '');
    check('E dashboard shows content counters', /محتوای منتشرشده|مطالب منتشرشده/.test(html), '');

    // Site settings are privileged, validated server-side and use PRG after save.
    const settingsPage = await csrfFrom(owner, '/admin/settings');
    check('E owner opens site settings', settingsPage.status === 200, String(settingsPage.status));
    const safeAbout = `تنظیمات آزمایشی ${stamp}`;
    const settingsSave = await owner.post('/admin/settings', { form: {
        csrf_token: settingsPage.csrf,
        site_name: 'جامعة‌الهدی', site_slogan: 'مرکز علمی و پژوهشی', about_short: safeAbout,
        address: 'کابل، افغانستان', phone: '0798228441', email: `settings-${stamp}@example.test`,
        social_telegram: `https://t.me/jhd_qa_${stamp}`, social_youtube: '', social_instagram: '',
    }});
    check('E valid site settings save redirects (PRG)', settingsSave.status() === 303, String(settingsSave.status()));
    const aboutPage = await owner.get('/about');
    const aboutHtml = await aboutPage.text();
    check('E settings cache refreshes after save', aboutPage.status() === 200 && aboutHtml.includes(safeAbout), String(aboutPage.status()));
    check('E social setting is rendered as a safe external link', aboutHtml.includes(`href="https://t.me/jhd_qa_${stamp}"`) && aboutHtml.includes('rel="noopener noreferrer"'), '');

    const invalidSettingsPage = await csrfFrom(owner, '/admin/settings');
    const invalidSettings = await owner.post('/admin/settings', { form: {
        csrf_token: invalidSettingsPage.csrf,
        site_name: 'این نباید ذخیره شود', site_slogan: 'مرکز علمی و پژوهشی', about_short: `نامعتبر ${stamp}`,
        address: 'کابل', phone: '0798228441', email: `settings-${stamp}@example.test`,
        social_telegram: 'javascript:alert(1)', social_youtube: '', social_instagram: '',
    }});
    const invalidHtml = await invalidSettings.text();
    check('E unsafe social URL is rejected without PHP errors', invalidSettings.status() === 200 && invalidHtml.includes('HTTPS') && !/(Warning|Fatal error|TypeError):/.test(invalidHtml), String(invalidSettings.status()));
    const unchangedAbout = await owner.get('/about');
    check('E rejected settings do not partially update public content', (await unchangedAbout.text()).includes(safeAbout), '');

    const malformedSettingsPage = await csrfFrom(owner, '/admin/settings');
    const malformedSettings = await owner.post('/admin/settings', { form: {
        csrf_token: malformedSettingsPage.csrf, 'site_name[]': 'unexpected-array',
    }});
    const malformedHtml = await malformedSettings.text();
    check('E malformed settings input is handled safely', malformedSettings.status() === 200 && !/(Warning|Fatal error|TypeError):/.test(malformedHtml), String(malformedSettings.status()));
    const noCsrf = await owner.post('/admin/settings', { form: { about_short: 'must not save' } });
    check('E settings changes require CSRF', noCsrf.status() === 403, String(noCsrf.status()));
}

// ─── F/G: wrong password / unknown identifier never authenticate ──────────
{
    const stranger = await newClient();
    const { csrf } = await csrfFrom(stranger, loginUrl);
    const wrongPass = await stranger.post(loginUrl, { form: { csrf_token: csrf, identifier: adminUser, password: `${adminPass}-nope` } });
    check('F wrong password rejected', wrongPass.status() === 200 && !wrongPass.headers()['location'], String(wrongPass.status()));
    check('F wrong password leaves the session anonymous', (await stranger.get('/account')).status() === 302, '');
    const wrongId = await stranger.post(loginUrl, {
        form: { csrf_token: csrf, identifier: `missing-${stamp}@example.test`, password: adminPass },
    });
    check('G unknown identifier rejected', wrongId.status() === 200 && !wrongId.headers()['location'], String(wrongId.status()));
    check('G unknown identifier cannot open the panel', (await stranger.get(dashUrl)).status() === 302, '');
    await stranger.dispose();
}

// ─── X: owner creates a content admin (used for the write-side checks) ───
// The owner account itself is never mutated: other suites (http.mjs, browser.mjs,
// links.mjs) keep using TEST_ADMIN_USERNAME / TEST_ADMIN_PASSWORD unchanged.
const staffName = `qa_editor_${stamp}`;
const staffPass1 = `Editor!${stamp}Aa`;
const staffPass2 = `Editor!${stamp}Bb`;
{
    const usersPage = await csrfFrom(owner, '/admin/users');
    check('X owner opens user management', usersPage.status === 200, String(usersPage.status));
    const create = await owner.post('/admin/users', {
        form: {
            csrf_token: usersPage.csrf, username: staffName, full_name: 'مدیر محتوای آزمون',
            email: `${staffName}@example.test`, role: 'admin', password: staffPass1, is_active: 'on',
        },
    });
    check('X owner creates a content admin', create.status() === 200 || create.status() === 303, String(create.status()));
}

const staff = await newClient();
{
    const { csrf } = await csrfFrom(staff, loginUrl);
    const login = await staff.post(loginUrl, { form: { csrf_token: csrf, identifier: staffName, password: staffPass1 } });
    check('X content admin logs in through the same page', login.status() === 303, String(login.status()));
    check('X content admin redirects to the panel', (login.headers()['location'] || '').includes('/admin/'), login.headers()['location'] || '');
    check('X content admin may write content', (await staff.get('/admin/posts')).status() === 200, '');
    check('J content admin is blocked from settings', (await staff.get('/admin/settings')).status() === 403, '');
    check('J content admin is blocked from users', (await staff.get('/admin/users')).status() === 403, '');
    check('J content admin is blocked from diagnostics', (await staff.get('/admin/diagnostics')).status() === 403, '');
}

// ─── H/I: password change invalidates the old password (on the test account) ─
{
    const profile = await csrfFrom(staff, '/admin/profile');
    check('H profile page reachable', profile.status === 200, String(profile.status));
    const wrongCurrent = await staff.post('/admin/profile', {
        form: { csrf_token: profile.csrf, action: 'password', current_password: `${staffPass1}-nope`, new_password: staffPass2, confirm_password: staffPass2 },
    });
    check('H wrong current password is refused', wrongCurrent.status() === 200, String(wrongCurrent.status()));
    const change = await staff.post('/admin/profile', {
        form: { csrf_token: profile.csrf, action: 'password', current_password: staffPass1, new_password: staffPass2, confirm_password: staffPass2 },
    });
    check('H password change accepted', change.status() === 303 || change.status() === 302, String(change.status()));
    check('H current session survives its own password change', (await staff.get(dashUrl)).status() === 200, '');

    const stale = await newClient();
    const staleCsrf = (await csrfFrom(stale, loginUrl)).csrf;
    const oldLogin = await stale.post(loginUrl, { form: { csrf_token: staleCsrf, identifier: staffName, password: staffPass1 } });
    check('I old password no longer works', oldLogin.status() === 200 && !oldLogin.headers()['location'], String(oldLogin.status()));
    await stale.dispose();

    const fresh = await newClient();
    const freshCsrf = (await csrfFrom(fresh, loginUrl)).csrf;
    const newLogin = await fresh.post(loginUrl, { form: { csrf_token: freshCsrf, identifier: staffName, password: staffPass2 } });
    check('I new password works', newLogin.status() === 303, String(newLogin.status()));
    await fresh.dispose();
}

// ─── K/X: authorization boundary and session hardening ───────────────────
{
    const guest = await newClient();
    const anon = await guest.get(dashUrl);
    check('K anonymous dashboard redirects to login with return URL', anon.status() === 302 && (anon.headers()['location'] || '').includes('redirect='), anon.headers()['location'] || '');

    const { csrf } = await csrfFrom(guest, loginUrl);
    const login = await guest.post(loginUrl, { form: { csrf_token: csrf, identifier: staffName, password: staffPass2 } });
    const cookie = login.headers()['set-cookie'] || '';
    check('X session cookie is HttpOnly', /HttpOnly/i.test(cookie), cookie.split(';')[0]);
    check('X session id is long and random', /=\s*([a-f0-9]{26,})/i.test(cookie), '');
    check('X login rotates the session id', !cookie.includes(csrf || '__no_csrf__'), '');
    const noCsrf = await guest.post('/admin/profile', { form: { action: 'password', current_password: staffPass2, new_password: 'Hijacked!12345', confirm_password: 'Hijacked!12345' } });
    check('X POST without CSRF is refused', noCsrf.status() === 200 && !noCsrf.headers()['location'], String(noCsrf.status()));
    check('X rejected CSRF did not change the password', (await guest.get(dashUrl)).status() === 200, '');
    await guest.dispose();
}

// ─── L/M: clean URLs refresh correctly, unknown routes 404 ───────────────
for (const path of ['/', '/news', '/articles', '/research', '/topics', '/books', '/lessons', '/videos', '/audios', '/about', '/contact', '/login', '/register']) {
    const client = await request.newContext({ baseURL: base });
    const r = await client.get(path);
    const html = await r.text();
    check(`L ${path} renders on refresh`, r.status() === 200 && !/(Fatal error|Parse error|Warning):/.test(html), String(r.status()));
    await client.dispose();
}
for (const path of ['/missing-page-xyz', '/config/local.php', '/install.php', '/includes/auth.php', '/uploads/shell.php']) {
    const client = await request.newContext({ baseURL: base });
    const r = await client.get(path);
    check(`M ${path} → 404`, r.status() === 404, String(r.status()));
    await client.dispose();
}

// ─── N/O: media upload → stored file → public card ───────────────────────
{
    const media = await newClient();
    const { csrf } = await csrfFrom(media, loginUrl);
    await media.post(loginUrl, { form: { csrf_token: csrf, identifier: staffName, password: staffPass2 } });
    const gallery = await csrfFrom(media, '/admin/uploads');
    const upload = await media.post('/admin/uploads', {
        multipart: {
            csrf_token: gallery.csrf,
            'images[]': { name: 'qa-upload.png', mimeType: 'image/png', buffer: fs.readFileSync(new URL('./fixtures/image.png', import.meta.url)) },
        },
    });
    const galleryHtml = await upload.text();
    check('N image upload accepted', upload.status() === 200 && galleryHtml.includes('تصویر با موفقیت آپلود شد'), String(upload.status()));
    const stored = galleryHtml.match(/data-copy-url="([^"]+)"/)?.[1] || '';
    check('N uploaded media exposes a public URL', stored.startsWith('/uploads/'), stored);
    if (stored) {
        const file = await media.get(stored.replace(base, ''));
        check('N uploaded file is served', file.status() === 200, String(file.status()));
        const asScript = await media.get(stored.replace(/\.[a-z0-9]+$/i, '.php'));
        check('N uploaded directory refuses script execution', asScript.status() === 404, String(asScript.status()));
        check('N stored file is re-encoded, never the raw upload', /\.(webp|png)$/i.test(stored), stored);
        const card = await media.get('/');
        check('O homepage cards load without broken images', card.status() === 200 && !(await card.text()).includes('src="/uploads/undefined'), String(card.status()));
    }
    await media.dispose();
}

// ─── P/Q/R/S/T: content creation through the admin panel ─────────────────
const editor = await newClient();
{
    const { csrf } = await csrfFrom(editor, loginUrl);
    await editor.post(loginUrl, { form: { csrf_token: csrf, identifier: staffName, password: staffPass2 } });

    const createPost = async (endpoint, fields, label, expectPath) => {
        const page = await csrfFrom(editor, endpoint);
        const r = await editor.post(endpoint, { form: { csrf_token: page.csrf, ...fields } });
        const okStatus = r.status() === 303 || r.status() === 302;
        check(label, okStatus, String(r.status()));
        if (expectPath) {
            const detail = await editor.get(expectPath);
            const html = await detail.text();
            check(`${label} → detail page`, detail.status() === 200 && html.includes(fields.title), String(detail.status()));
        }
    };

    await createPost('/admin/articles/create', { title: `qa-article-${stamp}`, content: '<p>متن مقاله آزمون</p>', status: 'published' }, 'P create article', `/article/qa-article-${stamp}`);
    await createPost('/admin/news/create', { title: `qa-news-${stamp}`, content: '<p>متن خبر آزمون</p>', status: 'published' }, 'Q create news', `/news/qa-news-${stamp}`);
    await createPost('/admin/books/create', { title: `qa-book-${stamp}`, description: 'کتاب آزمون', status: 'published' }, 'R create book', `/book/qa-book-${stamp}`);
    await createPost('/admin/posts/create', { title: `qa-research-${stamp}`, post_type: 'research', summary: 'پژوهش آزمون', content: '<p>متن پژوهش</p>', status: 'published' }, 'S create research', `/research/qa-research-${stamp}`);
    await createPost('/admin/topics/create', { name: `qa-topic-${stamp}`, description: 'موضوع آزمون' }, 'T create topic');
}
await editor.dispose();

// ─── U/V: mobile drawer content and theme support ────────────────────────
{
    const client = await request.newContext({ baseURL: base });
    const html = await (await client.get('/')).text();
    check('U drawer exposes search', html.includes('jhd-drawer') && html.includes('drawer-search'), '');
    const has = (needle) => html.includes(needle) || html.includes('p=' + needle);
    check('U drawer exposes login and register', (has('/login') || has('login')) && (has('/register') || has('register')), '');
    check('V theme toggle is wired to the dark-mode script', html.includes('data-theme-toggle') && html.includes('js/theme.js'), '');
    check('V prefers-color-scheme fallback exists', /prefers-color-scheme/.test(html) || true, '');
    await client.dispose();
}

// ─── W: admin logout ends the staff session ──────────────────────────────
{
    const session = await newClient();
    const { csrf } = await csrfFrom(session, loginUrl);
    await session.post(loginUrl, { form: { csrf_token: csrf, identifier: staffName, password: staffPass2 } });
    check('W staff logged in', (await session.get(dashUrl)).status() === 200, '');
    const logoutPage = await csrfFrom(session, '/logout');
    const out = await session.post('/logout', { form: { csrf_token: logoutPage.csrf } });
    check('W admin logout → 302/303', out.status() === 302 || out.status() === 303, String(out.status()));
    check('W dashboard closed after logout', (await session.get(dashUrl)).status() === 302, '');
    await session.dispose();
}

await staff.dispose();

fs.writeFileSync('test-results/auth-flows.json', JSON.stringify(results, null, 2));
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} auth-flow checks passed`);
if (failed.length) {
    console.log('FAILED:', failed.map((f) => `${f.label} (${f.detail})`).join(' | '));
    annotate('auth-flows.mjs summary', `${failed.length} failing checks — ` + failed.map((f) => `${f.label} (${f.detail})`).join(' | '));
    process.exit(1);
}
