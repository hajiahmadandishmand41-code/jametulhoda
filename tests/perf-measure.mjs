/**
 * tests/perf-measure.mjs — real response-time measurement of a live deployment.
 *
 * No guessing: for every URL it records the HTTP status, the time to first byte,
 * the full response time, the transferred size and the headers that prove *who*
 * answered (Vercel CDN cache vs. the PHP function) and *how long the server
 * itself* took (Server-Timing emitted by config/config.php when JHD_PROFILE=1).
 *
 * Usage:
 *   TEST_BASE_URL=https://jametulhoda.vercel.app node tests/perf-measure.mjs
 *   SAMPLES=3 LABEL=before node tests/perf-measure.mjs
 *
 * Output: a markdown table on stdout and in perf-report-<label>.md
 */
import { writeFileSync } from 'node:fs';

const base = (process.env.TEST_BASE_URL || 'https://jametulhoda.vercel.app').replace(/\/$/, '');
const samples = Number(process.env.SAMPLES || 3);
const label = process.env.LABEL || 'run';
const throttle = Number(process.env.THROTTLE_MS || 250);
// Hard ceiling per request. Without it a single hanging route eats the whole
// CI budget and we learn nothing about the remaining routes.
const timeoutMs = Number(process.env.REQUEST_TIMEOUT_MS || 60000);
const only = (process.env.ONLY_PATHS || '').split(',').map((s) => s.trim()).filter(Boolean);
const assetSamples = Number(process.env.ASSET_SAMPLES || samples);

const DOCUMENT_ROUTES = [
  '/', '/login', '/register', '/topics', '/news', '/articles', '/lessons',
  '/books', '/events', '/research', '/reports', '/media', '/speeches',
  '/about', '/contact', '/qa', '/search', '/announcements', '/programs',
  '/admin', '/admin/login', '/sitemap.xml', '/robots.txt',
  '/this-route-does-not-exist-xyz',
];

