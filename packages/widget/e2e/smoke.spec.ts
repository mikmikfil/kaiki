import { expect, test } from '@playwright/test';

import { embed, widget, world } from './support/world';

/**
 * The four mounts, loaded in a real browser against the built bundle
 * (WGT-5, ADR-0011's second release gate, issue 111).
 *
 * ## This is the subset the alias repoint depends on
 *
 * ADR-0011 gates a release on three checks, and this is the browser one. It has
 * to be fast enough to sit in front of every release and broad enough to be
 * worth having, which is why it asks one question of each mount — *does it
 * render its own job* — and leaves the booking walk to the full suite.
 *
 * A unit suite that mocks the transport cannot answer this question at all. The
 * bundle either parses, mounts a shadow root and paints, or it does not.
 */

test.describe('the four mounts', () => {
  test('the list mount draws the operator trips', async ({ page }) => {
    await embed(page, { mount: 'list' });

    const root = widget(page);

    await expect(root).toBeVisible();
    // Seeded by `DemoBookableSeeder`, and the point of asserting on a title is
    // that it came from the API rather than from the bundle.
    await expect(root.locator('.kaiki-card').first()).toBeVisible();
  });

  test('the calendar mount draws a month of availability', async ({ page }) => {
    await embed(page, { mount: 'calendar', product: world().product_uuid });

    const root = widget(page);

    await expect(root).toBeVisible();
    await expect(root.locator('.kaiki-day').first()).toBeVisible();
  });

  test('the enquiry mount draws its form', async ({ page }) => {
    await embed(page, { mount: 'enquiry', product: world().product_uuid });

    const root = widget(page);

    await expect(root.getByLabel(/name/i)).toBeVisible();
    await expect(root.getByLabel(/email/i)).toBeVisible();
  });

  test('the booking mount reaches its first step', async ({ page }) => {
    await embed(page, { mount: 'booking', product: world().product_uuid });

    const root = widget(page);

    // The four lines above the date picker — brand decision 3 of 2026-09-04 —
    // and then the picker itself: a month grid since 2026-09-10, with the days
    // that can be booked as buttons.
    await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();
    await expect(root.locator('button.kaiki-day-pick').first()).toBeVisible();
  });
});

test('nothing the widget does reaches the host page console as an error', async ({ page }) => {
  // It runs inside somebody else's page. An exception thrown into their console
  // is a support ticket for them and an invisible failure for us.
  const errors: string[] = [];

  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(message.text());
    }
  });

  await embed(page, { mount: 'booking', product: world().product_uuid });

  await expect(widget(page)).toBeVisible();

  expect(errors).toEqual([]);
});
