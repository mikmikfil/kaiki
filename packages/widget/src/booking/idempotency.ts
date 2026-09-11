/**
 * Idempotency keys, one per **intention** rather than one per request (§3.4).
 *
 * ## The requirement most likely to be implemented backwards
 *
 * The issue says so in as many words, and it is right: the instinct is to mint a
 * key per request, which is exactly what defeats the mechanism. §3.4's whole
 * design is that a retry of *the same intention* carries *the same key*, so the
 * server can recognise the second attempt and replay the first answer instead of
 * creating a second booking.
 *
 * A widget that minted a fresh key per attempt would double-book on a flaky
 * connection, and **every test of the server middleware would still pass** —
 * because from the server's side two different keys are two different
 * intentions, which is a true statement about a false situation.
 *
 * ## So the key is bound to what the guest meant, not to what the code did
 *
 * `keyFor('draft')` returns the same string until something about the intention
 * changes — a different party, a different date, a different extra. `renew()` is
 * called when it does. The guest pressing "try again" after a timeout reuses the
 * key; the guest going back, changing the party and continuing gets a new one,
 * because that is a different booking.
 *
 * `crypto.randomUUID` where it exists, with a fallback for the http:// pages an
 * operator's staging site is still on — `randomUUID` is a secure-context API and
 * the widget has to work where it is not available.
 */

export type Intention = 'draft' | 'checkout' | 'cancel';

export class IdempotencyKeys {
  private readonly keys = new Map<string, string>();

  constructor(private readonly generate: () => string = () => uuidV4()) {}

  /**
   * The key for this intention, minted once and kept.
   *
   * `fingerprint` is what makes "the same intention" checkable: pass the inputs
   * that define it — the party, the date, the extras — and a change to any of
   * them produces a new key without the caller having to remember to renew.
   */
  keyFor(intention: Intention, fingerprint = ''): string {
    const slot = `${intention}:${fingerprint}`;
    const existing = this.keys.get(slot);

    if (existing !== undefined) {
      return existing;
    }

    const key = this.generate();

    // Only this intention's slots are cleared, and only when its fingerprint
    // moved: a new draft must not invalidate the checkout key of a booking that
    // is already on its way to a gateway.
    for (const known of [...this.keys.keys()]) {
      if (known.startsWith(`${intention}:`)) {
        this.keys.delete(known);
      }
    }

    this.keys.set(slot, key);

    return key;
  }

  /** Forget an intention entirely — the guest abandoned it and started again. */
  forget(intention: Intention): void {
    for (const known of [...this.keys.keys()]) {
      if (known.startsWith(`${intention}:`)) {
        this.keys.delete(known);
      }
    }
  }
}

/**
 * A fingerprint of everything that makes this draft *this* draft.
 *
 * Deliberately not the whole state: the guest's telephone number changing does
 * not make it a different booking, and minting a new key for an edit to a field
 * the server does not deduplicate on would be the per-request mistake with extra
 * steps.
 */
export function draftFingerprint(input: {
  departureUuid: string | null;
  localDate: string | null;
  localTime: string | null;
  pax: Record<string, number>;
  extras: Record<string, number>;
  voucherCode: string;
}): string {
  const sorted = (record: Record<string, number>): string =>
    Object.entries(record)
      .filter(([, qty]) => qty > 0)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([key, qty]) => `${key}=${qty}`)
      .join(',');

  return [
    input.departureUuid ?? '',
    input.localDate ?? '',
    input.localTime ?? '',
    sorted(input.pax),
    sorted(input.extras),
    input.voucherCode.trim(),
  ].join('|');
}

/**
 * A version-4 UUID, whatever the page.
 *
 * `randomUUID` is a secure-context API: an http:// page on anything but
 * `localhost` does not have it — a staging site, or Kaiki's own pages opened on
 * a phone through the developer's LAN address. The fallback **must still be a
 * UUIDv4**: `EnforceIdempotencyKey` refuses anything else with a 422. It used
 * to be 32 bare hex characters, so every booking from such a page failed with
 * «Κάτι πήγε στραβά» before a draft existed (found 2026-09-11, from the trip
 * page on 192.168.1.43). The key is not a secret — the server scopes it to the
 * API key — so `getRandomValues`, or failing that `Math.random`, is entropy
 * enough; what matters is the shape.
 */
export function uuidV4(source: Pick<Crypto, 'getRandomValues'> & Partial<Pick<Crypto, 'randomUUID'>> | undefined = globalThis.crypto): string {
  if (source !== undefined && typeof source.randomUUID === 'function') {
    return source.randomUUID();
  }

  const bytes = new Uint8Array(16);

  if (source !== undefined && typeof source.getRandomValues === 'function') {
    source.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }

  // RFC 4122 §4.4: the version nibble is 4, the variant bits are 10.
  bytes[6] = ((bytes[6] as number) & 0x0f) | 0x40;
  bytes[8] = ((bytes[8] as number) & 0x3f) | 0x80;

  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
