/**
 * Screenshots for the testing walkthrough — by walking it (#51 follow-up).
 *
 *   node docs/testing/capture.mjs      # needs both servers, the queue worker and a seeded database
 *   node docs/testing/build.mjs        # the PDF
 *
 * ## It performs the walkthrough rather than illustrating it
 *
 * Every figure in the walkthrough is taken from a browser doing the step the
 * text describes, in order, against a **new operator this script creates**.
 * That is the point: a runbook whose screenshots were staged separately is a
 * runbook nobody has followed, and the first person to follow it finds the step
 * that does not work. If a step here breaks, the capture fails and the manual
 * cannot be built — which is the only way a document like this stays true.
 *
 * The operator it creates is deliberately not the demo one. `DemoTenantSeeder`
 * produces a business that has been trading for a season; what a tester needs
 * to see is the empty account, because that is what they will be given.
 *
 * Two figures are the exception and say so in their captions: the crew's
 * «Ποιοι είναι σε αυτή την εκδρομή» and the statistics page are taken from the
 * demo operator, because one booking on a new account draws empty charts and
 * a new account has no crew.
 *
 * ## One operator per run, and a run that can be resumed (2026-09-23)
 *
 * The walk is now long — the platform, the setup guide, a trip made in five
 * steps, a checkout, a booking from the telephone — and a selector that breaks
 * near the end should not cost a second operator. So the run is in stages, and
 *
 *   KAIKI_STAMP=123456 KAIKI_FROM=trip node docs/testing/capture.mjs
 *
 * picks the same operator up again at the named stage (`KAIKI_ONLY=a,b` runs
 * just those). Without `KAIKI_STAMP` a new operator is created, once.
 *
 * Stages, in order: admin, owner, guide, catalogue, trip, guest, booking,
 * backoffice, impersonation.
 */

import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const OUT = resolve(HERE, 'img');
mkdirSync(OUT, { recursive: true });

const PANEL = process.env.KAIKI_PANEL_URL ?? 'http://127.0.0.1:8000';
const GUEST = process.env.KAIKI_GUEST_URL ?? 'http://192.168.1.43:8001';
const MAILPIT = process.env.KAIKI_MAILPIT_URL ?? 'http://127.0.0.1:8025';

// `php84` by its path, because `php` on a developer machine may well be 8.3,
// which dies on Composer's platform check before it reaches the query.
const PHP = process.env.KAIKI_PHP ?? 'C:\\Users\\Mike\\php84\\php.exe';

/** Unique per run, so the script can be run twice without a slug collision. */
const STAMP = process.env.KAIKI_STAMP ?? Date.now().toString().slice(-6);
export const OPERATOR = {
  name: `Δοκιμαστικές Κρουαζιέρες ${STAMP}`,
  slug: `dokimi-${STAMP}`,
  email: `billing+${STAMP}@kaiki.example`,
  ownerName: 'Δοκιμαστής Ιδιοκτήτης',
  ownerEmail: `owner+${STAMP}@kaiki.example`,
};

/** The password the walkthrough tells the reader to set, so the two agree. */
export const OWNER_PASSWORD = 'kaiki-testing-2026';

const STAGES = ['admin', 'owner', 'guide', 'catalogue', 'trip', 'guest', 'booking', 'backoffice', 'impersonation'];
const FROM = process.env.KAIKI_FROM ?? 'admin';
const ONLY = process.env.KAIKI_ONLY ?? null;
const runs = (stage) => (ONLY ? ONLY.split(',').includes(stage) : STAGES.indexOf(stage) >= STAGES.indexOf(FROM));

const shots = [];

async function shot(page, name, locator, options = {}) {
  const target = locator ? page.locator(locator).first() : page;
  await page.waitForTimeout(900);
  await target.screenshot({ path: resolve(OUT, `${name}.jpg`), quality: 82, type: 'jpeg', ...options });
  shots.push(name);
  console.log('  ✓', name);
}

/** A PHP expression, evaluated in the app, printed back as its last line. */
function php(expression) {
  const printed = execSync(`"${PHP}" artisan tinker --execute="echo ${expression};"`, { cwd: ROOT, encoding: 'utf8' });
  return printed.trim().split(/\r?\n/).pop().trim();
}

/*
 * The Livewire component that owns the form on screen.
 *
 * Selects, pickers and repeaters are set through Livewire rather than clicked:
 * a searchable select and a date picker are several elements deep, and what
 * this script is proving is that the *form* accepts the answer — the widgets
 * themselves are Filament's.
 */
