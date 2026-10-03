/**
 * tests/php-node-server.mjs — dev/test HTTP server for the PHP app, using the
 * in-process PHP runtime from @platformatic/php-node (already a dependency of
 * this project). It is the Node equivalent of:
 *
 *     php -S 0.0.0.0:8080 router.php
 *
 * Every request is dispatched to router.php (the front controller), exactly as
 * `.htaccess` / `vercel.json` do in production, while REQUEST_URI keeps the
 * original path. This makes the whole test-suite runnable on machines that
 * have Node.js but no standalone PHP CLI.
 *
 * Usage:
 *   PHP_DOCROOT=/path/to/jametulhoda PORT=8080 node tests/php-node-server.mjs
 *
 * Environment variables are inherited by PHP (env_value() uses getenv()), so
 * the database/upload settings documented in README.md still apply:
 *   APP_ENV=development DB_DRIVER=sqlite SQLITE_PATH=/tmp/jhd.sqlite \
 *   SESSION_DRIVER=files UPLOAD_STORAGE=local node tests/php-node-server.mjs
 *
 * This file is a test harness only; production never uses it.
 */
import http from 'node:http';
import { Php, Request, Rewriter } from '@platformatic/php-node';

const docroot = process.env.PHP_DOCROOT || process.cwd();
const port = Number(process.env.PORT || 8080);
const host = process.env.HOST || '0.0.0.0';

/** All requests go to the front controller; router.php reads REQUEST_URI itself. */
const rewriter = new Rewriter([{ rewriters: [{ type: 'path', args: ['^(.*)$', '/router.php'] }] }]);
const php = new Php({ docroot, rewriter, throwRequestErrors: false });

const server = http.createServer(async (req, res) => {
  const startedAt = Date.now();
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const body = Buffer.concat(chunks);

  const headers = {};
  for (const [name, value] of Object.entries(req.headers)) {
    if (value === undefined || value === null) continue;
    headers[name] = Array.isArray(value) ? value.map(String) : [String(value)];
  }

  const socket = {
    localAddress: req.socket?.localAddress ?? '127.0.0.1',
    localPort: Number(req.socket?.localPort ?? port),
    localFamily: 'IPv4',
    remoteAddress: req.socket?.remoteAddress ?? '127.0.0.1',
    remotePort: Number(req.socket?.remotePort ?? 0),
    remoteFamily: 'IPv4',
  };

  const url = 'http://' + (headers.host?.[0] ?? `127.0.0.1:${port}`) + req.url;

  let response;
  try {
    response = await php.handleRequest(new Request({ method: req.method, url, headers, body, socket }));
  } catch (error) {
    res.writeHead(500, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('PHP runtime error: ' + (error?.message ?? String(error)));
    return;
  }

  const out = {};
  if (response.headers) {
    for (const [name, value] of response.headers.entries()) {
      out[name.toLowerCase()] = response.headers.getAll ? response.headers.getAll(name) : [value];
    }
  }
  const payload = Buffer.from(response.body ?? new Uint8Array());
  res.writeHead(Number(response.status ?? 200), out);
  res.end(payload);
  console.log(`${req.method} ${req.url} -> ${response.status ?? 200} (${Date.now() - startedAt}ms)`);

  if (response.log && Buffer.byteLength(response.log)) {
    console.error('[php log] ' + String(Buffer.from(response.log)).trim());
  }
  if (response.exception) console.error('[php exception]', response.exception);
});

server.listen(port, host, () => {
  console.log(`PHP ${process.env.PHP_DOCROOT ? '' : ''}dev server on http://${host}:${port} (docroot ${docroot})`);
});
