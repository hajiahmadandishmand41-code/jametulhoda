/**
 * tests/checkpoint.mjs — get past Vercel's "Security Checkpoint" so automated
 * checks can reach a deployment that has Attack Challenge Mode enabled.
 *
 * The checkpoint answers plain HTTP clients with a 403 plus a JavaScript
 * challenge; a real browser solves it and receives a cookie. This helper loads
 * the site once in headless Chromium and returns the resulting storage state,
 * which the API-based tests then reuse.
 *
 * Returns null when no browser is available, so callers can fall back to plain
 * HTTP (which works fine on deployments without the checkpoint).
 */
import fs from 'node:fs';
import path from 'node:path';

export async function passSecurityCheckpoint(base, { timeout = 60000, debug = false } = {}) {
  let chromium;
  try {
    ({ chromium } = await import('@playwright/test'));
  } catch {
    if (debug) console.log('  (playwright browser not installed; skipping the checkpoint step)');
    return null;
  }

  let browser;
  try {
    browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(base + '/', { waitUntil: 'domcontentloaded', timeout });

    // Wait until the served document is the application rather than the
    // challenge interstitial.
    const deadline = Date.now() + timeout;
    let passed = false;
    while (Date.now() < deadline) {
      const html = await page.content();
      if (!/Vercel Security Checkpoint/i.test(html)) { passed = true; break; }
      await page.waitForTimeout(1000);
    }

    const cookies = await context.cookies();
    const state = await context.storageState();
    if (debug) {
      console.log(`  checkpoint: ${passed ? 'passed' : 'still challenging after ' + timeout + 'ms'}`
        + `, cookies=${cookies.map((c) => c.name).join(',') || '(none)'}`);
    }
    await context.close();
    return passed ? state : null;
  } catch (error) {
    if (debug) console.log('  (checkpoint step failed: ' + (error?.message ?? error) + ')');
    return null;
  } finally {
    await browser?.close().catch(() => {});
  }
}

/** Storage state written to disk so several scripts can share one checkpoint. */
export async function checkpointStateFile(base, file = 'test-results/checkpoint.json', options = {}) {
  if (fs.existsSync(file)) {
    try {
      const cached = JSON.parse(fs.readFileSync(file, 'utf8'));
      if (cached?.cookies?.length) return cached;
    } catch { /* fall through and fetch a fresh state */ }
  }
  const state = await passSecurityCheckpoint(base, options);
  if (!state) return undefined;
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(state));
  return state;
}
