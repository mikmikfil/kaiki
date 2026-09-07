import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { embed, widget, world } from './support/world';

/**
 * The keyboard walk and the automated scan (A11Y-1, WGT-21, issue 111).
 *
 * ## A scan is a floor, not a ceiling
 *
 * axe finds perhaps a third of what is wrong with a page, and none of what is
 * wrong with a *flow*. So this file has both: the scan on each mount, and a
 * complete booking driven **by keyboard alone** — which is the half that finds
 * a focus trap, an unreachable control or a step that moves the cursor
 * somewhere nobody can see it.
 *
 * ## Scanned inside the shadow root, on purpose
 *
 * axe descends into an open shadow root, so `include`ing the host element scans
 * the widget and not the fixture page around it. A failure here is ours; the
 * operator's own page is their business and would otherwise drown the result.
 */

const MOUNTS = ['list', 'calendar', 'enquiry', 'booking'] as const;

for (const mount of MOUNTS) {
  test(`the ${mount} mount has no automatically detectable accessibility failures`, async ({ page }) => {
    await embed(page, { mount, product: world().product_uuid });

    await expect(widget(page)).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('#embed')
      // WCAG 2.1 A and AA, which is what A11Y-1 names. `best-practice` is
      // deliberately absent: it flags things that are opinions, and a gate that
      // fails on an opinion is a gate somebody switches off.
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([]);
  });
}

test('a guest can complete the whole booking without touching a mouse', async ({ page }) => {
  // WGT-21. Nothing below clicks, fills or focuses anything: every control is
  // reached by `Tab` and operated by `Enter` or `Space`. If a control cannot be
  // tabbed to, `tabTo` gives up after forty presses and says which one.
  await embed(page, { mount: 'booking', product: world().product_uuid });

  const root = widget(page);

  await expect(root.getByRole('heading', { name: 'Pick a date' })).toBeVisible();

  const date = new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10);
  const [year, month, day] = date.split('-');

  await tabTo(page, 'input[type="date"]');
  // A native date input has no value to insert into — it is three segments, and
  // a keyboard user fills it by typing digits in the browser locale's order.
  // The run pins `en-US`, so that order is month, day, year.
  await page.keyboard.type(`${month}${day}${year}`);

  await advance(page, root, 'How many of you?');

  await tabTo(page, 'input[type="number"]');
  await page.keyboard.type('2');

  await advance(page, root, 'Your details');

  await tabTo(page, 'input[autocomplete="name"]');
  await page.keyboard.type('Maria Papadopoulou');
  await page.keyboard.press('Tab');
  await page.keyboard.type('maria@example.test');
  await page.keyboard.press('Tab');
  await page.keyboard.type('+30 210 000 0000');

  // The terms checkbox, ticked with the space bar like any other checkbox.
  await tabTo(page, 'input[type="checkbox"]');
  await page.keyboard.press('Space');

  await expect(root.getByRole('checkbox')).toBeChecked();

  await advance(page, root, 'Check and pay');

  // Reaching the pay button by keyboard is where the walk ends. Pressing it is
  // the booking spec's job, and doing it twice would take two more seats out of
  // a departure with a real capacity.
  await tabTo(page, 'button', 'Pay and confirm');
});

/**
 * Tab to `Continue` and press it.
 *
 * A separate step rather than `Enter` in the field, because **the widget has no
 * `<form>`**: there is nothing for `Enter` to submit, so a keyboard user tabs to
 * the button like any other control. That is a legitimate design — a form
 * element inside somebody else's page can be caught by their submit handler —
 * and it is the actual path a keyboard user takes, which is what this file is
 * for.
 */
async function advance(
  page: import('@playwright/test').Page,
  root: import('@playwright/test').Locator,
  nextHeading: string,
): Promise<void> {
  await tabTo(page, 'button', 'Continue');
  await page.keyboard.press('Enter');

  await expect(root.getByRole('heading', { name: nextHeading })).toBeVisible();
}

/** Tab until the focused element matches, so nothing is reached by a click. */
async function tabTo(page: import('@playwright/test').Page, selector: string, text?: string): Promise<void> {
  for (let press = 0; press < 40; press += 1) {
    const matched = await page.evaluate(
      ({ selector: sel, text: wanted }) => {
        const active = (document.activeElement?.shadowRoot?.activeElement ?? document.activeElement) as HTMLElement | null;

        if (active === null || !active.matches(sel) || active.hasAttribute('disabled')) {
          return false;
        }

        return wanted === undefined || (active.textContent ?? '').includes(wanted);
      },
      { selector, text },
    );

    if (matched) {
      return;
    }

    await page.keyboard.press('Tab');
  }

  throw new Error(`Tabbed forty times and never reached ${selector}${text === undefined ? '' : ` ("${text}")`}.`);
}

/** Press a key until the expected thing appears, or give up loudly. */
async function pressUntil(
  page: import('@playwright/test').Page,
  key: string,
  target: () => import('@playwright/test').Locator,
): Promise<void> {
  for (let press = 0; press < 12; press += 1) {
    if (await target().isVisible()) {
      return;
    }

    await page.keyboard.press(key);
    await page.waitForTimeout(150);
  }

  await expect(target()).toBeVisible();
}
