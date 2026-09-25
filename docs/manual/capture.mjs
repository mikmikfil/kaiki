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
import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, 'img');

const PANEL = process.env.KAIKI_MANUAL_PANEL ?? 'http://127.0.0.1:8000';

const DESKTOP = { width: 1280, height: 860 };
const PHONE = { width: 390, height: 844 };

const PEOPLE = {
  owner: 'maria@aegean-blue.example',
  manager: 'giorgos@aegean-blue.example',
  crew: 'nikos@aegean-blue.example',
  admin: 'admin@kaiki.example',
  // Ionian Sunset: Solo with ten boats since plan limits became real
  // (2026-09-11) — the demo of a limit reached.
  soloOwner: 'elena@ionian-sunset.example',
};

const ROOT = resolve(HERE, '..', '..');
const PHP = process.env.KAIKI_PHP ?? 'C:\\Users\\Mike\\php84\\php.exe';

/** One line of PHP through `artisan tinker`, returning its last printed word. */
function tinker(code) {
  const out = execSync(`"${PHP}" artisan tinker --execute="${code}"`, { cwd: ROOT, encoding: 'utf8' });

  return out.trim().split(/\s+/).pop();
}

/** An operator's public identifier, which is what the panel's URLs are built from. */
function tenantRouteKey(slug) {
  return tinker(`print(App\\Models\\Tenant::where('slug','${slug}')->value('uuid') ?? '');`);
}

/**
 * Is this person in the database at all?
 *
 * A development database is not always the full demo seed, and a sign-in that
 * cannot succeed fails as a thirty-second navigation timeout rather than as a
 * sentence anybody can act on.
 */
async function exists(email) {
  return tinker(`print(App\\Models\\User::where('email','${email}')->exists() ? 'yes' : 'no');`) === 'yes';
}

/**
 * The guest side answers only on the hosted host (`KAIKI_HOSTED_HOST`), so the
 * default is read from the application rather than guessed: on 23 September
 * the old default, `127.0.0.1:8001`, was a 404 on every guest figure.
 */
const GUEST = process.env.KAIKI_MANUAL_GUEST
  ?? `http://${tinker("print(config('kaiki.tenancy.hosted_host'));")}`;

/** Run `fn` inside the demo operator, in PHP, and return what it printed. */
function inDemo(php) {
  return tinker(
    `App\\Support\\Tenancy::forTenant(App\\Models\\Tenant::where('slug','aegean-blue')->first(), fn () => print(${php} ?? 'none'));`,
  );
}

/**
 * The trip the trip-form figures open: a draft if there is one, because only a
 * trip with something left to do shows «Πριν τη δημοσίευση» beside the form.
 */
function demoTripUrl() {
  const uuid = inDemo(
    "App\\Models\\Product::query()->where('status','draft')->value('uuid') ?? App\\Models\\Product::query()->orderBy('id')->value('uuid')",
  );

  return uuid && uuid !== 'none' ? `/app/products/${uuid}/edit` : null;
}

/**
 * A guest link of one of the demo's bookings, by status. Read, never written:
 * the checkout of a lapsed draft and the page of a confirmed booking are both
 * pages a guest already holds a link to.
 */
function demoBookingToken(statuses) {
  const list = statuses.map((s) => `'${s}'`).join(',');
  const token = inDemo(`App\\Models\\Booking::query()->whereIn('status',[${list}])->latest('id')->value('manage_token')`);

  return token && token !== 'none' ? token : null;
}

/** The public address of a published demo trip, for the trip-page figure. */
function demoTripSlug() {
  const slug = inDemo(
    "App\\Models\\Product::query()->where('status','active')->where('mode','per_seat')->orderBy('id')->value('slug')",
  );

  return slug && slug !== 'none' ? slug : null;
}

/** Scroll the first element with exactly this text to the top of the screen. */
async function scrollToText(page, text) {
  await page.getByText(text, { exact: true }).first()
    .evaluate((el) => el.scrollIntoView({ block: 'start' }));
  await page.evaluate(() => window.scrollBy(0, -90));
  await page.waitForTimeout(300);
}

/** Open a Filament tab by its label. */
async function openTab(page, name) {
  await page.getByRole('tab', { name }).first().click();
  await page.waitForTimeout(900);
}