async function wireSet(page, path, value, anchor = '[id^="data."]') {
  await page.evaluate(([p, v, a]) => {
    const root = document.querySelector(a).closest('[wire\\:id]');
    return window.Livewire.find(root.getAttribute('wire:id')).set(p, v);
  }, [path, value, anchor]);
  await page.waitForTimeout(900);
}

async function wireGet(page, path, anchor = '[id^="data."]') {
  return page.evaluate(([p, a]) => {
    const root = document.querySelector(a).closest('[wire\\:id]');
    return JSON.parse(JSON.stringify(window.Livewire.find(root.getAttribute('wire:id')).get(p) ?? null));
  }, [path, anchor]);
}

async function signIn(page, email, password, path) {
  await page.goto(`${PANEL}${path}`, { waitUntil: 'networkidle' });
  await page.locator('[id="data.email"]').fill(email);
  await page.locator('[id="data.password"]').fill(password);
  await page.locator('form button[type=submit]').first().click();
  await page.waitForTimeout(3000);
}

/** Switch a Filament toggle on, if it is not already. */
async function switchOn(page, id) {
  const toggle = page.locator(`[id="${id}"]`).first();
  if ((await toggle.getAttribute('aria-checked')) !== 'true') {
    await toggle.click();
    await page.waitForTimeout(900);
  }
}

async function scrollTo(page, selector) {
  await page.locator(selector).first().evaluate((el) => el.scrollIntoView({ block: 'start' }));
  await page.waitForTimeout(600);
}

const browser = await chromium.launch();
const desktop = { viewport: { width: 1380, height: 940 }, deviceScaleFactor: 2 };
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };

console.log('Operator for this run:', OPERATOR.slug);

const tenantUuid = () => php(`App\\Models\\Tenant::where('slug','${OPERATOR.slug}')->value('uuid')`);
const tenantId = () => php(`App\\Models\\Tenant::where('slug','${OPERATOR.slug}')->value('id')`);

