import { describe, expect, it } from 'vitest';

import { uuidV4 } from '../src/booking/idempotency';

/*
 * The idempotency key must be a UUIDv4 on every page, because the server
 * refuses anything else with a 422 (`EnforceIdempotencyKey`).
 *
 * Found 2026-09-11: on an http:// page that is not localhost — Kaiki's trip
 * page opened through the LAN address — `crypto.randomUUID` does not exist, the
 * fallback produced 32 bare hex characters, and every booking failed with
 * «Κάτι πήγε στραβά» before a draft was created.
 */

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('the idempotency key', () => {
  it('uses randomUUID where the page has it', () => {
    expect(uuidV4({ randomUUID: () => 'from-the-browser', getRandomValues: (a: ArrayBufferView) => a } as unknown as Crypto)).toBe('from-the-browser');
  });

  it('is still a UUIDv4 on an http page without randomUUID', () => {
    const insecure = { getRandomValues: <T extends ArrayBufferView | null>(array: T): T => globalThis.crypto.getRandomValues(array as never) };

    for (let i = 0; i < 50; i += 1) {
      expect(uuidV4(insecure as Crypto)).toMatch(V4);
    }
  });

  it('is still a UUIDv4 with no crypto at all', () => {
    expect(uuidV4(undefined)).toMatch(V4);
  });
});