/**
 * The demo import's review page.
 *
 * Found rather than hard-coded: the uuid changes every time the demo import is
 * run again, and a manual that photographed a 404 would say nothing about why.
 */
function demoImportUrl() {
  const uuid = tinker(
    "App\\Support\\Tenancy::forTenant(App\\Models\\Tenant::where('slug','aegean-blue')->first(), fn () => print(App\\Models\\ImportJob::query()->latest('id')->value('uuid') ?? 'none'));",
  );

  return uuid && uuid !== 'none' ? `/app/imports/${uuid}` : null;
}

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
  // Pinned to a period rather than left on the default, so the figure is the
  // same page every time it is re-taken: "the last 30 days" is a different
  // month every month, and a manual whose screenshots drift is one nobody can
  // tell has been updated.
  ['analytics', '/app/analytics?period=this_year'],
  ['check-in', '/app/check-in'],
  ['products', '/app/products'],
  ['vessels', '/app/vessels'],
  ['ports', '/app/ports'],
  // «Τιμοκατάλογοι» in the sidebar: every trip's price lists in one place. Made inside
  // the trip since 2026-09-22, so this list has no «Νέο» of its own.
  ['rate-plans', '/app/rate-plans'],
  ['seasons', '/app/seasons'],
  // `schedule-rules` left the book on 23 September: schedules are made and
  // edited on the trip's «Πότε φεύγει» tab, and the old list has no menu entry.
  ['cancellation-policies', '/app/cancellation-policies'],
  ['vessel-blocks', '/app/vessel-blocks'],
  // «Κουπόνια» in the sidebar is the discount codes (2026-09-17). The form
  // rather than the list, because the demo has none and an empty list says
  // nothing. The credit vouchers of `/app/vouchers` are no longer photographed:
  // the demo has none either.
  ['discount-codes', '/app/discount-codes/create'],
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
  ['review-settings', '/app/review-settings'],
  ['payment-settings', '/app/payment-settings'],
  ['domains', '/app/domains'],
  ['integrations', '/app/integrations'],
  ['calendar-sync', '/app/calendar-sync'],
  ['departure-reconciliation', '/app/departure-reconciliation'],
  // The WooCommerce / YITH importer (2026-09-11), owner only.
  ['imports', '/app/imports'],
];

/**
 * The setup guide, opened at a step by its `?step=`. The demo operator has
 * finished it, which is why the list beside the question is all ticks; the
 * address keeps working after the guide leaves the menu.
 */
const SETUP_SHOTS = [
  ['setup-guide', '/app/setup?step=business'],
  // `setup-home-page` left on 25/9: the home page is no longer a step of the
  // guide but the last, optional one of «Τα πρώτα σας βήματα».
];

/**
 * «Νέα εκδρομή» is the «Βασικά» tab alone since 25/9, and «Συνέχεια» opens the
 * trip's own page, so there is one figure of it and the rest are the tabs of a
 * saved (draft) trip. The URL of the trip is found, not written here.
 */
const NEW_TRIP_SHOTS = [
  ['trip-new-basics', '/app/products/create'],
];

const TRIP_TAB_SHOTS = [
  ['trip-tab-basics', 'Βασικά', null],
  ['trip-tab-when', 'Πότε φεύγει', 'Δρομολόγια'],
  ['trip-tab-prices', 'Τιμές', 'Τιμές σε ευρώ'],
  ['trip-tab-terms', 'Όροι', null],
  ['trip-tab-page', 'Σελίδα', 'Φωτογραφίες'],
];

/**
 * An operator at their plan's limit: Solo with ten boats. The vessels list
 * says so and «Νέο» has become «Αναβάθμιση πακέτου»; the domain screen shows
 * what Pro includes instead of the form.
 */
const SOLO_SHOTS = [
  ['vessels-limit', '/app/vessels'],
  ['domains-pro-only', '/app/domains'],
];

/** The two screens a crew member has, on the device they hold. */
const CREW_PHONE_SHOTS = [
  ['crew-boarding-phone', '/app/boarding'],
  ['crew-dashboard-phone', '/app'],
];

