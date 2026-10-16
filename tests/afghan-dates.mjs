/**
 * tests/afghan-dates.mjs — Automated boundary & leap-year tests for Afghan Solar Hijri dates
 *
 * Verifies persianDate() and persianDateParts() in includes/functions.php across:
 *   • New Year (Nowruz / 1 Hamal) boundaries
 *   • Leap year (30 Hoot) vs non-leap year (29 Hoot) year-end boundaries
 *   • All 12 Afghan Solar Hijri month names (حمل، ثور، جوزا، سرطان، اسد، سنبله، میزان، عقرب، قوس، جدی، دلو، حوت)
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const cases = [
  // Year-end & start-of-year boundaries (including leap years 1399 and 1403)
  { input: '2020-03-19 12:00:00', expected: '29 حوت 1398', parts: { day: 29, month: 'حوت', year: 1398 } },
  { input: '2020-03-20 12:00:00', expected: '1 حمل 1399',  parts: { day: 1,  month: 'حمل', year: 1399 } },
  { input: '2021-03-20 12:00:00', expected: '30 حوت 1399', parts: { day: 30, month: 'حوت', year: 1399 } },
  { input: '2021-03-21 12:00:00', expected: '1 حمل 1400',  parts: { day: 1,  month: 'حمل', year: 1400 } },
  { input: '2024-03-19 12:00:00', expected: '29 حوت 1402', parts: { day: 29, month: 'حوت', year: 1402 } },
  { input: '2024-03-20 12:00:00', expected: '1 حمل 1403',  parts: { day: 1,  month: 'حمل', year: 1403 } },
  { input: '2025-03-20 12:00:00', expected: '30 حوت 1403', parts: { day: 30, month: 'حوت', year: 1403 } },
  { input: '2025-03-21 12:00:00', expected: '1 حمل 1404',  parts: { day: 1,  month: 'حمل', year: 1404 } },
  { input: '2026-03-20 12:00:00', expected: '29 حوت 1404', parts: { day: 29, month: 'حوت', year: 1404 } },
  { input: '2026-03-21 12:00:00', expected: '1 حمل 1405',  parts: { day: 1,  month: 'حمل', year: 1405 } },

  // All 12 Afghan Solar Hijri months in year 1404
  { input: '2025-03-25 12:00:00', expected: '5 حمل 1404',   parts: { day: 5, month: 'حمل',   year: 1404 } },
  { input: '2025-04-25 12:00:00', expected: '5 ثور 1404',   parts: { day: 5, month: 'ثور',   year: 1404 } },
  { input: '2025-05-26 12:00:00', expected: '5 جوزا 1404',  parts: { day: 5, month: 'جوزا',  year: 1404 } },
  { input: '2025-06-26 12:00:00', expected: '5 سرطان 1404', parts: { day: 5, month: 'سرطان', year: 1404 } },
  { input: '2025-07-27 12:00:00', expected: '5 اسد 1404',   parts: { day: 5, month: 'اسد',   year: 1404 } },
  { input: '2025-08-27 12:00:00', expected: '5 سنبله 1404', parts: { day: 5, month: 'سنبله', year: 1404 } },
  { input: '2025-09-27 12:00:00', expected: '5 میزان 1404', parts: { day: 5, month: 'میزان', year: 1404 } },
  { input: '2025-10-27 12:00:00', expected: '5 عقرب 1404',  parts: { day: 5, month: 'عقرب',  year: 1404 } },
  { input: '2025-11-26 12:00:00', expected: '5 قوس 1404',   parts: { day: 5, month: 'قوس',   year: 1404 } },
  { input: '2025-12-26 12:00:00', expected: '5 جدی 1404',   parts: { day: 5, month: 'جدی',   year: 1404 } },
  { input: '2026-01-25 12:00:00', expected: '5 دلو 1404',   parts: { day: 5, month: 'دلو',   year: 1404 } },
  { input: '2026-02-24 12:00:00', expected: '5 حوت 1404',   parts: { day: 5, month: 'حوت',   year: 1404 } },
];

// Create a small temporary PHP runner inside tests/fixtures/ to invoke persianDate & persianDateParts
const runnerPath = path.join(root, 'tests', 'fixtures', '_date-probe.php');
fs.writeFileSync(
  runnerPath,
  `<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    $raw = $argv[1] ?? '[]';
}
$dates = json_decode($raw, true) ?: [];
$out = [];
foreach ($dates as $dt) {
    $out[$dt] = [
        'formatted' => persianDate($dt),
        'parts' => persianDateParts($dt),
    ];
}
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
`
);

try {
  const inputJson = JSON.stringify(cases.map((c) => c.input));
  let payload;
  const cli = spawnSync('php', [runnerPath, inputJson], { encoding: 'utf8' });
  if (cli.status === 0 && cli.stdout) {
    payload = JSON.parse(cli.stdout.trim());
  } else {
    const { Php, Request } = await import('@platformatic/php-node');
    const php = new Php({ docroot: root, throwRequestErrors: false });
    const res = await php.handleRequest(new Request({
      method: 'POST',
      url: 'http://127.0.0.1/tests/fixtures/_date-probe.php',
      headers: { 'content-type': ['application/json'] },
      body: Buffer.from(inputJson),
    }));
    payload = JSON.parse(Buffer.from(res.body ?? new Uint8Array()).toString('utf8'));
  }
  for (const c of cases) {
    const actual = payload[c.input];
    assert.ok(actual, `Missing result for ${c.input}`);
    assert.equal(actual.formatted, c.expected, `persianDate(${c.input})`);
    assert.deepEqual(actual.parts, c.parts, `persianDateParts(${c.input})`);
    console.log(`PASS  ${c.input} -> ${actual.formatted}`);
  }
  console.log(`\nALL ${cases.length} AFGHAN SOLAR HIJRI BOUNDARY & LEAP-YEAR TESTS PASSED!`);
} finally {
  fs.rmSync(runnerPath, { force: true });
}

if (process.env.TEST_BASE_URL) {
  const base = process.env.TEST_BASE_URL.replace(/\/$/, '');
  const iranianMonthsRe = /\b(فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند)\b/;
  const afghanMonthsRe = /(حمل|ثور|جوزا|سرطان|اسد|سنبله|میزان|عقرب|قوس|جدی|دلو|حوت)/;

  // 1) Topic routes are real pages now
  for (const livePath of ['/topics', '/topic/mahdaviat', '/topics/mahdaviat']) {
    const r = await fetch(base + livePath, { redirect: 'manual' });
    assert.equal(r.status, 200, `${livePath} should return 200`);
    console.log(`PASS  topic route ${livePath} -> 200`);
  }

  // 2) Mobile API topics remain intact
  for (const apiPath of ['/api/mobile/topics', '/api/mobile/topic?slug=mahdaviat']) {
    const r = await fetch(base + apiPath);
    const json = await r.json();
    assert.equal(r.status, 200, `${apiPath} should return 200`);
    assert.equal(json.ok, true, `${apiPath} json.ok`);
    console.log(`PASS  mobile API ${apiPath} -> 200 OK`);
  }

  // 3) Public pages have no topic chips/badges/sidebars/filters and use Afghan Solar Hijri months
  for (const pubPath of ['/', '/news', '/articles', '/reports', '/books', '/lessons', '/events', '/search', '/articles/aql-in-religion', '/books/usul-aqaid', '/lessons/fiqh-lesson-1', '/login', '/register']) {
    const r = await fetch(base + pubPath);
    const html = await r.text();
    assert.equal(r.status, 200, `${pubPath} status 200`);
    assert.ok(!html.includes('jhd-card__topics'), `${pubPath} has no jhd-card__topics`);
    assert.ok(!html.includes('jhd-topic-pill'), `${pubPath} has no jhd-topic-pill`);
    assert.ok(!html.includes('id="archive-search-topic"'), `${pubPath} has no topic filter select`);

    assert.ok(!iranianMonthsRe.test(html), `${pubPath} has no Iranian month names`);
    console.log(`PASS  public page ${pubPath} clean (no topics UI, no Iranian months)`);
  }

  // 4) Homepage sections + unified news + footer Telegram button with text
  const homeHtml = await (await fetch(base + '/')).text();
  // The homepage was rebuilt as an editorial front page: a lead-story block
  // followed by the writing, books, lessons and media hubs. The section
  // headings are the anchors this check protects.
  for (const headingId of ['home-feature-heading', 'home-writing-heading', 'home-books-heading', 'home-lessons-heading', 'home-media-heading']) {
    assert.ok(homeHtml.includes(`id="${headingId}"`), `Homepage includes ${headingId}`);
  }
  assert.ok(homeHtml.includes('class="jhd-lead"'), 'Homepage renders the lead story');
  assert.ok(!homeHtml.includes('class="jhd-about"'), 'Homepage excludes the introduction band');
  assert.ok(homeHtml.includes('همهٔ اخبار'), 'Homepage includes همهٔ اخبار CTA');
  assert.ok(homeHtml.includes('social-link--telegram') && homeHtml.includes('<span>کانال تلگرام</span>'), 'Footer includes labeled Telegram button');
  assert.ok(afghanMonthsRe.test(homeHtml), 'Homepage renders Afghan Solar Hijri month names');
  assert.equal((homeHtml.match(/<footer class="main-footer"/g) || []).length, 1, 'Homepage has exactly 1 main-footer');
  console.log('PASS  homepage active sections, unified news CTA, labeled Telegram button, and single footer verified');

  // 5) Login & Register pages have logo, canonical brand, and unified auth layout
  for (const authPath of ['/login', '/register', '/admin/login']) {
    const html = await (await fetch(base + authPath)).text();
    assert.ok(html.includes('jhd-login-head') && html.includes('logo.png'), `${authPath} has logo header`);
    assert.ok(html.includes('جامعه‌الهدی'), `${authPath} uses canonical brand spelling`);
    assert.ok(html.includes('jhd-button-block'), `${authPath} has primary submit button`);
    console.log(`PASS  auth page ${authPath} logo, canonical title, and submit button verified`);
  }
}
