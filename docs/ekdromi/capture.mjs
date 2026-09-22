/**
 * Every screenshot in «Πώς φτιάχνω μια εκδρομή», taken from a running Kaiki.
 *
 *   php artisan serve --port=8000        # the panel
 *   php artisan serve --port=8001        # the guest side (KAIKI_HOSTED_HOST)
 *
 *   node docs/ekdromi/capture.mjs
 *   node docs/ekdromi/build.mjs
 *
 * Same discipline as `docs/manual/capture.mjs`, and for the same reason: a
 * guide with hand-taken pictures is stale the first time a label moves, and
 * nobody can tell which picture is the stale one. Walking the real URLs means
 * a screen that cannot be reached is a screen that does not get into the book.
 *
 * ## Two operators, on purpose
 *
 * The demo tenant (`aegean-blue`) has trips but **no rate plans**, so its price
 * tab is the empty state — which is the right picture for "what you see before
 * you have made a price list", and the wrong one for "this is the grid you fill
 * in". The test operator (`dokimi-kritis`) has seven plans and priced bands, so
 * the filled grid comes from there. Both are seeded, deterministic and carry no
 * real guest's name.
 *
 * ## Tabs are addressed, not clicked
 *
 * The form's tabs persist in the query string (`persistTabInQueryString('tab')`),
 * and the ids are Filament's own transliteration of the Greek labels — the list
 * is in the `TAB` map below. Navigating straight to `?tab=-times-tab` is one
 * page load with nothing to race; clicking a tab is a Livewire round trip that
 * has to be waited out, and the wait is what makes a capture flaky.
 */

import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, 'img');

const PANEL = process.env.KAIKI_MANUAL_PANEL ?? 'http://127.0.0.1:8000';
const GUEST = process.env.KAIKI_MANUAL_GUEST ?? 'http://192.168.1.43:8001';

const DESKTOP = { width: 1280, height: 900 };

/** Filament's ids for the five tabs, as the hidden `tabsData` input spells them. */
const TAB = {
  basics: '-basika-tab',
  when: '-pote-pheugei-tab',
  prices: '-times-tab',
  terms: '-oroi-tab',
  page: '-selida-tab',
};

const PEOPLE = {
  demo: 'maria@aegean-blue.example',
  test: 'nikos@dokimi-kritis.example',
};

/** The trips the guide photographs, by their route key (a uuid, §1.1). */
const TRIPS = {
  // Aegean Blue, sold per seat: the one the walkthrough edits.
  perSeat: 'c3dab4a6-9a49-4dc6-9c3a-c953465946df',
  // Aegean Blue, whole-boat charter: a different «Πότε φεύγει».
  charter: 'a99f7fd2-9e61-401f-93d6-7cc491c8495b',
  // Δοκιμή Κρήτης, per seat, with plans and prices behind it.
  priced: '221fe4d4-42da-4750-a67d-257466e218aa',
};

const edit = (trip, tab) => `/app/products/${trip}/edit?tab=${tab}`;

/**
 * `[name, url, scrollTo?]` — the third element is a selector to bring into view
 * before the shot, for the sections that sit below the fold at 900px.
 */
const DEMO_SHOTS = [
  ['products-list', '/app/products'],
  ['create-basics', `/app/products/create?tab=${TAB.basics}`],
  ['create-when', `/app/products/create?tab=${TAB.when}`],
  ['create-prices', `/app/products/create?tab=${TAB.prices}`],
  ['create-terms', `/app/products/create?tab=${TAB.terms}`],
  ['create-page', `/app/products/create?tab=${TAB.page}`],
  ['edit-basics', edit(TRIPS.perSeat, TAB.basics)],
  ['edit-when', edit(TRIPS.perSeat, TAB.when)],
  ['edit-schedules', edit(TRIPS.perSeat, TAB.when), 'button:has-text("Νέο δρομολόγιο")'],
  ['edit-prices-empty', edit(TRIPS.perSeat, TAB.prices)],
  ['edit-extras', edit(TRIPS.perSeat, TAB.prices), 'button:has-text("Νέο πρόσθετο")'],
  ['edit-terms', edit(TRIPS.perSeat, TAB.terms)],
  ['edit-page', edit(TRIPS.perSeat, TAB.page)],
  ['edit-checklist', edit(TRIPS.perSeat, TAB.basics), '.ka-checklist-side'],
  ['charter-when', edit(TRIPS.charter, TAB.when)],
];

const TEST_SHOTS = [
  ['bands', edit(TRIPS.priced, TAB.prices)],
  ['price-table', edit(TRIPS.priced, TAB.prices), '#prices'],
  ['checklist-ready', edit(TRIPS.priced, TAB.basics), '.ka-checklist-side'],
];

const GUEST_SHOTS = [
  ['guest-trip', '/aegean-blue/olimeri-tria-nisia'],
];

async function signIn(context, email) {
  const page = await context.newPage();

  await page.goto(`${PANEL}/app/login`);
  await page.locator('#data\\.email').fill(email);
  await page.locator('#data\\.password').fill('password');
  await page.locator('#form button[type="submit"]').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30_000 });

  await page.close();
}

/**
 * Wait for the form to have stopped moving, not merely to have arrived.
 *
 * The trip form is Livewire all the way down — the tabs, the checklist chips,
 * the embedded relation managers — and a shot taken on `load` photographs the
 * skeletons, which on paper look like a broken product rather than a fast one.
 */
async function settle(page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(900);
}

async function shoot(context, name, url, base, scrollTo = null) {
  const page = await context.newPage();

  try {
    await page.goto(base + url, { waitUntil: 'domcontentloaded' });
    await settle(page);

    if (scrollTo) {
      await page.locator(scrollTo).first()
        .evaluate((el) => el.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(400);
    }

    await page.screenshot({ path: resolve(OUT, `${name}.jpg`), type: 'jpeg', quality: 78 });
    process.stdout.write(`  ${name}\n`);

    return { name, url };
  } catch (error) {
    // With the URL, because the two ways this fails look identical without it:
    // a selector that no longer matches, and a page that was never there.
    process.stdout.write(`  ${name} — FAILED at ${base}${url}: ${error.message.split('\n')[0]}\n`);

    return null;
  } finally {
    await page.close();
  }
}

const browser = await chromium.launch();
const taken = [];

mkdirSync(OUT, { recursive: true });

async function run(email, shots, base, label) {
  const context = await browser.newContext({
    viewport: DESKTOP,
    deviceScaleFactor: 1.5,
    locale: 'el-GR',
    timezoneId: 'Europe/Athens',
  });

  if (email !== null) {
    await signIn(context, email);
  }

  process.stdout.write(`${label}:\n`);

  for (const [name, url, scrollTo] of shots) {
    taken.push(await shoot(context, name, url, base, scrollTo ?? null));
  }

  await context.close();
}

await run(PEOPLE.demo, DEMO_SHOTS, PANEL, 'Aegean Blue, 1280×900');
await run(PEOPLE.test, TEST_SHOTS, PANEL, 'Δοκιμή Κρήτης — the priced trip');
await run(null, GUEST_SHOTS, GUEST, 'the guest side');

await browser.close();

writeFileSync(
  resolve(OUT, 'index.json'),
  `${JSON.stringify({ takenAt: new Date().toISOString(), shots: taken.filter(Boolean) }, null, 2)}\n`,
);

const failed = taken.filter((shot) => shot === null).length;

process.stdout.write(`\n${taken.length - failed} shots, ${failed} failed\n`);

if (failed > 0) {
  process.exitCode = 1;
}