/** The owner's settings cards on a phone — two to a row, which is the point of squares. */
const OWNER_PHONE_SHOTS = [
  ['settings-phone', '/app/settings'],
  // The home page on a phone: «Πώληση τώρα» and «Σάρωση» in a bar fixed to
  // the foot of the screen (25/9).
  ['dashboard-phone', '/app'],
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
  // The **uuid**, not the id. `TenantResource` resolves by route key and every
  // public identifier in this product is a uuid (§1.1), so `/tenants/1/edit`
  // is a 404 — and a 404 fails here as a thirty-second locator timeout rather
  // than as a missing page, which is how this figure went stale unnoticed.
  //
  // The switches are on the «Λειτουργίες» tab since 2026-09-21, so the tab is
  // opened before the scroll.
  ['admin-tenant-edit', `/admin/tenants/${tenantRouteKey('aegean-blue')}/edit?lang=el`, async (page) => {
    await openTab(page, 'Λειτουργίες');
    await page.locator('[id="data.check_in_enabled"]').first()
      .evaluate((el) => el.scrollIntoView({ block: 'center' }));
  }],
  // «Εμφάνιση» (25/9): the logo and the colours, set by the platform when it
  // opens the account. Looked at, never saved.
  ['admin-tenant-branding', `/admin/tenants/${tenantRouteKey('aegean-blue')}/edit?lang=el`, async (page) => {
    await openTab(page, 'Εμφάνιση');
    await page.evaluate(() => window.scrollTo(0, 0));
  }],
  // «Σύνδεση ως» (2026-09-23): the form only. Pressing it is a separate,
  // opt-in block below, because it writes a line in the operator's log.
  ['admin-impersonate', '/admin/tenants?lang=el', async (page) => {
    await page.getByRole('row', { name: /Aegean Blue/ }).getByRole('button', { name: 'Σύνδεση ως' }).click();
    await page.waitForTimeout(1200);
  }],
  ['admin-vat-rates', '/admin/vat-rates?lang=el'],
  ['admin-policy-templates', '/admin/policy-templates?lang=el'],
  // The platform panel (2026-09-11): health, and the announcements list.
  ['admin-health', '/admin/health?lang=el'],
  ['admin-announcements', '/admin/announcements?lang=el'],
];

const GUEST_SHOTS = [
  ['guest-home', '/aegean-blue'],
  ['guest-search', '/aegean-blue/search'],
  ['guest-contact', '/aegean-blue/contact'],
  ['guest-legal', '/aegean-blue/legal'],
];

/**
 * The trip page, the checkout and the booking page (2026-09-18 to 09-23).
 *
 * The checkout is a draft booking's, whose hold has lapsed: it says so above
 * the price, and that is the page such a guest sees. The booking page is the
 * most recent confirmed booking, or failing that the most recent with a ticket.
 */
function guestFlowShots() {
  const shots = [];
  const slug = demoTripSlug();
  const draft = demoBookingToken(['draft']);
  // A confirmed booking if there is one — a completed one reads «Ολοκληρώθηκε».
  const ticketed = demoBookingToken(['confirmed']) ?? demoBookingToken(['checked_in', 'completed']);

  if (slug) shots.push(['guest-trip', `/aegean-blue/${slug}`]);
  if (draft) shots.push(['guest-checkout', `/c/${draft}`]);
  if (ticketed) shots.push(['guest-booking', `/b/${ticketed}`]);

  return { shots, draft };
}

/**
 * `panelPath` is explicit rather than inferred from the URL.
 *
 * Both panels are served by the same application on the same port, so there is
 * nothing in the base URL to infer it from — and the platform admin has no
 * tenant, so signing them in at `/app/login` fails in the least helpful way
 * available: the form simply stays put, and the run times out waiting for a
 * navigation that was never going to happen.
 */
