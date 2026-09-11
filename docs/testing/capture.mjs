/**
 * Screenshots for the testing walkthrough — by walking it (#51 follow-up).
 *
 *   node docs/testing/capture.mjs      # needs both servers and a seeded database
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
 */

import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, 'img');
mkdirSync(OUT, { recursive: true });

const PANEL = process.env.KAIKI_PANEL_URL ?? 'http://127.0.0.1:8000';
const GUEST = process.env.KAIKI_GUEST_URL ?? 'http://127.0.0.1:8001';

/** Unique per run, so the script can be run twice without a slug collision. */
const STAMP = Date.now().toString().slice(-6);
export const OPERATOR = {
  name: `Δοκιμαστικές Κρουαζιέρες ${STAMP}`,
  slug: `dokimi-${STAMP}`,
  email: `billing+${STAMP}@kaiki.example`,
  ownerName: 'Δοκιμαστής Ιδιοκτήτης',
  ownerEmail: `owner+${STAMP}@kaiki.example`,
};

/** The password the walkthrough tells the reader to set, so the two agree. */
export const OWNER_PASSWORD = 'kaiki-testing-2026';

const shots = [];

async function shot(page, name, locator) {
  const target = locator ? page.locator(locator).first() : page;
  await page.waitForTimeout(900);
  await target.screenshot({ path: resolve(OUT, `${name}.jpg`), quality: 82, type: 'jpeg' });
  shots.push(name);
  console.log('  ✓', name);
}

async function signIn(page, email, password) {
  await page.goto(`${PANEL}/admin/login`.replace('/admin/login', email.includes('admin') ? '/admin/login' : '/app/login'), {
    waitUntil: 'networkidle',
  });
  await page.locator('[id="data.email"]').fill(email);
  await page.locator('[id="data.password"]').fill(password);
  await page.locator('form button[type=submit]').first().click();
  await page.waitForTimeout(2500);
}

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1380, height: 940 }, deviceScaleFactor: 2 });
const page = await ctx.newPage();

console.log('Operator for this run:', OPERATOR.slug);

// --- 1. the platform admin signs in ----------------------------------
// `?lang=el` throughout the admin half: the demo platform admin's own language
// is English, and this is a Greek document.
await page.goto(`${PANEL}/admin/login?lang=el`, { waitUntil: 'networkidle' });
await shot(page, '01-admin-login');

await page.locator('[id="data.email"]').fill('admin@kaiki.example');
await page.locator('[id="data.password"]').fill('password');
await page.locator('form button[type=submit]').first().click();
await page.waitForTimeout(2500);
await page.goto(`${PANEL}/admin/tenants?lang=el`, { waitUntil: 'networkidle' });
await shot(page, '02-admin-tenants');

// --- 2. a new operator ------------------------------------------------
await page.goto(`${PANEL}/admin/tenants/create?lang=el`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);

await page.locator('[id="data.name"]').fill(OPERATOR.name);
await page.locator('[id="data.slug"]').fill(OPERATOR.slug);
await page.locator('[id="data.email"]').fill(OPERATOR.email);
await page.locator('[id="data.owner_name"]').fill(OPERATOR.ownerName);
await page.locator('[id="data.owner_email"]').fill(OPERATOR.ownerEmail);
await shot(page, '03-new-operator-form');

// The visible primary action, not the first `button[type=submit]` in the
// document — Filament renders hidden submit buttons inside dropdown menus,
// and `.first()` finds one of those before it finds the form's own.
await page.locator('.fi-form-actions button[type=submit]').first().click();
await page.waitForTimeout(3500);
await shot(page, '04-operator-created');

// --- 2b. the features a small operator may not want ------------------
//
// The operator's edit form, scrolled to «Λειτουργίες» — the QR boarding switch
// the walkthrough asks the reader to decide with the operator (2026-09-11).
// The id is read the way a person would find it, from the operator's slug.
const { execSync: exec } = await import('node:child_process');
const tenantId = exec(
  `php artisan tinker --execute="echo App\\Models\\Tenant::where('slug','${OPERATOR.slug}')->value('id');"`,
  { cwd: resolve(HERE, '..', '..'), encoding: 'utf8' },
).trim().split(/\s+/).pop();

