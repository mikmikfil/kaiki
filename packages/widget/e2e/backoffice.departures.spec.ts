import { expect, test } from '@playwright/test';

import { expectNoHorizontalScroll, expectScrollsWithin, expectTappable, signIn } from './support/panel';

/**
 * Today's departures on a 390×844 screen (OPS-22, OPS-1).
 *
 * ## This is the screen the phone is for
 *
 * The dashboard says *18 sailing today*; this is where the operator goes next,
 * standing on a quay, to find out which one is the boat in front of them. The
 * list is eleven columns wide by design — capacity, sold, held, status, source —
 * and none of that is negotiable on a desktop.
 *
 * So the question is not *does it fit*, because it does not and should not. It
 * is **where the extra width goes**: into the table's own scroller, which the
 * operator swipes, or into the document, where it takes every other control on
 * the page sideways with it. Filament answers this with `overflow-x-auto` on
 * `.fi-ta-content`, and that answer is one stylesheet change away from being
 * lost, which is why it is asserted here rather than assumed.
 */

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test('an operator reaches the list from the dashboard and can read it', async ({ page }) => {
  await page.goto('/app');

  // The route an operator actually takes: the "sailing today and tomorrow"
  // figure is a link, and on a phone it is faster than the drawer.
  await page.locator('.fi-wi-stats-overview-stat').first().tap();
  await page.waitForURL(/\/app\/departures/);

  const rows = page.locator('.fi-ta-row');

  await expect(rows.first()).toBeVisible();
  // A list with rows in it, not an empty state: the seed sails today, and a
  // spec that passed against "no departures" would be asserting nothing.
  expect(await rows.count()).toBeGreaterThan(0);

  await expectNoHorizontalScroll(page);
});

test('the wide table swipes, and the page underneath does not move', async ({ page }) => {
  await page.goto('/app/departures');

  await expect(page.locator('.fi-ta-row').first()).toBeVisible();

  await expectScrollsWithin(page, '.fi-ta-content');
  await expectNoHorizontalScroll(page);
});

test('the columns an operator needs first are on screen without swiping', async ({ page }) => {
  await page.goto('/app/departures');

  const first = page.locator('.fi-ta-row').first();

  await expect(first).toBeVisible();

  /*
   * Date and time lead the table, and they are the two an operator reads
   * before anything else — *is this the 07:30?* If a future column reorder
   * pushed them behind the fold, the list would still "work" and would still
   * be the wrong list to hand somebody on a pontoon.
   *
   * Asserted as *within the viewport*, not at a position: the columns may move
   * relative to each other; they may not move off the screen.
   */
  const width = page.viewportSize()?.width ?? 0;

  const leading = first.locator('.fi-ta-cell').nth(1);

  await expect(leading).toBeVisible();

  const box = await leading.boundingBox();

  expect(box).not.toBeNull();
  expect(box!.x + box!.width, `the leading column runs off a ${width}px screen`).toBeLessThanOrEqual(width + 1);
});

test('the row actions are reachable at the end of the swipe', async ({ page }) => {
  await page.goto('/app/departures');

  await expect(page.locator('.fi-ta-row').first()).toBeVisible();

  /*
   * The manifest and edit actions sit in the last column, so on a phone they
   * are past the right edge until the operator swipes the table across. That is
   * the intended behaviour of a horizontal scroller and not a defect — what
   * would be a defect is reaching the end of the swipe and still not being able
   * to hit them, which is what this asserts.
   */
  await page.locator('.fi-ta-content').first().evaluate((element) => {
    element.scrollLeft = element.scrollWidth;
  });

  await expectTappable(page, '.fi-ta-row .fi-ta-actions a, .fi-ta-row .fi-ta-actions button', 'the first row action');

  // Swiping a table is not supposed to move the page under it.
  await expectNoHorizontalScroll(page);
});
