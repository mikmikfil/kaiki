import { expect, test } from '@playwright/test';

import { dismissNavigation, expectNoHorizontalScroll, expectScrollsWithin, expectTappable, signIn } from './support/panel';

/**
 * The dashboard on a 390×844 screen (OPS-22, OPS-1, OPS-3, OOS-6).
 *
 * ## Why a browser, at this width, rather than a component test
 *
 * Every widget on this page already has a PHP test proving it computes the
 * right figures. Not one of them can answer the question an operator asks on a
 * pontoon: *can I read this on my phone?* That question is about layout, and
 * layout only exists once a real engine has applied the stylesheet at a real
 * width — which is what the `mobile` project in `playwright.config.ts` is for.
 *
 * ## What is asserted, and what is not
 *
 * The page must not scroll sideways. That is the failure that makes a back
 * office unusable rather than merely tight: every vertical swipe drifts, and the
 * column being read slides off. Where a card sits is not asserted at all — that
 * is a design decision and it will change; sideways scroll is a defect under
 * any design.
 */

test.beforeEach(async ({ page }) => {
  await signIn(page);
  await page.goto('/app');
});

test('the dashboard fits the screen it is being read on', async ({ page }) => {
  await expect(page.locator('main').first()).toBeVisible();

  await expectNoHorizontalScroll(page);
});

test('the six figures are all readable', async ({ page }) => {
  const stats = page.locator('.fi-wi-stats-overview-stat');

  // OPS-1 puts six on the screen and OPS-2 makes each of them say what it
  // counts. A phone that dropped one, or squashed one off the edge, would be
  // showing an operator a partial answer without saying so.
  await expect(stats).toHaveCount(6);

  const width = page.viewportSize()?.width ?? 0;

  for (let index = 0; index < 6; index += 1) {
    const stat = stats.nth(index);

    await expect(stat).toBeVisible();

    // A label, a figure and its definition — the whole of OPS-2's contract.
    // Emptiness here is a stat that rendered its frame and nothing else.
    await expect(stat).not.toBeEmpty();

    const box = await stat.boundingBox();

    expect(box).not.toBeNull();
    expect(box!.x + box!.width, `stat ${index + 1} runs off a ${width}px screen`).toBeLessThanOrEqual(width + 1);
  }
});

test('the fleet strip scrolls sideways instead of pushing the page over', async ({ page }) => {
  // OOS-6, and the reason `today-at-sea.blade.php` puts `overflow-x: auto` on
  // the strip: a day is a day wide whatever the screen is, so the timeline has
  // to be swipeable. Squashing three boats into 390px would be legible to
  // nobody, and letting it push the document wide breaks every other widget.
  const strip = page.locator('.kaiki-today');

  await expect(strip).toBeVisible();

  await expectScrollsWithin(page, '.kaiki-today');
  await expectNoHorizontalScroll(page);
});

test('a figure is a link a thumb can follow', async ({ page }) => {
  // OPS-1: every stat is clickable, and on a phone that is the whole
  // navigation — an operator reads "18 sailing today" and taps it rather than
  // opening the drawer and finding Departures.
  const stat = page.locator('.fi-wi-stats-overview-stat').first();

  await expectTappable(page, '.fi-wi-stats-overview-stat', 'the first stat');

  await stat.tap();

  await page.waitForURL(/\/app\/departures/);
});

test('a phone’s first visit arrives with the page in front, not the menu', async ({ page }) => {
  /*
   * This run is what found the defect, and this is the guard on the fix.
   *
   * Filament seeds its sidebar store `$persist(true)` without consulting the
   * viewport, so before `filament/sidebar-first-visit.blade.php` an operator's
   * *first* panel page on a phone opened behind a 320px drawer and a dark
   * backdrop — nothing tappable, and their first act in the back office a
   * dismissal of a menu they never opened. It then persisted `false` and never
   * appeared again, which is why a developer's second look never sees it.
   *
   * `isOpen` is the key Filament persists under; clearing it is what makes this
   * a first visit again.
   */
  await page.evaluate(() => localStorage.removeItem('isOpen'));
  await page.reload();

  await expect(page.locator('.fi-sidebar-close-overlay')).toBeHidden();
  await expectTappable(page, '.fi-wi-stats-overview-stat', 'the first stat, on a first visit');
});

test('the navigation drawer can be tapped away', async ({ page }) => {
  /*
   * An operator who *has* opened the drawer needs it to close again, and the
   * only way out is a tap in the strip beside it — see `dismissNavigation`
   * for why that strip is where the tap has to land. Asserted rather than
   * merely relied on: if it ever closes up, a phone reaches the back office and
   * can do nothing at all with it.
   */
  // Opened deliberately, the way an operator reaching for the menu opens it.
  await page.evaluate(() => localStorage.setItem('isOpen', 'true'));
  await page.reload();

  const backdrop = page.locator('.fi-sidebar-close-overlay');

  await expect(backdrop).toBeVisible();

  await dismissNavigation(page);

  await expect(backdrop).toBeHidden();
  await expectTappable(page, '.fi-wi-stats-overview-stat', 'the first stat, once the drawer is away');
});