// =====================================================================
// admin — the platform takes a new operator on
// =====================================================================
if (runs('admin')) {
  const ctx = await browser.newContext(desktop);
  const page = await ctx.newPage();

  // `?lang=el` throughout the admin half: the demo platform admin's own
  // language is English, and this is a Greek document.
  await page.goto(`${PANEL}/admin/login?lang=el`, { waitUntil: 'networkidle' });
  await shot(page, '01-admin-login');

  await page.locator('[id="data.email"]').fill('admin@kaiki.example');
  await page.locator('[id="data.password"]').fill('password');
  await page.locator('form button[type=submit]').first().click();
  await page.waitForTimeout(2500);
  await page.goto(`${PANEL}/admin/tenants?lang=el`, { waitUntil: 'networkidle' });
  await shot(page, '02-admin-tenants');

  // --- a new operator, with everything the platform decides (2026-09-21) ---
  await page.goto(`${PANEL}/admin/tenants/create?lang=el`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);

  await page.locator('[id="data.name"]').fill(OPERATOR.name);
  await page.locator('[id="data.slug"]').fill(OPERATOR.slug);
  await page.locator('[id="data.email"]').fill(OPERATOR.email);
  await page.locator('[id="data.owner_name"]').fill(OPERATOR.ownerName);
  await page.locator('[id="data.owner_email"]').fill(OPERATOR.ownerEmail);
  await shot(page, '03-new-operator-form');

  // The switches the walkthrough asks the reader to check on this form. On
  // the create form «Έλεγχος επιβίβασης» and «Οδηγός πρώτης ρύθμισης» start
  // **off** — the walkthrough says so and asks for them on.
  await switchOn(page, 'data.check_in_enabled');
  await switchOn(page, 'data.qr_check_in_enabled');
  await switchOn(page, 'data.setup_guide_enabled');
  await page.locator('[id="data.check_in_enabled"]').first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
  // The sticky top bar would otherwise sit across the section's first switch.
  await shot(page, '03b-new-operator-features', '.fi-section:has([id="data.check_in_enabled"])', { style: '.fi-topbar { display: none !important; }' });

  // The visible primary action, not the first `button[type=submit]` in the
  // document — Filament renders hidden submit buttons inside dropdown menus.
  await page.locator('.fi-form-actions button[type=submit]').first().click();
  await page.waitForTimeout(3500);
  await shot(page, '04-operator-created');

  // --- the merchant screen, in tabs (2026-09-21) -----------------------
  // By uuid, not id: `TenantResource` resolves by route key (§1.1).
  const uuid = tenantUuid();
  await page.goto(`${PANEL}/admin/tenants/${uuid}/edit?lang=el`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await page.locator('.fi-tabs-item', { hasText: 'Λειτουργίες' }).first().click();
  await page.waitForTimeout(1200);
  await shot(page, '04b-operator-features');

  // Deleting a merchant: the dialog is photographed and cancelled. Nothing is
  // deleted by this script.
  await page.locator('button', { hasText: 'Διαγραφή διοργανωτή' }).first().click();
  await page.waitForTimeout(1500);
  await shot(page, '04g-delete-merchant', '.fi-modal-window >> visible=true');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(800);

  // --- the platform's own screens --------------------------------------
  await page.goto(`${PANEL}/admin/policy-templates?lang=el`, { waitUntil: 'networkidle' });
  await shot(page, '04h-policy-templates');

  const vatKey = php(`App\\Models\\VatRate::query()->orderBy('id')->first()?->getRouteKey()`);
  await page.goto(`${PANEL}/admin/vat-rates/${vatKey}/edit?lang=el`, { waitUntil: 'networkidle' });
  await shot(page, '04i-vat-rate');

  await page.goto(`${PANEL}/admin/health?lang=el`, { waitUntil: 'networkidle' });
  await shot(page, '04c-admin-health');

  await page.goto(`${PANEL}/admin/announcements?lang=el`, { waitUntil: 'networkidle' });
  await shot(page, '04d-admin-announcements');

  await ctx.close();
}

// The create form alone, photographed and never saved — for re-shooting the
// two figures above without taking a second operator on. Only by name:
// KAIKI_ONLY=form.
if (ONLY && ONLY.split(',').includes('form')) {
  const ctx = await browser.newContext(desktop);
  const page = await ctx.newPage();
  await signIn(page, 'admin@kaiki.example', 'password', '/admin/login?lang=el');
  await page.goto(`${PANEL}/admin/tenants/create?lang=el`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await switchOn(page, 'data.check_in_enabled');
  await switchOn(page, 'data.qr_check_in_enabled');
  await switchOn(page, 'data.setup_guide_enabled');
  await page.locator('[id="data.check_in_enabled"]').first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
  await shot(page, '03b-new-operator-features', '.fi-section:has([id="data.check_in_enabled"])', { style: '.fi-topbar { display: none !important; }' });
  await ctx.close();
}

// =====================================================================
// owner — the invitation, and a password
// =====================================================================
if (runs('owner')) {
  const ctx = await browser.newContext(desktop);
  const page = await ctx.newPage();

  // Minted the way `kaiki:invitation-link` mints it, because that is the
  // command the walkthrough tells a reader to run.
  const printed = execSync(`"${PHP}" artisan kaiki:invitation-link ${OPERATOR.ownerEmail}`, { cwd: ROOT, encoding: 'utf8' });
  const link = (printed.match(/https?:\/\/\S*password-reset\S*/) ?? [])[0];

  if (!link) {
    throw new Error('No invitation link came back from kaiki:invitation-link.');
  }

  await page.goto(link, { waitUntil: 'networkidle' });
  await shot(page, '05-set-password');

  const pw = page.locator('input[type=password]');
  await pw.nth(0).fill(OWNER_PASSWORD);
  await pw.nth(1).fill(OWNER_PASSWORD);
  await page.locator('.fi-form-actions button[type=submit]').first().click();
  await page.waitForTimeout(3500);
  await shot(page, '06-after-password');

  await ctx.close();
}

// Every stage from here on is the owner, in a context of their own — the
// platform administrator has no tenant and `/app` answers 403 for them.
let owner = null;

async function ownerPage() {
  if (owner) return owner;
  const ctx = await browser.newContext(desktop);
  owner = await ctx.newPage();
  await signIn(owner, OPERATOR.ownerEmail, OWNER_PASSWORD, '/app/login');
  return owner;
}

async function next(page) {
  await page.locator('button:visible', { hasText: 'Συνέχεια' }).first().click();
  await page.waitForTimeout(2200);
}

// =====================================================================
// guide — four questions about the account, and the panel held until then
// =====================================================================
if (runs('guide')) {
  const page = await ownerPage();

  // Signing in lands on the guide, not the dashboard: it holds the panel.
  await page.goto(`${PANEL}/app`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  if (!page.url().includes('/app/setup')) {
    throw new Error(`Expected the setup guide to hold the panel, landed on ${page.url()}`);
  }
  await shot(page, '07-setup-guide');

  // 1. Η επιχείρησή σας
  await page.locator('[id="data.legal_name"]').fill(`${OPERATOR.name} ΙΚΕ`);
  await page.locator('[id="data.vat_number"]').fill('099999999');
  await page.locator('[id="data.tax_office"]').fill('Πειραιά');
  await next(page);

  // 2. Styling — nothing chosen, on purpose: the pages work without it.
  await shot(page, '08-setup-styling');
  await next(page);

  // 3. ΦΠΑ — the one rate the demo platform carries.
  const vatId = php(`App\\Models\\VatRate::query()->orderBy('id')->value('id')`);
  await wireSet(page, 'data.default_vat_rate_id', vatId);
  await next(page);

  // 4. Πολιτική ακύρωσης — the templates the platform keeps in /admin.
  await shot(page, '08b-setup-cancellation');
  await page.locator('.ka-setup-presets button', { hasText: 'Κανονική' }).first().click();
  await page.waitForTimeout(2200);
  if (await page.locator('.ka-setup-presets').count()) {
    await next(page);
  }

  // 5. Η αρχική σας — only for a full website, which is the default.
  await shot(page, '08c-setup-home');
  await page.locator('button:visible', { hasText: /^\s*Αργότερα\s*$/ }).first().click();
  await page.waitForTimeout(2200);

  // Έτοιμοι
  await shot(page, '08d-setup-ready');
  await page.locator('button:visible', { hasText: 'Τέλος' }).first().click();
  await page.waitForTimeout(3000);

  await page.goto(`${PANEL}/app`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);
  await shot(page, '09-dashboard-first-steps');
}

// =====================================================================
// catalogue — a port, a boat, a period
// =====================================================================
if (runs('catalogue')) {
  const page = await ownerPage();

  await page.goto(`${PANEL}/app/ports/create`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await wireSet(page, 'data.name.el', 'Μαρίνα Ζέας');
  await wireSet(page, 'data.name.en', 'Zea Marina');
  await page.locator('[id="data.address"]').fill('Ακτή Θεμιστοκλέους, Πειραιάς 185 36');
  await page.locator('[id="data.lat"]').fill('37.9366');
  await page.locator('[id="data.lng"]').fill('23.6446');
  await shot(page, '11-port-form');
  await page.locator('.fi-form-actions button[type=submit]').first().click();
  await page.waitForTimeout(2500);

  const tid = tenantId();
  const portId = php(`App\\Models\\Port::withoutGlobalScopes()->where('tenant_id',${tid})->value('id')`);
  if (!portId) throw new Error('The port was not saved.');

  await page.goto(`${PANEL}/app/vessels/create`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await page.locator('[id="data.name"]').fill('Αλκυόνη');
  await wireSet(page, 'data.type', 'motor');
  await page.locator('[id="data.capacity_max"]').fill('24');
  await wireSet(page, 'data.home_port_id', portId);
  await shot(page, '10-vessel-form');
  await page.locator('.fi-form-actions button[type=submit]').first().click();
  await page.waitForTimeout(2500);
  if (!php(`App\\Models\\Vessel::withoutGlobalScopes()->where('tenant_id',${tid})->value('id')`)) {
    throw new Error('The boat was not saved.');
  }

  await page.goto(`${PANEL}/app/vessels`, { waitUntil: 'networkidle' });
  await shot(page, '09b-vessels-list');

  // A period: «Θερινή», 1 June to 15 September next year, so it is in the
  // future whatever day this runs.
  await page.goto(`${PANEL}/app/seasons/create`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await wireSet(page, 'data.name.el', 'Θερινή');
  await wireSet(page, 'data.name.en', 'Summer');
  const year = new Date().getFullYear() + 1;
  const ranges = await wireGet(page, 'data.dateRanges');
  const rangeKey = Object.keys(ranges ?? {})[0] ?? 'r1';
  await wireSet(page, `data.dateRanges.${rangeKey}`, { starts_on: `${year}-06-01`, ends_on: `${year}-09-15` });
  await shot(page, '11b-season-form');
  await page.locator('.fi-form-actions button[type=submit]').first().click();
  await page.waitForTimeout(2500);
  if (!php(`App\\Models\\Season::withoutGlobalScopes()->where('tenant_id',${tid})->value('id')`)) {
    throw new Error('The period was not saved.');
  }

  await page.goto(`${PANEL}/app/cancellation-policies`, { waitUntil: 'networkidle' });
  await shot(page, '11c-cancellation-policies');
}

// =====================================================================
// trip — five steps to a trip that sells, then five tabs
// =====================================================================
if (runs('trip')) {
  const page = await ownerPage();
  const tid = tenantId();
  const vesselId = php(`App\\Models\\Vessel::withoutGlobalScopes()->where('tenant_id',${tid})->value('id')`);
  const portId = php(`App\\Models\\Port::withoutGlobalScopes()->where('tenant_id',${tid})->value('id')`);
  const seasonId = php(`App\\Models\\Season::withoutGlobalScopes()->where('tenant_id',${tid})->value('id')`);
  const policyId = php(`App\\Models\\CancellationPolicy::withoutGlobalScopes()->where('tenant_id',${tid})->value('id')`);

  await page.goto(`${PANEL}/app/products/create`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  const step = async () => {
    await page.locator('button:visible', { hasText: 'Επόμενο' }).last().click();
    await page.waitForTimeout(2500);
    const errors = await page.locator('.fi-fo-field-wrp-error-message:visible').allInnerTexts();
    if (errors.length) throw new Error(`The trip guide refused a step: ${errors.join(' | ')}`);
  };

  // 1. Τα βασικά
  // Through Livewire, one answer at a time: the Greek title is a live field
  // (it makes the slug), and a value typed while its request is in flight is
  // overwritten when the answer comes back.
  await wireSet(page, 'data.title.el', 'Ηλιοβασίλεμα στον Σαρωνικό');
  await wireSet(page, 'data.title.en', 'Saronic sunset cruise');
  await wireSet(page, 'data.vessel_id', Number(vesselId));
  await wireSet(page, 'data.category', 'sunset');
  await shot(page, '12-trip-basics');
  await step();

  // 2. Πότε φεύγει — every day at 18:30, from today, with no end.
  await wireSet(page, 'data.duration_minutes', 180);
  await wireSet(page, 'data.check_in_offset_minutes', 30);
  await wireSet(page, 'data.meeting_point_id', Number(portId));
  await wireSet(page, 'data.max_pax', 20);
  const schedules = await wireGet(page, 'data.wizard_schedules');
  const scheduleKey = Object.keys(schedules)[0];
  const times = schedules[scheduleKey].times ?? {};
  const timeKey = Object.keys(times)[0] ?? 't1';
  await wireSet(page, `data.wizard_schedules.${scheduleKey}.days`, ['1', '2', '3', '4', '5', '6', '7']);
  // The time by hand, through the picker: it holds its own copy of the value
  // and would show an empty box over a time set from outside.
  const picker = page.locator('.fi-fo-date-time-picker:has(input[id*=".times."])').first();
  await picker.locator('button').first().click();
  await page.waitForTimeout(700);
  const clock = picker.locator('input[type=number]:visible');
  await clock.nth(0).fill('18');
  await clock.nth(1).fill('30');
  await page.waitForTimeout(500);
  await page.locator('text=Ποιες μέρες φεύγει').first().click();
  await page.waitForTimeout(1200);
  void timeKey;
  await page.evaluate(() => window.scrollTo(0, 0));
  await shot(page, '12b-trip-when');
  await step();

  // 3. Τιμές — euro amounts per age band, and a summer price for the adult.
  const bands = await wireGet(page, 'data.age_bands');
  const bandKeys = Object.keys(bands);
  const prices = ['45', '25', '0'];
  for (const [i, key] of bandKeys.entries()) {
    await wireSet(page, `data.age_bands.${key}.wizard_price`, prices[i] ?? '0');
  }
  await wireSet(page, `data.age_bands.${bandKeys[0]}.wizard_season_prices`, { s1: { season_id: seasonId, price: '55' } });
  await page.evaluate(() => window.scrollTo(0, 0));
  await shot(page, '12c-trip-prices');
  await step();

  // 4. Σελίδα — three photographs in one pick; the first leads the card.
  const photos = [1, 2, 3].map((n) => resolve(ROOT, `storage/app/public/products/1/iliovasilema-aigina-${n}.jpg`));
  await page.locator('input[type=file]').first().setInputFiles(photos);
  await page.waitForTimeout(7000);
  await scrollTo(page, '.fi-fo-file-upload');
  await page.evaluate(() => window.scrollBy(0, -160));
  await shot(page, '12d-trip-page');
  await step();

  // 5. Δημοσίευση — the policy the setup guide made, and «Δημοσίευση τώρα».
  await wireSet(page, 'data.cancellation_policy_id', policyId);
  await shot(page, '12e-trip-publish');

  if (process.env.KAIKI_NO_SUBMIT === '1') {
    console.log('KAIKI_NO_SUBMIT: stopping before the trip is created.');
    await browser.close();
    process.exit(0);
  }

  await page.locator('button[type=submit]:visible').last().click();
  await page.waitForTimeout(6000);
  console.log('  after create:', page.url());
  if (!page.url().includes('/edit')) {
    throw new Error('The trip was not created.');
  }

  // The edit page, in five tabs.
  await shot(page, '13-trip-edit');
  await page.locator('.fi-tabs-item', { hasText: 'Τιμές' }).first().click();
  await page.waitForTimeout(1800);
  await scrollTo(page, 'text=Τιμές σε ευρώ');
  await shot(page, '13b-trip-price-grid');
  await page.locator('.fi-tabs-item', { hasText: 'Σελίδα' }).first().click();
  await page.waitForTimeout(1800);
  await scrollTo(page, 'text=Φωτογραφίες');
  await shot(page, '13c-trip-gallery');

  await page.goto(`${PANEL}/app/products`, { waitUntil: 'networkidle' });
  await shot(page, '12z-products-list');
}

// The trip, as the rest of the run needs it.
const trip = () => {
  const tid = tenantId();
  const id = php(`App\\Models\\Product::withoutGlobalScopes()->where('tenant_id',${tid})->orderBy('id')->value('id')`);
  const slug = php(`App\\Models\\Product::withoutGlobalScopes()->where('tenant_id',${tid})->orderBy('id')->value('slug')`);
  return { id, slug };
};

/** Departures are made by a queued job: wait for the worker, a minute at most. */
async function waitForDepartures(productId) {
  for (let i = 0; i < 30; i++) {
    const n = Number(php(`App\\Models\\Departure::withoutGlobalScopes()->where('product_id',${productId})->count()`));
    if (n > 0) return n;
    await new Promise((r) => setTimeout(r, 2000));
  }
  throw new Error('No departures appeared — is the queue worker running?');
}

// =====================================================================
// guest — the trip page, the phone sheet, the adult rule, the checkout
// =====================================================================
if (runs('guest')) {
  const { id: productId, slug } = trip();
  await waitForDepartures(productId);

  const url = `${GUEST}/${OPERATOR.slug}/${slug}`;

  // «Χρειάζεται συνοδό ενήλικα» on the child band, from the trip's «Τιμές»
  // tab, the way the walkthrough tells the reader to.
  const panel = await ownerPage();
  const productUuid = php(`App\\Models\\Product::withoutGlobalScopes()->whereKey(${productId})->value('uuid')`);
  await panel.goto(`${PANEL}/app/products/${productUuid}/edit`, { waitUntil: 'networkidle' });
  await panel.waitForTimeout(1500);
  await panel.locator('.fi-tabs-item', { hasText: 'Τιμές' }).first().click();
  await panel.waitForTimeout(1500);
  const bandsNow = await wireGet(panel, 'data.age_bands');
  const childKey = Object.keys(bandsNow).find((k) => JSON.stringify(bandsNow[k].label ?? '').includes('Παιδί'));
  await wireSet(panel, `data.age_bands.${childKey}.requires_adult`, true);
  await panel.locator('.fi-form-actions button[type=submit]').first().click();
  await panel.waitForTimeout(3000);

  // Desktop: the trip page as it opens.
  const gctx = await browser.newContext(desktop);
  const guest = await gctx.newPage();
  await guest.goto(url, { waitUntil: 'networkidle' });
  await guest.waitForTimeout(2000);
  await shot(guest, '16-guest-trip');

  // Phone: the booking sheet, a half sheet one step at a time.
  const pctx = await browser.newContext(phone);
  const mobile = await pctx.newPage();
  await mobile.goto(url, { waitUntil: 'networkidle' });
  await mobile.waitForTimeout(2000);
  await mobile.locator('button:visible', { hasText: 'Διαλέξτε ημερομηνία' }).first().click();
  await mobile.waitForTimeout(1500);
  await shot(mobile, '17-phone-sheet-date');

  // Tomorrow or later: the second day button that can be pressed.
  const pickDay = async (p) => {
    const days = p.locator('button:visible:not([disabled])').filter({ hasText: /^\d{1,2}$/ });
    await days.nth(1).click();
    await p.waitForTimeout(1200);
  };
  await pickDay(mobile);
  await mobile.locator('button:visible', { hasText: /^Συνέχεια$/ }).first().click();
  await mobile.waitForTimeout(1500);
  await shot(mobile, '17b-phone-sheet-party');

  // A child on their own, on a trip whose «Παιδί» needs an adult — the
  // switch the walkthrough asks the reader to turn on first. (A baby alone
  // is refused by a different rule: a baby takes no seat.)
  const plus = mobile.locator('button:visible', { hasText: '+' });
  await plus.nth(1).click();
  await mobile.waitForTimeout(600);
  await mobile.locator('button:visible', { hasText: 'Συνέχεια στην κράτηση' }).first().click();
  await mobile.waitForTimeout(3000);
  await shot(mobile, '17c-needs-adult');

  // Desktop: two adults to the checkout.
  await pickDay(guest);
  await guest.locator('button:visible', { hasText: /^Συνέχεια$/ }).first().click();
  await guest.waitForTimeout(1500);
  const plusDesk = guest.locator('button:visible', { hasText: '+' });
  await plusDesk.nth(0).click();
  await plusDesk.nth(0).click();
  await guest.waitForTimeout(600);
  await guest.locator('button:visible', { hasText: 'Συνέχεια στην κράτηση' }).first().click();
  await guest.waitForURL(/\/c\//, { timeout: 30000 });
  await guest.waitForLoadState('networkidle');
  await guest.waitForTimeout(1500);
  await shot(guest, '18-checkout', null, { fullPage: true });

  // The same checkout on a phone: the total and the button in a bar.
  await mobile.goto(guest.url(), { waitUntil: 'networkidle' });
  await mobile.waitForTimeout(1500);
  await shot(mobile, '18b-checkout-phone');

  await gctx.close();
  await pctx.close();
}

// =====================================================================
// booking — from the telephone, paid in cash, and what the guest gets
// =====================================================================
if (runs('booking')) {
  const page = await ownerPage();
  const tid = tenantId();
  const { id: productId } = trip();
  // Tomorrow's departure or later, so the booking page still has a ticket.
  const departureId = php(`App\\Models\\Departure::withoutGlobalScopes()->where('product_id',${productId})->where('starts_at_utc','>',now()->addHours(12))->orderBy('starts_at_utc')->value('id')`);

  await page.goto(`${PANEL}/app/bookings/create`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  await wireSet(page, 'data.product_id', productId);
  await wireSet(page, 'data.departure_id', departureId);
  const pax = await wireGet(page, 'data.pax');
  const paxKey = Object.keys(pax)[0];
  const code = await page.locator(`select[id="data.pax.${paxKey}.code"] option`).evaluateAll((os) => os.map((o) => o.value).filter(Boolean)[0]);
  await wireSet(page, `data.pax.${paxKey}`, { code, qty: 2 });
  await page.locator('[id="data.guest_name"]').fill('Ελένη Δοκιμή');
  await page.locator('[id="data.guest_email"]').fill(`eleni+${STAMP}@example.com`);
  await page.locator('[id="data.guest_phone"]').fill('+30 690 000 0000');
  await wireSet(page, 'data.paid_by', 'cash');
  await shot(page, '19-phone-booking');
  await page.locator('.fi-form-actions button[type=submit]').first().click();
  await page.waitForTimeout(4000);

  const token = php(`App\\Models\\Booking::withoutGlobalScopes()->where('tenant_id',${tid})->where('status','confirmed')->latest('id')->value('manage_token')`);
  if (!token) throw new Error('The telephone booking was not confirmed.');

  const gctx = await browser.newContext(desktop);
  const guest = await gctx.newPage();
  await guest.goto(`${PANEL}/b/${token}`, { waitUntil: 'networkidle' });
  await guest.waitForTimeout(2000);
  await shot(guest, '20-booking-page', null, { fullPage: true });

  // The confirmation, in Mailpit: from the platform's address, under the
  // operator's name. Queued, so give the worker a moment.
  await guest.waitForTimeout(8000);
  await guest.goto(MAILPIT, { waitUntil: 'networkidle' });
  await guest.waitForTimeout(2000);
  await shot(guest, '21-mailpit');
  await guest.locator('text=Ελένη').first().click().catch(() => {});
  await guest.waitForTimeout(2500);
  await shot(guest, '21b-mailpit-message');
  await gctx.close();
}

// =====================================================================
// backoffice — the day by boat, the messages, the statistics
// =====================================================================
if (runs('backoffice')) {
  // The crew's summary, from the demo operator: Νίκος is its crew.
  const cctx = await browser.newContext(desktop);
  const crew = await cctx.newPage();
  await signIn(crew, 'nikos@aegean-blue.example', 'password', '/app/login');
  await crew.goto(`${PANEL}/app/calendar`, { waitUntil: 'networkidle' });
  await crew.waitForTimeout(1500);
  const bars = crew.locator(`xpath=//*[@*[name()="wire:click" and starts-with(., "mountAction('pax'")]]`);
  const labels = await bars.allInnerTexts();
  const busy = labels.findIndex((t) => /\b[1-9]\d*\s*\/\s*\d+/.test(t));
  await bars.nth(Math.max(0, busy)).click();
  await crew.waitForTimeout(2500);
  await crew.locator('summary', { hasText: 'Τι περιλαμβάνεται' }).first().click().catch(() => {});
  await crew.waitForTimeout(800);
  await shot(crew, '22-crew-summary');
  await cctx.close();

  const page = await ownerPage();
  await page.goto(`${PANEL}/app/notification-logs`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  // The list opens on «Μόνο όσα χρειάζονται εμένα», which on a new account is
  // nothing; the walkthrough asks the reader to take the filter off.
  await page.locator('.fi-ta-filter-indicators button').last().click().catch(() => {});
  await page.waitForTimeout(1800);
  await shot(page, '23-messages');

  await page.goto(`${PANEL}/app/settings`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  await shot(page, '14-settings-hub');

  await page.goto(`${PANEL}/app/imports`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  await shot(page, '15-imports-empty');

  // The statistics, from the demo operator: a season of bookings draws charts.
  const sctx = await browser.newContext(desktop);
  const stats = await sctx.newPage();
  await signIn(stats, 'maria@aegean-blue.example', 'password', '/app/login');
  await stats.goto(`${PANEL}/app/analytics`, { waitUntil: 'networkidle' });
  await stats.waitForTimeout(2000);
  await shot(stats, '24-statistics');
  await scrollTo(stats, 'text=Ποιες μέρες ταξιδεύουν');
  await shot(stats, '24b-statistics-charts');
  await sctx.close();
}

// =====================================================================
// impersonation — «Σύνδεση ως», sixty minutes, with a reason
// =====================================================================
if (runs('impersonation')) {
  const ctx = await browser.newContext(desktop);
  const page = await ctx.newPage();
  await signIn(page, 'admin@kaiki.example', 'password', '/admin/login?lang=el');
  await page.goto(`${PANEL}/admin/tenants?lang=el`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);

  const row = page.locator('tr', { hasText: OPERATOR.slug }).first();
  await row.locator('button, a', { hasText: 'Σύνδεση ως' }).first().click();
  await page.waitForTimeout(1800);

  const ownerId = php(`App\\Models\\User::where('email','${OPERATOR.ownerEmail}')->value('id')`);
  const modal = page.locator('.fi-modal-window >> visible=true').first();
  // A searchable select: its <select> is hidden behind Choices.js.
  await wireSet(page, 'mountedTableActionsData.0.user_id', Number(ownerId), '[id="mountedTableActionsData.0.user_id"]');
  await modal.locator('textarea').first().fill('Δοκιμή του οδηγού δοκιμών: έλεγχος της πρώτης εκδρομής.');
  await page.waitForTimeout(600);
  await shot(page, '04e-sign-in-as', '.fi-modal-window >> visible=true');

  await modal.locator('button', { hasText: /^\s*Σύνδεση\s*$/ }).first().click();
  await page.waitForTimeout(4000);
  console.log('  signed in as:', page.url());

  // On 2026-09-23 this landed on /app/login: the audit line is written and the
  // guard switches, but the panel's `AuthenticateSession` still holds the
  // administrator's password hash and signs the borrowed session out on the
  // next request. The walkthrough tells the reader what to expect and to
  // report it; the banner is photographed only once there is one to see.
  if (page.url().includes('/login')) {
    console.warn('  ! «Σύνδεση ως» ended on the sign-in page — no banner to photograph.');
  } else {
    await shot(page, '04f-impersonation-banner');
    await page.locator('.ka-impersonating-out button').first().click();
    await page.waitForTimeout(2500);
    console.log('  after leaving:', page.url());
  }

  await ctx.close();
}

await browser.close();

console.log('\nOwner password used:', OWNER_PASSWORD);
console.log('Captured:', shots.length);
console.log(JSON.stringify(OPERATOR, null, 2));
