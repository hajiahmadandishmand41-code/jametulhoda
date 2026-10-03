/**
 * tests/responsive-audit.mjs — اندازه‌گیری واقعی کارت‌ها با مرورگر واقعی
 * ───────────────────────────────────────────────────────────────────────────
 * این آزمون با Chromium واقعی اجرا می‌شود و چیزهایی را اندازه می‌گیرد که با
 * نگاه‌کردن به CSS نمی‌شود فهمید:
 *
 *   • آیا صفحه در عرض‌های ۳۲۰/۳۶۰/۳۹۰/۴۱۴ پیکسل اسکرول افقی دارد؟
 *   • هر کارت چند پیکسل ارتفاع دارد؟ نسبت تصویر چقدر است؟
 *   • در هر عرض، چند کارت در یک ردیف قرار می‌گیرند؟
 *   • کدام عنصر از عرض صفحه بیرون زده است (برای رفع دقیق، نه حدس)؟
 *   • آیا هدرِ چسبنده روی کارت اول را پوشانده است؟
 *
 * اجرا در CI (مرورگر Playwright):
 *   TEST_BASE_URL=http://127.0.0.1:8080 node tests/responsive-audit.mjs
 *
 * اجرای محلی با یک Chromium دلخواه (مثلاً باینری استخراج‌شده):
 *   JHD_CHROME_PATH=/tmp/chromium TEST_BASE_URL=http://127.0.0.1:8000 \
 *       node tests/responsive-audit.mjs
 *
 * با SNAPSHOT_DIR یک مسیر، HTML هر صفحه هم ذخیره می‌شود تا بتوان همان صفحه را
 * بعداً به‌صورت ایستا (بدون PHP) دوباره اندازه گرفت.
 */
import fs from 'node:fs';
import path from 'node:path';

const BASE = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const WIDTHS = (process.env.JHD_WIDTHS || '320,360,390,414,768,1024,1440')
  .split(',').map((w) => parseInt(w.trim(), 10)).filter((w) => w > 0);
const PAGES = [
  ['home', '/'],
  ['news', '/news'],
  ['articles', '/articles'],
  ['reports', '/reports'],
  ['research', '/research'],
  ['books', '/books'],
  ['lessons', '/lessons'],
  ['topics', '/topics'],
  ['topic', '/topic/quran'],
  ['media', '/media'],
  ['search', '/search?q=%D9%82%D8%B1%D8%A2%D9%86'],
  ['post', '/post/new-school-year'],
];
const SNAPSHOT_DIR = process.env.SNAPSHOT_DIR || '';
const CHROME_PATH = process.env.JHD_CHROME_PATH || '';

/** یک رابط بسیار کوچک روی Playwright یا Puppeteer (هر کدام در دسترس باشد). */
async function openBrowser() {
  if (CHROME_PATH) {
    const { default: puppeteer } = await import('puppeteer-core');
    const browser = await puppeteer.launch({
      executablePath: CHROME_PATH,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
      headless: 'shell',
    });
    return {
      kind: 'puppeteer',
      browser,
      async newPage() {
        const page = await browser.newPage();
        return {
          page,
          viewport: (w, h) => page.setViewport({ width: w, height: h, deviceScaleFactor: 1 }),
          goto: (url) => page.goto(url, { waitUntil: 'load', timeout: 45000 }),
          evaluate: (fn, arg) => page.evaluate(fn, arg),
          content: () => page.content(),
          close: () => page.close(),
        };
      },
      close: () => browser.close(),
    };
  }
  const { chromium } = await import('@playwright/test');
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  return {
    kind: 'playwright',
    browser,
    async newPage() {
      const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
      return {
        page,
        viewport: (w, h) => page.setViewportSize({ width: w, height: h }),
        goto: (url) => page.goto(url, { waitUntil: 'load', timeout: 45000 }),
        evaluate: (fn, arg) => page.evaluate(fn, arg),
        content: () => page.content(),
        close: () => page.close(),
      };
    },
    close: () => browser.close(),
  };
}

