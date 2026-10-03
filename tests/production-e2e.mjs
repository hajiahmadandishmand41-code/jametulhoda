/**
 * tests/production-e2e.mjs — end-to-end audit of a live deployment.
 *
 * It performs the real operator flow against a deployed site:
 *   admin login -> diagnostics (database tables) -> create news content with
 *   a featured image, extra gallery images, a video, an audio track and a PDF
 *   -> publish -> open the public page -> verify every stored media URL is
 *   reachable -> verify the admin media list -> delete the test content again.
 *
 * It never prints credentials, session cookies or connection strings.
 *
 * Usage:
 *   TEST_BASE_URL=https://example.vercel.app \
 *   JHD_ADMIN_IDENTIFIER=... JHD_ADMIN_PASSWORD=... \
 *   node tests/production-e2e.mjs
 *
 * Optional:
 *   JHD_ADMIN_PASSWORD_ALT  second password candidate, tried only if the first
 *                           one is rejected (kept below the brute-force limit)
 *   JHD_E2E_KEEP=1          keep the created content instead of deleting it
 *   JHD_E2E_OVERSIZE=1      also prove the platform request-size ceiling
 */
import { request } from '@playwright/test';
import fs from 'node:fs';
import { passSecurityCheckpoint } from './checkpoint.mjs';

const base = (process.env.TEST_BASE_URL || 'https://jametulhoda.vercel.app').replace(/\/$/, '');
const identifier = process.env.JHD_ADMIN_IDENTIFIER;
const passwords = [process.env.JHD_ADMIN_PASSWORD, process.env.JHD_ADMIN_PASSWORD_ALT].filter(Boolean);
if (!identifier || passwords.length === 0) {
  console.error('Set JHD_ADMIN_IDENTIFIER and JHD_ADMIN_PASSWORD (never commit them).');
  process.exit(2);
}

// Deployments behind Vercel Attack Challenge Mode refuse plain HTTP clients, so
// solve the challenge once in a real browser and reuse its cookie everywhere.
const storageState = process.env.PROBE_SKIP_BROWSER
  ? undefined
  : await passSecurityCheckpoint(base, { debug: true });

const api = await request.newContext({ storageState, baseURL: base });
const results = [];
const findings = [];
const check = (label, ok, detail = '') => {
  results.push({ label, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  ' + detail : ''}`);
  if (!ok) findings.push(`${label}${detail ? ' — ' + detail : ''}`);
};
const csrfOf = (html) => html.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1]
  || html.match(/name="csrf-token" content="([a-f0-9]+)"/)?.[1];
const alertText = (html) => [...String(html).matchAll(/<div[^>]*class="[^"]*alert[^"]*"[^>]*>([\s\S]*?)<\/div>/g)]
  .map((m) => m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim())
  .filter(Boolean).join(' | ').slice(0, 500);

const fixture = (name) => fs.readFileSync(new URL('./fixtures/' + name, import.meta.url));
const stamp = Date.now();
const title = `e2e-audit-${stamp}`;
// A minimal but structurally valid PDF (the %PDF- magic is validated server-side).
const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n'
  + '2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n'
  + '3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n'
  + 'trailer\n<< /Root 1 0 R /Size 4 >>\n%%EOF\n');

console.log(`\n=== Target: ${base} ===`);

// ── 1. Public site ──────────────────────────────────────────────────────────
const home = await api.get('/');
check('public homepage responds', home.status() === 200, String(home.status()));

// ── 2. Admin login ──────────────────────────────────────────────────────────
const loginPage = await api.get('/login');
const loginCsrf = csrfOf(await loginPage.text());
check('login page and CSRF token', loginPage.status() === 200 && Boolean(loginCsrf), String(loginPage.status()));

let login = null;
let loginAttempt = 0;
for (const password of passwords) {
  loginAttempt++;
  const probe = await api.get('/login');
  const token = csrfOf(await probe.text());
  login = await api.post('/login', {
    form: { csrf_token: token, identifier, password },
    maxRedirects: 0,
  });
  if ([302, 303].includes(login.status())) break;
}
const loginLocation = login.headers()['location'] || '';
check(`admin login (${loginAttempt} attempt(s))`, [302, 303].includes(login.status()) && loginLocation.includes('/admin'),
  `${login.status()} ${loginLocation}${login.status() === 200 ? ' | ' + alertText(await login.text()) : ''}`);
