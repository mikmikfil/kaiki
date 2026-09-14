import { createRequire } from 'node:module';
const require = createRequire('C:/Users/Mike/kaiki/package.json');
const { chromium } = require('@playwright/test');
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Screenshots for the sales deck, from the two dev servers already running.
 *
 * Seeded demo credentials from DemoTenantSeeder — the same ones the e2e suite
 * uses. Nothing here touches a real account.
 */

const OUT = resolve(process.argv[2] ?? '.', 'deck-shots');
mkdirSync(OUT, { recursive: true });

const PANEL = process.env.KAIKI_PANEL ?? 'http://127.0.0.1:8000';
const GUEST = process.env.KAIKI_GUEST ?? 'http://192.168.1.43:8001';

const GUEST_PAGES = [
  ['guest-home', `${GUEST}/aegean-blue?lang=el`, 1400],
  ['guest-search', `${GUEST}/aegean-blue/search?lang=el`, 1400],
  ['guest-trip', `${GUEST}/aegean-blue/proino-kolymvitiko?lang=el`, 1500],
  ['guest-contact', `${GUEST}/aegean-blue/contact?lang=el`, 1200],
];

const PANEL_PAGES = [
  ['panel-dashboard', `${PANEL}/app`, 1500],
  ['panel-calendar', `${PANEL}/app/calendar`, 1100],
  ['panel-bookings', `${PANEL}/app/bookings`, 1100],
  // Pinned to a period rather than left on the default: "the last 30 days" is a
  // different month every month, and a deck whose screenshots drift is one
  // nobody can tell has been rebuilt.
  ['panel-analytics', `${PANEL}/app/analytics?period=this_year`, 1500],
  ['panel-departures', `${PANEL}/app/departures`, 1100],
  ['panel-products', `${PANEL}/app/products`, 1100],
  ['panel-invoices', `${PANEL}/app/invoices`, 1100],
];

const browser = await chromium.launch();

// --- guest side, anonymous -------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  for (const [name, url, height] of GUEST_PAGES) {
    await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
    await page.setViewportSize({ width: 1440, height });
    await page.waitForTimeout(1400);
    await page.screenshot({ path: resolve(OUT, `${name}.png`) });
    console.log('ok', name);
  }
  await ctx.close();
}

// --- operator panel, signed in ---------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(`${PANEL}/app/login`, { waitUntil: 'networkidle' });
  await page.locator('#data\\.email').fill('maria@aegean-blue.example');
  await page.locator('#data\\.password').fill('password');
  await page.locator('form button[type=submit]').first().click();
  await page.waitForURL(/\/app/, { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(2000);
  console.log('signed in ->', page.url());

  for (const [name, url, height] of PANEL_PAGES) {
    await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
    await page.setViewportSize({ width: 1440, height });
    await page.waitForTimeout(1600);
    await page.screenshot({ path: resolve(OUT, `${name}.png`) });
    console.log('ok', name);
  }
  await ctx.close();
}

// --- the boarding screen, on a phone ---------------------------------
{
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
  });
  const page = await ctx.newPage();

  await page.goto(`${PANEL}/app/login`, { waitUntil: 'networkidle' });
  await page.locator('#data\\.email').fill('maria@aegean-blue.example');
  await page.locator('#data\\.password').fill('password');
  await page.locator('form button[type=submit]').first().click();
  await page.waitForURL(/\/app/, { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1500);

  for (const [name, url] of [['phone-dashboard', `${PANEL}/app`], ['phone-checkin', `${PANEL}/app/check-in`]]) {
    await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({ path: resolve(OUT, `${name}.png`) });
    console.log('ok', name);
  }
  await ctx.close();
}

await browser.close();
console.log('done ->', OUT);
