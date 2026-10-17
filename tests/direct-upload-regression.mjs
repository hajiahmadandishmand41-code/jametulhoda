/**
 * Regression test for the direct-upload / image-optimizer submit race.
 * Real browser scripts run with mobile emulation and a fake TUS server.
 */
import { chromium } from '@playwright/test';
import fs from 'node:fs';

const directScript = fs.readFileSync(new URL('../assets/js/supabase-direct-upload.js', import.meta.url), 'utf8');
const optimizeScript = fs.readFileSync(new URL('../assets/js/upload-optimize.js', import.meta.url), 'utf8');
const fixtureVideo = fs.readFileSync(new URL('./fixtures/video.mp4', import.meta.url));
const limit = 4.5 * 1024 * 1024;
const largeVideo = Buffer.concat([fixtureVideo, Buffer.alloc(Math.max(0, 13 * 1024 * 1024 - fixtureVideo.length), 0x41)]);
const smallVideo = Buffer.from(fixtureVideo);
const expectedFileCount = 2;
const appOrigin = 'http://jhd-upload.test';
const storageOrigin = 'https://test-project.storage.supabase.co';
const endpoint = storageOrigin + '/storage/v1/upload/resumable';
let grantSequence = 0;
let failureMode = '';
let signedPutAttempts = 0;
let uploadSequence = 0;
const grants = new Map();
const uploads = new Map();
const storedObjects = new Map();
const appPosts = [];
const prematureSaves = [];

const check = (label, ok, detail = '') => {
  console.log((ok ? 'PASS ' : 'FAIL ') + label + (detail ? ' — ' + detail : ''));
  if (!ok) throw new Error(label + (detail ? ': ' + detail : ''));
};

const corsHeaders = {
  'access-control-allow-origin': appOrigin,
  'access-control-allow-methods': 'POST,PATCH,HEAD,OPTIONS',
  'access-control-allow-headers': 'Tus-Resumable,Upload-Length,Upload-Metadata,x-signature,x-upsert,Upload-Offset,Content-Type',
  'access-control-expose-headers': 'Location,Upload-Offset,Tus-Resumable',
  'cache-control': 'no-store',
};

const browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE || undefined, args: ['--no-sandbox'] });
try {
  const context = await browser.newContext({
    isMobile: true,
    hasTouch: true,
    viewport: { width: 390, height: 844 },
  });
  const page = await context.newPage();

  await page.route(storageOrigin + '/**', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    const headers = req.headers();

    if (req.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers: corsHeaders, body: '' });
    }

    if (req.method()==='PUT') signedPutAttempts++;
    if (url.pathname === '/storage/v1/upload/resumable' && req.method() === 'POST') {
      if(failureMode==='storage') return route.fulfill({status:403,headers:corsHeaders,body:'quota or policy rejected upload'});
      const metadata = {};
      for (const item of (headers['upload-metadata'] || '').split(',')) {
        const parts = item.split(' ');
        if (parts.length >= 2) {
          try { metadata[parts[0]] = Buffer.from(parts.slice(1).join(' '), 'base64').toString('utf8'); } catch {}
        }
      }
      const grant = grants.get(metadata.objectName);
      if (!grant || headers['x-signature'] !== grant.token || Number(headers['upload-length']) !== grant.size) {
        return route.fulfill({ status: 403, headers: corsHeaders, body: 'invalid signed grant' });
      }
      const uploadId = String(++uploadSequence);
      uploads.set(uploadId, { path: metadata.objectName, size: grant.size, offset: 0, bytes: 0, patches: [] });
      return route.fulfill({
        status: 201,
        headers: { ...corsHeaders, 'tus-resumable': '1.0.0', location: endpoint + '/' + uploadId },
        body: '',
      });
    }

    const uploadMatch = url.pathname.match(/\/storage\/v1\/upload\/resumable\/(\d+)$/);
    if (uploadMatch && req.method() === 'PATCH') {
      const upload = uploads.get(uploadMatch[1]);
      const offset = Number(headers['upload-offset']);
      const chunk = req.postDataBuffer() || Buffer.alloc(0);
      if (!upload || offset !== upload.offset || chunk.length < 1 || upload.offset + chunk.length > upload.size) {
        return route.fulfill({ status: 409, headers: corsHeaders, body: 'invalid offset' });
      }
      upload.offset += chunk.length;
      upload.bytes += chunk.length;
      upload.patches.push(chunk.length);
      return route.fulfill({
        status: 204,
        headers: { ...corsHeaders, 'tus-resumable': '1.0.0', 'upload-offset': String(upload.offset) },
        body: '',
      });
    }

    if (uploadMatch && req.method() === 'HEAD') {
      const upload = uploads.get(uploadMatch[1]);
      return route.fulfill({
        status: upload ? 200 : 404,
        headers: { ...corsHeaders, 'tus-resumable': '1.0.0', 'upload-offset': String(upload ? upload.offset : 0), 'upload-length': String(upload ? upload.size : 0) },
        body: '',
      });
    }

    return route.fulfill({ status: 404, headers: corsHeaders, body: 'not found' });
  });

  await page.route(appOrigin + '/**', async (route) => {
    const req = route.request();
    const url = new URL(req.url());

    if (url.pathname === '/assets/js/supabase-direct-upload.js') {
      return route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: directScript });
    }
    if (url.pathname === '/assets/js/upload-optimize.js') {
      return route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: optimizeScript });
    }
    if (url.pathname === '/admin/test' && req.method() === 'GET') {
      const html = '<!doctype html><html lang="fa" dir="rtl"><head>' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<script src="/assets/js/supabase-direct-upload.js"></script>' +
        '<script defer src="/assets/js/upload-optimize.js"></script></head><body>' +
        '<form action="/admin/save" method="post" enctype="multipart/form-data">' +
        '<input type="hidden" name="csrf_token" value="test-csrf-token-with-more-than-twenty-characters">' +
        '<label>ویدیوها <input type="file" name="video_files[]" accept="video/*" multiple required></label>' +
        '<button type="submit">ذخیره</button></form></body></html>';
      return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
    }
    if (url.pathname === '/admin/storage/direct' && req.method() === 'POST') {
      const form = new URLSearchParams(req.postData() || '');
      if (form.get('csrf_token') !== 'test-csrf-token-with-more-than-twenty-characters') {
        return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'invalid CSRF' }) });
      }
      if (form.get('action') === 'sign') {
        const path = 'staging/direct-' + (++grantSequence) + '.' + (form.get('name') || '').split('.').pop();
        const grant = {
          path: path,
          size: Number(form.get('size')),
          token: 'signed-token-' + grantSequence,
          bucket: 'site-media',
          kind: form.get('kind'),
        };
        grants.set(path, grant);
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ ok: true, ...grant, signed_url:storageOrigin+'/object/upload/sign/'+grant.path, tus_endpoint: endpoint, public_url: 'https://test-project.supabase.co/storage/v1/object/public/site-media/' + path }),
        });
      }
      if (form.get('action') === 'finalize') {
        if(failureMode==='finalize') return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,error:'remote file not confirmed'})});
        const path = form.get('path');
        const grant = grants.get(path);
        const upload = Array.from(uploads.values()).find((item) => item.path === path);
        const size = Number(form.get('size'));
        if (!grant || !upload || upload.offset !== grant.size || size !== grant.size) {
          return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'file not completely stored' }) });
        }
        storedObjects.set(path, { size: size, kind: grant.kind, chunks: upload.patches.slice() });
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, path: path, size: size }) });
      }
      return route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'unknown action' }) });
    }
    if (url.pathname === '/admin/save' && req.method() === 'POST') {
      const body = req.postDataBuffer() || Buffer.alloc(0);
      const bodyText = body.toString('latin1');
      appPosts.push({
        path: url.pathname,
        bytes: body.length,
        containsLargeName: bodyText.includes('filename="large-video.mp4"'),
        containsSmallName: bodyText.includes('filename="small-video.mp4"'),
        directPathCount: (bodyText.match(/jhd_direct\[video_files\]\[\]/g) || []).length,
      });
      if (storedObjects.size !== expectedFileCount) prematureSaves.push({ bytes: body.length, finalized: storedObjects.size });
      if (body.length > limit) {
        return route.fulfill({ status: 413, contentType: 'text/html; charset=utf-8', body: '<h1>413 Payload Too Large</h1>' });
      }
      if (storedObjects.size !== expectedFileCount) {
        return route.fulfill({ status: 409, contentType: 'text/html; charset=utf-8', body: '<h1>submitted before upload finished</h1>' });
      }
      return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<h1 id="saved">saved</h1>' });
    }

    return route.fulfill({ status: 404, contentType: 'text/plain', body: 'not found' });
  });

  const pendingLargePosts = [];
  page.on('request', (request) => {
    if (request.method() !== 'POST') return;
    try {
      if (new URL(request.url()).origin !== appOrigin) return;
    } catch { return; }
    const body = request.postDataBuffer();
    if (body && body.length > limit) pendingLargePosts.push({ url: request.url(), bytes: body.length });
  });

  await page.goto(appOrigin + '/admin/test');
  const wasMultipleInput = (await page.locator('input[type="file"]').getAttribute('multiple')) !== null;
  await page.locator('input[type="file"]').setInputFiles([
    { name: 'large-video.mp4', mimeType: 'video/mp4', buffer: largeVideo },
    { name: 'small-video.mp4', mimeType: 'video/mp4', buffer: smallVideo },
  ]);
  check('mobile browser test uses a video larger than Vercel body cap', largeVideo.length > limit, String(largeVideo.length) + ' bytes');
  await page.locator('button[type="submit"]').click();
  await page.locator('#saved').waitFor({ timeout: 180000 });

  check('all selected files were uploaded directly and finalized', storedObjects.size === expectedFileCount, storedObjects.size + '/' + expectedFileCount);
  check('13 MiB video was stored in fake Supabase object store', storedObjects.get('staging/direct-1.mp4')?.size === largeVideo.length, String(largeVideo.length) + ' bytes');
  check('the small second selection also avoided Vercel multipart', storedObjects.get('staging/direct-2.mp4')?.size === smallVideo.length, String(smallVideo.length) + ' bytes');
  check('no application request crossed the 4.5 MiB cap', pendingLargePosts.length === 0, String(pendingLargePosts.length) + ' oversized app POST(s)');
  check('final form payload stayed below 4.5 MiB', appPosts.length === 1 && appPosts[0].bytes < limit, String(appPosts[0] ? appPosts[0].bytes : 0) + ' bytes');
  check('final form contains both staging paths but neither original file', appPosts.length === 1 && appPosts[0].directPathCount === expectedFileCount && !appPosts[0].containsLargeName && !appPosts[0].containsSmallName, JSON.stringify(appPosts[0] || {}));
  check('save was not submitted while TUS upload was incomplete', prematureSaves.length === 0, String(prematureSaves.length) + ' early submit(s)');
  check('TUS actually transferred the full file lengths by chunks', Array.from(uploads.values()).every((item) => item.offset === item.size && item.bytes === item.size && item.patches.length > 0), JSON.stringify(Array.from(uploads.values()).map((u) => ({ path: u.path, size: u.size, chunks: u.patches }))));
  check('test covers multi-file input on a mobile viewport', wasMultipleInput && page.viewportSize()?.width === 390, 'multiple + 390px viewport');
  console.log('\nDirect upload regression: ' + storedObjects.size + '/' + expectedFileCount + ' files stored; final request ' + (appPosts[0] ? appPosts[0].bytes : 0) + ' bytes.');
  for(const mode of ['storage','finalize']) {
    failureMode=mode;
    const postsBefore=appPosts.length;
    await page.goto(appOrigin+'/admin/test');
    await page.locator('input[type="file"]').setInputFiles([{name:'retry-video.mp4',mimeType:'video/mp4',buffer:largeVideo}]);
    await page.locator('button[type="submit"]').click();
    await page.locator('[data-jhd-direct-status-global].alert-danger').waitFor();
    check(mode+' failure never submits content',appPosts.length===postsBefore);
    check(mode+' failure retains selected file and permits retry',await page.locator('input[type="file"]').evaluate(el=>el.files.length===1) && await page.locator('button[type="submit"]').isEnabled());
    check(mode+' failure never pretends file verification succeeded',await page.locator('input[name^="jhd_direct"]').count()===0);
  }
  check('policy/quota rejection never triggers duplicate signed PUT',signedPutAttempts===0);
  await context.close();
} finally {
  await browser.close();
}
