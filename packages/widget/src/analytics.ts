/**
 * The eight analytics events, as DOM events (WGT-11 FIXED, GDR-12).
 *
 * ## DOM events, not a tracker
 *
 * The widget does not know or care whether the host page has Google Analytics,
 * Matomo, a data layer or nothing at all. It dispatches a `CustomEvent` on
 * `document` and the operator's own site decides — which is why there is no
 * third-party script, no cookie (WGT-12) and no consent question for the widget
 * to get wrong on somebody else's behalf.
 *
 * ## The detail object is an allow-list, and that is the whole file
 *
 * GDR-12 and WGT-11: *"No guest name, email, phone or token may appear in an
 * event detail."* A denylist would leak the first field somebody adds. So the
 * payload is **built key by key** from a fixed set — product uuid, date, pax
 * counts, value in cents, currency, widget version — and anything else a caller
 * passes is dropped rather than forwarded.
 *
 * `AnalyticsTest` asserts that field by field, including that a payload
 * containing an email and a manage token comes out the other side without them.
 */

export const EVENTS = [
  'kaiki:ready',
  'kaiki:product-viewed',
  'kaiki:availability-loaded',
  'kaiki:booking-started',
  'kaiki:checkout-started',
  'kaiki:booking-confirmed',
  'kaiki:enquiry-submitted',
  'kaiki:error',
] as const;

export type EventName = (typeof EVENTS)[number];

/** Everything an event detail may contain. Nothing outside this list survives. */
export interface EventDetail {
  readonly widget_version?: string;
  readonly mount?: string;
  readonly locale?: string;
  readonly product_uuid?: string;
  readonly departure_uuid?: string;
  readonly date?: string;
  readonly pax?: number;
  readonly value_cents?: number;
  readonly currency?: string;
  readonly error_code?: string;
}

const ALLOWED: readonly (keyof EventDetail)[] = [
  'widget_version',
  'mount',
  'locale',
  'product_uuid',
  'departure_uuid',
  'date',
  'pax',
  'value_cents',
  'currency',
  'error_code',
];

export interface Analytics {
  emit(name: EventName, detail?: Record<string, unknown>): void;
}

/**
 * Where a counted event is sent, when the operator's own panel is counting
 * (ADR-0032).
 */
export interface Beacon {
  readonly apiBase: string;
  readonly key: string;
}

/**
 * @param enabled WGT-7's `data-analytics`. When off, nothing is dispatched at
 *   all — not a stripped event, not an empty one, and nothing is sent to Kaiki.
 * @param beacon ADR-0032. Given, the same scrubbed detail is also counted in
 *   the operator's own statistics page. Omitted, the widget behaves exactly as
 *   it did before that decision: a DOM event and nothing else.
 */
export function analytics(
  enabled: boolean,
  version: string,
  target: EventTarget = document,
  beacon?: Beacon,
): Analytics {
  return {
    emit(name: EventName, detail: Record<string, unknown> = {}): void {
      if (!enabled) {
        return;
      }

      const clean = scrub(detail);

      target.dispatchEvent(
        new CustomEvent(name, {
          detail: { widget_version: version, ...clean },
          bubbles: true,
          composed: true,
        }),
      );

      if (beacon) {
        count(beacon, name, clean);
      }
    },
  };
}

/**
 * Send one count to Kaiki, and never let it matter.
 *
 * ## `fetch` with `keepalive`, not `sendBeacon`
 *
 * `sendBeacon` cannot set a header, and the API is authenticated by one. A
 * `keepalive` fetch survives the page being closed the same way a beacon does
 * and can carry `Authorization`, which is the whole difference.
 *
 * ## It cannot fail loudly
 *
 * The promise is swallowed. This runs on a page in the middle of selling
 * something, and an unhandled rejection from a counter — or worse, an exception
 * inside `emit` — would put a defect in the booking flow in exchange for a
 * number nobody is watching. The scrubbed detail is what is sent, so the
 * allow-list above is the only thing that decides what leaves the browser.
 */
function count(beacon: Beacon, event: EventName, detail: EventDetail): void {
  try {
    void fetch(`${beacon.apiBase}/events`, {
      method: 'POST',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${beacon.key}`,
      },
      body: JSON.stringify({ event, ...detail }),
    }).catch(() => undefined);
  } catch {
    // An environment without `fetch`, or one that refuses it. Counting is the
    // least important thing this widget does.
  }
}

/**
 * The allow-list, applied.
 *
 * Exported because the test asserts on it directly: a guest's email reaching an
 * operator's analytics vendor is the kind of defect that is discovered by a
 * regulator rather than by a user, so the filter is tested as a function and not
 * only through a dispatched event.
 */
export function scrub(detail: Record<string, unknown>): EventDetail {
  const clean: Record<string, unknown> = {};

  for (const key of ALLOWED) {
    const value = detail[key];

    // Only scalars. An object would be a place for a nested `guest` to hide,
    // and no field in the allow-list needs one.
    if (typeof value === 'string' || typeof value === 'number') {
      clean[key] = value;
    }
  }

  return clean as EventDetail;
}
