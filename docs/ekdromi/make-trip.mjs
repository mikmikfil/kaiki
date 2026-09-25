/**
 * Create a whole εκδρομή the way an operator would, and say what broke.
 *
 * A throwaway that earns its keep: it walks the real panel as
 * `nikos@dokimi-kritis.example` — the test operator — creating the port and the
 * cancellation policy the trip needs, then the trip itself, its bands, its
 * price list, its prices and its schedule, and finally publishes it.
 *
 *   node docs/ekdromi/make-trip.mjs           # every stage
 *   node docs/ekdromi/make-trip.mjs prices    # from that stage on
 *
 * Stages are separate because a Filament form is a Livewire round trip and a
 * failure halfway is worth resuming rather than repeating: the trip is found by
 * its slug, so a second run continues instead of creating a second trip.
 */

import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const SHOTS = resolve(HERE, 'shots');
const PANEL = 'http://127.0.0.1:8000';

const SLUG = 'dokimi-sunset-escort';
const TITLE_EL = 'Δοκιμαστικό ηλιοβασίλεμα';
const TITLE_EN = 'Test sunset cruise';

const STAGES = ['port', 'policy', 'trip', 'bands', 'plan', 'prices', 'schedule', 'publish'];
const from = process.argv[2] ?? STAGES[0];
const start = Math.max(0, STAGES.indexOf(from));

mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'el-GR', timezoneId: 'Europe/Athens' });
const page = await ctx.newPage();

let failures = 0;

function say(line) {
  process.stdout.write(`${line}\n`);
}

async function settle(ms = 900) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(ms);
}

async function shot(name) {
  await page.screenshot({ path: resolve(SHOTS, `${name}.png`) });
}

/**
 * Move to a tab by clicking it, never by reloading with `?tab=`.
 *
 * The form is one Livewire component across all five tabs: a reload would
 * throw away everything typed into the ones already filled. A field on a tab
 * that is not showing is in the DOM but hidden, and a fill against it times
 * out — which is the whole reason this exists.
 */
/**
 * Pick from a select, whether or not Filament enhanced it.
 *
 * A searchable select is a Choices.js widget: the real `<select>` is hidden and
 * `selectOption` against it times out on "element is not visible". The dropdown
 * has to be opened and the item clicked, the way a person does it. The plain
 * ones are still plain, so both paths stay.
 */
async function choose(selector, label) {
  const select = page.locator(selector);
  const choices = select.locator('xpath=ancestor::div[contains(@class,"choices")][1]');

  if ((await choices.count()) === 0) {
    await select.selectOption({ label });

    return;
  }

  await choices.first().click();
  await page.waitForTimeout(400);

  const search = choices.first().locator('input.choices__input');

  if ((await search.count()) > 0) {
    await search.first().fill(label);
    await page.waitForTimeout(500);
  }

  await page.locator('.choices__list--dropdown .choices__item--selectable').filter({ hasText: label }).first().click();
  await page.waitForTimeout(400);
}

async function tab(name) {
  await page.getByRole('tab', { name }).first().click();
  await page.waitForTimeout(500);
}

/** Filament's own notification, which is the only honest "it saved". */
async function savedOk(what) {
  const alert = page.locator('.fi-no-notification, [role="status"]').first();

  try {
    await alert.waitFor({ state: 'visible', timeout: 8000 });
    say(`  ✓ ${what} — «${(await alert.innerText()).trim().split('\n')[0]}»`);

    return true;
  } catch {
    const errors = await page.locator('.fi-fo-field-wrp-error-message').allInnerTexts();

    failures += 1;
    say(`  ✗ ${what} — no confirmation. Field errors: ${errors.length > 0 ? errors.join(' | ') : 'none on screen'}`);
    await shot(`fail-${what.replace(/\W+/g, '-')}`);

    return false;
  }
}

await page.goto(`${PANEL}/app/login`);
await page.locator('#data\\.email').fill('nikos@dokimi-kritis.example');
await page.locator('#data\\.password').fill('password');
await page.locator('#form button[type="submit"]').click();
await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30_000 });
say('signed in as the test operator');

