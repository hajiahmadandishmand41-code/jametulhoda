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
const smallAudio = Buffer.from([0x49, 0x44, 0x33, 0x04, 0x00, 0x00]);
const expectedFileCount = 3;
const appOrigin = 'http://jhd-upload.test';
const storageOrigin = 'https://test-project.storage.supabase.co';
const endpoint = storageOrigin + '/storage/v1/upload/resumable/sign';
let grantSequence = 0;
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

    // Exercise the signed-PUT fallback with a real request shape. This fake
    // Storage server records the complete file body without involving Vercel.
    const signedPutMatch = url.pathname.match(/\/storage\/v1\/object\/upload\/sign\/site-media\/(.+)$/);
    if (req.method() === 'PUT' && signedPutMatch) {
      const path = decodeURIComponent(signedPutMatch[1]);
      const grant = grants.get(path);
      const payload = req.postDataBuffer() || Buffer.alloc(0);
      if (!grant || url.searchParams.get('token') !== grant.token || payload.length !== grant.size) {
        return route.fulfill({ status: 403, headers: corsHeaders, body: 'invalid signed PUT' });
      }
      const uploadId = 'signed-' + (++uploadSequence);
      uploads.set(uploadId, { path, size: grant.size, offset: payload.length, bytes: payload.length, patches: [payload.length], signedPut: true });
      return route.fulfill({ status: 200, headers: corsHeaders, body: JSON.stringify({ Key: path }) });
    }

    if (url.pathname === '/storage/v1/upload/resumable/sign' && req.method() === 'POST') {
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
      // The audio fixture intentionally simulates a deployment where the TUS
      // route is missing; the browser must upload it through signed PUT.
      if (metadata.objectName.endsWith('.mp3')) {
        return route.fulfill({ status: 404, headers: corsHeaders, body: 'not found' });
      }
      const uploadId = String(++uploadSequence);
      uploads.set(uploadId, { path: metadata.objectName, size: grant.size, offset: 0, bytes: 0, patches: [] });
      return route.fulfill({
        status: 201,
        headers: { ...corsHeaders, 'tus-resumable': '1.0.0', location: endpoint + '/' + uploadId },
        body: '',
      });
    }

    const uploadMatch = url.pathname.match(/\/storage\/v1\/upload\/resumable\/(?:sign\/)?(\d+)$/);
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
        '<label>صوت‌ها <input type="file" name="audio_files[]" accept="audio/*" multiple></label>' +
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
          body: JSON.stringify({
            ok: true,
            ...grant,
            tus_endpoint: endpoint,
            signed_url: storageOrigin + '/storage/v1/object/upload/sign/site-media/' + path + '?token=' + encodeURIComponent(grant.token),
            public_url: 'https://test-project.supabase.co/storage/v1/object/public/site-media/' + path
          }),
        });
      }
      if (form.get('action') === 'finalize') {
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
        directPathCount: (bodyText.match(/jhd_direct\[[^\]]+\]\[\]/g) || []).length,
        containsAudioName: bodyText.includes('filename="lecture.mp3"'),
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
  const wasMultipleInput = (await page.locator('input[name="video_files[]"]').getAttribute('multiple')) !== null;
  await page.locator('input[name="video_files[]"]').setInputFiles([
    { name: 'large-video.mp4', mimeType: 'video/mp4', buffer: largeVideo },
    { name: 'small-video.mp4', mimeType: 'video/mp4', buffer: smallVideo },
  ]);
  await page.locator('input[name="audio_files[]"]').setInputFiles([
    { name: 'lecture.mp3', mimeType: 'audio/mpeg', buffer: smallAudio },
  ]);
  check('mobile browser test uses a video larger than Vercel body cap', largeVideo.length > limit, String(largeVideo.length) + ' bytes');
  await page.locator('button[type="submit"]').click();
  await page.locator('#saved').waitFor({ timeout: 180000 });

  check('all selected files were uploaded directly and finalized', storedObjects.size === expectedFileCount, storedObjects.size + '/' + expectedFileCount);
  check('13 MiB video was stored in fake Supabase object store', storedObjects.get('staging/direct-1.mp4')?.size === largeVideo.length, String(largeVideo.length) + ' bytes');
  check('the small second video also avoided Vercel multipart', storedObjects.get('staging/direct-2.mp4')?.size === smallVideo.length, String(smallVideo.length) + ' bytes');
  check('audio file was uploaded and finalized through direct Storage', storedObjects.get('staging/direct-3.mp3')?.size === smallAudio.length && storedObjects.get('staging/direct-3.mp3')?.kind === 'audio', String(storedObjects.get('staging/direct-3.mp3')?.size || 0) + ' bytes');
  check('no application request crossed the 4.5 MiB cap', pendingLargePosts.length === 0, String(pendingLargePosts.length) + ' oversized app POST(s)');
  check('final form payload stayed below 4.5 MiB', appPosts.length === 1 && appPosts[0].bytes < limit, String(appPosts[0] ? appPosts[0].bytes : 0) + ' bytes');
  check('final form contains both staging paths but neither original file', appPosts.length === 1 && appPosts[0].directPathCount === expectedFileCount && !appPosts[0].containsLargeName && !appPosts[0].containsSmallName && !appPosts[0].containsAudioName, JSON.stringify(appPosts[0] || {}));
  check('save was not submitted while TUS upload was incomplete', prematureSaves.length === 0, String(prematureSaves.length) + ' early submit(s)');
  check('TUS chunks or signed PUT transferred every full file', Array.from(uploads.values()).length === expectedFileCount && Array.from(uploads.values()).every((item) => item.offset === item.size && item.bytes === item.size && item.patches.length > 0), JSON.stringify(Array.from(uploads.values()).map((u) => ({ path: u.path, size: u.size, chunks: u.patches, transport: u.signedPut ? 'signed PUT fallback' : 'TUS' }))));
  check('audio succeeded through signed PUT after the simulated TUS 404', uploads.get('signed-3')?.signedPut === true && storedObjects.get('staging/direct-3.mp3')?.size === smallAudio.length, 'TUS 404 → direct signed PUT → finalize');
  check('test covers multi-file input on a mobile viewport', wasMultipleInput && page.viewportSize()?.width === 390, 'multiple + 390px viewport');
  console.log('\nDirect upload regression: ' + storedObjects.size + '/' + expectedFileCount + ' files stored; final request ' + (appPosts[0] ? appPosts[0].bytes : 0) + ' bytes.');
  await context.close();
} finally {
  await browser.close();
}
