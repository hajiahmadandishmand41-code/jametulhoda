/**
 * tests/mock-blob-server.mjs — شبیه‌ساز بسیار کوچک REST API ی Vercel Blob
 * ───────────────────────────────────────────────────────────────────────────
 * فقط برای آزمونِ مسیر واقعی storage.php بدون نیاز به هیچ نشانی یا توکن واقعی.
 * قراردادهایی که پیاده‌سازی می‌کند همان‌هایی هستند که @vercel/blob استفاده
 * می‌کند و آزمون بررسی می‌کند که کدِ ما دقیقاً همین‌ها را صدا بزند:
 *
 *   PUT    /{pathname}          → ذخیره و پاسخ { url, pathname, contentType, size }
 *   POST   /delete              → حذف با بدنهٔ {"urls":[…]}
 *   GET    /?limit=1            → فهرست (برای کشف مبدأ عمومی)
 *   GET    /{pathname}          → سروِ عمومی شیء
 *   GET    /__log               → درخواست‌های ثبت‌شده (برای assertion)
 */
import http from 'node:http';

const PORT = parseInt(process.env.MOCK_BLOB_PORT || '9111', 10);
const PUBLIC_ORIGIN = process.env.MOCK_BLOB_ORIGIN || `http://127.0.0.1:${PORT}`;
const PREFIX = new URL(PUBLIC_ORIGIN).pathname.replace(/\/+$/, ''); // e.g. '/public'

/** @type {Map<string, {body: Buffer, contentType: string}>} */
const objects = new Map();
/** @type {Array<{method: string, url: string, headers: object, body: string}>} */
const log = [];
const unauthorized = /invalid-token/;

const server = http.createServer((request, response) => {
  const chunks = [];
  request.on('data', (chunk) => chunks.push(chunk));
  request.on('end', () => {
    const body = Buffer.concat(chunks);
    const url = new URL(request.url, `http://127.0.0.1:${PORT}`);
    const pathname = decodeURIComponent(url.pathname);
    const headers = Object.fromEntries(
      Object.entries(request.headers).map(([k, v]) => [k.toLowerCase(), Array.isArray(v) ? v.join(',') : v])
    );
    log.push({ method: request.method, url: pathname, headers, body: body.toString('latin1').slice(0, 400) });

    const send = (status, payload, type = 'application/json') => {
      const data = typeof payload === 'string' || Buffer.isBuffer(payload) ? payload : JSON.stringify(payload);
      response.writeHead(status, { 'content-type': type, 'content-length': Buffer.byteLength(data) });
      response.end(data);
    };

    const authorization = String(headers.authorization || '');
    if (!authorization.startsWith('Bearer ') || unauthorized.test(authorization)) {
      send(403, { error: 'unauthorized' });
      return;
    }

    if (pathname === '/__log') { send(200, log); return; }
    if (pathname === '/__objects') { send(200, Array.from(objects.keys())); return; }

    // List (used by blobPublicOrigin)
    if (request.method === 'GET' && url.pathname === '/') {
      const blobs = Array.from(objects.entries()).slice(0, 1).map(([key, value]) => ({
        url: PUBLIC_ORIGIN + key,
        pathname: key.slice(1),
        contentType: value.contentType,
        size: value.body.length,
      }));
      send(200, { blobs, folders: [], hasMore: false, cursor: null });
      return;
    }

    // Delete — the contract the application must use.
    if (request.method === 'POST' && pathname === '/delete') {
      let parsed = {};
      try { parsed = JSON.parse(body.toString('utf8')); } catch { parsed = {}; }
      if (!Array.isArray(parsed.urls)) { send(400, { error: 'urls is required' }); return; }
      const removed = [];
      for (const target of parsed.urls) {
        const key = new URL(String(target)).pathname;
        if (objects.delete(key)) removed.push(key);
      }
      send(200, { deleted: removed.length });
      return;
    }

    // Upload
    if (request.method === 'PUT') {
      const key = pathname.startsWith(PREFIX + '/') ? pathname.slice(PREFIX.length) : pathname;
      const contentType = String(headers['x-content-type'] || headers['content-type'] || 'application/octet-stream');
      const suffix = headers['x-add-random-suffix'] === '0' ? '' : '-randomsuffix';
      const finalKey = key.replace(/\.([a-z0-9]+)$/, (m, ext) => `${suffix}.${ext}`);
      objects.set(finalKey, { body, contentType });
      send(200, {
        url: PUBLIC_ORIGIN + finalKey,
        pathname: finalKey.slice(1),
        contentType,
        size: body.length,
        uploadedAt: new Date().toISOString(),
      });
      return;
    }

    // Public read
    if (request.method === 'GET' || request.method === 'HEAD') {
      const object = objects.get(pathname);
      if (!object) { send(404, { error: 'not_found' }); return; }
      response.writeHead(200, {
        'content-type': object.contentType,
        'content-length': object.body.length,
      });
      response.end(request.method === 'HEAD' ? undefined : object.body);
      return;
    }

    send(405, { error: 'method_not_allowed' });
  });
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`mock blob server listening on ${PUBLIC_ORIGIN}`);
});
