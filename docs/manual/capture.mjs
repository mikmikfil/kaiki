/**
 * Every screenshot in the operator manual, taken from a running Kaiki.
 *
 * ## Why a script rather than a person with a cropping tool
 *
 * A manual with hand-taken screenshots is out of date the first time a label
 * changes, and nobody can tell which pictures are stale because they all look
 * equally finished. This walks the same URLs every time, so regenerating the
 * whole book is one command and a picture that disappears is a route that
 * disappeared.
 *
 * It also means the manual is **written against the product**, not against
 * somebody's memory of it: a screen that cannot be reached by signing in and
 * following a link does not get into the book.
 *
 * ## What it needs
 *
 *   php artisan serve --port=8000        # the panel
 *   php artisan serve --port=8001        # the guest side (KAIKI_HOSTED_HOST)
 *   php artisan db:seed --class=DemoTenantSeeder   # and DemoBookingSeeder
 *
 *   node docs/manual/capture.mjs
 *
 * Credentials are the demo seeder's, which are deterministic and public
 * (`DemoTenantSeeder`). Nothing here reads a real operator's data, and the
 * manual must never be built against one — the pictures would carry guest names
 * and telephone numbers into a PDF that gets emailed around.
 *
 * ## The shape of a shot
 *
 * 1280×860 at a device pixel ratio of 1.5, giving 1920px of image for a figure
 * that prints about 160mm wide — a shade over 300dpi, which is as much as paper
 * can hold and more than a screen will ever show.
 * Viewport rather than full-page: a full-page capture of a table set to ten rows
 * is the same picture with more whitespace, and of a long form it is a picture
 * nobody can read at print size.
 *
 * **JPEG, not PNG.** These are committed, and the same pictures as lossless PNG
 * are eight megabytes against a repository of eleven — doubling a clone every
 * time a label changes, for detail nobody can see at 160mm on paper. The
 * resolution is what costs, not the format: 1.5 rather than 2 halves the pixels
 * before the encoder ever sees them.
 */

import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, 'img');

const PANEL = process.env.KAIKI_MANUAL_PANEL ?? 'http://127.0.0.1:8000';
const GUEST = process.env.KAIKI_MANUAL_GUEST ?? 'http://127.0.0.1:8001';

const DESKTOP = { width: 1280, height: 860 };
const PHONE = { width: 390, height: 844 };

const PEOPLE = {
  owner: 'maria@aegean-blue.example',
  manager: 'giorgos@aegean-blue.example',
  crew: 'nikos@aegean-blue.example',
  admin: 'admin@kaiki.example',
};

/**
 * The panel, as the owner sees it — which is everything.
 *
 * Ordered the way the manual is: what you set up, what you do every morning,
 * what you do with money, what the guest sees, and what you configure once.
 */
const PANEL_SHOTS = [
  ['dashboard', '/app'],
  // «Ρυθμίσεις» since 2026-09-11: one item at the bottom of the sidebar,
  // opening a page of cards rather than a collapsed group of fifteen links.
  ['settings', '/app/settings'],
  ['departures', '/app/departures'],
  ['bookings', '/app/bookings'],
  ['calendar', '/app/calendar'],
  ['check-in', '/app/check-in'],
  ['products', '/app/products'],
  ['vessels', '/app/vessels'],
  ['ports', '/app/ports'],
  ['rate-plans', '/app/rate-plans'],
  ['seasons', '/app/seasons'],
  ['schedule-rules', '/app/schedule-rules'],
  ['cancellation-policies', '/app/cancellation-policies'],
  ['vessel-blocks', '/app/vessel-blocks'],
  ['vouchers', '/app/vouchers'],
  ['quotes', '/app/quotes'],
  ['enquiries', '/app/enquiries'],
  ['faqs', '/app/faqs'],
  ['invoices', '/app/invoices'],
  ['exports', '/app/exports'],
  ['failures', '/app/failures'],
  ['notification-logs', '/app/notification-logs'],
  ['audit-logs', '/app/audit-logs'],
  ['staff', '/app/staff'],
  ['api-keys', '/app/api-keys'],
  ['webhook-endpoints', '/app/webhook-endpoints'],
  ['branding', '/app/branding'],
  ['home-page', '/app/home-page'],
  ['search-settings', '/app/search-settings'],
  ['payment-settings', '/app/payment-settings'],
  ['domains', '/app/domains'],
  ['integrations', '/app/integrations'],
  ['calendar-sync', '/app/calendar-sync'],
  ['departure-reconciliation', '/app/departure-reconciliation'],
];

/** The two screens a crew member has, on the device they hold. */
const CREW_PHONE_SHOTS = [
  ['crew-boarding-phone', '/app/boarding'],
  ['crew-dashboard-phone', '/app'],
];

/** The owner's settings cards on a phone — two to a row, which is the point of squares. */
const OWNER_PHONE_SHOTS = [
  ['settings-phone', '/app/settings'],
];

