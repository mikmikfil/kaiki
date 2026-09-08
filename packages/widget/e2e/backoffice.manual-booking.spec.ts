import { expect, test } from '@playwright/test';

import { expectNoHorizontalScroll, expectTappable, signIn } from './support/panel';

/**
 * BKG-30's manual booking, taken on a phone (OPS-22).
 *
 * ## The one form that is *always* filled in one-handed
 *
 * Every other panel screen has a desk somewhere behind it. This one does not:
 * it is the booking taken standing on a quay with somebody's card in the other
 * hand, or on the phone walking back to the office. If any part of it needs a
 * sideways scroll to reach, the operator writes the name on their hand instead
 * and types it in later — which is how a booking goes missing.
 *
 * ## Half the form does not exist until a trip is chosen
 *
 * `departure_id` and the pax repeater are `visible(fn () => $get('product_id')
 * !== null)`. That makes the widest moment of this page the moment *after* the
 * first choice, when Livewire re-renders a section that was not measured on
 * load — so the layout is asserted twice, on either side of that choice. A
 * spec that only looked at the empty form would be checking the easy half.
 */

test.beforeEach(async ({ page }) => {
  await signIn(page);
  await page.goto('/app/bookings/create');
});

test('the form opens on a phone without pushing the page sideways', async ({ page }) => {
  // Four sections: trip, guest, price, payment. Filament renders the frame
  // before Livewire settles, so waiting on all four is waiting on the form.
  await expect(page.locator('.fi-section')).toHaveCount(4);

  await expectNoHorizontalScroll(page);
});

test('the guest fields are reachable and take what is typed into them', async ({ page }) => {
  await expect(page.locator('#data\\.guest_name')).toBeVisible();

  for (const [selector, name] of [
    ['#data\\.guest_name', 'the guest name'],
    ['#data\\.guest_email', 'the guest email'],
    ['#data\\.guest_phone', 'the guest phone'],
    ['#data\\.special_requests', 'the special requests box'],
  ] as const) {
    await expectTappable(page, selector, name);
  }

  // Visible and enabled is not the same as usable: a field covered by a
  // sticky header takes the tap and never receives the text. Typing into it is
  // the only assertion that tells the two apart.
  await page.locator('#data\\.guest_name').fill('Δοκιμή Επιβάτη');
  await page.locator('#data\\.guest_email').fill('guest@example.test');

  await expect(page.locator('#data\\.guest_name')).toHaveValue('Δοκιμή Επιβάτη');
  await expect(page.locator('#data\\.guest_email')).toHaveValue('guest@example.test');
});

test('the money and settlement controls are reachable', async ({ page }) => {
  // BKG-31's adjustment and BKG-33's cash-or-bank choice. Both are at the
  // bottom of a long form, which on a phone is where controls get lost.
  await expectTappable(page, '#data\\.discount_cents', 'the discount field');
  await expectTappable(page, '#data\\.override_total_cents', 'the agreed-total field');
  await expectTappable(page, '#data\\.adjustment_reason', 'the reason box');
  await expectTappable(page, '#data\\.paid_by', 'the payment method');

  // The button that commits the whole thing. Reaching everything else and not
  // this would be a form an operator can fill in and cannot submit.
  await expectTappable(page, 'form .fi-form-actions button[type="submit"]', 'the create button');
});

test('choosing a trip reveals the departure and pax fields, still within the width', async ({ page }) => {
  /*
   * The product select is `searchable()`, so Filament hands it to Choices.js:
   * the native `<select>` is zero-sized and the thing a thumb hits is the
   * `.choices` combobox drawn over it. Addressing the native element here
   * would assert a control nobody can tap.
   */
  const combobox = page.locator('.choices').first();

  await expectTappable(page, '.choices', 'the trip select');

  await combobox.tap();

  const option = page.locator('.choices__list--dropdown .choices__item').first();

  await expect(option).toBeVisible();
  await option.click();

  // Livewire redraws the section; `departure_id` only exists afterwards.
  await expect(page.locator('#data\\.departure_id')).toBeAttached();
  await expect(page.locator('.fi-fo-repeater')).toBeVisible();

  // The widest the page ever gets, and the moment nothing measured it.
  await expectNoHorizontalScroll(page);

  await expectTappable(page, '.fi-fo-repeater input[id$="qty"]', 'the passenger count');
});
