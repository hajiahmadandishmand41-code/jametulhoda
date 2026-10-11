#!/usr/bin/env node
/**
 * tests/mobile-api.mjs — آزمون قرارداد API بومی موبایل (/api/mobile/*)
 *
 * بدون وابستگی به Playwright اجرا می‌شود؛ فقط یک سرور در حال اجرا لازم است:
 *
 *   APP_ENV=development DB_DRIVER=sqlite SQLITE_PATH=/tmp/jhd.sqlite \
 *   SESSION_DRIVER=files UPLOAD_STORAGE=local php -S 127.0.0.1:8080 router.php
 *   TEST_BASE_URL=http://127.0.0.1:8080 node tests/mobile-api.mjs
 *
 * پوشش: سلامت، خانه، فهرست‌ها و صفحه‌بندی، جزئیات، جست‌وجو، رسانه، دانلودپذیری
 * URLها، درباره/تماس، ورود/ثبت‌نام/پروفایل/تغییر رمز/خروج، لایک امن (idempotent
 * و ضد جعل)، خطاهای شبکه/اعتبارسنجی، و پنهان‌ماندن پیش‌نویس‌ها.
 */
import { strict as assert } from 'node:assert';

const BASE = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const results = [];
let failures = 0;

function check(label, ok, detail = '') {
  results.push({ label, ok, detail });
  if (!ok) failures++;
  console.log(ok ? 'PASS' : 'FAIL', label, ok ? '' : detail);
}

async function call(path, { method = 'GET', body, token, headers = {} } = {}) {
  const init = { method, headers: { Accept: 'application/json', ...headers } };
  if (body !== undefined) {
    init.body = JSON.stringify(body);
    init.headers['Content-Type'] = 'application/json';
  }
  if (token) init.headers.Authorization = `Bearer ${token}`;
  try {
    const res = await fetch(BASE + path, init);
    let json = null;
    const text = await res.text();
    try { json = JSON.parse(text); } catch { /* non-JSON body */ }
    return { status: res.status, json, text, headers: res.headers };
  } catch (e) {
    return { status: 0, json: null, text: String(e), headers: new Headers() };
  }
}

const email = `mobile-api-${Date.now()}@example.test`;
const phone = `07${String(Date.now()).slice(-8)}`;
let token = '';
let secondToken = '';

// ─── سلامت و ساختار پاسخ ────────────────────────────────────────────────────
{
  const r = await call('/api/mobile/health');
  check('health endpoint responds', r.status === 200 && r.json?.ok === true && r.json?.data?.database === true, `status=${r.status}`);
  const bad = await call('/api/mobile/health', { method: 'DELETE' });
  check('health rejects unknown methods', bad.status === 405, String(bad.status));
}