// ── port ────────────────────────────────────────────────────────────────────
if (start <= STAGES.indexOf('port')) {
  await page.goto(`${PANEL}/app/ports`, { waitUntil: 'domcontentloaded' });
  await settle();

  if ((await page.getByText('Παλιό Λιμάνι Χανίων').count()) > 0) {
    say('port — already there');
  } else {
    await page.goto(`${PANEL}/app/ports/create`, { waitUntil: 'domcontentloaded' });
    await settle();
    await page.locator('#data\\.name\\.el').fill('Παλιό Λιμάνι Χανίων');
    await page.locator('#data\\.name\\.en').fill('Chania Old Port');
    await page.locator('#data\\.address').fill('Ακτή Τομπάζη, Χανιά');
    await page.locator('form#form button[type="submit"]').first().click();
    await savedOk('port');
  }
}

// ── cancellation policy ─────────────────────────────────────────────────────
if (start <= STAGES.indexOf('policy')) {
  await page.goto(`${PANEL}/app/cancellation-policies`, { waitUntil: 'domcontentloaded' });
  await settle();

  if ((await page.getByText('Ευέλικτη 48 ωρών').count()) > 0) {
    say('policy — already there');
  } else {
    await page.goto(`${PANEL}/app/cancellation-policies/create`, { waitUntil: 'domcontentloaded' });
    await settle();
    await page.locator('#data\\.name\\.el').fill('Ευέλικτη 48 ωρών');
    await page.locator('#data\\.name\\.en').fill('Flexible 48 hours');
    await page.locator('#data\\.summary\\.el').fill('Δωρεάν ακύρωση έως 48 ώρες πριν την αναχώρηση.');
    await page.locator('#data\\.summary\\.en').fill('Free cancellation up to 48 hours before departure.');
    await page.locator('#data\\.free_cancellation_hours').fill('48');
    await page.locator('form#form button[type="submit"]').first().click();
    await savedOk('cancellation policy');
  }
}

// ── the trip ────────────────────────────────────────────────────────────────
if (start <= STAGES.indexOf('trip')) {
  await page.goto(`${PANEL}/app/products/create`, { waitUntil: 'domcontentloaded' });
  await settle(1500);

  await page.locator('#data\\.title\\.el').fill(TITLE_EL);
  await page.locator('#data\\.slug').fill(SLUG);
  await choose('#data\\.vessel_id', 'Αύρα');
  await choose('#data\\.category', 'Ηλιοβασίλεμα');
  // The field the whole form hangs off: everything below appears once it is set.
  await choose('#data\\.mode', 'Ανά θέση');
  await settle(1200);

  await shot('01-basics-filled');

  await tab('Πότε φεύγει');
  await page.locator('#data\\.duration_minutes').fill('180');
  await page.locator('#data\\.check_in_offset_minutes').fill('20');
  await choose('#data\\.meeting_point_id', 'Παλιό Λιμάνι Χανίων');
  await page.locator('#data\\.max_pax').fill('20');

  await tab('Όροι');
  await choose('#data\\.cancellation_policy_id', 'Ευέλικτη 48 ωρών');
  // The only rate on this platform is the placeholder the seed ships, and it is
  // not on the publishing checklist — so this is attempted and not insisted on.
  await choose('#data\\.vat_rate_id', 'ΠΡΟΣΩΡΙΝΟΣ').catch(() => say('  · VAT rate skipped — no selectable rate'));

  await tab('Σελίδα');
  await page.locator('#data\\.summary\\.el').fill('Τρεις ώρες στον κόλπο, με το φως να πέφτει.');
  await page.locator('#data\\.description\\.el').fill('Δοκιμαστική εκδρομή, φτιαγμένη για να ελεγχθεί η ροή από την αρχή ως τη δημοσίευση.');
  await shot('01b-page-filled');

  // The English half. One switch for the whole form rather than a pair of tabs
  // above every field — so the English fields simply are not in view until it
  // is thrown, and a fill against a hidden input times out.
  await page.getByRole('button', { name: 'English', exact: true }).first().click();
  await settle(900);
  await page.locator('#data\\.summary\\.en').fill('Three hours in the bay as the light goes.');
  await page.locator('#data\\.description\\.en').fill('A test trip, made to check the walk from an empty form to a published page.');

  await tab('Βασικά');
  await page.locator('#data\\.title\\.en').fill(TITLE_EN);
  await shot('01c-english');

  await page.getByRole('button', { name: 'Ελληνικά', exact: true }).first().click();
  await settle(900);

  await page.getByRole('button', { name: 'Συνέχεια στις τιμές' }).click();
  await savedOk('trip created');
  await settle(1500);
  say(`  → ${page.url().replace(PANEL, '')}`);
  await shot('02-after-create');
}

