/**
 * Screenshots for the team presentation.
 *
 * Every screen Kaiki has, from the two dev servers already running, signed in
 * with the demo seeder's deterministic credentials. Nothing here touches a real
 * account and nothing is written into the repository.
 *
 *   node shots.mjs            # -> ./shots
 */

import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire('C:/Users/Mike/kaiki/package.json');
const { chromium } = require('@playwright/test');

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, 'shots');
mkdirSync(OUT, { recursive: true });

const PANEL = process.env.KAIKI_PANEL ?? 'http://127.0.0.1:8000';
const GUEST = process.env.KAIKI_GUEST ?? 'http://192.168.1.43:8001';

const ok = [];
const bad = [];

/** One shot. `height` is the viewport the page is measured at, not a crop. */
async function shot(page, name, url, height = 1100) {
  try {
    await page.setViewportSize({ width: 1440, height });
    await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
    // Filament's tables and charts arrive after the first paint.
    await page.waitForTimeout(1800);
    await page.screenshot({ path: resolve(OUT, name + '.png') });
    ok.push(name);
    console.log('ok  ', name);
  } catch (e) {
    bad.push(name + ': ' + String(e).split('\n')[0]);
    console.log('FAIL', name, String(e).split('\n')[0]);
  }
}

async function signIn(page, base, email) {
  await page.goto(base + '/login', { waitUntil: 'networkidle' });
  await page.locator('#data\\.email').fill(email);
  await page.locator('#data\\.password').fill('password');
  await page.locator('form button[type=submit]').first().click();
  await page.waitForTimeout(3000);
  console.log('signed in', email, '->', page.url());
}

const browser = await chromium.launch();

// --- the guest side, anonymous ---------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const g = GUEST + '/aegean-blue';
  await shot(page, 'guest-home', g + '?lang=el', 1500);
  await shot(page, 'guest-search', g + '/search?lang=el', 1400);
  await shot(page, 'guest-trip', g + '/proino-kolymvitiko?lang=el', 1600);
  await shot(page, 'guest-contact', g + '/contact?lang=el', 1200);
  await shot(page, 'guest-legal', g + '/legal?lang=el', 1200);
  await shot(page, 'guest-home-en', g + '?lang=en', 1500);
  await ctx.close();
}

// --- the operator panel ----------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await signIn(page, PANEL + '/app', 'maria@aegean-blue.example');

  const PAGES = [
    ['panel-dashboard', '/app', 1600],
    // Pinned to a period: "the last 30 days" is a different month every month.
    ['panel-analytics', '/app/analytics?period=this_year', 1700],
    ['panel-calendar', '/app/calendar', 1200],
    ['panel-departures', '/app/departures', 1200],
    ['panel-bookings', '/app/bookings', 1200],
    ['panel-products', '/app/products', 1100],
    ['panel-vessels', '/app/vessels', 1000],
    ['panel-seasons', '/app/seasons', 1000],
    ['panel-rate-plans', '/app/rate-plans', 1000],
    ['panel-schedule-rules', '/app/schedule-rules', 1000],
    ['panel-vessel-blocks', '/app/vessel-blocks', 1000],
    ['panel-cancellation', '/app/cancellation-policies', 1000],
    ['panel-invoices', '/app/invoices', 1100],
    ['panel-vouchers', '/app/vouchers', 1000],
    ['panel-quotes', '/app/quotes', 1000],
    ['panel-enquiries', '/app/enquiries', 1000],
    ['panel-check-in', '/app/check-in', 1100],
    ['panel-failures', '/app/failures', 1000],
    ['panel-settings', '/app/settings', 1300],
    ['panel-branding', '/app/branding', 1400],
    ['panel-home-page', '/app/home-page', 1500],
    ['panel-faqs', '/app/faqs', 1000],
    ['panel-integrations', '/app/integrations', 1200],
    ['panel-payment', '/app/payment-settings', 1200],
    ['panel-domains', '/app/domains', 1100],
    ['panel-calendar-sync', '/app/calendar-sync', 1100],
    ['panel-webhooks', '/app/webhook-endpoints', 1000],
    ['panel-api-keys', '/app/api-keys', 1000],
    ['panel-exports', '/app/exports', 1000],
    ['panel-imports', '/app/imports', 1000],
    ['panel-staff', '/app/staff', 1000],
    ['panel-audit-log', '/app/audit-logs', 1100],
    ['panel-notifications', '/app/notification-logs', 1100],
    ['panel-setup', '/app/setup', 1400],
    ['panel-search-settings', '/app/search-settings', 1100],
    ['panel-reconciliation', '/app/departure-reconciliation', 1000],
  ];
  for (const [name, path, h] of PAGES) await shot(page, name, PANEL + path, h);

  // One booking, opened — the detail screen is where the money lives.
  try {
    await page.setViewportSize({ width: 1440, height: 1500 });
    await page.goto(PANEL + '/app/bookings', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await page.locator('table tbody tr').first().click();
    await page.waitForTimeout(2500);
    await page.screenshot({ path: resolve(OUT, 'panel-booking-detail.png') });
    ok.push('panel-booking-detail');
    console.log('ok   panel-booking-detail ->', page.url());
  } catch (e) {
    bad.push('panel-booking-detail: ' + String(e).split('\n')[0]);
  }
  await ctx.close();
}

// --- the platform panel ----------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await signIn(page, PANEL + '/admin', 'admin@kaiki.example');
  const PAGES = [
    ['admin-tenants', '/admin/tenants', 1100],
    ['admin-health', '/admin/health', 1300],
    ['admin-announcements', '/admin/announcements', 1000],
    ['admin-vat-rates', '/admin/vat-rates', 1000],
  ];
  for (const [name, path, h] of PAGES) await shot(page, name, PANEL + path, h);
  await ctx.close();
}

// --- a phone ---------------------------------------------------------
{
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true,
  });
  const page = await ctx.newPage();

  // The guest side first, while nobody is signed in.
  for (const [name, url] of [
    ['phone-guest-home', GUEST + '/aegean-blue?lang=el'],
    ['phone-guest-trip', GUEST + '/aegean-blue/proino-kolymvitiko?lang=el'],
  ]) {
    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(1800);
      await page.screenshot({ path: resolve(OUT, name + '.png') });
      ok.push(name); console.log('ok  ', name);
    } catch (e) { bad.push(name + ': ' + String(e).split('\n')[0]); }
  }

  await signIn(page, PANEL + '/app', 'maria@aegean-blue.example');
  for (const [name, url] of [
    ['phone-dashboard', PANEL + '/app'],
    ['phone-check-in', PANEL + '/app/check-in'],
    ['phone-boarding', PANEL + '/app/boarding'],
  ]) {
    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(1800);
      await page.screenshot({ path: resolve(OUT, name + '.png') });
      ok.push(name); console.log('ok  ', name);
    } catch (e) { bad.push(name + ': ' + String(e).split('\n')[0]); }
  }
  await ctx.close();
}

await browser.close();
console.log('\ndone -> ' + OUT + '\n' + ok.length + ' ok, ' + bad.length + ' failed');
if (bad.length) console.log(bad.join('\n'));
