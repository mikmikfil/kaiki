import type { Api } from '../api-client';

/**
 * Coming back from the gateway (WGT-20).
 *
 * ## The poll must never become a claim
 *
 * The issue's note is the requirement: *"Showing 'confirmed' on a timeout would
 * be telling a guest their money moved on the strength of a redirect."* A
 * gateway redirect means the guest pressed a button on somebody else's page. The
 * **webhook** is what means the money moved, and #83 is where a booking becomes
 * `confirmed`.
 *
 * So this polls `GET /bookings/{uuid}` for up to sixty seconds and reports what
 * it found. When the webhook has not landed in that time the answer is
 * `pending` — which the widget renders as a promise of an email, not as a
 * confirmation with a spinner.
 *
 * ## Why sixty seconds
 *
 * Long enough for a webhook that is queued behind a busy Saturday, short enough
 * that a guest is not watching a spinner while their taxi waits. Both ends are
 * WGT-20's, not ours.
 */

/** WGT-20. */
export const POLL_TIMEOUT_MS = 60_000;

const POLL_INTERVAL_MS = 3_000;

export type ConfirmationOutcome = 'confirmed' | 'pending' | 'cancelled' | 'unknown';

/**
 * One read, before deciding whether there is anything to poll for.
 *
 * ADR-0030 moved the gateway hand-off to the hosted checkout page, so a guest
 * who lands back on the embed did not necessarily come from a payment — they may
 * simply have pressed Back on the page that asks for their name. Polling that
 * booking for sixty seconds and then promising an email would be a lie told
 * slowly.
 *
 * So the mount asks once what state the booking is actually in, and only polls
 * when the answer is that a payment is under way. Null means the question could
 * not be answered — a network failure, a token that no longer opens the booking
 * — and the caller treats that as "carry on", never as an outcome.
 */
export async function readBookingStatus(client: Api, bookingUuid: string, token: string): Promise<string | null> {
    try {
        const response = await client.get<BookingStatusPayload>(`/bookings/${bookingUuid}`, {
            headers: { 'X-Kaiki-Guest-Token': token },
            cache: false,
        });

        return response.data?.status ?? null;
    } catch {
        return null;
    }
}

export interface BookingStatusPayload {
    readonly data?: { readonly status?: string; readonly reference?: string };
}

export interface PollResult {
    readonly outcome: ConfirmationOutcome;
    readonly reference: string | null;
    readonly attempts: number;
}

/**
 * @param token the booking's `manage_token`, captured from the `201` (§2.1
 *   footnote 1). A publishable key alone may not read a booking.
 */
export async function pollForConfirmation(
    client: Api,
    bookingUuid: string,
    token: string,
    options: {
        now?: () => number;
        sleep?: (ms: number) => Promise<void>;
        timeoutMs?: number;
    } = {},
): Promise<PollResult> {
    const now = options.now ?? (() => Date.now());
    const sleep = options.sleep ?? ((ms) => new Promise<void>((resolve) => setTimeout(resolve, ms)));
    const timeoutMs = options.timeoutMs ?? POLL_TIMEOUT_MS;
    const startedAt = now();

    let attempts = 0;
    let reference: string | null = null;

    while (now() - startedAt < timeoutMs) {
        attempts += 1;

        try {
            const response = await client.get<BookingStatusPayload>(`/bookings/${bookingUuid}`, {
                // **A header, not a query parameter.** It was `?token=` until
                // issue 111's end-to-end run polled a real endpoint and got a
                // 403: `AuthenticateGuestToken` reads `X-Kaiki-Guest-Token`, and
                // §3.3 names it in the CORS allow-list for exactly this call.
                //
                // A credential in a query string is the wrong place on its own
                // terms as well — it lands in access logs, in a proxy's cache
                // key and in a `Referer` — so the contract is right and the
                // widget was wrong twice over.
                headers: { 'X-Kaiki-Guest-Token': token },
                // Never from cache: the whole point of the loop is that the
                // answer is expected to change while it runs, and WGT-17's
                // sixty-second window is exactly the length of this poll.
                cache: false,
            });

            const status = response.data?.status ?? null;

            reference = response.data?.reference ?? reference;

            if (status === 'confirmed' || status === 'checked_in' || status === 'completed') {
                return { outcome: 'confirmed', reference, attempts };
            }

            if (status === 'cancelled' || status === 'expired') {
                return { outcome: 'cancelled', reference, attempts };
            }
        } catch {
            // A failed read is not an answer about the booking. Keep polling —
            // the deadline is what ends this, not one bad response.
        }

        await sleep(POLL_INTERVAL_MS);
    }

    // Sixty seconds and no webhook. The guest is told an email will follow,
    // which is true, rather than told they are confirmed, which is not known.
    return { outcome: 'pending', reference, attempts };
}
