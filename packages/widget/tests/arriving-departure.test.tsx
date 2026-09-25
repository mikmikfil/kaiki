import { render } from 'preact';
import { act } from 'preact/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { analytics } from '../src/analytics';
import { ApiClient } from '../src/api-client';
import { back, initialStateOn } from '../src/booking/machine';
import { readConfig } from '../src/config';
import { translator } from '../src/i18n';
import { BookingMount, type ProductSummary } from '../src/mounts/booking/BookingMount';

/*
 * The departures calendar's «Κράτηση» (2026-09-25).
 *
 * The hosted calendar links to the trip page with `?date=…&departure=…`, the
 * page hands both to the widget (`data-date`, `data-departure`), and the walk
 * opens on «Άτομα» with the day *and the time* already chosen. If the sailing
 * is no longer on offer the guest is sent back to the day's times instead.
 */

const DEPARTURE = '0b6f3c1e-2d4a-4f8e-9a1b-7c5d3e2f1a09';
const OTHER = '6a1e2b3c-4d5e-4f60-8a7b-9c0d1e2f3a4b';

const product: ProductSummary = {
  mode: 'per_seat',
  title: 'Sailing with sails',
  duration_minutes: 360,
  from_price_formatted: '€65.00',
  age_bands: [{ uuid: 'band-adult', code: 'adult', label: 'Adults', counts_toward_capacity: true }],
  extras: [],
};

function availability(departures: { uuid: string; time: string; seats: number }[]): Response {
  return new Response(
    JSON.stringify({
      data: [
        {
          local_date: '2026-10-10',
          status: 'available',
          departures: departures.map((d) => ({ uuid: d.uuid, window: { local_time: d.time }, seats_available: d.seats })),
        },
      ],
    }),
    { status: 200, headers: { 'Content-Type': 'application/json' } },
  );
}

async function mount(response: Response, departure: string | null = DEPARTURE): Promise<HTMLElement> {
  const host = document.getElementById('host') as HTMLElement;
  const fetchMock = vi.fn(() => Promise.resolve(response));
  const client = new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchMock as unknown as typeof fetch);

  await act(async () => {
    render(
      <BookingMount
        client={client}
        productUuid="product-uuid"
        product={product}
        t={translator('en')}
        analytics={analytics(false, 'test', new EventTarget())}
        locale="en"
        initialDate="2026-10-10"
        initialDeparture={departure}
      />,
      host,
    );
  });

  // The availability read and the patch it causes.
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });

  return host;
}

beforeEach(() => {
  document.body.innerHTML = '<div id="host"></div>';
});

describe('the embed', () => {
  const embed = (value?: string): HTMLScriptElement => {
    const script = document.createElement('script');
    script.src = 'https://api.kaiki.app/widget/v1/kaiki-widget.js';
    script.setAttribute('data-key', 'pk_live_1');

    if (value !== undefined) {
      script.setAttribute('data-departure', value);
    }

    document.body.appendChild(script);

    return script;
  };

  it('reads data-departure when it is a uuid, and only then', () => {
    expect(readConfig(embed(DEPARTURE))?.departure).toBe(DEPARTURE);
    expect(readConfig(embed(` ${DEPARTURE.toUpperCase()} `))?.departure).toBe(DEPARTURE);
    expect(readConfig(embed('not-a-uuid'))?.departure).toBeNull();
    expect(readConfig(embed())?.departure).toBeNull();
  });
});

describe('a walk that arrives with a departure', () => {
  it('starts on the party step with the sailing chosen', () => {
    const state = initialStateOn('2026-10-10', { hasExtras: false }, DEPARTURE);

    expect(state.step).toBe('party');
    expect(state.localDate).toBe('2026-10-10');
    expect(state.departureUuid).toBe(DEPARTURE);
  });

  it('still goes back to the day, keeping it', () => {
    const options = { hasExtras: false };
    const state = back(initialStateOn('2026-10-10', options, DEPARTURE), options);

    expect(state.step).toBe('date');
    expect(state.localDate).toBe('2026-10-10');
  });

  it('opens on «how many» with the day and the time shown', async () => {
    const host = await mount(availability([
      { uuid: OTHER, time: '18:30', seats: 10 },
      { uuid: DEPARTURE, time: '09:30', seats: 1 },
    ]));

    expect(host.textContent).toContain('How many of you?');
    expect(host.querySelector('.kaiki-picked')?.textContent).toContain('09:30');
    expect(host.querySelector('.kaiki-picked')?.textContent).toContain('October');
    // The seats left came with it: one seat, so the stepper stops at one.
    expect(host.textContent).toContain('One seat left.');
  });

  it('goes back to the day\'s times when that sailing is no longer offered', async () => {
    const host = await mount(availability([{ uuid: OTHER, time: '18:30', seats: 10 }]));

    expect(host.textContent).not.toContain('How many of you?');
    expect(host.querySelector('.kaiki-picked')).toBeNull();
  });

  it('opens on the party step as before when only a day was given', async () => {
    const host = await mount(availability([{ uuid: DEPARTURE, time: '09:30', seats: 8 }]), null);

    expect(host.textContent).toContain('How many of you?');
    // No time was chosen, so nothing claims one.
    expect(host.querySelector('.kaiki-picked')).toBeNull();
  });
});