await page.goto(`${PANEL}/admin/tenants/${tenantId}/edit?lang=el`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
// By the field's id, not its label: the label follows the admin's locale.
await page.locator('[id="data.qr_check_in_enabled"]').first()
  .evaluate((el) => el.scrollIntoView({ block: 'center' }));
await shot(page, '04b-operator-features', '.fi-section:has([id="data.qr_check_in_enabled"])');

// --- 3. the owner sets a password and signs in ------------------------
//
// The link is minted the way `kaiki:invitation-link` mints it, because that is
// the command the walkthrough tells a reader to run. Driving the real page
// proves the link works rather than asserting that it should.
const { execSync } = await import('node:child_process');
const printed = execSync(
  `php artisan kaiki:invitation-link ${OPERATOR.ownerEmail}`,
  { cwd: resolve(HERE, '..', '..'), encoding: 'utf8' },
);
const link = (printed.match(/https?:\/\/\S*password-reset\S*/) ?? [])[0];

if (!link) {
  throw new Error('No invitation link came back from kaiki:invitation-link.');
}

await page.goto(link, { waitUntil: 'networkidle' });
await shot(page, '05-set-password');

// By type rather than by id: Filament names the confirmation field
// differently between versions, and the page has exactly two password inputs.
const pw = page.locator('input[type=password]');
await pw.nth(0).fill(OWNER_PASSWORD);
await pw.nth(1).fill(OWNER_PASSWORD);
await page.locator('.fi-form-actions button[type=submit]').first().click();
await page.waitForTimeout(3500);
await shot(page, '06-after-password');

// --- 4. the guide, on an account with nothing in it -------------------
//
// A **new browser context**, because the one above is still signed in as the
// platform administrator — and a super-admin has no tenant, so `/app` answers
// 403 for them by design (ADR-0020). Reusing the window photographs that 403
// and calls it the operator's first screen.
await ctx.close();
const ownerCtx = await browser.newContext({ viewport: { width: 1380, height: 940 }, deviceScaleFactor: 2 });
const owner = await ownerCtx.newPage();

await owner.goto(`${PANEL}/app/login`, { waitUntil: 'networkidle' });
await owner.locator('[id="data.email"]').fill(OPERATOR.ownerEmail);
await owner.locator('[id="data.password"]').fill(OWNER_PASSWORD);
await owner.locator('form button[type=submit]').first().click();
await owner.waitForTimeout(3000);
await shot(owner, '07-first-screen');

await owner.goto(`${PANEL}/app/setup`, { waitUntil: 'networkidle' });
await owner.waitForTimeout(2500);
await shot(owner, '08-setup-guide');

// --- 5. the screens a new operator fills in ---------------------------
for (const [name, path] of [
  ['09-vessels-empty', '/app/vessels'],
  ['10-vessel-form', '/app/vessels/create'],
  ['11-ports-empty', '/app/ports'],
  ['12-products-empty', '/app/products'],
  ['13-product-form', '/app/products/create'],
]) {
  await owner.goto(`${PANEL}${path}`, { waitUntil: 'networkidle' }).catch(() => {});
  await owner.waitForTimeout(1800);
  await shot(owner, name);
}

// --- 6. «Ρυθμίσεις», at the bottom of the menu -----------------------
//
// Every "Ρυθμίσεις → …" step in the walkthrough starts here since 2026-09-11:
// one item at the foot of the sidebar, opening a page of cards.
await owner.goto(`${PANEL}/app/settings`, { waitUntil: 'networkidle' });
await owner.waitForTimeout(1800);
await shot(owner, '14-settings-hub');

await browser.close();

console.log('\nOwner password used:', OWNER_PASSWORD);
console.log('Captured:', shots.length);
console.log(JSON.stringify(OPERATOR, null, 2));
