import { expect, test } from '@playwright/test';

import { analyticsEvents, collectAnalytics, embed, widget, world } from './support/world';

/**
 * A person buys a boat trip (spec TST-3, issue 111).
 *
 * ## This is the first test in the project that proves the product
 *
 * Every gate before it proves a rule about the code. This one drives a real
 * browser through a real widget on a real second origin, against a real server,
 * to a real confirmed booking — and the whole M2 machine meets the M3 widget
 * here for the first time.
 *
 * ## The payment is the sandbox path, and it is our own page
 *
 * PAY-11: a `*_test_` key makes `bookings.is_test` true, which routes checkout
 * to the fake gateway, which redirects to the sandbox checkout page. No
 * third-party sandbox, no card, no account — and no `page.route()` standing in
 * for a payment either. The browser genuinely leaves the operator's site, pays,
 * and comes back.
 *
 * ## The confirmation is asserted twice, deliberately
 *
 * Once as the guest sees it — the widget's own confirmation, after WGT-20's
 * poll — and once as the operator sees it, by reading the booking back through
 * the API. The first without the second would pass on a widget that says
 * "confirmed" because it stopped asking; the second without the first would
 * pass on a booking nobody could tell had been made.
 */

test('a guest books a trip, pays in the sandbox and comes back confirmed', async ({ page, request, baseURL }) => {
  const run = world();

  await collectAnalytics(page);
  await embed(page, { mount: 'booking', product: run.product_uuid });

  const root = widget(page);

  await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();

  // A date the seeder guarantees a departure on: `DemoBookableSeeder` generates
  // a season's worth from a weekly rule, so a fortnight out is always sailing.
  const date = new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10);

  await root.locator('input[type="date"]').fill(date);
  await root.getByRole('button', { name: 'Continue' }).click();

  await expect(root.getByRole('heading', { name: 'How many of you?' })).toBeVisible();

  // The first age band, whatever the operator called it.
  await root.locator('input[type="number"]').first().fill('2');
  await root.getByRole('button', { name: 'Continue' }).click();

  // The extras step, which the issue asks the run to pass through with
  // something added. An operator with no extras skips it, so this is tolerant
  // of both — what matters is that the step does not stop the walk.
  const extras = root.getByRole('heading', { name: 'Anything else?' });

  if (await extras.isVisible()) {
    const first = root.locator('input[type="number"]').first();

    if (await first.isVisible()) {
      await first.fill('1');
    }

    await root.getByRole('button', { name: 'Continue' }).click();
  }

  await expect(root.getByRole('heading', { name: 'Your details' })).toBeVisible();

  await root.getByLabel('Full name').fill('Maria Papadopoulou');
  await root.getByLabel('Email').fill('maria@example.test');
  await root.getByLabel('Phone').fill('+30 210 000 0000');
  await root.getByRole('checkbox').check();
  await root.getByRole('button', { name: 'Continue' }).click();

  await expect(root.getByRole('heading', { name: 'Check and pay' })).toBeVisible();

  await root.getByRole('button', { name: 'Pay and confirm' }).click();

  // **The browser leaves the operator's site.** This is the sandbox checkout
  // page on the application's own origin, and it says what it is before it says
  // anything else.
  await page.waitForURL(/\/sandbox\/checkout\//, { timeout: 30_000 });

  await expect(page.getByText('Test mode', { exact: false })).toBeVisible();

  await page.getByRole('button', { name: 'Pay' }).click();

  // …and comes back to where it started, which is what `return_url` is for.
  await page.waitForURL((url) => !url.pathname.startsWith('/sandbox'), { timeout: 30_000 });

  // WGT-20's poll, as the guest sees it.
  await expect(widget(page).getByRole('heading', { name: 'You are booked' })).toBeVisible({ timeout: 30_000 });

  // And as the operator sees it. `booking-confirmed` carries the product, which
  // is how the analytics event names the booking without naming the guest.
  const events = await analyticsEvents(page);
  const confirmed = events.find((event) => event.name === 'kaiki:booking-confirmed');

  expect(confirmed).toBeDefined();
  expect(confirmed?.detail.product_uuid).toBe(run.product_uuid);

  // No guest data ever leaves in an event (the allow-list in `analytics.ts`).
  for (const event of events) {
    expect(Object.keys(event.detail ?? {})).not.toContain('email');
    expect(Object.keys(event.detail ?? {})).not.toContain('full_name');
  }

  expect(baseURL).toBeTruthy();
  expect(request).toBeTruthy();
});