// ─── خانه ───────────────────────────────────────────────────────────────────
{
  const r = await call('/api/mobile/home?limit=3');
  const data = r.json?.data ?? {};
  check('home returns site + sections', r.status === 200 && r.json?.ok && typeof data.site?.name === 'string' && /^https?:\/\//.test(data.site.logo_url ?? ''), `status=${r.status}`);
  check('home sections cover the site areas', ['news', 'announcements', 'events', 'articles', 'research', 'books', 'lessons', 'topics', 'media'].every((k) => Array.isArray(data.sections?.[k])), JSON.stringify(Object.keys(data.sections ?? {})));
  check('home items carry like state fields', (data.sections?.news ?? []).every((i) => typeof i.like_count === 'number' && i.liked === null), '');
}

// ─── فهرست‌ها و صفحه‌بندی ───────────────────────────────────────────────────
{
  const r = await call('/api/mobile/posts?type=news&page=1&per_page=5');
  const meta = r.json?.meta ?? {};
  check('posts listing paginates', r.status === 200 && Array.isArray(r.json?.items) && meta.page === 1 && meta.per_page === 5 && typeof meta.total === 'number', JSON.stringify(meta));
  const p2 = await call('/api/mobile/posts?type=news&page=2&per_page=5');
  check('posts page 2 keeps filters', p2.status === 200 && p2.json?.meta?.page === 2, String(p2.status));
  const events = await call('/api/mobile/posts?type=event&per_page=50');
  check('event listing matches site scope (program/religious/announcement)', events.status === 200 && (events.json?.items ?? []).every((i) => ['program', 'religious', 'announcement'].includes(i.type)), JSON.stringify((events.json?.items ?? []).map((i) => i.type)));
  const bad = await call('/api/mobile/posts?type=;drop');
  check('invalid content type is rejected', bad.status === 422, String(bad.status));
}

// ─── جزئیات و محتوای منتشرنشده ─────────────────────────────────────────────
{
  const list = await call('/api/mobile/posts?type=news&per_page=5');
  const first = (list.json?.items ?? [])[0];
  check('listing items expose stable keys', !!first && /^post-\d+$/.test(first.key ?? ''), JSON.stringify(first?.key));
  const detail = await call(`/api/mobile/post?slug=${encodeURIComponent(first?.slug ?? '')}`);
  const d = detail.json?.data ?? {};
  check('post detail has content_text and sanitized html', detail.status === 200 && typeof d.content_text === 'string' && d.content_text.length > 0 && typeof d.content_html === 'string' && !/<script/i.test(d.content_html), `status=${detail.status}`);
  check('post detail has topics/gallery/attachments arrays', Array.isArray(d.topics) && Array.isArray(d.gallery) && Array.isArray(d.attachments), '');
  check('post detail carries viewer like state', d.liked === null && typeof d.like_count === 'number', '');
  const missing = await call('/api/mobile/post?slug=does-not-exist-xyz');
  check('missing post returns 404', missing.status === 404, String(missing.status));
  const draft = await call('/api/mobile/post?slug=draft-book');
  check('draft content never leaks', draft.status === 404, String(draft.status));
}

// ─── کتاب‌ها ────────────────────────────────────────────────────────────────
{
  const r = await call('/api/mobile/books?per_page=5');
  const first = (r.json?.items ?? [])[0];
  check('books listing works', r.status === 200 && !!first, `status=${r.status}`);
  const d = await call(`/api/mobile/book?id=${first?.id ?? 0}`);
  const book = d.json?.data ?? {};
  check('book detail exposes pdf/word download urls', d.status === 200 && ('pdf_url' in book) && ('word_url' in book), JSON.stringify(Object.keys(book).slice(0, 12)));
}

// ─── درس‌ها و مجموعه‌ها ─────────────────────────────────────────────────────
{
  const c = await call('/api/mobile/collections');
  check('lesson collections list', c.status === 200 && Array.isArray(c.json?.items), String(c.status));
  const l = await call('/api/mobile/lessons?per_page=5');
  check('lessons listing works', l.status === 200 && Array.isArray(l.json?.items) && typeof l.json?.meta?.total === 'number', String(l.status));
  const first = (l.json?.items ?? [])[0];
  const d = await call(`/api/mobile/lesson?slug=${encodeURIComponent(first?.slug ?? '')}`);
  const lesson = d.json?.data ?? {};
  check('lesson detail has media urls and attachments', d.status === 200 && ('audio_url' in lesson) && ('video_url' in lesson) && Array.isArray(lesson.attachments), `status=${d.status}`);
}

// ─── موضوع‌ها ───────────────────────────────────────────────────────────────
{
  const t = await call('/api/mobile/topics');
  check('topics tree endpoint', t.status === 200 && (t.json?.items ?? []).length > 0, String(t.status));
  const slug = (t.json?.items ?? [])[0]?.slug ?? '';
  const d = await call(`/api/mobile/topic?slug=${encodeURIComponent(slug)}`);
  check('topic detail lists its content', d.status === 200 && Array.isArray(d.json?.data?.posts) && Array.isArray(d.json?.data?.lessons) && Array.isArray(d.json?.data?.books), `status=${d.status}`);
}

// ─── رسانه ─────────────────────────────────────────────────────────────────
{
  const m = await call('/api/mobile/media?kind=audio&per_page=5');
  const items = m.json?.items ?? [];
  check('audio listing works', m.status === 200 && Array.isArray(items), String(m.status));
  if (items.length > 0) {
    const item = items[0];
    check('media items expose absolute stream urls', typeof item.media_url === 'string' && /^https?:\/\//.test(item.media_url), JSON.stringify(item.media_url));
    const d = await call(`/api/mobile/media-item?kind=${encodeURIComponent(item.type)}&id=${item.id}`);
    check('media detail resolves published parent', d.status === 200 && d.json?.data?.parent?.title !== '', `status=${d.status}`);
    // URL فایل رسانه باید قابل واکشی باشد (دانلود/پخش در اپ).
    const head = await fetch(item.media_url, { method: 'GET', headers: { Range: 'bytes=0-64' } }).catch(() => null);
    check('media file url is reachable (download)', !!head && (head.status === 200 || head.status === 206), `status=${head?.status ?? 'net'}`);
  }
  const v = await call('/api/mobile/media?kind=video&per_page=5');
  check('video listing works', v.status === 200 && Array.isArray(v.json?.items), String(v.status));
  const bad = await call('/api/mobile/media-item?kind=document&id=1');
  check('media detail rejects unknown kinds', bad.status === 422, String(bad.status));
}

// ─── جست‌وجو ────────────────────────────────────────────────────────────────
{
  const s = await call('/api/mobile/search?q=' + encodeURIComponent('مهدویت') + '&type=article');
  check('search finds published article', s.status === 200 && (s.json?.meta?.total ?? 0) >= 1 && (s.json?.items ?? []).some((i) => i.title.includes('مهدویت')), JSON.stringify(s.json?.meta));
  const injection = await call('/api/mobile/search?q=' + encodeURIComponent("') OR 1=1 --"));
  check('search treats SQL-looking input as data', injection.status === 200 && !/SQLSTATE|Warning|Fatal/i.test(injection.text ?? ''), '');
  const empty = await call('/api/mobile/search?q=');
  check('empty search returns empty discovery state', empty.status === 200 && (empty.json?.items ?? []).length === 0, '');
  const page2 = await call('/api/mobile/search?q=' + encodeURIComponent('درس') + '&page=2&per_page=5');
  check('search paginates', page2.status === 200 && (page2.json?.meta?.page === 2), JSON.stringify(page2.json?.meta));
}

// ─── دسته‌ها و صفحه‌ها و تماس ───────────────────────────────────────────────
{
  const c = await call('/api/mobile/categories');
  check('categories endpoint', c.status === 200 && Array.isArray(c.json?.items), String(c.status));
  const a = await call('/api/mobile/pages?page=about');
  check('about page payload', a.status === 200 && a.json?.data?.founder && /^https?:\/\//.test(a.json?.data?.logo_url ?? ''), String(a.status));
  const p = await call('/api/mobile/pages?page=contact');
  check('contact page payload', p.status === 200 && (p.json?.data?.form?.fields ?? []).includes('message'), String(p.status));
  const missing = await call('/api/mobile/pages?page=other');
  check('unknown page returns 404', missing.status === 404, String(missing.status));
  const invalid = await call('/api/mobile/contact', { method: 'POST', body: { name: 'x', subject: '', message: 'کوتاه' } });
  check('contact validates input', invalid.status === 422, String(invalid.status));
  const sent = await call('/api/mobile/contact', { method: 'POST', body: { name: 'کاربر آزمون', subject: 'موضوع آزمون', message: 'این پیام آزمایشی است و متن کافی دارد.', email: 'a@b.co' } });
  // سقف ۳ پیام در ۳۰ دقیقه از هر IP؛ در اجرای متوالی محلی ممکن است سقف پر شود.
  check('contact accepts a valid message', (sent.status === 200 && sent.json?.data?.sent === true) || (sent.status === 429 && sent.json?.error === 'rate_limited'), `status=${sent.status} ${sent.text?.slice(0, 120)}`);
}

// ─── ثبت‌نام و ورود ────────────────────────────────────────────────────────
{
  const weak = await call('/api/mobile/auth/register', { method: 'POST', body: { full_name: 'کاربر', country: 'AF', phone, email, password: '123', agreed_terms: true } });
  check('register enforces password length', weak.status === 422, String(weak.status));
  const noTerms = await call('/api/mobile/auth/register', { method: 'POST', body: { full_name: 'کاربر آزمون', country: 'AF', phone, email, password: 'TestPass123!', password_confirm: 'TestPass123!', agreed_terms: false } });
  check('register requires accepting the terms', noTerms.status === 422, String(noTerms.status));
  const badCountry = await call('/api/mobile/auth/register', { method: 'POST', body: { full_name: 'کاربر آزمون', country: 'XX', phone, email, password: 'TestPass123!', password_confirm: 'TestPass123!', agreed_terms: true } });
  check('register validates country', badCountry.status === 422, String(badCountry.status));

  const reg = await call('/api/mobile/auth/register', { method: 'POST', body: { full_name: 'کاربر آزمون', country: 'AF', phone, email, password: 'TestPass123!', password_confirm: 'TestPass123!', agreed_terms: true, device_label: 'api-test' } });
  token = reg.json?.data?.token ?? '';
  check('register issues a bearer token', reg.status === 201 && token.startsWith('jh1_') && reg.json?.data?.user?.role === 'user', `status=${reg.status}`);
  check('register response never leaks hashes', !JSON.stringify(reg.json).includes('$2y$') && !('password' in (reg.json?.data?.user ?? {})), '');

  const dup = await call('/api/mobile/auth/register', { method: 'POST', body: { full_name: 'کاربر آزمون', country: 'AF', phone, email, password: 'TestPass123!', password_confirm: 'TestPass123!', agreed_terms: true } });
  check('duplicate registration is refused', dup.status >= 400, String(dup.status));

  const wrong = await call('/api/mobile/auth/login', { method: 'POST', body: { identifier: email, password: 'WrongPass123!' } });
  check('wrong password is rejected with generic error', wrong.status === 401 && wrong.json?.error === 'invalid_credentials', String(wrong.status));
  const login = await call('/api/mobile/auth/login', { method: 'POST', body: { identifier: email, password: 'TestPass123!' } });
  secondToken = login.json?.data?.token ?? '';
  check('login issues a working token', login.status === 200 && secondToken.startsWith('jh1_'), `status=${login.status}`);

  const me = await call('/api/mobile/auth/me', { token });
  check('me returns the signed-in account', me.status === 200 && me.json?.data?.user?.full_name === 'کاربر آزمون', `status=${me.status}`);
  const anon = await call('/api/mobile/auth/me');
  check('me without token is 401', anon.status === 401, String(anon.status));
}

// ─── پروفایل و تغییر رمز و خروج ────────────────────────────────────────────
{
  const upd = await call('/api/mobile/auth/profile', { method: 'POST', body: { full_name: 'نام به‌روزشده' }, token });
  check('profile update works', upd.status === 200 && upd.json?.data?.user?.full_name === 'نام به‌روزشده', `status=${upd.status}`);
  const badMail = await call('/api/mobile/auth/profile', { method: 'POST', body: { email: 'not-an-email' }, token });
  check('profile validates email', badMail.status === 422, String(badMail.status));

  const chg = await call('/api/mobile/auth/change-password', { method: 'POST', body: { current_password: 'TestPass123!', new_password: 'NewPass456!', new_password_confirm: 'NewPass456!' }, token });
  check('change password succeeds', chg.status === 200 && chg.json?.data?.changed === true, `status=${chg.status}`);
  const stale = await call('/api/mobile/auth/me', { token });
  check('password change revokes old tokens', stale.status === 401, String(stale.status));
  const staleSecond = await call('/api/mobile/auth/me', { token: secondToken });
  check('password change revokes ALL sessions', staleSecond.status === 401, String(staleSecond.status));
  const relogin = await call('/api/mobile/auth/login', { method: 'POST', body: { identifier: email, password: 'NewPass456!' } });
  token = relogin.json?.data?.token ?? '';
  check('login with the new password works', relogin.status === 200 && token !== '', `status=${relogin.status}`);
}

// ─── لایک: امن، idempotent، ضد جعل ─────────────────────────────────────────
{
  const anon = await call('/api/mobile/likes?ref_type=post&ref_id=1');
  check('anon sees the like count (liked=null)', anon.status === 200 && anon.json?.data?.liked === null && typeof anon.json?.data?.like_count === 'number', `status=${anon.status}`);
  const unauth = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'post', ref_id: 1, action: 'like' } });
  check('unauthenticated like is rejected', unauth.status === 401, String(unauth.status));
  const invalid = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'post', ref_id: 999999, action: 'like' }, token });
  check('liking missing content is 404', invalid.status === 404, String(invalid.status));
  const badType = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'users', ref_id: 1, action: 'like' }, token });
  check('like validates ref_type', badType.status === 422, String(badType.status));

  const l1 = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'post', ref_id: 1, action: 'like', like_count: 99999 }, token });
  const l2 = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'post', ref_id: 1, action: 'like', like_count: 99999 }, token });
  check('double like keeps a single count (idempotent)', l1.status === 200 && l2.status === 200 && l2.json?.data?.like_count === l1.json?.data?.like_count && l1.json?.data?.liked === true, JSON.stringify([l1.json?.data, l2.json?.data]));
  check('forged like_count from the client is ignored', l2.json?.data?.like_count !== 99999 && typeof l2.json?.data?.like_count === 'number', '');
  const state = await call('/api/mobile/post?id=1', { token });
  check('post detail reflects the like state', state.json?.data?.liked === true && state.json?.data?.like_count === l2.json?.data?.like_count, JSON.stringify({ liked: state.json?.data?.liked, count: state.json?.data?.like_count, expected: l2.json?.data?.like_count }));
  const u1 = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'post', ref_id: 1, action: 'unlike' }, token });
  const u2 = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'post', ref_id: 1, action: 'unlike' }, token });
  check('unlike is idempotent too', u1.status === 200 && u2.status === 200 && u2.json?.data?.liked === false, JSON.stringify(u2.json?.data));
  const restored = await call('/api/mobile/likes', { method: 'POST', body: { ref_type: 'post', ref_id: 1, action: 'like' }, token });
  check('like can be toggled back on', restored.json?.data?.liked === true, '');
}

// ─── خروج امن ──────────────────────────────────────────────────────────────
{
  const out = await call('/api/mobile/auth/logout', { method: 'POST', token });
  check('logout succeeds', out.status === 200 && out.json?.data?.logged_out === true, `status=${out.status}`);
  const after = await call('/api/mobile/auth/me', { token });
  check('token is dead after logout', after.status === 401, String(after.status));
}

// ─── روش‌های غیرمجاز ────────────────────────────────────────────────────────
{
  const post = await call('/api/mobile/home', { method: 'POST', body: {} });
  check('public GET endpoints reject POST', post.status === 405, String(post.status));
  const loginGet = await call('/api/mobile/auth/login');
  check('auth login rejects GET', loginGet.status === 405, String(loginGet.status));
}

console.log(`\n${results.length - failures}/${results.length} checks passed`);
if (failures > 0) process.exit(1);
