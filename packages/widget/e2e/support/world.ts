import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Locator, Page } from '@playwright/test';

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

export interface World {
  readonly key: string;
  readonly tenant_slug: string;
  readonly product_uuid: string;
  readonly product_slug: string;
  readonly origins: readonly string[];
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
