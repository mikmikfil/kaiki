import { expect, type Cookie, type Page } from '@playwright/test';

import { world, type PanelWorld } from './world';

/**
 * Getting into the operator back office, on a phone (OPS-22).
 *
 * Everything else in this suite is anonymous and cross-origin: the widget on
 * somebody's website, talking to the API. The back office is neither — it is a
 * session on the application's own origin behind `/app/login` — and OPS-22's
 * question, *does this work on a phone*, cannot be asked until somebody is
 * signed in. So this file exists rather than three copies of a login walk.
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
 * One session per role, minted once and lent to every context that asks.
 *
 * **Filament rate-limits the login form to five attempts a minute, and it is
 * right to.** Thirteen specs each filling the form in turn trips that on the
 * sixth — and the failure reads as "the panel would not let me in", which is
 * both alarming and untrue. Nobody signs in thirteen times before breakfast:
 * the repetition is an artefact of how tests are isolated, so it is the thing
 * that gets removed rather than the protection.
 *
 * Each test still gets its own browser context, its own storage and its own
 * viewport — only the cookie is shared, which is the part of a session an
 * operator would have kept anyway. `workers: 1` (see `playwright.config.ts`)
 * makes one cache per role enough.
 */
const sessions = new Map<PanelRole, Cookie[]>();

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
  const cached = sessions.get(role);

  if (cached === undefined) {
    const panel = credentials();

    await page.goto('/app/login');

    await page.locator('#data\\.email').fill(panel[role]);
    await page.locator('#data\\.password').fill(panel.password);
    await page.locator('#form button[type="submit"]').click();

    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30_000 });

    sessions.set(role, await page.context().cookies());
  } else {
    await page.context().addCookies(cached);
    await page.goto('/app');

    // A restored cookie that no longer authenticates lands back on the login
    // form, and every assertion after it would be about that form.
    await expect(page).not.toHaveURL(/\/app\/login/);
  }

  await dismissNavigation(page);
}

/**
 * Close the navigation drawer, the way an operator has to.
 *
 * ## Which is now usually nothing, and that is the point
 *
 * This run found the panel opening the drawer over the page on a phone's *first*
 * visit: Filament persists `$store.sidebar.isOpen` and seeds it `true` whatever
 * the viewport, so an operator's first arrival in the back office had a 320px
 * drawer and a dark backdrop over it and nothing tappable underneath. It then
 * persisted `false` and never came back, which is why nobody had seen it.
 * Fixed in the panel — `filament/sidebar-first-visit.blade.php` — rather than
 * here, and guarded by the first-visit spec.
 *
 * This stays because an operator can still *open* the drawer, and a spec that
 * has navigated through the menu needs a way back to the page.
 *
 * ## The tap has to land beside the drawer, not on the backdrop's centre
 *
 * The drawer is 320px of a 390px screen, and it shares `z-30` with the backdrop
 * while coming later in the DOM — so it sits on top of it, correctly: a tap on
 * a menu is not a dismissal. The backdrop's *reachable* part is the ~70px strip
 * beside it, and Playwright taps element centres, which would land on the
 * drawer. Hence the explicit point past its right edge.
 */
export async function dismissNavigation(page: Page): Promise<void> {
  const backdrop = page.locator('.fi-sidebar-close-overlay');

  if (!(await backdrop.isVisible())) {
    return;
  }

  const drawer = await page.locator('aside.fi-sidebar').boundingBox();
  const width = page.viewportSize()?.width ?? 0;

  expect(drawer, 'the drawer is over the page but has no box to measure').not.toBeNull();
  expect(
    drawer!.x + drawer!.width,
    `the drawer covers the whole ${width}px screen — there is nowhere left to tap it away`,
  ).toBeLessThan(width);

  await backdrop.tap({ position: { x: (drawer!.x + drawer!.width + width) / 2, y: 80 } });

  await expect(backdrop).toBeHidden();
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
  const measured = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
  }));

  expect(
    measured.scrollWidth,
    `the page scrolls sideways at ${measured.innerWidth}px: the document is ${measured.scrollWidth}px wide`,
  ).toBeLessThanOrEqual(measured.innerWidth + 1);
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

  /*
   * WCAG 2.5.8 (AA): 24×24 CSS pixels.
   *
   * The standard's own number, not whatever the panel currently manages — a
   * floor lowered to fit looks like a checked guarantee and is none. This run
   * found the departures row actions at 20px, and they were fixed
   * (`filament/touch-targets.blade.php`) rather than measured around.
   */
  expect(box!.height, `${name} is ${Math.round(box!.height)}px tall — under the 24px a thumb needs`).toBeGreaterThanOrEqual(24);
  expect(box!.width, `${name} is ${Math.round(box!.width)}px wide — under the 24px a thumb needs`).toBeGreaterThanOrEqual(24);
}