/** The trip's edit page, found by slug so a second run continues one trip. */
async function editUrl() {
  await page.goto(`${PANEL}/app/products`, { waitUntil: 'domcontentloaded' });
  await settle();
  await page.getByRole('link', { name: TITLE_EL }).first().click();
  await settle(1500);

  return page.url().split('?')[0];
}

let edit = null;

if (start <= STAGES.indexOf('publish')) {
  edit = await editUrl();
  say(`editing ${edit.replace(PANEL, '')}`);
}

// ── the escort toggle ───────────────────────────────────────────────────────
if (start <= STAGES.indexOf('bands')) {
  await page.goto(`${edit}?tab=-times-tab`, { waitUntil: 'domcontentloaded' });
  await settle(1500);

  // The child band by **its own row**, not by position in the page: the
  // repeater's uuid is in every one of that band's field ids, so finding the
  // name input that holds «Παιδί» gives the exact switch to throw. Counting
  // switches instead quietly hit the wrong band the first time this ran.
  const uuid = await page.evaluate(() => {
    const name = [...document.querySelectorAll('input[id^="data.age_bands."][id$=".label.el"]')].find(
      (el) => el.value.trim() === 'Παιδί',
    );

    return name?.id.split('.')[2] ?? null;
  });

  if (uuid === null) {
    failures += 1;
    say('  ✗ no «Παιδί» band on this trip');
  } else {
    const toggle = page.locator(`#data\\.age_bands\\.${uuid}\\.requires_adult`);

    if ((await toggle.getAttribute('aria-checked')) === 'true') {
      say('escort toggle — already on');
    } else {
      await toggle.click();
      await page.waitForTimeout(400);
      await page.getByRole('button', { name: 'Αποθήκευση ως πρόχειρη' }).first().click();
      await settle(2000);

      // Read it back from a fresh page rather than trusting a toast: the
      // notification is the panel saying it tried, and this is the trip saying
      // it changed.
      await page.goto(`${edit}?tab=-times-tab`, { waitUntil: 'domcontentloaded' });
      await settle(1500);

      const on = await page.evaluate(() => {
        const name = [...document.querySelectorAll('input[id^="data.age_bands."][id$=".label.el"]')].find(
          (el) => el.value.trim() === 'Παιδί',
        );
        const id = name?.id.split('.')[2];

        return document.getElementById(`data.age_bands.${id}.requires_adult`)?.getAttribute('aria-checked') === 'true';
      });

      if (on) {
        say('  ✓ «Χρειάζεται συνοδό ενήλικα» is on for «Παιδί», and stayed on after a reload');
      } else {
        failures += 1;
        say('  ✗ the escort toggle did not persist');
        await shot('fail-escort-toggle');
      }
    }
  }

  await shot('03-bands');
}