async function signIn(context, email, panelPath, base = PANEL, attempt = 1) {
  const page = await context.newPage();

  try {
    await page.goto(`${base}/${panelPath}/login`);
    await page.locator('#data\\.email').fill(email);
    await page.locator('#data\\.password').fill('password');
    await page.locator('#form button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30_000 });
  } catch (error) {
    // The sign-in form allows a few attempts a minute per address, and this run
    // signs the same people in more than once. One wait, then give up loudly.
    if (attempt > 1) {
      throw error;
    }

    process.stdout.write(`  (sign-in for ${email} held back — waiting a minute)\n`);
    await page.close();
    await new Promise((done) => setTimeout(done, 65_000));

    return signIn(context, email, panelPath, base, attempt + 1);
  }

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

/**
 * `scrollTo` is a selector, or a function given the page — for the figures that
 * need a tab opened, a step shown or a button pressed before they are taken.
 */
async function shoot(context, name, url, base, scrollTo = null) {
  const page = await context.newPage();

  try {
    await page.goto(base + url, { waitUntil: 'domcontentloaded' });
    await settle(page);

    if (typeof scrollTo === 'function') {
      await scrollTo(page);
      await page.waitForTimeout(300);
    } else if (scrollTo) {
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
    // The URL, because the two ways this fails look identical without it: a
    // selector that no longer matches, and a page that was never there.
    process.stdout.write(`  ${name} — FAILED at ${base}${url}: ${error.message.split('\n')[0]}\n`);

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

  // Further down the same pages: the dashboard's decisions and the charts.
  taken.push(await shoot(context, 'dashboard-attention', '/app', PANEL, (page) => scrollToText(page, 'Χρειάζονται προσοχή')));
  taken.push(await shoot(context, 'analytics-charts', '/app/analytics?period=this_year', PANEL, (page) => scrollToText(page, 'Ποιες μέρες ταξιδεύουν')));

  for (const [name, url] of SETUP_SHOTS) {
    taken.push(await shoot(context, name, url, PANEL));
  }

  for (const [name, url] of NEW_TRIP_SHOTS) {
    taken.push(await shoot(context, name, url, PANEL));
  }

  const tripUrl = demoTripUrl();

  if (tripUrl) {
    for (const [name, tab, heading] of TRIP_TAB_SHOTS) {
      taken.push(await shoot(context, name, tripUrl, PANEL, async (page) => {
        await openTab(page, tab);

        if (heading) {
          await scrollToText(page, heading);
        }
      }));
    }
  } else {
    process.stdout.write('  trip-tab-* — skipped: no trip on Aegean Blue\n');
  }

  // The importer's upload form is a modal behind «Νέα εισαγωγή», so it is
  // opened by clicking the button a person would click.
  {
    const page = await context.newPage();

    try {
      await page.goto(`${PANEL}/app/imports`, { waitUntil: 'domcontentloaded' });
      await settle(page);
      await page.getByRole('button', { name: 'Νέα εισαγωγή' }).first().click();
      await page.waitForTimeout(1200);
      await page.screenshot({ path: resolve(OUT, 'import-upload.jpg'), type: 'jpeg', quality: 78 });
      process.stdout.write('  import-upload\n');
      taken.push({ name: 'import-upload', url: '/app/imports' });
    } catch (error) {
      process.stdout.write(`  import-upload — FAILED: ${error.message.split('\n')[0]}\n`);
      taken.push(null);
    } finally {
      await page.close();
    }
  }

  // The review screen of the demo import, which has already run.
  const reviewUrl = demoImportUrl();

  if (reviewUrl) {
    taken.push(await shoot(context, 'import-review', reviewUrl, PANEL));
  } else {
    process.stdout.write('  import-review — skipped: no demo import on Aegean Blue\n');
  }

  await context.close();
}

// The owner, with a platform announcement on screen — created for this one
// shot and deleted straight after, so no fake notice is left behind.
{
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  const script = resolve(HERE, 'capture-announce.php').replace(/\\/g, '/');
  const created = tinker(`require '${script}';`);
  const id = (created.match(/ANNOUNCEMENT_ID=(\d+)/) ?? [])[1];

  try {
    await signIn(context, PEOPLE.owner, 'app');
    process.stdout.write('owner with an announcement, 1280×860:\n');
    taken.push(await shoot(context, 'announcement-banner', '/app', PANEL));
  } finally {
    if (id) {
      tinker(`App\\Models\\PlatformAnnouncement::query()->whereKey(${id})->delete(); echo 'deleted';`);
    }

    await context.close();
  }
}

// An operator on Solo, at the limit.
//
// **Skipped rather than fatal when that operator is not in this database.** A
// development database is not always the full demo seed — on 14 September the
// product owner asked for every operator except two to be cleared out, and this
// block then failed the whole run thirty screenshots in, on a sign-in that
// could never succeed. The figures it takes are committed, so a run without it
// leaves them as they were rather than leaving holes.
//
// Loudly, though: a silently skipped capture is a manual that quietly stops
// describing the product.
if (await exists(PEOPLE.soloOwner)) {
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  await signIn(context, PEOPLE.soloOwner, 'app');
  process.stdout.write('solo owner, 1280×860:\n');

  // `?lang=el`: Ionian Sunset's owner writes in English.
  for (const [name, url] of SOLO_SHOTS) {
    taken.push(await shoot(context, name, `${url}?lang=el`, PANEL));
  }

  await context.close();
} else {
  process.stdout.write(
    `SKIPPED — ${PEOPLE.soloOwner} is not in this database, so the plan-limit figures\n` +
      `  (${SOLO_SHOTS.map(([name]) => name).join(', ')}) keep their committed versions.\n` +
      '  Seed the full demo to re-take them.\n',
  );
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

// The crew at a desk: «Ποιοι είναι σε αυτή την εκδρομή» from the calendar, with
// the trip summary above the passengers (2026-09-23) and no price anywhere.
{
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  await signIn(context, PEOPLE.crew, 'app');
  process.stdout.write('crew, 1280×860:\n');

  taken.push(await shoot(context, 'crew-trip-summary', '/app/calendar', PANEL, async (page) => {
    const bars = page.locator('xpath=//*[@*[name()="wire:click" and starts-with(., "mountAction(\'pax")]]');
    // The first departure with somebody on it, so the list under the summary is not empty.
    const count = await bars.count();
    let pick = 0;

    for (let i = 0; i < count; i++) {
      if (!/ · 0\//.test(await bars.nth(i).innerText())) {
        pick = i;
        break;
      }
    }

    await bars.nth(pick).click();
    await page.waitForTimeout(1500);
  }));

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

  const { shots, draft } = guestFlowShots();

  for (const [name, url] of shots) {
    taken.push(await shoot(context, name, url, GUEST));
  }

  await context.close();

  // The checkout on a phone: the total and the button in a bar at the foot.
  if (draft) {
    const phone = await browser.newContext({
      viewport: PHONE,
      deviceScaleFactor: 3,
      isMobile: true,
      hasTouch: true,
      locale: 'el-GR',
      timezoneId: 'Europe/Athens',
    });

    process.stdout.write('guest, 390×844:\n');
    taken.push(await shoot(phone, 'guest-checkout-phone', `/c/${draft}`, GUEST));
    await phone.close();
  }
}

// «Σύνδεση ως», pressed: the bar across the operator's panel.
//
// **Opt-in** (`KAIKI_MANUAL_IMPERSONATE=1`), because starting a session writes
// a line into Aegean Blue's «Ιστορικό ενεργειών» — that is the point of it —
// and a rebuild of the book should not add one every time. Without the flag the
// committed figure stays as it is. The session is ended with «Έξοδος» straight
// after the shot.
if (process.env.KAIKI_MANUAL_IMPERSONATE === '1') {
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  await signIn(context, PEOPLE.admin, 'admin');
  process.stdout.write('platform admin signed in as the owner, 1280×860:\n');

  const page = await context.newPage();

  try {
    await page.goto(`${PANEL}/admin/tenants?lang=el`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await page.getByRole('row', { name: /Aegean Blue/ }).getByRole('button', { name: 'Σύνδεση ως' }).click();
    await page.waitForTimeout(1200);

    // Filament keeps every table action's modal in the page; the open one is
    // the visible one.
    const modal = page.locator('.fi-modal-window:visible').first();
    await modal.locator('.choices').first().click();
    await modal.locator('.choices__item--choice', { hasText: 'Μαρία' }).first().click();
    await modal.locator('textarea').fill('Εικόνα για το εγχειρίδιο του διοργανωτή.');
    await modal.getByRole('button', { name: 'Σύνδεση', exact: true }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 30_000 });
    await settle(page);
    await page.screenshot({ path: resolve(OUT, 'impersonation-banner.jpg'), type: 'jpeg', quality: 78 });
    process.stdout.write('  impersonation-banner\n');
    taken.push({ name: 'impersonation-banner', url: '/app' });
    await page.getByRole('button', { name: 'Έξοδος' }).first().click();
    await page.waitForTimeout(1500);
  } catch (error) {
    process.stdout.write(`  impersonation-banner — FAILED: ${error.message.split('\n')[0]}\n`);
    taken.push(null);
  } finally {
    await page.close();
    await context.close();
  }
} else {
  process.stdout.write('SKIPPED — impersonation-banner (set KAIKI_MANUAL_IMPERSONATE=1 to re-take it).\n');
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
