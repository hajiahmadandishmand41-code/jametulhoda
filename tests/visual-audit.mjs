/**
 * tests/visual-audit.mjs — real-browser UI audit + screenshots.
 *
 * Why this exists: a redesign cannot be judged from source code alone. This
 * script opens every public route in a real Chromium (Playwright), records the
 * things that actually break a UI — horizontal overflow, console/page errors,
 * failed requests, broken images, header/hero heights, navigation breakpoints —
 * and writes screenshots so a human (or an agent) can *look* at the result.
 *
 * Environment:
 *   BASE_URL   base URL to audit            (default http://127.0.0.1:8080/)
 *   OUT_DIR    screenshot/report directory  (default docs/visual-audit)
 *   LABEL      filename prefix              (default "")
 *   SCOPE      core | full                  (default core)
 *   JPEG_Q     screenshot quality 1-100     (default 60)
 *   THEME      light | dark | both          (default light)
 *
 * Exit code is 0 even when issues are found: the report is the deliverable.
 */
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const BASE_URL = (process.env.BASE_URL || 'http://127.0.0.1:8080/').replace(/\/$/, '') + '/';
const OUT_DIR = process.env.OUT_DIR || 'docs/visual-audit';
const LABEL = process.env.LABEL || '';
const SCOPE = process.env.SCOPE || 'core';
const JPEG_Q = Number(process.env.JPEG_Q || 60);
const THEME = process.env.THEME || 'light';

fs.mkdirSync(OUT_DIR, { recursive: true });
const prefix = (name) => path.join(OUT_DIR, (LABEL ? LABEL + '-' : '') + name + '.jpg');

/** Routes required by the redesign brief. */
const LIST_ROUTES = [
  { path: '', name: 'home' },
  { path: 'news', name: 'news' },
  { path: 'articles', name: 'articles' },
  { path: 'reports', name: 'reports' },
  { path: 'research', name: 'research' },
  { path: 'events', name: 'events' },
  { path: 'books', name: 'books' },
  { path: 'lessons', name: 'lessons' },
  { path: 'media', name: 'media' },
  { path: 'topics', name: 'topics' },
  { path: 'qa', name: 'qa' },
  { path: 'about', name: 'about' },
  { path: 'contact', name: 'contact' },
  { path: 'login', name: 'login' },
  { path: 'register', name: 'register' },
  { path: 'search?q=قرآن', name: 'search' },
];

const VIEWPORTS = [360, 390, 430, 768, 1024, 1280, 1440];

/** In-page measurements collected on every audited page. */
const METRICS_FN = () => {
  const px = (el) => (el ? Math.round(el.getBoundingClientRect().height) : null);
  const q = (s) => document.querySelector(s);
  const overflowers = [];
  const vw = document.documentElement.clientWidth;
  const drawerOpen = !!document.querySelector('.jhd-drawer.open, #siteDrawer.open');
  for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    // Off-canvas drawer/overlay are intentionally outside the viewport.
    if (!drawerOpen && el.closest && el.closest('#siteDrawer, #drawerOverlay, .jhd-drawer')) continue;
    const st = getComputedStyle(el);
    if (st.position === 'fixed' && st.visibility === 'hidden') continue;
    if (r.right > vw + 1 || r.left < -1) {
      if (overflowers.length < 12) {
        overflowers.push({
          tag: el.tagName.toLowerCase(),
          cls: (el.className && typeof el.className === 'string' ? el.className : '').slice(0, 90),
          id: el.id || '',
          left: Math.round(r.left),
          right: Math.round(r.right),
        });
      }
    }
  }
  const brokenImages = [...document.images]
    .filter((img) => img.complete && img.naturalWidth === 0 && img.getAttribute('src'))
    .slice(0, 12)
    .map((img) => img.getAttribute('src').slice(0, 140));
  const h1 = q('main h1') || q('h1');
  const cs = (el) => (el ? getComputedStyle(el) : null);
  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: vw,
    overflow: document.documentElement.scrollWidth > vw + 1,
    overflowers,
    brokenImages,
    docHeight: Math.round(document.documentElement.scrollHeight),
    header: {
      height: px(q('.jhd-header')),
      bismillah: px(q('.bismillah-bar')),
      navVisible: !!q('.jhd-navbar-desktop') && cs(q('.jhd-navbar-desktop')).display !== 'none',
      utilityVisible: !!q('.jhd-utility-nav') && cs(q('.jhd-utility-nav')).display !== 'none',
      searchVisible: !!q('.jhd-search') && cs(q('.jhd-search')).display !== 'none',
      burgerVisible: !!q('#menuToggle') && cs(q('#menuToggle')).display !== 'none',
    },
    hero: { height: px(q('.jhd-hero')) || px(q('.jhd-brand-board')) || px(q('[data-hero]')) },
    h1: h1 ? { text: h1.textContent.trim().slice(0, 90), size: cs(h1).fontSize } : null,
    bodyBg: cs(document.body).backgroundColor,
    bodyColor: cs(document.body).color,
    sections: document.querySelectorAll('main section').length,
    cards: document.querySelectorAll('main .jhd-card, main .news-card, main .book-card, main .topic-card, main .lesson-card').length,
    emptyStates: document.querySelectorAll('.jhd-empty-state').length,
    footer: { height: px(q('.main-footer')) },
    theme: document.documentElement.dataset.theme || '(unset)',
    title: document.title,
  };
};