// ── the price list, and its prices ──────────────────────────────────────────
if (start <= STAGES.indexOf('plan')) {
  await page.goto(`${edit}?tab=-times-tab`, { waitUntil: 'domcontentloaded' });
  await settle(1500);

  if ((await page.getByText('Καμία τιμή ακόμη').count()) === 0) {
    say('rate plan — already there');
  } else {
    await page.getByRole('button', { name: 'Νέος τιμοκατάλογος' }).first().click();
    await page.waitForTimeout(2000);

    // Περίοδος left empty on purpose: that is «όλες τις άλλες μέρες», the
    // price that applies when no season matches — the one every trip needs.
    for (const [band, price] of [['Ενήλικας', '40'], ['Παιδί', '20'], ['Βρέφος', '0']]) {
      const row = page.locator('.fi-modal .fi-fo-repeater-item, .fi-modal [class*="repeater-item"]').filter({ hasText: band }).first();

      await row.locator('input[id$="price_cents"]').first().fill(price);
    }

    await shot('04-plan-modal');
    await page.locator('.fi-modal').getByRole('button', { name: 'Αποθήκευση', exact: true }).first().click();
    await savedOk('rate plan with its prices');
    await settle(1500);
    await shot('05-prices');
  }
}

// ── when it sails ───────────────────────────────────────────────────────────
if (start <= STAGES.indexOf('schedule')) {
  await page.goto(`${edit}?tab=-pote-pheugei-tab`, { waitUntil: 'domcontentloaded' });
  await settle(1800);

  if ((await page.getByRole('button', { name: 'Νέο δρομολόγιο' }).count()) === 0) {
    failures += 1;
    say('  ✗ no «Νέο δρομολόγιο» button on the «Πότε φεύγει» tab');
    // The tab's own badge, not a row count: the «Τιμοκατάλογοι» table is on
    // the same page and counting `.fi-ta-row` anywhere found its rows and
    // concluded the trip had a schedule it did not have.
  } else if (!/κανένα/.test(await page.getByRole('tab', { name: /Πότε φεύγει/ }).first().innerText())) {
    say('schedule — already there');
  } else {
    await page.getByRole('button', { name: 'Νέο δρομολόγιο' }).first().click();
    await page.waitForTimeout(2500);

    // The open dialog, not `.fi-modal` — Filament leaves closed modal shells in
    // the page and `.first()` found one of those.
    const modal = page.getByRole('dialog').filter({ hasText: 'Νέο δρομολόγιο' }).first();

    for (const day of ['Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο', 'Κυριακή']) {
      // The day's own `<label>`, matched on substring: the element's text
      // carries the checkbox's whitespace, so an anchored pattern matches none
      // of them.
      await page.locator('label.fi-fo-checkbox-list-option-label').filter({ hasText: day }).first().click();
    }

    // One departure a day, at seven in the evening — it is a sunset trip. The
    // time lives in a repeater, so its id carries the row's uuid.
    await page.locator('input[id^="mountedTableActionsData.0.start_times."][id$=".time"]').first().fill('19:00');
    await page.locator('[id="mountedTableActionsData.0.valid_from"]').fill('2026-09-23');
    await page.waitForTimeout(600);
    await shot('06-schedule-modal');

    await modal.getByRole('button', { name: 'Αποθήκευση', exact: true }).first().click();
    await page.waitForTimeout(3000);
    await settle(1500);

    const badge = await page.getByRole('tab', { name: /Πότε φεύγει/ }).first().innerText();

    if (!/κανένα/.test(badge)) {
      say(`  ✓ schedule saved — the tab now reads «${badge.replace(/\s+/g, ' ').trim()}»`);
    } else {
      failures += 1;
      say('  ✗ the schedule rule did not save');
      await shot('fail-schedule');
    }
  }
}

// ── publish ─────────────────────────────────────────────────────────────────
if (start <= STAGES.indexOf('publish')) {
  await page.goto(`${edit}?tab=-basika-tab`, { waitUntil: 'domcontentloaded' });
  await settle(1500);

  const publish = page.getByRole('button', { name: 'Δημοσίευση' }).first();

  if ((await publish.count()) === 0) {
    say('publish — already on sale');
  } else {
    await publish.click();
    await page.waitForTimeout(3000);
    await settle(1500);
    await shot('07-published');
    say('  · publish clicked');
  }
}

await browser.close();

say(failures === 0 ? '\nno failures' : `\n${failures} failure(s)`);
process.exitCode = failures === 0 ? 0 : 1;
