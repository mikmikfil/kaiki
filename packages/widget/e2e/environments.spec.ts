import { expect, test } from '@playwright/test';

import { embed, widget, world } from './support/world';

/**
 * The two host pages WGT-22 names, and one thing each of them must not do
 * (issue 111).
 *
 * > *"The widget must render correctly inside hosts with aggressive global CSS,
 * > inside iframe-heavy page builders, and on pages with a strict CSP. It MUST
 * > NOT require `unsafe-inline`."*
 *
 * Both fixtures are served with real headers by `support/fixture-server.mjs`,
 * which is the only way to test a CSP: a `<meta>` policy is not the same policy,
 * and a policy the test itself relaxes is no test at all.
 */

test.describe('a page with a strict Content-Security-Policy', () => {
  test('renders, with no policy violation and no console error', async ({ page }) => {
    const violations: string[] = [];
    const errors: string[] = [];

    // A blocked style or script reports here and *only* here — the widget
    // itself sees nothing and renders a slightly wrong page in silence, which
    // is exactly the failure that reaches an operator's customers.
    page.on('console', (message) => {
      const text = message.text();

      if (/Content Security Policy|Refused to/i.test(text)) {
        violations.push(text);
      } else if (message.type() === 'error') {
        errors.push(text);
      }
    });

    page.on('pageerror', (error) => errors.push(error.message));

    await embed(page, { mount: 'booking', product: world().product_uuid, fixture: 'strict-csp' });

    const root = widget(page);

    await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();

    expect(violations).toEqual([]);
    expect(errors).toEqual([]);
  });

  test('is styled, which is the half a policy quietly breaks', async ({ page }) => {
    // A widget that mounted but lost its stylesheet passes every "is it
    // visible" assertion and looks like a stack of unstyled inputs. The shadow
    // root's own styles are injected by script; if `style-src` had blocked
    // them, the computed background would be the page's rather than the
    // operator's.
    await embed(page, { mount: 'booking', product: world().product_uuid, fixture: 'strict-csp' });

    const root = widget(page);

    await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();

    const painted = await root.locator('.kaiki-root').evaluate((element) => {
      const styles = getComputedStyle(element);

      return {
        background: styles.backgroundColor,
        radius: styles.borderTopLeftRadius,
        padding: styles.paddingTop,
      };
    });

    expect(painted.background).not.toBe('rgba(0, 0, 0, 0)');
    expect(painted.radius).not.toBe('0px');
    expect(painted.padding).not.toBe('0px');
  });
});

test.describe('a page whose theme styles everything on it', () => {
  test('keeps the host page CSS out of the widget', async ({ page }) => {
    // The fixture shouts at `*`, restyles every `button`, hides `div > div`,
    // shrinks every input to four pixels and sets a 42px root font size. None
    // of it may cross the shadow boundary — that boundary is the whole reason
    // WGT-1 fixes a shadow root rather than a class prefix.
    await embed(page, { mount: 'booking', product: world().product_uuid, fixture: 'hostile-css' });

    const root = widget(page);

    await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();

    const button = root.getByRole('button', { name: 'Continue' });

    await expect(button).toBeVisible();

    const styles = await button.evaluate((element) => {
      const computed = getComputedStyle(element);

      return {
        background: computed.backgroundColor,
        colour: computed.color,
        padding: computed.paddingTop,
        family: computed.fontFamily,
        boxSizing: computed.boxSizing,
      };
    });

    // `#ff00ff` and `#00ff00` are what the host page paints every button.
    expect(styles.background).not.toBe('rgb(255, 0, 255)');
    expect(styles.colour).not.toBe('rgb(0, 255, 0)');
    expect(styles.padding).not.toBe('48px');
    expect(styles.family).not.toContain('Comic Sans');
    // The widget sets `box-sizing: border-box` on everything inside its root;
    // the host page sets `content-box !important` on `*`. An `!important` in
    // the outer document does not reach in.
    expect(styles.boxSizing).toBe('border-box');
  });

  test('is not hidden by a rule that hides every nested div', async ({ page }) => {
    // `div > div { display: none !important }` is not a contrived example: it
    // is what a page builder's own reset does to a container it did not create.
    await embed(page, { mount: 'list', fixture: 'hostile-css' });

    const root = widget(page);

    await expect(root).toBeVisible();
    await expect(root.locator('.kaiki-card').first()).toBeVisible();
  });

  test('does not inherit a 42px root font size', async ({ page }) => {
    // The widget sets its own `font-size` on the host rather than trusting
    // `rem`, because a theme with `:root { font-size: 42px }` — or the 62.5%
    // trick — would otherwise scale every measurement in the bundle.
    await embed(page, { mount: 'list', fixture: 'hostile-css' });

    const size = await widget(page)
      .locator('.kaiki-root')
      .evaluate((element) => Number.parseFloat(getComputedStyle(element).fontSize));

    expect(size).toBeLessThan(24);
  });
});
