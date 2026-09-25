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
 * ## One operator, two kinds of trip
 *
 * Until 25/9 the filled price grid came from a second test operator
 * (`dokimi-kritis`), which is no longer in the local database. The demo
 * operator's trips on sale are priced, so the grid now comes from one of them,
 * and the draft is used for everything a draft shows: the step buttons under
 * each tab and «Πριν τη δημοσίευση». Nothing here writes to the database.
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
};

/** The trips the guide photographs, by their route key (a uuid, §1.1). */
const TRIPS = {
  // Aegean Blue, sold per seat and still a draft: the one the walkthrough
  // edits. A draft on purpose — only a draft shows «← / Επόμενο →» under each
  // tab and «Πριν τη δημοσίευση» beside the form.
  perSeat: 'c3dab4a6-9a49-4dc6-9c3a-c953465946df',
  // Aegean Blue, whole-boat charter: a different «Πότε φεύγει».
  charter: 'a99f7fd2-9e61-401f-93d6-7cc491c8495b',
  // Aegean Blue, per seat and on sale, so its «Τιμές» tab is filled in: the
  // groups, the ticked periods and the table. (The priced test operator,
  // «Δοκιμή Κρήτης», is no longer in the local database — 25/9.)
  priced: '710f71e2-279e-4b58-a2f6-f76d8c3baddb',
};

const edit = (trip, tab) => `/app/products/${trip}/edit?tab=${tab}`;

/**
 * Open «Νέο δρομολόγιο» and then «+ Ώρα», so the figure is the hour grid
 * (25/9). Nothing is saved: the modal is photographed and the page closed.
 */
async function openTimePicker(page) {
  await page.locator('button:has-text("Νέο δρομολόγιο")').first().click();
  await page.waitForTimeout(1500);
  const modal = page.locator('.fi-modal-window:visible').first();
  await modal.locator('.ka-time-add').first().click();
  await page.waitForTimeout(300);
  // An hour picked, so the minutes are live too.
  await modal.locator('.ka-pick-grid button', { hasText: /^10$/ }).first().click().catch(() => {});
  await page.waitForTimeout(300);
  await modal.locator('.ka-pick-label').first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
}

/** The first row's «⋯», opened: on a deleted trip it holds «Οριστική διαγραφή». */
async function openRowMenu(page) {
  await page.evaluate(() => document.querySelectorAll('.fi-ta-content').forEach((el) => { el.scrollLeft = el.scrollWidth; }));
  await page.locator('tbody tr').first().locator('button.fi-icon-btn').last().click();
  await page.waitForTimeout(600);
}

/**
 * `[name, url, scrollTo?]` — the third element is a selector to bring into view
 * before the shot, for the sections that sit below the fold at 900px, or a
 * function given the page.
 *
 * «Νέα εκδρομή» is one tab since 25/9 («Βασικά», then «Συνέχεια»), so it is
 * photographed once; everything after it is the trip's own edit page.
 */
const DEMO_SHOTS = [
  ['products-list', '/app/products'],
  ['create-basics', '/app/products/create'],
  ['edit-when', edit(TRIPS.perSeat, TAB.when)],
  ['edit-vessel', edit(TRIPS.perSeat, TAB.when), 'text=Συνήθες σκάφος'],
  ['edit-schedules', edit(TRIPS.perSeat, TAB.when), 'button:has-text("Νέο δρομολόγιο")'],
  ['time-picker', edit(TRIPS.perSeat, TAB.when), openTimePicker],
  ['step-nav', edit(TRIPS.perSeat, TAB.when), '.ka-step-nav'],
  ['step-nav-last', edit(TRIPS.perSeat, TAB.page), '.ka-step-last'],
  ['edit-terms', edit(TRIPS.perSeat, TAB.terms)],
  ['edit-page', edit(TRIPS.perSeat, TAB.page)],
  ['edit-checklist', edit(TRIPS.perSeat, TAB.basics), '.ka-checklist-side'],
  ['charter-when', edit(TRIPS.charter, TAB.when)],
  ['bands', edit(TRIPS.priced, TAB.prices)],
  ['periods', edit(TRIPS.priced, TAB.prices), '.kpp'],
  ['price-table', edit(TRIPS.priced, TAB.prices), '#prices'],
  ['edit-extras', edit(TRIPS.priced, TAB.prices), 'button:has-text("Νέο πρόσθετο")'],
  // «Διαγραμμένες» only, where «Οριστική διαγραφή» lives.
  // «λείπει 2» on «Τιμές»: the deleted draft is the demo's one trip with
  // requirements unmet. Read only, like every other shot.
  ['tab-badges', '/app/products/815a12c7-905b-4c26-ae95-731b50f6bd5c/edit?tab=-times-tab'],
  ['products-trashed', '/app/products?tableFilters[trashed][value]=0', openRowMenu],
];

const GUEST_SHOTS = [
  ['guest-trip', '/aegean-blue/iliovasilema-aigina'],
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

    if (typeof scrollTo === 'function') {
      await scrollTo(page);
      await page.waitForTimeout(400);
    } else if (scrollTo) {
      // The visible one: every tab is in the page, and the hidden tabs carry
      // the same step buttons and sections.
      await page.locator(`${scrollTo} >> visible=true`).first()
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
