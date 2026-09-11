import { render } from 'preact';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { analytics } from '../src/analytics';
import { ApiClient } from '../src/api-client';
import { translator } from '../src/i18n';
import { CalendarMount } from '../src/mounts/calendar/CalendarMount';
import { EnquiryMount } from '../src/mounts/enquiry/EnquiryMount';
import { ListMount } from '../src/mounts/list/ListMount';
import type { MountProps } from '../src/mounts';

/*
 * The three mounts that are not the booking flow (WGT-5).
 *
 * The load-bearing test in this file is the one about a `quote` product, and the
 * issue says why it is written the way it is: *"A `quote` product whose price is
 * hidden by CSS still has the number in the DOM."* So it scans the rendered
 * output for a currency symbol rather than asking whether an element is visible.
 * "We hid the price" and "no price reached the DOM" are different claims, and
 * BKG-24 is the second one.
 */

function client(responder: (url: string, init?: RequestInit) => unknown, status = 200): ApiClient {
  const fetchImpl = vi.fn((url: string, init?: RequestInit) =>
    Promise.resolve(
      new Response(JSON.stringify(responder(String(url), init)), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
    ),
  );

  return new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchImpl as unknown as typeof fetch);
}

function props(overrides: Partial<MountProps> & Pick<MountProps, 'client'>): MountProps {
  return {
    productUuid: 'product-uuid',
    category: null,
    t: translator('en'),
    analytics: analytics(false, '0.0.0'),
    locale: 'en',
    ...overrides,
  };
}

let host: HTMLElement;

beforeEach(() => {
  document.body.innerHTML = '<div id="host"></div>';
  host = document.getElementById('host') as HTMLElement;
});

/**
 * Wait until the mount has stopped saying "loading".
 *
 * `vi.waitFor` rather than a fixed number of microtask turns: the chain is
 * fetch, then `json()`, then a state update Preact schedules, and counting turns
 * is how a test passes on one machine and fails on a slower one. The first
 * version of this file counted turns and was flaky in exactly that way.
 */
async function settle(): Promise<void> {
  await vi.waitFor(() => expect(host.textContent ?? '').not.toContain('Loading'));
}

/** Wait for a request to have been made, for the tests that assert on a body. */
async function sent(check: () => void): Promise<void> {
  await vi.waitFor(check);
}

const trips = [
  {
    uuid: 'uuid-sunset',
    slug: 'sunset',
    title: 'Sunset cruise',
    summary: 'Three hours in the Saronic.',
    category: 'sunset',
    mode: 'per_seat',
    duration_minutes: 180,
    from_price_cents: 4500,
    from_price_formatted: '45,00 €',
    booking_url: 'https://book.kaiki.app/aegean-blue/sunset',
    meeting_point: { name: 'Zea Marina' },
  },
  {
    uuid: 'uuid-charter',
    slug: 'charter',
    title: 'Private charter',
    summary: null,
    category: 'custom',
    mode: 'quote',
    duration_minutes: 480,
    // The API already refuses to price a quote product. The widget is the
    // second lock on the same door.
    from_price_cents: null,
    from_price_formatted: null,
    booking_url: null,
    meeting_point: null,
  },
];

describe('the list mount', () => {
  it('renders a card per trip with the from-price', async () => {
    render(<ListMount {...props({ client: client(() => ({ data: trips })) })} />, host);
    await settle();

    expect(host.textContent).toContain('Sunset cruise');
    expect(host.textContent).toContain('45,00 €');
    expect(host.querySelectorAll('.kaiki-card')).toHaveLength(2);
  });

  it('shows no price at all for a quote product', async () => {
    render(<ListMount {...props({ client: client(() => ({ data: [trips[1]] })) })} />, host);
    await settle();

    // The honest test: scan the markup for a currency symbol and for digits
    // that could be a price. Hiding the element would pass a visibility check
    // and fail this one.
    expect(host.innerHTML).not.toContain('€');
    expect(host.innerHTML).not.toContain('45');
    expect(host.textContent).toContain('Price on request');
  });

  it('offers a tab per category the operator actually sells', async () => {
    render(<ListMount {...props({ client: client(() => ({ data: trips })) })} />, host);
    await settle();

    const tabs = [...host.querySelectorAll('[role="tab"]')].map((tab) => tab.textContent);

    // "All trips" plus the two categories in the response — not the six the
    // enum has, because a fleet that sells two kinds of day wants two tabs.
    expect(tabs).toHaveLength(3);
  });

  it('filters to one category when a tab is chosen', async () => {
    render(<ListMount {...props({ client: client(() => ({ data: trips })) })} />, host);
    await settle();

    await vi.waitFor(() => expect(host.querySelectorAll('[role="tab"]').length).toBeGreaterThan(1));

    const sunsetTab = [...host.querySelectorAll<HTMLButtonElement>('[role="tab"]')].find(
      (tab) => tab.textContent === 'Sunset',
    );

    sunsetTab?.click();

    await vi.waitFor(() => expect(host.querySelectorAll('.kaiki-card')).toHaveLength(1));
    expect(host.textContent).toContain('Sunset cruise');
  });

  it('narrows the fetch itself when the embed names a category', async () => {
    const seen: string[] = [];

    const api = client((url) => {
      seen.push(url);

      return { data: trips };
    });

    render(<ListMount {...props({ client: api, category: 'sunset' })} />, host);

    await vi.waitFor(() => expect(seen.length).toBeGreaterThan(0));

    expect(seen[0] ?? '').toContain('category=sunset');
  });
});