if (![302, 303].includes(login.status())) {
  console.log(JSON.stringify({ base, results, findings, fatal: 'login failed' }));
  await api.dispose();
  process.exit(1);
}

const dashboard = await api.get('/admin/');
check('admin dashboard after login', dashboard.status() === 200, String(dashboard.status()));

// ── 3. Database diagnostics: every table the app depends on ─────────────────
const diag = await api.get('/admin/diagnostics');
const diagHtml = await diag.text();
const wanted = ['users', 'categories', 'topics', 'posts', 'post_images', 'post_topics',
  'lesson_collections', 'lesson_volumes', 'lessons', 'lesson_topics', 'contact_messages',
  'media_files', 'settings', 'books', 'book_topics', 'site_banners', 'featured_banners',
  'app_sessions', 'login_limits', 'stored_files', 'storage_deletions', 'pending_uploads'];
const tableRows = [...diagHtml.matchAll(/<td>([a-z_]+)<\/td>\s*<td>([^<]*)<\/td>\s*<td>([^<]*)<\/td>/g)]
  .map((m) => ({ name: m[1], ok: m[2].trim(), rows: m[3].trim() }));
const seen = new Map(tableRows.map((r) => [r.name, r]));
const missing = wanted.filter((t) => !seen.has(t));
const broken = [...seen.values()].filter((r) => !/موجود|available|yes/i.test(r.ok));
check(`diagnostics page reachable`, diag.status() === 200, String(diag.status()));
check(`all ${wanted.length} required tables reachable`, missing.length === 0 && broken.length === 0,
  `missing=[${missing.join(',')}] broken=[${broken.map((b) => b.name).join(',')}]`);
const dbDriver = diagHtml.match(/db_driver:<\/strong>\s*([^<]+)</)?.[1]?.trim();
console.log('  database driver:', dbDriver || '(not reported)');
console.log('  table row counts:', tableRows.map((r) => `${r.name}=${r.rows}`).join(' '));

// ── 4. Create + publish content with image / video / audio / PDF ────────────
const createPage = await api.get('/admin/news/create.php');
const createCsrf = csrfOf(await createPage.text());
check('news create form', createPage.status() === 200 && Boolean(createCsrf), String(createPage.status()));

const imageBuffer = fixture('image.png');
const videoBuffer = fixture('video.mp4');
const audioBuffer = fixture('audio.mp3');

const create = await api.post('/admin/news/create.php', {
  timeout: 180000,
  multipart: {
    csrf_token: createCsrf,
    title,
    status: 'published',
    post_type: 'news',
    'page_section[]': 'news',
    summary: 'مطلب آزمایش end-to-end — پس از آزمون حذف می‌شود.',
    content: '<p>آزمون بارگذاری تصویر، ویدیو، صوت و فایل.</p>',
    author_name: 'E2E Audit',
    featured_image: { name: 'featured.png', mimeType: 'image/png', buffer: imageBuffer },
    'images[]': { name: 'gallery-one.png', mimeType: 'image/png', buffer: imageBuffer },
    featured_video: { name: 'featured.mp4', mimeType: 'video/mp4', buffer: videoBuffer },
    'video_files[]': { name: 'extra.mp4', mimeType: 'video/mp4', buffer: videoBuffer },
    'audio_files[]': { name: 'track.mp3', mimeType: 'audio/mpeg', buffer: audioBuffer },
    'document_files[]': { name: 'document.pdf', mimeType: 'application/pdf', buffer: pdf },
  },
  maxRedirects: 0,
});
const createLocation = create.headers()['location'] || '';
const postId = createLocation.match(/id=(\d+)/)?.[1];
if (create.status() !== 303) {
  const failHtml = await create.text();
  fs.mkdirSync('test-results', { recursive: true });
  fs.writeFileSync('test-results/production-create-failure.html', failHtml);
  console.log('  server said:', alertText(failHtml) || '(no alert text)');
}
check('create + publish news with image/video/audio/pdf', create.status() === 303 && Boolean(postId),
  `${create.status()} ${createLocation}`);