const STATIC_ASSETS = [
  '/assets/css/design-system.css',
  '/assets/vendor/bootstrap.rtl.min.css',
  '/assets/vendor/icons/bootstrap-icons.min.css',
  '/assets/vendor/plyr.css',
  '/assets/js/interface.js',
  '/assets/js/theme.js',
  '/assets/js/main.js',
  '/assets/fonts/Vazirmatn-Regular.woff2',
  '/assets/fonts/Amiri-Bold.woff2',
  '/assets/img/logo.png',
  '/assets/img/favicon.svg',
  '/favicon.ico',
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const INTERESTING_HEADERS = [
  'cache-control', 'content-type', 'content-length', 'age', 'etag',
  'x-vercel-cache', 'x-vercel-id', 'x-matched-path', 'server-timing',
  'content-encoding', 'location',
];

async function measureOnce(url) {
  const t0 = process.hrtime.bigint();
  try {
    const res = await fetch(url, {
      redirect: 'manual',
      signal: AbortSignal.timeout(timeoutMs),
      headers: { 'user-agent': 'jhd-perf-measure/1.0', 'accept-encoding': 'gzip, br' },
    });
    const tFirst = process.hrtime.bigint();
    const body = await res.arrayBuffer();
    const tEnd = process.hrtime.bigint();
    const headers = {};
    for (const h of INTERESTING_HEADERS) {
      const v = res.headers.get(h);
      if (v !== null) headers[h] = v;
    }
    return {
      ok: true,
      status: res.status,
      ttfb: Number(tFirst - t0) / 1e6,
      total: Number(tEnd - t0) / 1e6,
      bytes: body.byteLength,
      headers,
      bodyHead: new TextDecoder().decode(body.slice(0, 400)),
    };
  } catch (e) {
    return { ok: false, status: 0, ttfb: 0, total: Number(process.hrtime.bigint() - t0) / 1e6, bytes: 0, headers: {}, error: e.cause?.code || e.message };
  }
}

async function measure(path, n = samples) {
  const url = base + path;
  const runs = [];
  for (let i = 0; i < n; i++) {
    runs.push(await measureOnce(url));
    await sleep(throttle);
  }
  const good = runs.filter((r) => r.ok);
  const ttfbs = good.map((r) => r.ttfb).sort((a, b) => a - b);
  const totals = good.map((r) => r.total).sort((a, b) => a - b);
  const med = (a) => (a.length ? a[Math.floor(a.length / 2)] : 0);
  const first = runs[0];
  const last = good[good.length - 1] || first;
  return {
    path,
    statuses: runs.map((r) => (r.ok ? r.status : `ERR:${r.error}`)),
    coldTtfb: first.ttfb,
    medTtfb: med(ttfbs),
    minTtfb: ttfbs[0] || 0,
    maxTtfb: ttfbs[ttfbs.length - 1] || 0,
    medTotal: med(totals),
    bytes: last.bytes,
    headers: last.headers,
    bodyHead: last.bodyHead || '',
    error: first.ok ? '' : first.error,
  };
}

const fmt = (n) => (n >= 1000 ? (n / 1000).toFixed(2) + 's' : Math.round(n) + 'ms');

function table(rows, title) {
  const out = [];
  out.push(`### ${title}`);
  out.push('');
  out.push('| path | status | TTFB cold | TTFB med | TTFB min/max | total med | bytes | x-vercel-cache | cache-control | server-timing |');
  out.push('|---|---|---|---|---|---|---|---|---|---|');
  for (const r of rows) {
    out.push([
      '`' + r.path + '`',
      r.statuses.join('/'),
      fmt(r.coldTtfb),
      fmt(r.medTtfb),
      `${fmt(r.minTtfb)} / ${fmt(r.maxTtfb)}`,
      fmt(r.medTotal),
      r.bytes,
      r.headers['x-vercel-cache'] || '—',
      (r.headers['cache-control'] || '—').slice(0, 48),
      (r.headers['server-timing'] || '—').slice(0, 220),
    ].join(' | '));
  }
  out.push('');
  return out.join('\n');
}

const docList = only.length ? only : DOCUMENT_ROUTES;
const assetList = process.env.SKIP_ASSETS === '1' ? [] : STATIC_ASSETS;

console.log(`# Perf measurement — ${label}`);
console.log(`target: ${base}`);
console.log(`samples per URL: ${samples}`);
console.log(`started: ${new Date().toISOString()}`);
console.log('');

const docRows = [];
for (const p of docList) {
  const r = await measure(p);
  docRows.push(r);
  console.log(`  ${p} -> ${r.statuses.join('/')}  ttfb(med)=${fmt(r.medTtfb)} total=${fmt(r.medTotal)} bytes=${r.bytes}`);
}

const assetRows = [];
for (const p of assetList) {
  const r = await measure(p, assetSamples);
  assetRows.push(r);
  console.log(`  ${p} -> ${r.statuses.join('/')}  ttfb(med)=${fmt(r.medTtfb)} cache=${r.headers['x-vercel-cache'] || '—'}`);
}

// Who serves the static assets? A `x-vercel-cache: HIT` plus no PHP-specific
// header means the CDN; anything else means the PHP function ran.
const servedByFunction = assetRows.filter((r) => !['HIT', 'STALE'].includes(r.headers['x-vercel-cache'] || ''));

const report = [];
report.push(`# Perf measurement — ${label}`);
report.push('');
report.push(`- target: \`${base}\``);
report.push(`- samples per URL: ${samples}`);
report.push(`- UTC: ${new Date().toISOString()}`);
report.push('');
report.push(table(docRows, 'Document routes'));
report.push(table(assetRows, 'Static assets'));
report.push('### Findings');
report.push('');
report.push(`- static assets NOT served from the Vercel CDN cache (i.e. the PHP function ran): **${servedByFunction.length}/${assetRows.length}**`);
const bad = docRows.filter((r) => r.statuses.some((s) => typeof s === 'string' || s >= 500 || s === 0));
report.push(`- document routes returning 5xx / transport error: **${bad.length}** ${bad.length ? '(' + bad.map((r) => r.path).join(', ') + ')' : ''}`);
const slow = docRows.filter((r) => r.medTtfb > 1000);
report.push(`- document routes with median TTFB > 1s: **${slow.length}** ${slow.length ? '(' + slow.map((r) => `${r.path} ${fmt(r.medTtfb)}`).join(', ') + ')' : ''}`);
report.push('');
report.push('### Error bodies (first 400 bytes) for non-2xx/3xx document routes');
report.push('');
for (const r of docRows) {
  const s = r.statuses[r.statuses.length - 1];
  if (typeof s === 'number' && s < 400) continue;
  if (r.path.includes('does-not-exist')) continue;
  report.push('```');
  report.push(`${r.path} -> ${s}`);
  report.push((r.bodyHead || r.error || '').replace(/\s+/g, ' ').slice(0, 400));
  report.push('```');
}
report.push('');

// Edge-cache probe: does a repeat anonymous GET of "/" actually hit the CDN?
// `x-vercel-cache: MISS` on every sample is the difference between a visitor
// waiting for a full render and one being served in tens of milliseconds.
if (process.env.CACHE_PROBE === '1') {
  report.push('### Edge cache probe — 6 sequential GETs of `/`');
  report.push('');
  report.push('| # | status | x-vercel-cache | age | TTFB | cache-control |');
  report.push('|---|---|---|---|---|---|');
  console.log('cache probe:');
  for (let i = 1; i <= 6; i++) {
    const r = await measureOnce(base + '/');
    const row = `| ${i} | ${r.status} | ${r.headers['x-vercel-cache'] || '—'} | ${r.headers['age'] || '—'} | ${fmt(r.ttfb)} | ${r.headers['cache-control'] || '—'} |`;
    report.push(row);
    console.log('  ' + row);
    await sleep(3000);
  }
  report.push('');
}

const text = report.join('\n');
writeFileSync(`perf-report-${label}.md`, text);
console.log('');
console.log(text);
