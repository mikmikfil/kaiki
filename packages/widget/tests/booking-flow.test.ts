import { describe, expect, it, vi } from 'vitest';

import { ApiClient } from '../src/api-client';
import { BookingApi, isSoldOut } from '../src/booking/api';
import { pollForConfirmation } from '../src/booking/confirmation';
import { holdState, WARN_AT_MS } from '../src/booking/countdown';
import { draftFingerprint, IdempotencyKeys } from '../src/booking/idempotency';
import { initialState, type BookingState } from '../src/booking/machine';

/*
 * The four rules of #107 that are not about a form: the idempotency key, the
 * hold countdown, the sold-out refusal and the confirmation poll.
 *
 * Each of them fails silently if implemented backwards — a fresh key per attempt
 * double-books on a flaky connection while every server test still passes; a
 * poll that claims "confirmed" on a timeout tells a guest their money moved on
 * the strength of a redirect. So each of them is asserted directly rather than
 * through a rendered component.
 */

function state(overrides: Partial<BookingState> = {}): BookingState {
  return { ...initialState(), localDate: '2026-07-18', pax: { adult: 2 }, ...overrides };
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('the idempotency key', () => {
  it('is the same on a retry of the same intention', () => {
    const keys = new IdempotencyKeys();
    const fingerprint = draftFingerprint(state());

    const first = keys.keyFor('draft', fingerprint);
    const retry = keys.keyFor('draft', fingerprint);

    // §3.4's whole mechanism. A fresh key per attempt is two intentions as far
    // as the server is concerned, and two bookings as far as the guest is.
    expect(retry).toBe(first);
  });

  it('is a new key once the guest changes what they are booking', () => {
    const keys = new IdempotencyKeys();

    const first = keys.keyFor('draft', draftFingerprint(state()));
    const changed = keys.keyFor('draft', draftFingerprint(state({ pax: { adult: 3 } })));

    expect(changed).not.toBe(first);
  });

  it('ignores fields that do not make it a different booking', () => {
    const keys = new IdempotencyKeys();

    const first = keys.keyFor('draft', draftFingerprint(state()));

    // A band nobody booked is not a different intention, and minting a new key
    // for it would be the per-request mistake wearing a disguise.
    const sameIntention = keys.keyFor('draft', draftFingerprint(state({ pax: { adult: 2, infant: 0 } })));

    expect(sameIntention).toBe(first);
  });

  it('keeps one intention while another is renewed', () => {
    const keys = new IdempotencyKeys();

    const cancel = keys.keyFor('cancel', 'booking-uuid');

    keys.keyFor('draft', draftFingerprint(state()));
    keys.keyFor('draft', draftFingerprint(state({ pax: { adult: 4 } })));

    // A new draft must not invalidate another intention's key. Only the slots
    // of the intention whose fingerprint moved are cleared.
    expect(keys.keyFor('cancel', 'booking-uuid')).toBe(cancel);
  });

  it('sends the key as a header on every write', async () => {
    const fetchMock = vi.fn(() =>
      Promise.resolve(
        jsonResponse({
          data: {
            uuid: 'b-1',
            reference: 'KAI-1',
            manage_token: 'tok',
            checkout_url: 'https://book.kaiki.app/c/tok',
            status: 'draft',
          },
        }),
      ),
    );

    const client = new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchMock as unknown as typeof fetch);
    const api = new BookingApi(client);

    await api.createDraft(state(), 'product-uuid', 'el');

    const init = (fetchMock.mock.calls as unknown as [string, RequestInit][])[0]?.[1];
    const headers = init?.headers as Record<string, string>;

    expect(headers['Idempotency-Key']).toBeTruthy();
  });

  it('reads the checkout url out of the 201, where it appears exactly once', async () => {
    const fetchMock = vi.fn(() =>
      Promise.resolve(
        jsonResponse({
          data: {
            uuid: 'b-1',
            reference: 'KAI-1',
            manage_token: 'tok',
            checkout_url: 'https://book.kaiki.app/c/tok',
            status: 'draft',
          },
        }),
      ),
    );

    const client = new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchMock as unknown as typeof fetch);
    const draft = await new BookingApi(client).createDraft(state(), 'product-uuid', 'el');

    // ADR-0030: the walk ends by sending the guest here. The widget never
    // assembles the address itself — a URL built in a browser out of a config
    // value is a guess about somebody else's deployment, and a custom domain
    // makes it a wrong one.
    expect(draft.checkoutUrl).toBe('https://book.kaiki.app/c/tok');
  });
});

