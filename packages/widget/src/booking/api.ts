import type { Api, ApiError } from '../api-client';
import { draftFingerprint, IdempotencyKeys } from './idempotency';
import type { BookingState } from './machine';
import { draftPayload } from './machine';

/**
 * The four calls the booking walk makes, and the credential each one uses.
 *
 * ## The token is captured once, and there is no second chance
 *
 * §2.1 footnote 1 and #89: `manage_token` is returned **only** in the `201` from
 * `POST /bookings`. Every later read returns null, because the caller already
 * holds it. So the draft response is where it is taken, and losing it means the
 * guest can no longer be shown their own booking — which is why it also goes
 * into `sessionStorage` (WGT-12) before anything else happens.
 *
 * ## A publishable key may finish what it started
 *
 * Footnote 1 again, and it has a **state** in it: a `pk_` may call checkout
 * while the booking is still a live `draft`, and not one moment later. That is
 * the whole of what the widget needs and nothing more — it never reads a booking
 * with the publishable key alone, which is the rule that keeps a uuid in a
 * browser history from being a guest's name and telephone number.
 */

export interface DraftResult {
  readonly uuid: string;
  readonly reference: string;
  readonly status: string;
  readonly manageToken: string | null;
  readonly holdExpiresAt: string | null;
  readonly totalCents: number | null;
  readonly totalFormatted: string | null;
  readonly raw: Record<string, unknown>;
}

export interface CheckoutResult {
  /** Null when a voucher covered the total — BKG-19, no gateway at all. */
  readonly redirectUrl: string | null;
  readonly status: string | null;
  readonly holdExpiresAt: string | null;
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

  /**
   * Start checkout, or discover there is nothing to pay.
   *
   * BKG-19 and PRC-22: a voucher covering the whole total means no gateway
   * session, and the endpoint answers with the **booking** rather than a
   * session. The widget tells them apart by the absence of `redirect_url`, not
   * by the status code, because a client that branched on 200-versus-201 would
   * be reading a fact about HTTP rather than about the booking.
   */
  async checkout(bookingUuid: string, returnUrl: string): Promise<CheckoutResult> {
    const key = this.keys.keyFor('checkout', bookingUuid);

    const response = await this.client.post<{ data: Record<string, unknown> }>(
      `/bookings/${bookingUuid}/checkout`,
      { return_url: returnUrl },
      { headers: { 'Idempotency-Key': key } },
    );

    const data = response.data ?? {};

    return {
      redirectUrl: typeof data['redirect_url'] === 'string' ? data['redirect_url'] : null,
      status: typeof data['status'] === 'string' ? data['status'] : null,
      holdExpiresAt: typeof data['hold_expires_at'] === 'string' ? data['hold_expires_at'] : null,
    };
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
    holdExpiresAt: typeof data['hold_expires_at'] === 'string' ? data['hold_expires_at'] : null,
    // Read, never computed (WGT-13, PRC-1). The formatted string is the
    // server's too — a widget that formatted cents would be one decimal
    // separator away from telling a Greek guest their trip costs 18.000 €.
    totalCents: typeof money['total_cents'] === 'number' ? money['total_cents'] : null,
    totalFormatted: typeof money['total_formatted'] === 'string' ? money['total_formatted'] : null,
    raw: data,
  };
}
