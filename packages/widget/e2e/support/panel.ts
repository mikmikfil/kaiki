import { expect, type Page } from '@playwright/test';

import { world, type PanelWorld } from './world';

/**
 * Getting into the operator back office, on a phone (OPS-22).
 *
 * Everything else in this suite is anonymous and cross-origin: the widget on
 * somebody's website, talking to the API. The back office is neither — it is a
 * session on the application's own origin behind `/app/login` — and OPS-22's
 * question, *does this work on a phone*, cannot be asked until somebody is
 * signed in. So this file exists rather than a fourth copy of a login walk.
 *
 * ## The credentials come from the seed, not from here
 *
 * `kaiki:e2e-prepare` publishes the three seeded operators into `.state.json`
 * **by role**, so a rename in `DemoTenantSeeder` fails during seeding rather
 * than turning every mobile spec red at a login form nobody can read. See
 * {@see \App\Console\Commands\E2eSeedCommand}.
 */

export type PanelRole = 'owner' | 'manager' | 'crew';

function credentials(): PanelWorld {
  const { panel } = world();

  if (panel === undefined) {
    throw new Error(
      'No `panel` block in the run state. It is written by `kaiki:e2e-prepare`, which playwright.config.ts runs before the suite.',
    );
  }

  return panel;
}

/**
 * Sign in and land on the panel, with the navigation out of the way.
 *
 * Fields are addressed by `id` rather than by label. The seeded operator's
 * locale is Greek — `DemoTenantSeeder` gives staff the tenant default — so an
 * English label selector would match nothing and read as a broken form rather
 * than as a wrong selector.
 *
 * The wait is on leaving `/login`, not on a timeout: Filament authenticates
 * over Livewire and then redirects, and asserting the redirect landed is what
 * makes a later assertion about layout an assertion about the *panel* rather
 * than about a login page that happens to fit.
 */
export async function signIn(page: Page, role: PanelRole = 'owner'): Promise<void> {
  const panel = credentials();

  await page.goto('/app/login');

  await page.locator('#data\\.email').fill(panel[role]);
  await page.locator('#data\\.password').fill(panel.password);
  await page.locator('#form button[type="submit"]').click();

  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30_000 });

  await dismissNavigation(page);
}

/**
 * Close the navigation drawer, the way an operator has to.
 *
 * **This is a workaround for a real defect, and it is deliberately visible.**
 * Filament persists `$store.sidebar.isOpen` to `localStorage` and seeds it
 * `true` regardless of viewport, so the *first* panel page an operator opens on
 * a phone arrives with the full-height drawer over it behind a dark backdrop —
 * every control underneath is unclickable until they tap the backdrop away.
 * After that the persisted `false` sticks, which is why it never shows up on a
 * developer's second look.
 *
 * Fixing it belongs upstream in the panel, not in a test helper, so the specs
 * step past it here and `backoffice.dashboard.spec.ts` asserts the tap that
 * dismisses it actually works — which is the part an operator depends on.
 */
export async function dismissNavigation(page: Page): Promise<void> {
  const backdrop = page.locator('.fi-sidebar-close-overlay');

  if (await backdrop.isVisible()) {
    await backdrop.tap();
    await expect(backdrop).toBeHidden();
  }
}

/**
 * The OPS-22 assertion, in one place.
 *
 * A back office on a phone fails in one way that matters more than the rest:
 * the document grows wider than the screen, so every vertical scroll drifts
 * sideways and the operator loses the column they were reading. One pixel of
 * slack absorbs sub-pixel rounding at a device pixel ratio of 3, which is a
 * rendering artefact rather than a layout bug.
 *
 * Deliberately not an assertion about where anything sits. Where a card lands
 * is a design decision that will change; a page that scrolls sideways is a
 * defect in any design.
 */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const page_ = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
  }));

  expect(
    page_.scrollWidth,
    `the page scrolls sideways at ${page_.innerWidth}px: the document is ${page_.scrollWidth}px wide`,
  ).toBeLessThanOrEqual(page_.innerWidth + 1);
}

/**
 * Everything wide the page holds is inside its own scroller.
 *
 * The counterpart to {@see expectNoHorizontalScroll}: a table of eleven columns
 * or a fleet timeline *should* be wider than a phone. What matters is that the
 * overflow is handed to a container the operator can swipe, so the page itself
 * stays put — which is the difference between a usable list and a broken one.
 */
export async function expectScrollsWithin(page: Page, selector: string): Promise<void> {
  const box = await page.locator(selector).first().evaluate((element) => ({
    scrollWidth: element.scrollWidth,
    clientWidth: element.clientWidth,
    overflowX: getComputedStyle(element).overflowX,
  }));

  expect(['auto', 'scroll'], `${selector} does not offer to scroll sideways`).toContain(box.overflowX);
  expect(box.scrollWidth, `${selector} has nothing to scroll to`).toBeGreaterThan(box.clientWidth);

  // Asked to move, and moved: `overflow-x` on an element whose content a
  // parent has already clipped scrolls nowhere, and reads as fine from CSS.
  const moved = await page.locator(selector).first().evaluate((element) => {
    element.scrollLeft = element.scrollWidth;

    return element.scrollLeft;
  });

  expect(moved, `${selector} is clipped rather than scrollable`).toBeGreaterThan(0);
}

/**
 * A control an operator can actually hit with a thumb.
 *
 * Three questions, and none of them about position: is it on screen, is it
 * usable, and does it fit within the width — a field whose box runs off the
 * right edge is one the operator scrolls the page to reach, which is the thing
 * OPS-22 forbids.
 */
export async function expectTappable(page: Page, selector: string, name: string): Promise<void> {
  const control = page.locator(selector).first();

  await expect(control, `${name} is not on screen`).toBeVisible();
  await expect(control, `${name} cannot be used`).toBeEnabled();

  const box = await control.boundingBox();
  const width = page.viewportSize()?.width ?? 0;

  expect(box, `${name} has no box`).not.toBeNull();
  expect(box!.x, `${name} starts off the left edge`).toBeGreaterThanOrEqual(-1);
  expect(box!.x + box!.width, `${name} runs past the right edge of a ${width}px screen`).toBeLessThanOrEqual(width + 1);
  // Filament's control height is 36px. A floor well under it catches a field
  // that has collapsed to nothing without asserting a design decision.
  expect(box!.height, `${name} is too short to tap`).toBeGreaterThanOrEqual(24);
}