describe('the list mount photographs', () => {
  const card = (overrides: Record<string, unknown>) => ({
    uuid: 'uuid-x',
    slug: 'x',
    title: 'Sunset cruise',
    summary: null,
    category: 'sunset',
    mode: 'per_seat',
    duration_minutes: 180,
    from_price_cents: 4500,
    from_price_formatted: '€45.00',
    booking_url: 'https://book.kaiki.app/aegean-blue/sunset',
    ...overrides,
  });

  it('shows each trip photograph at the top of its card', async () => {
    const api = client(() => ({
      data: [card({ uuid: 'with', hero_image_url: 'https://book.kaiki.app/storage/products/1/sunset.jpg' })],
    }));

    render(<ListMount {...props({ client: api })} />, host);
    await settle();

    const image = host.querySelector<HTMLImageElement>('.kaiki-card .kaiki-card-image');

    expect(image?.getAttribute('src')).toBe('https://book.kaiki.app/storage/products/1/sunset.jpg');
    // The title under it names the trip; the photo is not read out twice.
    expect(image?.getAttribute('alt')).toBe('');
    expect(image?.getAttribute('loading')).toBe('lazy');
  });

  it('draws no empty frame for a trip without a photograph', async () => {
    render(<ListMount {...props({ client: client(() => ({ data: [card({ hero_image_url: null })] })) })} />, host);
    await settle();

    expect(host.querySelector('.kaiki-card')).not.toBeNull();
    expect(host.querySelector('.kaiki-card-image')).toBeNull();
  });
});

