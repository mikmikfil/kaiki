import { expect, test } from '@playwright/test';

import { embed, widget, world } from './support/world';

/**
 * A hold running out, on a real clock (WGT-19, issue 111).
 *
 * > *"Given the hold, when a run lets a draft expire, then the widget's expiry
 * > handling is exercised for real rather than by a mocked clock."*
 *
 * ## Why a mocked clock would not have done
 *
 * The countdown is unit-tested against a fake `now()` and passes; what that
 * cannot test is the part that only exists at runtime — one interval running
 * for the life of the mount, cleared when the hold ends, driving a re-render
 * that swaps the whole view. A test that moves a fake clock never starts a
 * timer, so it proves the arithmetic and nothing about the machinery.
 *
 * So the run waits. `KAIKI_HOLD_MINUTES=1` for the whole suite, which makes this
 * spec about seventy seconds long and is the honest cost of the assertion.
 *
 * ## It is the nightly suite's, not the release gate's
 *
 * Seventy seconds in front of every alias repoint would be seventy seconds
 * somebody eventually removes. The smoke project does not run this file.
 */

test('a hold that runs out tells the guest, and offers to start again', async ({ page }) => {
  test.setTimeout(180_000);

  await embed(page, { mount: 'booking', product: world().product_uuid });

  const root = widget(page);

  await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();

  const date = new Date(Date.now() + 21 * 86_400_000).toISOString().slice(0, 10);

  await root.locator('input[type="date"]').fill(date);
  await root.getByRole('button', { name: 'Continue' }).click();

  await root.locator('input[type="number"]').first().fill('2');
  await root.getByRole('button', { name: 'Continue' }).click();

  await expect(root.getByRole('heading', { name: 'Your details' })).toBeVisible();

  await root.getByLabel('Full name').fill('Nikos Andreou');
  await root.getByLabel('Email').fill('nikos@example.test');
  await root.getByLabel('Phone').fill('+30 210 000 0001');
  await root.getByRole('checkbox').check();
  await root.getByRole('button', { name: 'Continue' }).click();

  await expect(root.getByRole('heading', { name: 'Check and pay' })).toBeVisible();

  // The draft — and therefore the hold — exists from the moment the review step
  // is reached. The guest is deliberately **not** sent to the gateway: they are
  // the person who walked away from the screen, which is the case a hold exists
  // for. (Pressing pay would commit the seats, BKG-9, and there would be
  // nothing left to expire.)

  // The countdown, which is WGT-19's "in words" rather than a bare number.
  await expect(root.locator('.kaiki-hold')).toBeVisible({ timeout: 30_000 });

  // It goes into its warning state before it expires, which is the whole point
  // of warning at two minutes — on a one-minute hold that is immediately.
  await expect(root.locator('.kaiki-hold-warning')).toBeVisible({ timeout: 30_000 });

  // And then it runs out. Nothing here advances a clock.
  await expect(root.getByRole('heading', { name: 'We could not hold your seats any longer' }))
    .toBeVisible({ timeout: 120_000 });

  // The way out is offered, and it says what happened to the money: nothing.
  await expect(root.getByText('Nothing was charged', { exact: false })).toBeVisible();
  await expect(root.getByRole('button', { name: 'Start again' })).toBeVisible();

  // Starting again puts the guest back at the first step rather than at a dead
  // end — a booking that expires and cannot be retried is a sale lost twice.
  await root.getByRole('button', { name: 'Start again' }).click();

  await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();
});
