import { describe, expect, it, vi } from 'vitest';

import { ApiClient } from '../src/api-client';

/*
 * WGT-16 and WGT-17, which are two rules a widget gets wrong in opposite
 * directions.
 *
 * Retry too eagerly and a guest holds two seats on the same boat; retry too
 * little and a phone on one bar of signal shows an error where a booking form
 * belongs. The line is drawn at the **method**, and these tests are what keeps
 * it there.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/**
 * A fetch that hands back a **fresh** response every call.
 *
 * `mockResolvedValue(jsonResponse(...))` returns the same `Response` object
 * twice, and a body can only be read once — the second read throws, the client
 * correctly reports a network error, and the test fails for a reason that has
 * nothing to do with the cache it was written about. Learnt the hard way.
 */
function always(body: unknown, status = 200) {
  return vi.fn(() => Promise.resolve(jsonResponse(body, status)));
}

function client(fetchImpl: typeof fetch, now: () => number = () => 1_000) {
  // No real sleeping: the backoff is asserted by counting attempts, and a test
  // that waited 900 ms to prove it would be a test somebody deletes.
  return new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchImpl, now, async () => {});
}

describe('reads', () => {
  it('retries twice on a 500 and then gives up', async () => {
    const fetchImpl = always({ error: { code: 'server_error' } }, 500);

    await expect(client(fetchImpl as unknown as typeof fetch).get('/products')).rejects.toMatchObject({
      status: 500,
      retryable: true,
    });

    // Three attempts: the original and WGT-16's two retries.
    expect(fetchImpl).toHaveBeenCalledTimes(3);
  });

  it('succeeds on the second attempt without telling the caller anything happened', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({}, 503))
      .mockResolvedValueOnce(jsonResponse({ data: ['a trip'] }));

    const result = await client(fetchImpl as unknown as typeof fetch).get<{ data: string[] }>('/products');

    expect(result.data).toEqual(['a trip']);
    expect(fetchImpl).toHaveBeenCalledTimes(2);
  });

  it('does not retry a 422, because asking again does not make the request right', async () => {
    const fetchImpl = always({ error: { code: 'validation_failed' } }, 422);

    await expect(client(fetchImpl as unknown as typeof fetch).get('/availability')).rejects.toMatchObject({
      code: 'validation_failed',
      retryable: false,
    });

    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('retries a network failure, which is the case a phone in a harbour actually hits', async () => {
    const fetchImpl = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));

    await expect(client(fetchImpl as unknown as typeof fetch).get('/branding')).rejects.toMatchObject({
      code: 'network_error',
    });

    expect(fetchImpl).toHaveBeenCalledTimes(3);
  });
});

describe('writes', () => {
  it('never retries, because the booking may already exist', async () => {
    const fetchImpl = always({}, 500);

    await expect(client(fetchImpl as unknown as typeof fetch).post('/bookings', { pax: 2 })).rejects.toMatchObject({
      status: 500,
    });

    // The response was lost, not necessarily the write. A second POST is how a
    // guest ends up holding two boats.
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('sends no cookies on any request', async () => {
    const fetchImpl = always({ data: {} });

    await client(fetchImpl as unknown as typeof fetch).get('/branding');

    // WGT-12 and GDR-12: the key is the credential, and a cookie would make the
    // widget a third-party tracker on somebody else's domain.
    const init = (fetchImpl.mock.calls as unknown as unknown[][])[0]?.[1];

    expect(init).toMatchObject({ credentials: 'omit' });
  });
});

describe('the availability cache', () => {
  it('answers the same query from memory inside sixty seconds', async () => {
    const fetchImpl = always({ data: ['day'] });
    let clock = 1_000;

    const api = client(fetchImpl as unknown as typeof fetch, () => clock);

    await api.get('/availability', { query: { product: 'uuid-1', from: '2026-07-01' } });
    clock += 59_000;
    await api.get('/availability', { query: { product: 'uuid-1', from: '2026-07-01' } });

    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('asks again after sixty seconds, and for a different date range', async () => {
    const fetchImpl = always({ data: ['day'] });
    let clock = 1_000;

    const api = client(fetchImpl as unknown as typeof fetch, () => clock);

    await api.get('/availability', { query: { product: 'uuid-1', from: '2026-07-01' } });
    clock += 61_000;
    await api.get('/availability', { query: { product: 'uuid-1', from: '2026-07-01' } });
    await api.get('/availability', { query: { product: 'uuid-1', from: '2026-08-01' } });

    expect(fetchImpl).toHaveBeenCalledTimes(3);
  });

  it('is thrown away by a booking action', async () => {
    const fetchImpl = always({ data: ['day'] });
    const api = client(fetchImpl as unknown as typeof fetch);

    await api.get('/availability', { query: { product: 'uuid-1' } });
    api.invalidate();
    await api.get('/availability', { query: { product: 'uuid-1' } });

    // WGT-17. After a hold, the seat counts this guest is looking at are the
    // ones their own action just changed.
    expect(fetchImpl).toHaveBeenCalledTimes(2);
  });

  it('never caches a write', async () => {
    const fetchImpl = always({ data: {} });
    const api = client(fetchImpl as unknown as typeof fetch);

    await api.post('/price-quote', { pax: 2 });
    await api.post('/price-quote', { pax: 2 });

    expect(fetchImpl).toHaveBeenCalledTimes(2);
  });
});

describe('language', () => {
  /*
   * The widget knew its own locale and never told the server, so a Greek page
   * drew Greek chrome around English trip titles and port names. The bundle's
   * strings are local; everything a guest actually reads about the trip comes
   * from the API, and the API decides its language from this header.
   */

  it('asks the API in the locale the widget resolved', async () => {
    const fetchImpl = always({ data: [] });
    const api = client(fetchImpl as unknown as typeof fetch);

    api.setLocale('el');
    await api.get('/products');

    const headers = (fetchImpl.mock.calls[0]?.[1] as RequestInit).headers as Record<string, string>;

    expect(headers['Accept-Language']).toBe('el');
  });

  it('sends no language header before it knows one', async () => {
    const fetchImpl = always({ data: [] });

    // Not a guess at "en". An unset header lets the server answer in the
    // operator's own default, which is a better wrong answer than the
    // platform's, and is what happened before any of this existed.
    await client(fetchImpl as unknown as typeof fetch).get('/products');

    const headers = (fetchImpl.mock.calls[0]?.[1] as RequestInit).headers as Record<string, string>;

    expect(headers['Accept-Language']).toBeUndefined();
  });
});
