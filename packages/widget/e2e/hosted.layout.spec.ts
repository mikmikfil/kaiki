import { expect, test } from '@playwright/test';

import { world } from './support/world';

/**
 * The hosted pages start below the header — and the home page still does not.
 *
 * ## Why this is a browser test and not a Pest one
 *
 * What broke was arithmetic between two CSS declarations in different parts of
 * one stylesheet, and it produced a page that returned 200 with every element
 * present in the DOM. Nothing on the server could see it. The trip page's
 * breadcrumb carried `margin-bottom: calc(<the container's gap> * -1 + 1.25rem)`
 * — cancel the gap, add back the 20 pixels it wants — which held for exactly as
 * long as the container had one gap. The moment app-drawn pages were given a
 * tighter one, the compensator was still subtracting the old ninety and the
 * whole article, photograph included, was dragged twenty pixels **above the
 * bottom of the site header** and printed over it.
 *
 * ## The asymmetry is deliberate, so it is asserted
 *
 * `main` has no top padding, because the home page's hero is full-bleed and has
 * to sit flush under the header. Every other page's first element is a
 * breadcrumb, a heading or a paragraph, and those need air. Both halves are
 * checked here: a test that only pinned the trip page would be satisfied by
 * padding `main` and quietly putting a white band above the hero.
 */

/** Elements that overlap are the failure; a hairline apart is also the failure. */
const AIR = 16;

async function box(page: import('@playwright/test').Page, selector: string) {
  const rect = await page.locator(selector).first().boundingBox();

  if (rect === null) {
    throw new Error(`${selector} is not in the page, so its position cannot be asserted.`);
  }

  return rect;
}

test.describe('hosted page layout', () => {
  test('the trip page opens below the header, with the breadcrumb visible', async ({ page }) => {
    const { tenant_slug: tenant, product_slug: product } = world();

    await page.goto(`/${tenant}/${product}?lang=el`);

    const header = await box(page, 'header');
    const crumbs = await box(page, '.crumbs');
    const article = await box(page, '.product');

    // The bug, stated as the assertion that would have caught it: the article
    // was at 59 and the header ended at 79.
    expect(article.y).toBeGreaterThan(header.y + header.height);

    expect(crumbs.y).toBeGreaterThanOrEqual(header.y + header.height + AIR);
    expect(article.y).toBeGreaterThanOrEqual(crumbs.y + crumbs.height + AIR);
  });

  test('the search page opens below the header', async ({ page }) => {
    const { tenant_slug: tenant } = world();

    await page.goto(`/${tenant}/search?lang=el`);

    const header = await box(page, 'header');
    const head = await box(page, '.search-head');

    expect(head.y).toBeGreaterThanOrEqual(header.y + header.height + AIR);
  });

  test('the home page hero stays flush against the header', async ({ page }) => {
    const { tenant_slug: tenant } = world();

    await page.goto(`/${tenant}?lang=el`);

    const header = await box(page, 'header');
    const first = await box(page, 'main .wrap > *');

    // Not "below with air" — flush, to within a pixel of rounding. The hero
    // bleeds to the edges of the page and a gap above it would read as a seam.
    expect(Math.abs(first.y - (header.y + header.height))).toBeLessThanOrEqual(1);
  });
});