describe('the calendar mount', () => {
  /**
   * Two days in the **current** month, because that is the month the mount
   * opens on.
   *
   * The fixture used to be two dates in July 2026, which the grid only draws
   * for anybody running the suite in July 2026 — the assertions passed anyway,
   * on the words in the legend, which is a test that measures nothing.
   */
  const first = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-01`;
  const second = first.replace(/01$/, '02');

  const days = [
    { local_date: first, status: 'available' },
    { local_date: second, status: 'sold_out' },
  ];

  it('shows availability and never a price', async () => {
    render(<CalendarMount {...props({ client: client(() => ({ data: days })) })} />, host);
    await settle();

    expect(host.textContent).toContain('Available');
    expect(host.textContent).toContain('Sold out');
    // WGT-5: this mount goes on a page that has already sold the trip. A price
    // here would compete with the one the operator's own page shows.
    expect(host.innerHTML).not.toContain('€');
  });

  it('draws a month rather than a list of the days that sail', async () => {
    render(<CalendarMount {...props({ client: client(() => ({ data: days })) })} />, host);
    await settle();

    await vi.waitFor(() => expect(host.querySelector('.kaiki-day-available')).not.toBeNull());

    // Seven headings, and a cell for every day of the month plus the blanks
    // that push the first onto its own weekday. The old mount rendered only
    // the days the endpoint returned, so a month with two sailings was two
    // boxes in a row and nothing to say which days they were.
    const length = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate();

    expect(host.querySelectorAll('.kaiki-weekdays span')).toHaveLength(7);
    expect(host.querySelectorAll('.kaiki-day').length).toBeGreaterThanOrEqual(length);
    // A day the endpoint said nothing about is «not sailing», not a hole.
    expect(host.querySelectorAll('.kaiki-day-unavailable').length).toBeGreaterThan(0);
  });

  it('says the status in words as well as in colour', async () => {
    render(<CalendarMount {...props({ client: client(() => ({ data: days })) })} />, host);
    await settle();

    await vi.waitFor(() => expect(host.querySelector('.kaiki-day-available')).not.toBeNull());

    // A11Y-1: a calendar that only shades cannot be read by a colour-blind
    // guest. A grid cell has no room for the word, so the status is in the
    // accessible name, the legend says what the two colours mean, and a
    // sold-out day is struck through as well as red.
    expect(host.querySelector('.kaiki-day-available')?.getAttribute('aria-label')).toContain('Available');
    expect(host.querySelector('.kaiki-day-sold_out')?.getAttribute('aria-label')).toContain('Sold out');
    expect(host.querySelector('.kaiki-legend')?.textContent).toContain('Available');
  });

  it('keeps the days read-only unless the embed asks for them to lead somewhere', async () => {
    render(<CalendarMount {...props({ client: client(() => ({ data: days })) })} />, host);
    await settle();

    await vi.waitFor(() => expect(host.querySelector('.kaiki-day-available')).not.toBeNull());

    expect(host.querySelector('.kaiki-day-pick')).toBeNull();
  });

  it('makes an open day a button when linked to the trip page, and leaves a full one alone', async () => {
    const api = client((url) =>
      url.includes('/availability')
        ? { data: days }
        : // The payload's real shape: the trip page at the top as `booking_url`,
          // and `canonical_url` only under `seo`. A fake with `canonical_url` at
          // the top is how this shipped read-only once.
          {
            data: {
              uuid: 'product-uuid',
              booking_url: 'https://book.kaiki.app/aegean-blue/sunset',
              seo: { canonical_url: 'https://book.kaiki.app/aegean-blue/sunset' },
            },
          },
    );

    render(<CalendarMount {...props({ client: api, link: 'trip' })} />, host);
    await settle();

    // WGT-5 as amended 2026-09-11: the available day can be pressed, the sold
    // out one is still drawn and labelled and does nothing.
    await vi.waitFor(() => expect(host.querySelector('.kaiki-day-available .kaiki-day-pick')).not.toBeNull());
    expect(host.querySelector('.kaiki-day-sold_out .kaiki-day-pick')).toBeNull();
  });

  it('serves a month it has already fetched from memory', async () => {
    const seen: string[] = [];

    const api = client((url) => {
      seen.push(url);

      return { data: days };
    });

    render(<CalendarMount {...props({ client: api })} />, host);

    await vi.waitFor(() => expect(seen.length).toBe(1));

    const next = host.querySelectorAll<HTMLButtonElement>('.kaiki-calendar-step')[1];
    const previous = host.querySelectorAll<HTMLButtonElement>('.kaiki-calendar-step')[0];

    next?.click();
    await vi.waitFor(() => expect(seen.length).toBe(2));
    previous?.click();
    await new Promise((resolve) => setTimeout(resolve, 20));

    // Two clicks, two months: forward is a new range and back is one the
    // client already holds (WGT-17).
    expect(seen).toHaveLength(2);
  });
});

describe('the enquiry mount', () => {
  /**
   * Fill the form the way a person would, then let Preact catch up.
   *
   * The `input` events reach the component, but the state update they cause is
   * scheduled rather than immediate — submitting in the same tick posts the
   * *initial* state, which is an empty message and a test that fails for a
   * reason that has nothing to do with what it is testing.
   */
  async function fill(form: HTMLElement): Promise<void> {
    const set = (selector: string, value: string) => {
      const field = form.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector);

      if (field !== null) {
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
      }
    };

    set('input[type="text"]', 'Γιώργος Νικολάου');
    set('input[type="email"]', 'giorgos@example.com');
    set('textarea', 'Είμαστε έξι άτομα, υπάρχει διαθεσιμότητα;');

    await new Promise((resolve) => setTimeout(resolve, 0));
  }

  it('sends the honeypot and the timing field', async () => {
    let body: Record<string, unknown> = {};

    const api = client((_url, init) => {
      body = JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>;

      return { data: { uuid: 'enquiry-uuid' } };
    });

    render(<EnquiryMount {...props({ client: api })} />, host);
    await fill(host);

    host.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await sent(() => expect(Object.keys(body).length).toBeGreaterThan(0));

    // #85's two cheap filters. `company_website` empty from a person, and
    // `form_rendered_at` from when the form was drawn — a submission under
    // three seconds later is refused server-side.
    expect(body['company_website']).toBe('');
    expect(typeof body['form_rendered_at']).toBe('string');
    expect(body['product_uuid']).toBe('product-uuid');
    expect(body['message']).toContain('έξι άτομα');
  });

  it('hides the honeypot from people without hiding it from a form filler', () => {
    render(<EnquiryMount {...props({ client: client(() => ({ data: {} })) })} />, host);

    const trap = host.querySelector('.kaiki-trap');
    const input = trap?.querySelector('input');

    expect(trap?.getAttribute('aria-hidden')).toBe('true');
    expect(input?.getAttribute('tabindex')).toBe('-1');
    // Off-screen rather than `display: none`: a filler that skips hidden inputs
    // would skip the trap too, which is the whole point of the field.
    expect(host.innerHTML).not.toContain('display:none');
  });

  it('renders the API refusal rather than a sentence of its own', async () => {
    const api = client(
      () => ({ error: { code: 'enquiry_rejected', message: 'Δεν μπορέσαμε να δεχτούμε το μήνυμα.' } }),
      422,
    );

    render(<EnquiryMount {...props({ client: api })} />, host);
    await fill(host);

    host.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await sent(() => expect(host.querySelector('[role="alert"]')).not.toBeNull());

    // §4.1: the envelope already carries a sentence in the guest's language.
    // Replacing it with "your message failed" would swap a specific answer for
    // a vague one, and would drift the day the server's changed.
    expect(host.textContent).toContain('enquiry_rejected');
  });
});
