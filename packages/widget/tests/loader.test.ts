import { beforeEach, describe, expect, it } from 'vitest';

import { findEmbeds, readConfig } from '../src/config';

/*
 * WGT-3 and WGT-7: the script tag is the whole API.
 *
 * The tests that matter here are the defaults, because they are what an
 * operator gets when they paste the one line from the guide and read no
 * further — and `data-mount` in particular, which changes meaning depending on
 * whether `data-product` is beside it.
 */

function embed(attributes: Record<string, string>, src = 'https://api.kaiki.app/widget/v1/kaiki-widget.js'): HTMLScriptElement {
  const script = document.createElement('script');

  script.src = src;

  for (const [name, value] of Object.entries(attributes)) {
    script.setAttribute(name, value);
  }

  document.body.appendChild(script);

  return script;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('finding embeds', () => {
  it('finds the widget script and ignores every other script on the page', () => {
    embed({ 'data-key': 'pk_test_1' });

    const analytics = document.createElement('script');
    analytics.src = 'https://www.googletagmanager.com/gtag/js';
    analytics.setAttribute('data-key', 'GTM-XYZ');
    document.body.appendChild(analytics);

    // A host page has a dozen scripts and some of them carry a `data-key` of
    // their own. Matching on the filename as well is what keeps the widget from
    // trying to boot itself out of somebody's tag manager.
    expect(findEmbeds(document)).toHaveLength(1);
  });

  it('refuses an embed with no key rather than rendering an error at a guest', () => {
    const script = embed({ 'data-key': '  ' });

    // The operator's misconfiguration belongs in their console, not on their
    // page in front of somebody trying to book a boat.
    expect(readConfig(script)).toBeNull();
  });
});

describe('the mount default', () => {
  it('is booking when a product is named', () => {
    const config = readConfig(embed({ 'data-key': 'pk_test_1', 'data-product': 'uuid-1' }));

    expect(config?.mount).toBe('booking');
  });

  it('is list when no product is named', () => {
    expect(readConfig(embed({ 'data-key': 'pk_test_1' }))?.mount).toBe('list');
  });

  it('honours an explicit mount over both defaults', () => {
    const config = readConfig(embed({ 'data-key': 'pk_test_1', 'data-product': 'uuid-1', 'data-mount': 'calendar' }));

    expect(config?.mount).toBe('calendar');
  });

  it('falls back rather than refusing when the mount is not one of the four', () => {
    // A typo in a page builder should not blank the widget. `booking` is the
    // right guess here because a product was named.
    const config = readConfig(embed({ 'data-key': 'pk_test_1', 'data-product': 'uuid-1', 'data-mount': 'bookings' }));

    expect(config?.mount).toBe('booking');
  });
});

describe('the rest of the attributes', () => {
  it('reads every documented attribute', () => {
    const config = readConfig(
      embed({
        'data-key': 'pk_test_1',
        'data-product': 'uuid-1',
        'data-locale': 'el',
        'data-theme': 'light',
        'data-category': 'sunset',
        'data-target': '#booking-here',
      }),
    );

    expect(config).toMatchObject({
      key: 'pk_test_1',
      productUuid: 'uuid-1',
      locale: 'el',
      theme: 'light',
      category: 'sunset',
      target: '#booking-here',
      analytics: true,
    });
  });

  it('treats anything but the literal true as analytics off', () => {
    expect(readConfig(embed({ 'data-key': 'pk_1', 'data-analytics': 'false' }))?.analytics).toBe(false);
    expect(readConfig(embed({ 'data-key': 'pk_2', 'data-analytics': 'no' }))?.analytics).toBe(false);
    expect(readConfig(embed({ 'data-key': 'pk_3' }))?.analytics).toBe(true);
  });

  it('takes the API origin from the script it was served by, never from an attribute', () => {
    const config = readConfig(
      embed({ 'data-key': 'pk_test_1', 'data-api': 'https://evil.example' }, 'https://api.kaiki.app/widget/v1/kaiki-widget.js'),
    );

    // A `data-api` would let a compromised page point a live publishable key at
    // somebody else's server. The bundle is served by the platform that owns
    // the API, so the origin is a fact rather than a setting.
    expect(config?.apiBase).toBe('https://api.kaiki.app/api/v1');
  });
});