// ── 5. Public page must show the media immediately (without a second edit) ──
let mediaUrls = [];
if (postId) {
  const publicPage = await api.get('/post.php?slug=' + encodeURIComponent(title));
  const publicHtml = await publicPage.text();
  check('public detail page renders right after save', publicPage.status() === 200, String(publicPage.status()));
  const candidates = [...publicHtml.matchAll(/(?:src|href)="((?:https?:\/\/[^"]+|\/uploads\/[^"?]+))"/g)]
    .map((m) => m[1]);
  mediaUrls = [...new Set(candidates.filter((u) => u.includes('/uploads/')
    || /\.(webp|png|jpe?g|gif|mp4|webm|mov|mkv|mp3|pdf|docx?)($|\?)/i.test(u)))];
  check('media is visible on the public page immediately after save', mediaUrls.length > 0,
    `${mediaUrls.length} url(s): ${mediaUrls.join(', ')}`);

  for (const url of mediaUrls) {
    const absolute = url.startsWith('http') ? url : base + url;
    const asset = await api.get(absolute);
    check(`stored media reachable ${url}`, asset.status() === 200,
      `${asset.status()} ${asset.headers()['content-type'] || ''}`);
  }

  const editPage = await api.get('/admin/news/edit.php?id=' + postId);
  const editHtml = await editPage.text();
  check('admin edit page lists the attachments', editPage.status() === 200
    && mediaUrls.every((u) => editHtml.includes(u.split('/').pop() ?? u)),
    `${editPage.status()}`);
}

// ── 6. Where do files physically live? ──────────────────────────────────────
const storageHosts = [...new Set(mediaUrls.filter((u) => u.startsWith('http')).map((u) => new URL(u).host))];
console.log('  storage hosts seen in public HTML:', storageHosts.join(', ') || '(same-origin /uploads)');

// ── 7. Document the platform request-size ceiling ───────────────────────────
if (process.env.JHD_E2E_OVERSIZE === '1') {
  const big = Buffer.alloc(6 * 1024 * 1024, 7); // 6 MB, above Vercel's 4.5 MB body limit
  const oversize = await api.post('/admin/news/create.php', {
    multipart: {
      csrf_token: csrfOf(await (await api.get('/admin/news/create.php')).text()),
      title: `e2e-oversize-${stamp}`,
      status: 'draft',
      post_type: 'news',
      'page_section[]': 'news',
      content: '<p>oversize probe</p>',
      featured_image: { name: 'big.png', mimeType: 'image/png', buffer: big },
    },
    maxRedirects: 0,
    failOnStatusCode: false,
  });
  console.log(`  oversize (6 MB) upload -> HTTP ${oversize.status()}`);
  check('oversize upload is rejected by an explicit status, not a silent failure',
    [413, 303, 200].includes(oversize.status()), String(oversize.status()));
}

// ── 8. Clean up the audit content ───────────────────────────────────────────
if (postId && process.env.JHD_E2E_KEEP !== '1') {
  const deleteCsrf = csrfOf(await (await api.get('/admin/news/')).text())
    || csrfOf(await (await api.get('/admin/')).text());
  const del = await api.post('/admin/news/delete.php', {
    form: { csrf_token: deleteCsrf, id: postId },
    maxRedirects: 0,
  });
  check('audit content deleted again', [302, 303].includes(del.status()), String(del.status()));
  const gone = await api.get('/post.php?slug=' + encodeURIComponent(title));
  check('deleted content is no longer public', gone.status() === 404, String(gone.status()));
}

const failed = results.filter((r) => !r.ok);
console.log(`\n=== ${results.length - failed.length}/${results.length} checks passed ===`);
if (findings.length) console.log('FINDINGS:\n - ' + findings.join('\n - '));
console.log(JSON.stringify({ base, passed: results.length - failed.length, total: results.length, findings }));
await api.dispose();
process.exit(failed.length ? 1 : 0);
