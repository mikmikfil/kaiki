import { useEffect, useRef, useState } from 'preact/hooks';

import type { Api, ApiError } from '../api-client';
import { countedPax, draftPayload, type BookingState } from './machine';

/**
 * The running total, asked of the server on every change.
 *
 * ## Why this exists
 *
 * The walk showed the product's from-price — «Από 55,00 €» — from the first
 * screen to the last, and the real total first appeared on the checkout page.
 * A guest who had chosen two adults was looking at a number that was not what
 * they would pay. ADR-0033 made the bottom sheet's peek bar carry the price,
 * which turns that from a wart into a lie repeated on every scroll, so the
 * total has to become real before the bar can exist.
 *
 * ## The widget still computes nothing
 *
 * WGT-13 and PRC-1 are untouched: this posts the same inputs the draft would
 * carry to `POST /price-quote` and renders `total_formatted` exactly as it
 * comes back. No multiplication, no currency formatting, no rounding — the
 * build guard greps for arithmetic near a price and this file gives it nothing
 * to find. The contract says in as many words that the endpoint has no side
 * effects and "is safe to call on every pax change".
 *
 * ## What it refuses to do
 *
 * **It does not ask until there is a question.** No date, or nobody in the
 * party, and there is no total to want; the bar shows the from-price, which is
 * honest at that point because nothing has been chosen.
 *
 * **It does not race.** Every request carries a sequence number and a stale
 * answer is dropped on arrival. A guest tapping `+` four times gets the fourth
 * answer, not whichever of the four the network returned last.
 *
 * **It does not blank the price while it thinks.** The previous total stays on
 * screen with `loading` raised beside it, because a bar that empties itself
 * between keystrokes is worse than one that is briefly a step behind.
 *
 * **It does not invent a total when the server refuses.** A failed quote gives
 * `failed`, the caller falls back to the from-price, and the checkout page —
 * which renders the frozen `price_snapshot` and needs no request at all — is
 * still the thing that takes the money.
 */
export interface Quote {
  /** `total_formatted` from the server, or null before the first answer. */
  readonly total: string | null;
  readonly loading: boolean;
  readonly failed: boolean;
  /**
   * The server's sentence when it refused the question itself — a date past
   * its lead time, a party that may not sail (2026-09-25) — rather than
   * failing to answer it. Null for a malformed body or a network failure,
   * where the from-price fallback is the whole answer.
   */
  readonly refusal: string | null;
}

interface QuotePayload {
  readonly data?: { readonly total_formatted?: unknown };
}

/** Long enough to swallow a burst of taps on a stepper, short enough to feel live. */
const SETTLE_MS = 300;

export function usePriceQuote(
  client: Api,
  state: BookingState,
  productUuid: string,
  locale: string,
): Quote {
  const [total, setTotal] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);

  // Bumped per request; an answer whose number is not the current one is late.
  const seq = useRef(0);

  // The body is the identity of the question. Serialising it is what stops a
  // re-render with the same selection from asking the same thing again.
  const body = draftPayload(state, productUuid, locale);
  const key = countedPax(state) > 0 && state.localDate !== null ? JSON.stringify(body) : null;

  useEffect(() => {
    if (key === null) {
      setTotal(null);
      setLoading(false);
      setFailed(false);
      setRefusal(null);

      return;
    }

    const mine = ++seq.current;
    setLoading(true);

    const timer = setTimeout(() => {
      client
        .post<QuotePayload>('/price-quote', JSON.parse(key) as Record<string, unknown>)
        .then((response) => {
          if (mine !== seq.current) {
            return;
          }

          const formatted = response.data?.total_formatted;

          setTotal(typeof formatted === 'string' ? formatted : null);
          setFailed(typeof formatted !== 'string');
          setRefusal(null);
          setLoading(false);
        })
        .catch((error: unknown) => {
          if (mine !== seq.current) {
            return;
          }

          setFailed(true);
          setRefusal(quoteRefusal(error));
          setLoading(false);
        });
    }, SETTLE_MS);

    return () => {
      clearTimeout(timer);
    };
  }, [client, key]);

  return { total, loading, failed, refusal };
}

/**
 * A `422` with a code of its own and a sentence beside it. `validation_failed`
 * is excluded: its sentence is about the request body, which the guest did not
 * write and cannot fix.
 */
function quoteRefusal(error: unknown): string | null {
  const e = error as ApiError | null;

  if (e?.status !== 422 || e.code === null || e.code === 'validation_failed') {
    return null;
  }

  return typeof e.detail === 'string' && e.detail.trim() !== '' ? e.detail : null;
}
