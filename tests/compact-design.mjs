/**
 * tests/compact-design.mjs — بازطراحی فشرده: صفحهٔ اصلی، ریل‌های انتهای مطلب
 * و کنترل کامل آن‌ها از پنل مدیریت.
 *
 * مانند tests/homepage-settings.mjs فقط روی نمونهٔ محلی ایزوله اجرا می‌شود و
 * در پایان همهٔ تنظیمات (و شمار بازدیدهای دست‌خورده) را به حالت اول برمی‌گرداند.
 * اگر SQLITE_PATH در محیط باشد، آزمون‌های شمارندهٔ بازدید با دادهٔ واقعیِ
 * همان دیتابیس انجام می‌شود؛ در غیر این صورت آن بخش با پیام رد می‌شود.
 */
import assert from 'node:assert/strict';
const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'Never mutate production');
const jar = new Map();
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; console.log('PASS ' + label); };
const decode = s => s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');
async function get(path, options = {}) {
  const response = await fetch(new URL(path, base), { redirect: 'manual', signal: AbortSignal.timeout(20000), ...options,
    headers: { Cookie: [...jar].map(([k, v]) => `${k}=${v}`).join('; '), ...options.headers } });
  for (const c of response.headers.getSetCookie()) { const pair = c.split(';')[0]; const i = pair.indexOf('='); jar.set(pair.slice(0, i), pair.slice(i + 1)); }
  const body = await response.text();
  check(!/Warning:|Fatal error:|Uncaught|شناسه پیگیری/.test(body), 'no PHP error ' + path);
  return { response, body };
}
const csrf = s => s.match(/name="csrf_token"\s+value="([^"]+)"/)?.[1];
/** GET با دنبال‌کردن redirectهای canonical (حداکثر ۵ پرش). */
async function getFollow(path) {
  let r = await get(path);
  for (let i = 0; i < 5 && [301, 302, 303, 307, 308].includes(r.response.status); i++) {
    const location = r.response.headers.get('location');
    if (!location) break;
    r = await get(new URL(location, base).pathname + new URL(location, base).search);
  }
  return r;
}
function formValues(html) {
  const out = new URLSearchParams();
  const form = html.match(/<form[^>]*class="admin-form"[\s\S]*?<\/form>/)?.[0];
  assert.ok(form, 'settings form');
  for (const m of form.matchAll(/<input\b[^>]*>/g)) {
    const tag = m[0], name = tag.match(/name="([^"]+)"/)?.[1];
    if (!name || /type="(?:file|submit)"/.test(tag) || (/type="checkbox"/.test(tag) && !tag.includes('checked'))) continue;
    out.append(decode(name), decode(tag.match(/value="([^"]*)"/)?.[1] || ''));
  }
  for (const m of form.matchAll(/<textarea[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/textarea>/g)) out.set(decode(m[1]), decode(m[2]));
  for (const m of form.matchAll(/<select[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/select>/g)) {
    const opts = [...m[2].matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/g)];
    const opt = opts.find(o => o[1].includes('selected')) || opts[0];
    out.set(decode(m[1]), decode(opt?.[1].match(/value="([^"]*)"/)?.[1] || ''));
  }
  return out;
}
async function save(params) {
  const current = await get('/admin/settings');
  params = new URLSearchParams(params); params.set('csrf_token', csrf(current.body));
  const result = await get('/admin/settings', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params });
  check(result.response.status === 303, 'settings committed with 303');
}

// ── ورود مدیر ────────────────────────────────────────────────────────────────
let r = await get('/login');
r = await get('/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ csrf_token: csrf(r.body), identifier: process.env.ADMIN_USER || 'admin', password: process.env.ADMIN_PASSWORD || 'TestAdmin123!@#' }) });
check(r.response.status === 303, 'admin login succeeds');

const originalSettings = formValues((await get('/admin/settings')).body);

// ── ۱) صفحهٔ اصلی: چیدمان فشرده و افقی ─────────────────────────────────────
let home = (await get('/')).body;
check(/data-density="(compact|cozy)"/.test(home) && /data-thumb="(sm|md)"/.test(home), 'homepage carries density/thumb attributes');
check((home.match(/<article class="jhd-lead">/g) || []).length === 1, 'exactly one featured lead story');
const lead = home.match(/<article class="jhd-lead">[\s\S]*?<\/article>/)?.[0] || '';
check(lead.includes('jhd-lead__body'), 'lead renders its text side');
check(!lead.includes('<img') || lead.includes('jhd-lead__media'), 'lead is horizontal: whenever it has an image, media sits beside the text');
check(lead.includes('jhd-lead__meta'), 'lead shows date/author meta');
const homeSection = key => home.match(new RegExp(`<section class="jhd-section" data-home-section="${key}"[\\s\\S]*?</section>`))?.[0] || '';
check(homeSection('topics') !== '', 'topics section (موضوعات علمی) renders on the homepage');
check(homeSection('announcements') !== '', 'announcements section (اطلاعیه‌ها) renders on the homepage');
check(!/>اطلاعیه</.test(homeSection('news')), 'news section has no announcement duplicates while its own section is on');
for (const key of ['news', 'writing', 'research', 'books', 'lessons', 'media', 'topics', 'announcements']) {
  const section = homeSection(key);
  if (section === '') continue;
  check(/col-12 col-md-6/.test(section) && section.includes('jhd-card--compact'), `section ${key} uses compact horizontal cards by default`);
  check((section.match(/<article\b/g) || []).length <= 12, `section ${key} respects its item limit`);
}

// ── ۲) ریل‌های انتهای مطلب (هم‌نوع، واقعی، بدون تکرار) ─────────────────────
const articleHref = home.match(/href="(\/articles\/[a-z0-9-]+)"/)?.[1];
assert.ok(articleHref, 'a seeded article link exists on the homepage');
let post = (await get(articleHref)).body;
check(post.includes('jhd-end-rails'), 'end-of-article rails render');
check(post.indexOf('jhd-prose') < post.indexOf('jhd-end-rails') && post.indexOf('jhd-end-rails') < post.indexOf('<footer'), 'rails sit after the body and before the footer');
check((post.match(/id="jhd-end-latest"/g) || []).length <= 1 && (post.match(/id="jhd-end-popular"/g) || []).length <= 1, 'rail ids are unique');
const endLatest = post.match(/<section class="jhd-rail" aria-labelledby="jhd-end-latest">[\s\S]*?<\/section>/)?.[0] || '';
check(endLatest !== '', 'latest same-type rail exists');
check(!endLatest.includes(articleHref), 'the current article is excluded from its own rails');
const railItemHrefs = [...endLatest.matchAll(/<a class="jhd-rail__link" href="([^"]+)"/g)].map(m => m[1]);
check(railItemHrefs.length > 0, 'latest rail has real items');
for (const href of railItemHrefs) check(href.startsWith('/articles/'), 'rail item is the same content type: ' + href);

// ── ۳) آستانهٔ پربازدید با شمارندهٔ واقعی ──────────────────────────────────
let sqlitePath = process.env.SQLITE_PATH || '';
let db = null;
if (sqlitePath) {
  const { DatabaseSync } = await import('node:sqlite');
  db = new DatabaseSync(sqlitePath);
}
const modified = new URLSearchParams(originalSettings);
try {
  if (db) {
    // دو «قدیمی‌ترین» مقاله انتخاب می‌شوند تا در ریل تازه‌ترین‌ها نباشند؛
    // ریل پربازدید طبق طراحی، مواردِ presenti در تازه‌ترین‌ها را تکرار نمی‌کند.
    const others = db.prepare("SELECT id, title, slug, view_count FROM posts WHERE post_type='article' AND status='published' AND slug <> ? ORDER BY COALESCE(published_at, created_at) ASC, id ASC LIMIT 2").all(articleHref.split('/').pop());
    assert.ok(others.length === 2, 'two older articles available for the counter test');
    const restore = [];
    for (const a of others) restore.push([a.id, a.view_count]);
    try {
      db.prepare('UPDATE posts SET view_count=? WHERE id=?').run(120, others[0].id);
      db.prepare('UPDATE posts SET view_count=? WHERE id=?').run(90, others[1].id);

      // آستانه ۱۰۰: فقط مطلب ۱۲۰بازدید واجد شرایط است.
      modified.set('rails_min_views', '100');
      await save(modified);
      post = (await get(articleHref)).body;
      const pop100 = post.match(/<section class="jhd-rail" aria-labelledby="jhd-end-popular">[\s\S]*?<\/section>/)?.[0] || '';
      check(pop100.includes(others[0].title) && !pop100.includes(others[1].title), 'threshold 100 keeps only the genuinely popular item');
      home = (await get('/')).body;
      const homePopular = homeSection('popular');
      check(homePopular.includes(others[0].title) && !homePopular.includes(others[1].title), 'homepage popular section uses the same real threshold');

      // آستانه ۲۰۰: هیچ مطلبی واجد نیست → بخش پربازدید رندر نمی‌شود (بدون دادهٔ ساختگی).
      modified.set('rails_min_views', '200');
      await save(modified);
      post = (await get(articleHref)).body;
      check(!post.includes('jhd-end-popular') && post.includes('jhd-end-latest'), 'no eligible item: popular rail is hidden, latest stays');

      // آستانه ۵۰ + ترتیب نزولی واقعی.
      modified.set('rails_min_views', '50');
      await save(modified);
      post = (await get(articleHref)).body;
      const pop50 = post.match(/<section class="jhd-rail" aria-labelledby="jhd-end-popular">[\s\S]*?<\/section>/)?.[0] || '';
      check(pop50.indexOf(others[0].title) < pop50.indexOf(others[1].title) && pop50.includes('۱۲۰'), 'popular rail is ordered by the real counter and shows it');
    } finally {
      for (const [id, count] of restore) db.prepare('UPDATE posts SET view_count=? WHERE id=?').run(count, id);
    }
  } else {
    console.log('SKIP view-counter checks (SQLITE_PATH not set in this environment)');
  }

  // ── ۴) کلیدها و تعدادها از پنل مدیریت ───────────────────────────────────
  modified.set('rails_min_views', String(originalSettings.get('rails_min_views') || 50));
  modified.set('rails_latest_count', '2');
  modified.delete('content_rails[]');
  modified.append('content_rails[]', 'latest'); // popular off
  await save(modified);
  post = (await get(articleHref)).body;
  check(!post.includes('jhd-end-popular'), 'popular rail can be switched off');
  const latestCount = (post.match(/<section class="jhd-rail" aria-labelledby="jhd-end-latest">[\s\S]*?<\/section>/)?.[0].match(/<li class="jhd-rail__item">/g) || []).length;
  check(latestCount > 0 && latestCount <= 2, 'latest rail respects the configured count (' + latestCount + ')');

  modified.delete('content_rails[]'); // both off
  await save(modified);
  post = (await get(articleHref)).body;
  check(!post.includes('jhd-end-rails'), 'both rails off: no end-rails markup at all');

  // ── ۵) حالت کارت‌ها و تراکم ──────────────────────────────────────────────
  modified.delete('content_rails[]');
  modified.append('content_rails[]', 'latest');
  modified.append('content_rails[]', 'popular');
  modified.set('homepage_layout[news][mode]', 'grid');
  modified.set('homepage_density', 'cozy');
  modified.set('homepage_thumb', 'md');
  await save(modified);
  home = (await get('/')).body;
  const newsGrid = homeSection('news');
  check(newsGrid !== '' && !newsGrid.includes('jhd-card--compact') && newsGrid.includes('row g-3'), 'grid mode restores the larger card layout');
  check(home.includes('data-density="cozy"') && home.includes('data-thumb="md"'), 'density/thumb attributes follow the panel');

  // ── ۶) ماندگاری تنظیمات پس از بارگذاری مجدد ──────────────────────────────
  const reloaded = formValues((await get('/admin/settings')).body);
  check(reloaded.get('homepage_layout[news][mode]') === 'grid' && reloaded.get('homepage_density') === 'cozy'
      && reloaded.get('homepage_thumb') === 'md' && reloaded.get('rails_latest_count') === '2'
      && reloaded.getAll('content_rails[]').join(',') === 'latest,popular', 'every new setting persisted after reload');

  // ── ۷) تیتر تیرهٔ تایپ‌شده توسط مدیر (متن ساده) ─────────────────────────
  const stamp = Date.now();
  const title = 'md-heading-test-' + stamp;
  let create = await get('/admin/posts/create.php');
  const created = await get('/admin/posts/create.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ csrf_token: csrf(create.body), title, status: 'published', post_type: 'article',
      content: '## تیتر تیرهٔ آزمون\n\nیک پاراگراف ساده با **بخش مهم** داخل آن.\n\n### زیرتیتر آزمون\n\nپاراگراف دوم.\n\n## تیتر بخش سوم\n\nپاراگراف پایانی.' }) });
  check(created.response.status === 303, 'admin can publish plain-text content');
  const newId = new URL(created.response.headers.get('location'), base).searchParams.get('id');
  try {
    const page = (await getFollow('/post.php?slug=' + title)).body;
    check(/<h2[^>]*>تیتر تیرهٔ آزمون<\/h2>/.test(page), '## line becomes a dark h2 heading in the article body');
    check(/<h3[^>]*>زیرتیتر آزمون<\/h3>/.test(page), '### line becomes an h3 heading');
    check(page.includes('<strong>بخش مهم</strong>'), '**bold** becomes <strong>');
    check(page.includes('jhd-article-toc') && page.includes('تیتر تیرهٔ آزمون'), 'promoted headings join the article outline/TOC');
  } finally {
    if (newId) {
      // توکن حذف از همان صفحهٔ ویرایش مطلب خوانده می‌شود (csrfField).
      const editPage = await get('/admin/posts/edit.php?id=' + newId);
      const del = await get('/admin/posts/delete.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf(editPage.body), id: newId }) });
      check([302, 303].includes(del.response.status), 'test post cleaned up');
      const after = await get('/post.php?slug=' + title);
      check(after.response.status === 404, 'deleted post is gone from the public site');
    }
  }
} finally {
  await save(originalSettings);
  home = (await get('/')).body;
  check(home.includes('data-density="compact"') && homeSection('news').includes('jhd-card--compact'), 'original settings restored on the homepage');
  if (db) db.close();
}
console.log(`${checks} compact-design checks passed; settings and counters restored.`);
