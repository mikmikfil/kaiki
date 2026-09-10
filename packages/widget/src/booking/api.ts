import type { Api, ApiError } from '../api-client';
import { draftFingerprint, IdempotencyKeys } from './idempotency';
import type { BookingState } from './machine';
import { draftPayload } from './machine';

/**
 * The calls the booking walk makes, and the credential each one uses.
 *
 * ## The token is captured once, and there is no second chance
 *
 * §2.1 footnote 1 and #89: `manage_token` is returned **only** in the `201` from
 * `POST /bookings`. Every later read returns null, because the caller already
 * holds it. So the draft response is where it is taken, and losing it means the
 * guest can no longer be shown their own booking — which is why it also goes
 * into `sessionStorage` (WGT-12) before anything else happens.
 *
 * ## There is no checkout call here any more
 *
 * ADR-0030: the walk ends with a draft and a redirect to `/c/{manage_token}`,
 * the operator's own checkout page. Starting the gateway session is that page's
 * job, because it is the page that collected the name, the consent and the
 * passenger manifest the session is for. The widget's old `checkout()` wrapper
 * went with the review step it served.
 *
 * What is left is the draft, availability, and reading a booking back with the
 * guest token — never with the publishable key alone, which is the rule that
 * keeps a uuid in a browser history from being a guest's name and telephone
 * number (§2.1 footnote 1).
 */

export interface DraftResult {
  readonly uuid: string;
  readonly reference: string;
  readonly status: string;
  readonly manageToken: string | null;
  readonly holdExpiresAt: string | null;
  readonly totalCents: number | null;
  readonly totalFormatted: string | null;
  /**
   * Where the guest goes next (ADR-0030).
   *
   * The hosted checkout page for this booking, `/c/{manage_token}`. Returned
   * beside the token and for the same reason — it *contains* the token, so it
   * appears exactly once, in the `201`, and is never handed out again.
   *
   * The widget does not build this itself. A URL assembled in a browser from a
   * host in a config file is a guess about somebody else's deployment; the
   * server knows its own hosted origin and a custom domain when there is one.
   */
  readonly checkoutUrl: string | null;
  readonly raw: Record<string, unknown>;
}

export class BookingApi {
  constructor(
    private readonly client: Api,
    private readonly keys: IdempotencyKeys = new IdempotencyKeys(),
  ) {}

  /**
   * Create the draft and take the hold.
   *
   * The idempotency key is bound to the **intention** — this party, this date,
   * these extras — so a retry after a timeout replays the first answer instead
   * of holding a second set of seats. See {@see IdempotencyKeys}.
   */
  async createDraft(state: BookingState, productUuid: string, locale: string): Promise<DraftResult> {
    const key = this.keys.keyFor('draft', draftFingerprint(state));

    const response = await this.client.post<{ data: Record<string, unknown> }>(
      '/bookings',
      draftPayload(state, productUuid, locale),
      { headers: { 'Idempotency-Key': key } },
    );

    // Availability just changed, by this guest's own hand (WGT-17).
    this.client.invalidate();

    return readDraft(response.data ?? {});
  }

  /** Availability for a product, which the cache serves for sixty seconds. */
  async availability(productUuid: string, from: string, to: string, pax?: number): Promise<unknown> {
    return this.client.get('/availability', { query: { product: productUuid, from, to, pax } });
  }
}

/** `409 insufficient_capacity` — AVL-39, and the one refusal with its own screen. */
export function isSoldOut(error: unknown): boolean {
  return (error as ApiError | null)?.code === 'insufficient_capacity';
}

/** `409 price_changed` — the guest was shown a price that no longer holds. */
export function isPriceChanged(error: unknown): boolean {
  return (error as ApiError | null)?.code === 'price_changed';
}

function readDraft(data: Record<string, unknown>): DraftResult {
  const money = (data['money'] ?? {}) as Record<string, unknown>;

  return {
    uuid: String(data['uuid'] ?? ''),
    reference: String(data['reference'] ?? ''),
    status: String(data['status'] ?? 'draft'),
    // The one moment this exists. Read it here or never.
    manageToken: typeof data['manage_token'] === 'string' ? data['manage_token'] : null,
    checkoutUrl: typeof data['checkout_url'] === 'string' ? data['checkout_url'] : null,
    holdExpiresAt: typeof data['hold_expires_at'] === 'string' ? data['hold_expires_at'] : null,
    // Read, never computed (WGT-13, PRC-1). The formatted string is the
    // server's too — a widget that formatted cents would be one decimal
    // separator away from telling a Greek guest their trip costs 18.000 €.
    totalCents: typeof money['total_cents'] === 'number' ? money['total_cents'] : null,
    totalFormatted: typeof money['total_formatted'] === 'string' ? money['total_formatted'] : null,
    raw: data,
  };
}