async function newPage(browser, { width, height, colorScheme }) {
  const ctx = await browser.newContext({
    viewport: { width, height },
    colorScheme,
    locale: 'fa',
    deviceScaleFactor: 1,
  });
  const page = await ctx.newPage();
  const issues = { console: [], pageErrors: [], failedRequests: [] };
  page.on('console', (m) => {
    if (m.type() === 'error') issues.console.push(m.text().slice(0, 200));
  });
  page.on('pageerror', (e) => issues.pageErrors.push(String(e).slice(0, 200)));
  page.on('response', (r) => {
    if (r.status() >= 400) issues.failedRequests.push(`${r.status()} ${r.url().replace(BASE_URL, '/').slice(0, 140)}`);
  });
  page.on('requestfailed', (r) => issues.failedRequests.push(`FAILED ${r.url().replace(BASE_URL, '/').slice(0, 140)} (${r.failure()?.errorText})`));
  return { ctx, page, issues };
}

/** Follow internal listing links to discover real detail pages (fixture mode). */
async function discoverDetailUrls(page) {
  const found = {};
  const listingPages = ['news', 'articles', 'reports', 'research', 'events', 'books', 'lessons', 'media', 'topics', 'qa'];
  for (const p of listingPages) {
    try {
      await page.goto(new URL(p, BASE_URL).href, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const links = await page.$$eval('main a[href]', (as) => as.map((a) => a.getAttribute('href')).filter(Boolean));
      for (const href of links) {
        const clean = href.split('#')[0];
        const u = new URL(clean, BASE_URL);
        // Query-mode spellings: index.php?p=news&slug=x (or ?id=)
        const qp = u.searchParams.get('p');
        const slug = u.searchParams.get('slug') || u.searchParams.get('id');
        let key = u.pathname.replace(/^https?:\/\/[^/]+/, '');
        if (slug && /index\.php$/.test(key)) key = '/' + String(qp || p).replace(/s$/, '') + '/' + slug;
        else if (slug) key = key.replace(/\/$/, '') ;
        if (/^\/(news|articles?|reports?|research(es)?|events?|announcements|programs|books?|lessons?|topics?|speech(es)?|video|audio|media|qa|post)\/[^/]+$/.test(key)) {
          found[key] = found[key] || u.href;
        }
      }
    } catch { /* listing may not exist in limited mode */ }
  }
  // Prefer one detail URL per content family.
  const want = [
    ['article', /^\/(articles?|post)\//],
    ['news', /^\/news\//],
    ['report', /^\/reports?\//],
    ['research', /^\/research/],
    ['event', /^\/(events?|programs|announcements)\//],
    ['book', /^\/books?\//],
    ['lesson', /^\/lessons?\//],
    ['topic', /^\/topics?\//],
    ['media', /^\/(video|audio|media|speech)/],
  ];
  const out = [];
  for (const [name, re] of want) {
    const hit = Object.keys(found).find((k) => re.test(k));
    if (hit) out.push({ name: 'detail-' + name, url: found[hit] });
  }
  return out;
}

// Keep reports reproducible: the audit workflow commits them back to the
// branch, so a wall-clock timestamp would create a bot-only commit every run.
const report = {
  baseUrl: BASE_URL,
  scope: SCOPE,
  pages: [],
  viewports: {},
  detailUrls: [],
};

const browser = await chromium.launch({ args: ['--no-sandbox', '--force-color-profile=srgb'], headless: true });

try {
  // ── 1. Responsive sweep (no screenshots, cheap) ──────────────────────────
  {
    const { ctx, page, issues } = await newPage(browser, { width: 1440, height: 900, colorScheme: 'light' });
    for (const route of LIST_ROUTES) {
      const url = new URL(route.path, BASE_URL).href;
      const entry = { route: route.name, url, status: null, widths: {}, issues };
      try {
        const res = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
        entry.status = res?.status();
        await page.waitForTimeout(350);
        for (const w of VIEWPORTS) {
          await page.setViewportSize({ width: w, height: 900 });
          await page.waitForTimeout(120);
          const m = await page.evaluate(METRICS_FN);
          entry.widths[w] = {
            overflow: m.overflow,
            scrollWidth: m.scrollWidth,
            offenders: m.overflowers.slice(0, 4),
            navVisible: m.header.navVisible,
            burgerVisible: m.header.burgerVisible,
            headerHeight: m.header.height,
            heroHeight: m.hero.height,
            docHeight: m.docHeight,
          };
        }
      } catch (e) {
        entry.error = String(e).slice(0, 200);
      }
      report.viewports[route.name] = entry.widths;
      report.pages.push(entry);
    }
    // detail discovery happens at desktop size
    report.detailUrls = await discoverDetailUrls(page);
    await ctx.close();
  }

  // ── 2. Screenshots ──────────────────────────────────────────────────────
  const shots = [];
  const desktopRoutes = SCOPE === 'full'
    ? LIST_ROUTES
    : LIST_ROUTES.filter((r) => ['home', 'news', 'articles', 'topics', 'books', 'lessons', 'media', 'about', 'contact', 'login'].includes(r.name));
  const mobileRoutes = SCOPE === 'full'
    ? LIST_ROUTES.filter((r) => r.name !== 'search')
    : LIST_ROUTES.filter((r) => ['home', 'news', 'articles', 'topics', 'books', 'lessons', 'contact', 'login'].includes(r.name));

  for (const theme of THEME === 'both' ? ['light', 'dark'] : [THEME]) {
    // Desktop, full page
    {
      const { ctx, page } = await newPage(browser, { width: 1440, height: 900, colorScheme: theme });
      for (const route of theme === 'dark' ? desktopRoutes.slice(0, SCOPE === 'full' ? 8 : 4) : desktopRoutes) {
        const url = new URL(route.path, BASE_URL).href;
        try {
          await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
          await page.waitForTimeout(500);
          const name = `${theme === 'dark' ? 'dark-' : ''}desktop-${route.name}`;
          await page.screenshot({ path: prefix(name), type: 'jpeg', quality: JPEG_Q, fullPage: true });
          const m = await page.evaluate(METRICS_FN);
          shots.push({ name, url, docHeight: m.docHeight, sections: m.sections, cards: m.cards, emptyStates: m.emptyStates, theme: m.theme, h1: m.h1, header: m.header, hero: m.hero, brokenImages: m.brokenImages });
        } catch (e) {
          shots.push({ name: `desktop-${route.name}`, url, error: String(e).slice(0, 160) });
        }
      }
      // Desktop detail pages
      if (theme === 'light') {
        for (const d of report.detailUrls.slice(0, SCOPE === 'full' ? 9 : 5)) {
          try {
            await page.goto(d.url, { waitUntil: 'networkidle', timeout: 60000 });
            await page.waitForTimeout(400);
            await page.screenshot({ path: prefix('desktop-' + d.name), type: 'jpeg', quality: JPEG_Q, fullPage: true });
            const m = await page.evaluate(METRICS_FN);
            shots.push({ name: 'desktop-' + d.name, url: d.url, docHeight: m.docHeight, h1: m.h1, cards: m.cards, brokenImages: m.brokenImages });
          } catch (e) {
            shots.push({ name: 'desktop-' + d.name, url: d.url, error: String(e).slice(0, 160) });
          }
        }
      }
      await ctx.close();
    }
    // Mobile, full page
    {
      const { ctx, page } = await newPage(browser, { width: 390, height: 844, colorScheme: theme });
      for (const route of theme === 'dark' ? mobileRoutes.slice(0, 3) : mobileRoutes) {
        const url = new URL(route.path, BASE_URL).href;
        try {
          await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
          await page.waitForTimeout(450);
          const name = `${theme === 'dark' ? 'dark-' : ''}mobile-${route.name}`;
          await page.screenshot({ path: prefix(name), type: 'jpeg', quality: JPEG_Q, fullPage: true });
          const m = await page.evaluate(METRICS_FN);
          shots.push({ name, url, docHeight: m.docHeight, overflow: m.overflow, offenders: m.overflowers, header: m.header, hero: m.hero });
        } catch (e) {
          shots.push({ name: `mobile-${route.name}`, url, error: String(e).slice(0, 160) });
        }
      }
      // Mobile drawer open + a detail page
      if (theme === 'light') {
        try {
          await page.goto(BASE_URL, { waitUntil: 'networkidle', timeout: 60000 });
          await page.click('#menuToggle');
          await page.waitForTimeout(600);
          await page.screenshot({ path: prefix('mobile-drawer-open'), type: 'jpeg', quality: JPEG_Q });
          shots.push({ name: 'mobile-drawer-open', url: BASE_URL });
        } catch (e) {
          shots.push({ name: 'mobile-drawer-open', error: String(e).slice(0, 160) });
        }
        const firstDetail = report.detailUrls[0];
        if (firstDetail) {
          try {
            await page.goto(firstDetail.url, { waitUntil: 'networkidle', timeout: 60000 });
            await page.waitForTimeout(400);
            await page.screenshot({ path: prefix('mobile-' + firstDetail.name), type: 'jpeg', quality: JPEG_Q, fullPage: true });
            shots.push({ name: 'mobile-' + firstDetail.name, url: firstDetail.url });
          } catch (e) {
            shots.push({ name: 'mobile-' + firstDetail.name, url: firstDetail.url, error: String(e).slice(0, 160) });
          }
        }
      }
      await ctx.close();
    }
  }

  report.screenshots = shots;
} finally {
  await browser.close();
}

fs.writeFileSync(path.join(OUT_DIR, (LABEL ? LABEL + '-' : '') + 'report.json'), JSON.stringify(report, null, 1));

// ── Console summary ───────────────────────────────────────────────────────
const bad = [];
for (const p of report.pages) {
  for (const [w, m] of Object.entries(p.widths || {})) {
    if (m.overflow) bad.push(`OVERFLOW ${p.route} @${w}px (scrollWidth ${m.scrollWidth}) ${JSON.stringify(m.offenders.slice(0, 2))}`);
  }
  if (p.status && p.status >= 400) bad.push(`HTTP ${p.status} ${p.route}`);
  if (p.issues?.pageErrors?.length) bad.push(`PAGEERROR ${p.route}: ${p.issues.pageErrors[0]}`);
  if (p.issues?.console?.length) bad.push(`CONSOLE ${p.route}: ${p.issues.console[0]}`);
  if (p.issues?.failedRequests?.length) bad.push(`REQ ${p.route}: ${p.issues.failedRequests.slice(0, 3).join(' | ')}`);
}
for (const s of report.screenshots || []) {
  if (s.error) bad.push(`SHOT-ERROR ${s.name}: ${s.error}`);
  if (s.brokenImages?.length) bad.push(`BROKEN-IMG ${s.name}: ${s.brokenImages.slice(0, 3).join(' | ')}`);
}
console.log(`routes audited: ${report.pages.length}, screenshots: ${report.screenshots?.length ?? 0}, detail urls: ${report.detailUrls.length}`);
console.log(bad.length ? 'ISSUES:\n' + bad.join('\n') : 'no overflow/console/request issues found');

// The screenshot artifact is not always reachable (log archives and artifacts
// live behind a separate storage host). Layout problems are therefore also
// emitted as workflow annotations, which stay readable through the API and are
// shown inline on the pull request.
if (process.env.GITHUB_ACTIONS) {
  const oneLine = (text) => String(text).replace(/[\r\n]+/g, ' ').slice(0, 900);
  for (const line of bad.slice(0, 8)) console.log(`::error title=Visual audit${LABEL ? ' (' + LABEL + ')' : ''}::${oneLine(line)}`);
  if (bad.length > 8) console.log(`::error title=Visual audit::${bad.length - 8} more layout issues — see the run log`);
  if (!bad.length) {
    const measured = report.pages
      .filter((p) => p.widths && p.widths['390'])
      .slice(0, 6)
      .map((p) => `${p.route}: ${p.widths['390'].docHeight}px`)
      .join(' | ');
    console.log(`::notice title=Visual audit${LABEL ? ' (' + LABEL + ')' : ''}::${report.pages.length} routes clean at every width. Mobile page heights — ${oneLine(measured)}`);
  }
}