/**
 * The third element, when present, is a selector to scroll to before the shot.
 *
 * The operator edit form's «Λειτουργίες» section — the QR boarding switch —
 * sits below the fold at 860px, and a shot of the top of the form would show
 * the plan and the status and not the thing the chapter is about.
 */
// `?lang=el` because the demo platform admin's own language is English, and a
// Greek manual with English admin screens reads as two products.
const ADMIN_SHOTS = [
  ['admin-dashboard', '/admin?lang=el'],
  ['admin-tenants', '/admin/tenants?lang=el'],
  ['admin-tenant-edit', '/admin/tenants/1/edit?lang=el', '[id="data.qr_check_in_enabled"]'],
  ['admin-vat-rates', '/admin/vat-rates?lang=el'],
];

const GUEST_SHOTS = [
  ['guest-home', '/aegean-blue'],
  ['guest-search', '/aegean-blue/search'],
  ['guest-contact', '/aegean-blue/contact'],
  ['guest-legal', '/aegean-blue/legal'],
];

/**
 * `panelPath` is explicit rather than inferred from the URL.
 *
 * Both panels are served by the same application on the same port, so there is
 * nothing in the base URL to infer it from — and the platform admin has no
 * tenant, so signing them in at `/app/login` fails in the least helpful way
 * available: the form simply stays put, and the run times out waiting for a
 * navigation that was never going to happen.
 */
async function signIn(context, email, panelPath, base = PANEL) {
  const page = await context.newPage();

  await page.goto(`${base}/${panelPath}/login`);
  await page.locator('#data\\.email').fill(email);
  await page.locator('#data\\.password').fill('password');
  await page.locator('#form button[type="submit"]').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30_000 });

  await page.close();
}

/**
 * Wait for the page to have stopped moving, not merely to have arrived.
 *
 * Livewire paints a table and then fills it, and Filament's charts animate in.
 * A shot taken on `load` catches skeletons, which in a manual look like a
 * broken product rather than a fast one.
 */
async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(700);
}

async function shoot(context, name, url, base, scrollTo = null) {
  const page = await context.newPage();

  try {
    await page.goto(base + url, { waitUntil: 'domcontentloaded' });
    await settle(page);

    if (scrollTo) {
      // A selector rather than label text: the admin's own locale decides the
      // label, and a Greek string never matches an English page.
      await page.locator(scrollTo).first()
        .evaluate((el) => el.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(300);
    }

    await page.screenshot({ path: resolve(OUT, `${name}.jpg`), type: 'jpeg', quality: 78 });
    process.stdout.write(`  ${name}\n`);

    return { name, url };
  } catch (error) {
    process.stdout.write(`  ${name} — FAILED: ${error.message.split('\n')[0]}\n`);

    return null;
  } finally {
    await page.close();
  }
}

const browser = await chromium.launch();
const taken = [];

mkdirSync(OUT, { recursive: true });

// The owner, at a desk.
{
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  await signIn(context, PEOPLE.owner, 'app');
  process.stdout.write('owner, 1280×860:\n');

  for (const [name, url] of PANEL_SHOTS) {
    taken.push(await shoot(context, name, url, PANEL));
  }

  await context.close();
}

// The crew, on a phone at the quay.
{
  const context = await browser.newContext({
    viewport: PHONE,
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  await signIn(context, PEOPLE.crew, 'app');
  process.stdout.write('crew, 390×844:\n');

  for (const [name, url] of CREW_PHONE_SHOTS) {
    taken.push(await shoot(context, name, url, PANEL));
  }

  await context.close();
}

// The owner, on a phone — the settings cards at 390px.
{
  const context = await browser.newContext({
    viewport: PHONE,
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  await signIn(context, PEOPLE.owner, 'app');
  process.stdout.write('owner, 390×844:\n');

  for (const [name, url] of OWNER_PHONE_SHOTS) {
    taken.push(await shoot(context, name, url, PANEL));
  }

  await context.close();
}

// The platform operator — a different panel and a different person.
{
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  await signIn(context, PEOPLE.admin, 'admin');
  process.stdout.write('platform admin, 1280×860:\n');

  for (const [name, url, scrollTo] of ADMIN_SHOTS) {
    taken.push(await shoot(context, name, url, PANEL, scrollTo));
  }

  await context.close();
}

// The guest, signed in to nothing.
{
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  process.stdout.write('guest, 1280×860:\n');

  for (const [name, url] of GUEST_SHOTS) {
    taken.push(await shoot(context, name, url, GUEST));
  }

  await context.close();
}

await browser.close();

const ok = taken.filter(Boolean);

writeFileSync(
  resolve(OUT, 'index.json'),
  `${JSON.stringify({ takenAt: new Date().toISOString(), shots: ok }, null, 2)}\n`,
);

process.stdout.write(`\n${ok.length} of ${taken.length} captured into ${OUT}\n`);

if (ok.length !== taken.length) {
  process.exitCode = 1;
}