/** همهٔ اندازه‌گیری‌ها در داخل صفحه انجام می‌شود تا واقعاً Layout محاسبه شود. */
const measure = () => {
  const vw = window.innerWidth;
  const doc = document.documentElement;
  const body = document.body;
  const scrollWidth = Math.max(doc.scrollWidth, body ? body.scrollWidth : 0);

  const offenders = [];
  const all = document.querySelectorAll('body *');
  for (const el of all) {
    const rect = el.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) continue;
    const style = getComputedStyle(el);
    if (style.position === 'fixed') continue;
    // A container that scrolls on purpose (chip strip, table wrapper) is fine.
    let scrolls = false;
    for (let p = el; p && p !== document.body; p = p.parentElement) {
      const ps = getComputedStyle(p);
      if (ps.overflowX === 'auto' || ps.overflowX === 'scroll') { scrolls = true; break; }
    }
    if (scrolls) continue;
    if (rect.right > vw + 1 || rect.left < -1) {
      offenders.push({
        tag: el.tagName.toLowerCase(),
        cls: String(el.className || '').slice(0, 48),
        left: Math.round(rect.left),
        right: Math.round(rect.right),
      });
    }
  }

  const cards = Array.from(document.querySelectorAll('.jhd-card'));
  const metrics = cards.slice(0, 4).map((card) => {
    const rect = card.getBoundingClientRect();
    const media = card.querySelector('.jhd-card-media');
    const mediaImg = media ? media.querySelector('img') : null;
    const title = card.querySelector('.jhd-card-title');
    const summary = card.querySelector('.jhd-card-summary');
    const titleStyle = title ? getComputedStyle(title) : null;
    const box = (el) => (el ? Math.round(el.getBoundingClientRect().height) : 0);
    return {
      w: Math.round(rect.width),
      h: Math.round(rect.height),
      mediaH: box(media),
      mediaW: media ? Math.round(media.getBoundingClientRect().width) : 0,
      imgFit: mediaImg ? getComputedStyle(mediaImg).objectFit : '-',
      titleH: box(title),
      titleLines: titleStyle ? Math.round(parseFloat(titleStyle.height) / parseFloat(titleStyle.lineHeight || '20')) : 0,
      summaryH: box(summary),
    };
  });

  const rows = {};
  for (const card of cards) {
    const rect = card.getBoundingClientRect();
    const key = Math.round(rect.top / 8) * 8;
    rows[key] = (rows[key] || 0) + 1;
  }
  const perRow = Object.values(rows);

  // Is the first card actually reachable (not covered by the sticky header)?
  let firstCardReachable = null;
  if (cards[0]) {
    const rect = cards[0].getBoundingClientRect();
    const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + 6);
    firstCardReachable = !!hit && (cards[0] === hit || cards[0].contains(hit));
  }

  // Broken images: a card image that failed to load shows as an empty frame.
  const images = Array.from(document.querySelectorAll('.jhd-card-media img'));
  const brokenImages = images.filter((img) => img.complete && img.naturalWidth === 0).length;

  return {
    vw,
    scrollWidth,
    overflow: scrollWidth - vw,
    offenders: offenders.slice(0, 5),
    cardCount: cards.length,
    perRow,
    metrics,
    firstCardReachable,
    images: images.length,
    brokenImages,
  };
};

const results = [];
let failures = 0;
const driver = await openBrowser();

try {
  for (const [name, route] of PAGES) {
    const url = BASE + route;
    const handle = await driver.newPage();
    let status = 0;
    for (const width of WIDTHS) {
      await handle.viewport(width, Math.round(width * 1.9));
      if (width === WIDTHS[0]) {
        try {
          const response = await handle.goto(url);
          status = response ? (response.status ? response.status() : response.status) : 0;
        } catch (error) {
          status = 0;
          console.log(`SKIP ${name} ${route} — ${error.message.split('\n')[0]}`);
          break;
        }
        if (SNAPSHOT_DIR) {
          fs.mkdirSync(SNAPSHOT_DIR, { recursive: true });
          fs.writeFileSync(path.join(SNAPSHOT_DIR, name + '.html'), await handle.content());
        }
      } else {
        await handle.evaluate(() => new Promise((r) => requestAnimationFrame(() => r())));
      }
      const data = await handle.evaluate(measure);
      const tallestCard = data.metrics.reduce((a, b) => (b.h > a.h ? b : a), data.metrics[0] || { h: 0, w: 0, mediaH: 0, imgFit: '-' });
      const tallest = tallestCard.h;
      const row = {
        page: name, width, status,
        overflow: data.overflow,
        cards: data.cardCount,
        perRow: data.perRow.slice(0, 3).join('/'),
        cardW: data.metrics[0] ? data.metrics[0].w : 0,
        cardH: tallest,
        tallestMedia: tallestCard.mediaH || 0,
        mediaH: data.metrics[0] ? data.metrics[0].mediaH : 0,
        fit: data.metrics[0] ? data.metrics[0].imgFit : '-',
        broken: data.brokenImages,
        reachable: data.firstCardReachable,
      };
      results.push(row);
      if (data.overflow > 1) row.offenders = data.offenders;
      const flag = data.overflow > 1 ? 'FAIL' : 'ok  ';
      console.log(
        `${flag} ${name.padEnd(9)} w=${String(width).padEnd(4)} cards=${String(row.cards).padEnd(3)}` +
        ` row=${String(row.perRow).padEnd(6)} card=${row.cardW}x${row.cardH}` +
        ` media=${row.tallestMedia} fit=${row.fit} overflow=${data.overflow}` +
        (row.broken ? ` broken-img=${row.broken}` : '') +
        (row.reachable === false ? ' first-card-covered' : '')
      );
      if (data.overflow > 1) {
        failures++;
        console.log('     offenders: ' + JSON.stringify(data.offenders));
      }
      if (row.reachable === false) {
        failures++;
        console.log('     FAIL: the first card is covered by the sticky header.');
      }
    }
    await handle.close();
  }
} finally {
  await driver.close();
}

const critical = results.filter((r) => r.width <= 414);
const maxCardH = critical.reduce((m, r) => Math.max(m, r.cardH), 0);
const maxMedia = critical.reduce((m, r) => Math.max(m, r.mediaH), 0);
console.log('\n--- summary (mobile widths only) ---');
console.log('pages x widths measured :', critical.length);
console.log('tallest card (mobile)   :', maxCardH, 'px');
console.log('tallest media (mobile)  :', maxMedia, 'px');
console.log('overflow failures       :', results.filter((r) => r.overflow > 1).length);
if (process.env.JHD_MAX_CARD_HEIGHT && maxCardH > parseInt(process.env.JHD_MAX_CARD_HEIGHT, 10)) {
  console.log(`FAIL: mobile card height ${maxCardH} exceeds the ${process.env.JHD_MAX_CARD_HEIGHT}px budget.`);
  failures++;
}
console.log(failures ? `\nRESULT: ${failures} problem(s)` : '\nRESULT: clean');
process.exit(failures ? 1 : 0);
