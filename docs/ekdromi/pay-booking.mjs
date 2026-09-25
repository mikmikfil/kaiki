/**
 * Take the test booking through checkout and the sandbox, so the emails exist.
 *
 *   node docs/ekdromi/pay-booking.mjs <manage-token>
 *
 * The booking was made with a `pk_test_` key, so PAY-11 routes it to the fake
 * gateway and its own sandbox page — no third party, no card. What lands in
 * Mailpit afterwards is the real confirmation, rendered from the real template.
 */

import { chromium } from '@playwright/test';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const token = process.argv[2];

if (token === undefined) {
  process.stderr.write('Give me the booking manage token.\n');
  process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, locale: 'el-GR' });

await page.goto(`http://192.168.1.43:8001/c/${token}`, { waitUntil: 'domcontentloaded' });
await page.waitForLoadState('networkidle').catch(() => {});

// The three are already filled from the booking the API made, and the page
// folds them away once it has them — so they are typed only when on screen.
for (const [id, value] of [
  ['#guest_name', 'Μαρία Παπαδοπούλου'],
  ['#guest_email', 'maria@example.gr'],
  ['#guest_phone', '+30 694 123 4567'],
]) {
  const field = page.locator(id);

  if ((await field.count()) > 0 && (await field.isVisible())) {
    await field.fill(value);
  }
}

// Passenger rows, when the trip asks for them: each is a `<details>` and only
// the first is open, so a party of four is a list rather than twelve fields.
const rows = page.locator('details.passenger');

for (let i = 0; i < (await rows.count()); i += 1) {
  const row = rows.nth(i);

  if ((await row.getAttribute('open')) === null) {
    await row.locator('summary').click();
  }

  await row.locator('input[name$="[full_name]"]').fill('Μαρία Παπαδοπούλου');
}

// Consent travelled with the booking (`terms_accepted`), and the page does not
// ask a second time when it already has a timestamp.
const terms = page.locator('input[name="terms"]');

if ((await terms.count()) > 0) {
  await terms.check();
}

await page.screenshot({ path: resolve(HERE, 'shots', '08-checkout.png') });
await page.locator('button.pay').click();

await page.waitForURL(/\/sandbox\/checkout\//, { timeout: 30_000 });
await page.screenshot({ path: resolve(HERE, 'shots', '09-sandbox.png') });
await page.locator('button.pay').click();

await page.waitForURL(/\/b\//, { timeout: 30_000 });
await page.waitForLoadState('networkidle').catch(() => {});
await page.screenshot({ path: resolve(HERE, 'shots', '10-booked.png'), fullPage: true });

process.stdout.write(`paid — the guest landed on ${page.url()}\n`);

await browser.close();
