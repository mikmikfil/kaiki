import { expect, test } from '@playwright/test';

import { signIn } from './support/panel';
import { world } from './support/world';

/**
 * «Αποθήκευση» on a trip actually saves (2026-09-22).
 *
 * ## The bug this exists for made no sound
 *
 * The trip form embeds relation managers — price lists, extras, schedule rules
 * — inside its tabs, and each of them renders a `<form>`. A nested `<form>`
 * ends the outer one as far as the browser is concerned, so the page's own save
 * button, which comes after them, belonged to no form at all. Clicking it
 * issued **no request**: no error, no notification, no saved trip. The operator
 * only found out on the next page load, with their edit gone.
 *
 * Every server test passed throughout, because nothing on the server was wrong.
 * This is the kind of failure only a browser can see, which is why it is here
 * and not in Pest.
 *
 * ## It asserts the round trip, not the markup
 *
 * The fix is one `formId('form')`, and asserting *that* would pass again the
 * day somebody restructures the page and breaks it a different way. So the
 * assertion is the operator's: change a number, save it, load the page again,
 * and the number is the one you typed.
 */

test('an edit to a trip survives the save button and a reload', async ({ page }) => {
  await signIn(page);

  const run = world();

  await page.goto(`/app/products/${run.product_uuid}/edit`);

  // «Πότε φεύγει» holds the capacity. Clicked rather than reached by `?tab=`,
  // the way an operator moves between tabs — and a reload would be a different
  // test, since it is exactly what this spec is checking survives.
  await page.getByRole('tab', { name: 'Πότε φεύγει' }).first().click();

  const maxPax = page.locator('#data\\.max_pax');

  await expect(maxPax).toBeVisible();

  const before = Number((await maxPax.inputValue()) || '0');
  const after = String(before === 18 ? 17 : 18);

  await maxPax.fill(after);

  // Whatever the trip's status calls it: a draft saves «ως πρόχειρη».
  await page.getByRole('button', { name: /^Αποθήκευση/ }).first().click();

  // The panel says it saved…
  await expect(page.locator('.fi-no-notification')).toBeVisible({ timeout: 15_000 });

  // …and the trip agrees, which is the half that was missing.
  await page.reload();
  await page.getByRole('tab', { name: 'Πότε φεύγει' }).first().click();

  await expect(page.locator('#data\\.max_pax')).toHaveValue(after);
});
