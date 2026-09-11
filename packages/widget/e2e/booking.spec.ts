import { expect, test } from '@playwright/test';

import { FIXTURE_ORIGIN, embed, isoDaysAhead, pickDay, reachCheckoutButton, widget, world } from './support/world';

/**
 * A person buys a boat trip (spec TST-3, issue 111; rewritten 2026-09-11).
 *
 * ## This is the test in the project that proves the product
 *
 * Every other gate proves a rule about the code. This one drives a real browser
 * through a real widget on a real second origin, against a real server, to a
 * real confirmed booking.
 *
 * ## The walk it follows is today's
 *
 * Date on a month grid (2026-09-10), then the party, then «Continue to
 * checkout», which takes the whole page to Kaiki's checkout (ADR-0030): the
 * guest's details and the consent are typed there, the payment happens on the
 * gateway's page, and the guest lands on their booking page. The spec it
 * replaces still typed into a date input and a details step that were both
 * removed on 2026-09-10, and had failed every run since.
 *
 * ## The payment is the sandbox path, and it is our own page
 *
 * PAY-11: a `*_test_` key makes `bookings.is_test` true, which routes checkout
 * to the fake gateway and its sandbox page. No third-party sandbox, no card.
 *
 * ## And the way back to the operator's site
 *
 * The page the guest started on travels with the draft (2026-09-11), so both
 * Kaiki pages offer a link back to it — asserted here on the real origin the
 * widget ran on, because that is the one value a unit test has to invent.
 */

test('a guest books a trip, pays in the sandbox and lands on their booking', async ({ page }) => {
  const run = world();

  await embed(page, { mount: 'booking', product: run.product_uuid });

  const root = widget(page);

  await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();

  await pickDay(root, isoDaysAhead(14));
  await root.getByRole('button', { name: 'Continue', exact: true }).click();

  await expect(root.getByRole('heading', { name: 'How many of you?' })).toBeVisible();

  // The first age band, whatever the operator called it.
  await root.locator('input[type="number"]').first().fill('2');

  const checkoutButton = await reachCheckoutButton(root);

  await checkoutButton.click();

  // **The browser leaves the operator's site** for Kaiki's checkout page.
  await page.waitForURL(/\/c\//, { timeout: 30_000 });

  // The way back, to the page the widget was on.
  await expect(page.locator(`a[href^="${FIXTURE_ORIGIN}"]`).first()).toBeVisible();

  await page.locator('#guest_name').fill('Maria Papadopoulou');
  await page.locator('#guest_email').fill('maria@example.test');
  await page.locator('#guest_phone').fill('+30 210 000 0000');

  // A trip that needs a manifest asks for each passenger's name as well.
  for (const passenger of await page.locator('input[name$="[full_name]"]').all()) {
    await passenger.fill('Maria Papadopoulou');
  }

  await page.locator('input[name="terms"]').check();
  await page.locator('button.pay').click();

  // The sandbox checkout, saying what it is before anything else.
  await page.waitForURL(/\/sandbox\/checkout\//, { timeout: 30_000 });
  await expect(page.getByText('Test mode', { exact: false })).toBeVisible();
  await page.locator('button.pay').click();

  // Paid: the guest's own booking page, with the way back still offered.
  await page.waitForURL(/\/b\//, { timeout: 30_000 });
  await expect(page.locator(`a[href^="${FIXTURE_ORIGIN}"]`).first()).toBeVisible();
});

test('a declined card brings the guest back to the checkout to try again', async ({ page }) => {
  await embed(page, { mount: 'booking', product: world().product_uuid });

  const root = widget(page);

  await pickDay(root, isoDaysAhead(15));
  await root.getByRole('button', { name: 'Continue', exact: true }).click();
  await root.locator('input[type="number"]').first().fill('1');
  await (await reachCheckoutButton(root)).click();

  await page.waitForURL(/\/c\//, { timeout: 30_000 });

  await page.locator('#guest_name').fill('Nikos Andreou');
  await page.locator('#guest_email').fill('nikos@example.test');

  for (const passenger of await page.locator('input[name$="[full_name]"]').all()) {
    await passenger.fill('Nikos Andreou');
  }

  await page.locator('input[name="terms"]').check();
  await page.locator('button.pay').click();

  await page.waitForURL(/\/sandbox\/checkout\//, { timeout: 30_000 });
  await page.locator('button.decline').click();

  // Back on the checkout, told what happened, with the details still there.
  await page.waitForURL(/\/c\//, { timeout: 30_000 });
  await expect(page.locator('.notice-error')).toBeVisible();
  await expect(page.locator('#guest_name')).toHaveValue('Nikos Andreou');
});