describe('the hold countdown', () => {
  const now = Date.parse('2026-07-18T09:00:00Z');

  it('counts down while the hold is alive', () => {
    const hold = holdState('2026-07-18T09:10:00Z', now);

    expect(hold.phase).toBe('live');
    expect(hold.label).toBe('10:00');
  });

  it('warns at two minutes, and not at two minutes and one second', () => {
    expect(holdState(new Date(now + WARN_AT_MS).toISOString(), now).phase).toBe('warning');
    expect(holdState(new Date(now + WARN_AT_MS + 1000).toISOString(), now).phase).toBe('live');
  });

  it('is expired at zero, never negative', () => {
    const hold = holdState('2026-07-18T08:59:00Z', now);

    expect(hold.phase).toBe('expired');
    expect(hold.remainingMs).toBe(0);
    expect(hold.label).toBe('0:00');
  });

  it('treats an unparseable deadline as no hold rather than as expired', () => {
    // A widget that told a guest their seats were gone because of a date format
    // would be inventing the one thing it must never invent.
    expect(holdState('not a date', now).phase).toBe('live');
  });
});

describe('the sold-out refusal', () => {
  it('is recognised by its contract code, not by its status', () => {
    expect(isSoldOut({ code: 'insufficient_capacity', status: 409 })).toBe(true);
    // Another 409 is a different conversation — `price_changed`, for one.
    expect(isSoldOut({ code: 'price_changed', status: 409 })).toBe(false);
    expect(isSoldOut(new Error('boom'))).toBe(false);
  });

  it('reaches the caller as an error rather than as an empty result', async () => {
    const fetchMock = vi.fn(() => Promise.resolve(jsonResponse({ error: { code: 'insufficient_capacity' } }, 409)));

    const client = new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchMock as unknown as typeof fetch);
    const api = new BookingApi(client);

    await expect(api.createDraft(state(), 'product-uuid', 'el')).rejects.toMatchObject({
      code: 'insufficient_capacity',
    });

    // AVL-39: one attempt. A write is never retried, and least of all this one.
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});

describe('the confirmation poll', () => {
  function client(statuses: string[]): ApiClient {
    let call = 0;

    const fetchMock = vi.fn(() => {
      const status = statuses[Math.min(call, statuses.length - 1)] ?? 'pending_payment';

      call += 1;

      return Promise.resolve(jsonResponse({ data: { status, reference: 'KAI-7F3K2' } }));
    });

    return new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchMock as unknown as typeof fetch);
  }

  it('reports confirmed once the webhook has landed', async () => {
    const result = await pollForConfirmation(client(['pending_payment', 'pending_payment', 'confirmed']), 'b-1', 'tok', {
      sleep: async () => {},
    });

    expect(result.outcome).toBe('confirmed');
    expect(result.reference).toBe('KAI-7F3K2');
    expect(result.attempts).toBe(3);
  });

  it('reports pending after sixty seconds rather than claiming confirmed', async () => {
    let clock = 0;

    const result = await pollForConfirmation(client(['pending_payment']), 'b-1', 'tok', {
      now: () => clock,
      sleep: async () => {
        clock += 3_000;
      },
    });

    // WGT-20, and the note the issue makes about it: showing "confirmed" on a
    // timeout would be telling a guest their money moved on the strength of a
    // redirect. The webhook is what means the money moved.
    expect(result.outcome).toBe('pending');
  });

  it('stops early when the booking came back cancelled', async () => {
    const result = await pollForConfirmation(client(['cancelled']), 'b-1', 'tok', { sleep: async () => {} });

    expect(result.outcome).toBe('cancelled');
    expect(result.attempts).toBe(1);
  });

  it('reads past the cache, because the answer is expected to change', async () => {
    const fetchMock = vi.fn(() => Promise.resolve(jsonResponse({ data: { status: 'pending_payment' } })));
    const api = new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchMock as unknown as typeof fetch);

    let clock = 0;

    await pollForConfirmation(api, 'b-1', 'tok', {
      now: () => clock,
      sleep: async () => {
        clock += 20_000;
      },
      timeoutMs: 60_000,
    });

    // WGT-17's sixty-second cache is exactly the length of this poll, so a
    // cached read would re-read the first response and conclude nothing ever
    // happened.
    expect(fetchMock.mock.calls.length).toBeGreaterThan(1);
  });
});
