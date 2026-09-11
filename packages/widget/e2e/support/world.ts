import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Locator, type Page } from '@playwright/test';

/**
 * The seeded world, and how a spec puts the widget in front of a browser
 * (issue 111).
 *
 * The host pages are served by `support/fixture-server.mjs` on a **real second
 * origin**, for the reason recorded there: an intercepted route cannot load a
 * loopback bundle without switching off a browser security check, and a real
 * origin makes the widget's requests genuinely cross-origin so SEC-7's
 * allow-list is exercised instead of stepped around.
 */

/**
 * The seeded operators who can sign in to `/app`, by role (OPS-22).
 *
 * Published by the seed command rather than written down in a spec, so a
 * rename in `DemoTenantSeeder` fails during seeding instead of at a login form.
 */
export interface PanelWorld {
  readonly owner: string;
  readonly manager: string;
  readonly crew: string;
  readonly password: string;
}

export interface World {
  readonly key: string;
  readonly tenant_slug: string;
  readonly product_uuid: string;
  readonly product_slug: string;
  readonly origins: readonly string[];
  readonly panel?: PanelWorld;
}

export const FIXTURE_ORIGIN = `http://127.0.0.1:${process.env.KAIKI_E2E_FIXTURE_PORT ?? 8124}`;

export function world(): World {
  const path = resolve('packages/widget/e2e/.state.json');

  try {
    return JSON.parse(readFileSync(path, 'utf8')) as World;
  } catch {
    throw new Error(
      `No run state at ${path}. It is written by \`php artisan kaiki:e2e-prepare\`, which playwright.config.ts runs before the suite.`,
    );
  }
}

export type Fixture = 'plain' | 'strict-csp' | 'hostile-css';

export interface EmbedOptions {
  readonly mount?: 'booking' | 'list' | 'calendar' | 'enquiry';
  readonly product?: string;
  readonly locale?: 'el' | 'en';
  readonly analytics?: boolean;
  readonly fixture?: Fixture;
}

/** Open a host page with the widget embedded on it. */
export async function embed(page: Page, options: EmbedOptions = {}): Promise<void> {
  const path = options.fixture === undefined || options.fixture === 'plain' ? '/' : `/${options.fixture}`;
  const params = new URLSearchParams();

  if (options.mount) {
    params.set('mount', options.mount);
  }

  if (options.product) {
    params.set('product', options.product);
  }

  if (options.locale) {
    params.set('locale', options.locale);
  }

  if (options.analytics === false) {
    params.set('analytics', 'false');
  }

  const query = params.toString();

  await page.goto(`${FIXTURE_ORIGIN}${path}${query === '' ? '' : `?${query}`}`);
}

/**
 * The widget's shadow root.
 *
 * Playwright pierces an open shadow root for CSS selectors, so everything a
 * spec looks for is addressed from here rather than from the document — which
 * is also the assertion that the boundary exists, since nothing the widget
 * renders is reachable any other way.
 */
export function widget(page: Page): Locator {
  return page.locator('#embed [data-kaiki-widget]').first();
}

/** A calendar day `days` from today, as the widget labels it. */
export function isoDaysAhead(days: number): string {
  return new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
}

/**
 * Press the first bookable day on or after `date` in the widget's month grid.
 *
 * The date step has been a grid since 2026-09-10 rather than a native input,
 * and a date a fortnight out may fall in next month — so this pages forward
 * until it finds one, and says so loudly if four months have nothing.
 */
export async function pickDay(root: Locator, date: string): Promise<string> {
  for (let month = 0; month < 4; month += 1) {
    await expect(root.locator('.kaiki-days')).toBeVisible();

    const labels = await root
      .locator('button.kaiki-day-pick')
      .evaluateAll((buttons) => buttons.map((button) => button.getAttribute('aria-label') ?? ''));
    const hit = labels.find((label) => label.slice(0, 10) >= date);

    if (hit !== undefined) {
      await root.locator(`button.kaiki-day-pick[aria-label="${hit}"]`).click();

      return hit.slice(0, 10);
    }

    const shown = await root.locator('.kaiki-calendar-head strong').textContent();

    await root.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(root.locator('.kaiki-calendar-head strong')).not.toHaveText(shown ?? '');
  }

  throw new Error(`No bookable day on or after ${date} within four months.`);
}

/**
 * Past the extras step when the trip has one, to the button that opens the
 * checkout. A trip with no extras ends on the party step; one with them ends
 * on extras — and either way the last button says where it goes.
 */
export async function reachCheckoutButton(root: Locator): Promise<Locator> {
  const checkout = root.getByRole('button', { name: 'Continue to checkout' });

  if (!(await checkout.isVisible())) {
    await root.getByRole('button', { name: 'Continue', exact: true }).click();
  }

  await expect(checkout).toBeEnabled();

  return checkout;
}

/**
 * Every analytics event the page has seen.
 *
 * Installed before navigation, because `kaiki:ready` fires during mount and a
 * listener attached afterwards would miss it and read as "no events".
 */
export async function collectAnalytics(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const seen: { name: string; detail: unknown }[] = [];

    (window as unknown as { __events: typeof seen }).__events = seen;

    for (const name of [
      'kaiki:ready',
      'kaiki:product-viewed',
      'kaiki:availability-loaded',
      'kaiki:booking-started',
      'kaiki:checkout-started',
      'kaiki:booking-confirmed',
      'kaiki:enquiry-submitted',
      'kaiki:error',
    ]) {
      window.addEventListener(name, (event) => seen.push({ name, detail: (event as CustomEvent).detail }));
    }
  });
}

export async function analyticsEvents(page: Page): Promise<{ name: string; detail: Record<string, unknown> }[]> {
  return page.evaluate(() => (window as unknown as { __events: { name: string; detail: Record<string, unknown> }[] }).__events ?? []);
}
