import { useCallback, useEffect, useMemo, useReducer, useState } from 'preact/hooks';

import type { Analytics } from '../../analytics';
import type { ApiClient } from '../../api-client';
import { BookingApi, isSoldOut, type DraftResult } from '../../booking/api';
import { holdState } from '../../booking/countdown';
import { pollForConfirmation } from '../../booking/confirmation';
import { back, canAdvance, initialState, next, type BookingState, type MachineOptions, type Step } from '../../booking/machine';
import type { Translator } from '../../i18n';
import { forgetDraft, rememberDraft } from '../../storage';
import { ContactStep } from './steps/ContactStep';
import { DateStep } from './steps/DateStep';
import { ExtrasStep } from './steps/ExtrasStep';
import { FourLines } from './FourLines';
import { Hold } from './Hold';
import { PartyStep } from './steps/PartyStep';
import { ReviewStep } from './steps/ReviewStep';

/**
 * The booking mount (WGT-18, WGT-19, WGT-20).
 *
 * ## The machine owns the answers; this owns the asking
 *
 * Every field lives in one state object in `booking/machine.ts`, which is what
 * makes WGT-18's *"back navigation never loses entered data"* a property of the
 * design rather than a thing to remember. This component moves the cursor, calls
 * the API and decides what is on screen.
 *
 * ## Four lines above the date picker, and nothing else
 *
 * Brand decision 3 of 2026-09-04: title, duration, port, vessel. The temptation
 * is a photograph, because the space looks empty — it looked empty in the mockup
 * too, and the decision was made against exactly that.
 *
 * ## Three states after "pay", and only one of them is a claim
 *
 * A gateway redirect, a direct confirmation when a voucher covered the total
 * (BKG-19), and the WGT-20 poll on the way back. The poll says `confirmed` only
 * when the booking says so — never on a timeout, because a redirect is a guest
 * pressing a button and the webhook is the money moving.
 */

interface BookingMountProps {
  readonly client: ApiClient;
  readonly productUuid: string;
  readonly product: ProductSummary;
  readonly t: Translator;
  readonly analytics: Analytics;
  readonly locale: string;
}

export interface ProductSummary {
  readonly title: string;
  readonly duration_minutes: number;
  readonly meeting_point?: { readonly name?: string } | null;
  readonly vessel?: { readonly name?: string } | null;
  readonly age_bands?: readonly { readonly code: string; readonly label: string }[];
  readonly extras?: readonly { readonly uuid: string; readonly name: string; readonly price_formatted?: string }[];
}

type Phase = 'walking' | 'submitting' | 'redirecting' | 'confirmed' | 'pending' | 'sold_out' | 'expired' | 'failed';

export function BookingMount({ client, productUuid, product, t, analytics, locale }: BookingMountProps) {
  const options: MachineOptions = { hasExtras: (product.extras ?? []).length > 0 };
  const api = useMemo(() => new BookingApi(client), [client]);

  const [state, dispatch] = useReducer(reduce, initialState());
  const [phase, setPhase] = useState<Phase>('walking');
  const [draft, setDraft] = useState<DraftResult | null>(null);
  const [tick, setTick] = useState(0);

  const hold = holdState(draft?.holdExpiresAt ?? null);

  // One timer for the whole mount, and only while a hold is running: a widget
  // that ticked forever would keep a phone's CPU awake on a page nobody is
  // looking at any more.
  useEffect(() => {
    if (draft === null || phase === 'confirmed' || phase === 'pending') {
      return undefined;
    }

    const timer = setInterval(() => setTick((value) => value + 1), 1000);

    return () => clearInterval(timer);
  }, [draft, phase]);

  // WGT-19: on expiry the widget explains and re-fetches, rather than letting
  // the guest press "pay" and meet a refusal they cannot interpret.
  useEffect(() => {
    if (hold.phase === 'expired' && phase === 'walking') {
      setPhase('expired');
      client.invalidate();
      forgetDraft();
    }
  }, [hold.phase, phase, client, tick]);

  const advance = useCallback(() => dispatch({ type: 'next', options }), [options]);
  const retreat = useCallback(() => dispatch({ type: 'back', options }), [options]);

  const submit = useCallback(async () => {
    setPhase('submitting');

    try {
      const created = draft ?? (await api.createDraft(state, productUuid, locale));

      if (created.manageToken !== null) {
        // §2.1 footnote 1: this is the one moment the token exists. It is
        // stored before anything else can fail.
        rememberDraft(created.uuid);
      }

      setDraft(created);
      analytics.emit('kaiki:booking-started', { product_uuid: productUuid, pax: countedPax(state) });

      const session = await api.checkout(created.uuid, window.location.href);

      if (session.redirectUrl === null) {
        // BKG-19: a voucher covered the total. Confirmed already, nowhere to
        // send them.
        analytics.emit('kaiki:booking-confirmed', { product_uuid: productUuid });
        forgetDraft();
        setPhase('confirmed');

        return;
      }

      analytics.emit('kaiki:checkout-started', {
        product_uuid: productUuid,
        value_cents: created.totalCents ?? undefined,
      });

      setPhase('redirecting');
      window.location.assign(session.redirectUrl);
    } catch (error) {
      if (isSoldOut(error)) {
        // AVL-39: the last seats went while this guest was deciding. A
        // sentence in their language with fresh availability behind it, not an
        // error dialog they start over from.
        client.invalidate();
        setPhase('sold_out');
        analytics.emit('kaiki:error', { product_uuid: productUuid, error_code: 'insufficient_capacity' });

        return;
      }

      setPhase('failed');
      analytics.emit('kaiki:error', { product_uuid: productUuid, error_code: 'checkout_failed' });
    }
  }, [api, draft, state, productUuid, locale, analytics, client]);

  const resume = useCallback(
    async (bookingUuid: string, token: string) => {
      const result = await pollForConfirmation(client, bookingUuid, token);

      if (result.outcome === 'confirmed') {
        analytics.emit('kaiki:booking-confirmed', { product_uuid: productUuid });
        forgetDraft();
        setPhase('confirmed');

        return;
      }

      // WGT-20: not a claim. An email will follow when the webhook lands.
      setPhase(result.outcome === 'cancelled' ? 'failed' : 'pending');
    },
    [client, analytics, productUuid],
  );

  useEffect(() => {
    // Coming back from the gateway is a fresh page load with a uuid in the URL.
    const params = new URLSearchParams(window.location.search);
    const bookingUuid = params.get('kaiki_booking');
    const token = params.get('kaiki_token');

    if (bookingUuid !== null && token !== null) {
      setPhase('submitting');
      void resume(bookingUuid, token);
    }
  }, [resume]);

  if (phase === 'confirmed') {
    return <Outcome t={t} heading="booking.confirmed.heading" body="booking.confirmed.body" />;
  }

  if (phase === 'pending') {
    return <Outcome t={t} heading="booking.pending.heading" body="booking.pending.body" />;
  }

  if (phase === 'sold_out') {
    return <Outcome t={t} heading="booking.sold_out.heading" body="booking.sold_out.body" onRetry={() => setPhase('walking')} retryKey="booking.sold_out.retry" />;
  }

  if (phase === 'expired') {
    return <Outcome t={t} heading="booking.expired.heading" body="booking.expired.body" onRetry={() => { setDraft(null); setPhase('walking'); }} retryKey="booking.expired.retry" />;
  }

  if (phase === 'failed') {
    return <Outcome t={t} heading="widget.error.generic" body="widget.error.contact" onRetry={() => setPhase('walking')} retryKey="widget.retry" />;
  }

  return (
    <div class="kaiki-booking">
      <FourLines product={product} t={t} />

      {draft !== null ? <Hold hold={hold} t={t} /> : null}

      <StepView
        step={state.step}
        state={state}
        product={product}
        t={t}
        onChange={(patch) => dispatch({ type: 'patch', patch })}
      />

      <div class="kaiki-actions">
        {state.step !== 'date' ? (
          <button type="button" class="kaiki-button kaiki-button-ghost" onClick={retreat}>
            {t('booking.back')}
          </button>
        ) : null}

        {state.step === 'review' ? (
          <button type="button" class="kaiki-button" onClick={() => void submit()} disabled={phase === 'submitting'}>
            {t(phase === 'submitting' ? 'booking.submitting' : 'booking.pay')}
          </button>
        ) : (
          <button type="button" class="kaiki-button" onClick={advance} disabled={!canAdvance(state, options)}>
            {t('booking.next')}
          </button>
        )}
      </div>
    </div>
  );
}

function StepView({
  step,
  state,
  product,
  t,
  onChange,
}: {
  readonly step: Step;
  readonly state: BookingState;
  readonly product: ProductSummary;
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  switch (step) {
    case 'date':
      return <DateStep state={state} t={t} onChange={onChange} />;
    case 'party':
      return <PartyStep state={state} bands={product.age_bands ?? []} t={t} onChange={onChange} />;
    case 'extras':
      return <ExtrasStep state={state} extras={product.extras ?? []} t={t} onChange={onChange} />;
    case 'contact':
      return <ContactStep state={state} t={t} onChange={onChange} />;
    case 'review':
      return <ReviewStep state={state} product={product} t={t} />;
    default:
      return null;
  }
}

function Outcome({
  t,
  heading,
  body,
  onRetry,
  retryKey,
}: {
  readonly t: Translator;
  readonly heading: string;
  readonly body: string;
  readonly onRetry?: () => void;
  readonly retryKey?: string;
}) {
  return (
    // `alert` rather than `status`: this replaced what the guest was doing, and
    // a screen reader should interrupt rather than wait for a pause (A11Y).
    <div class="kaiki-error" role="alert">
      <p class="kaiki-heading">{t(heading as never)}</p>
      <p>{t(body as never)}</p>
      {onRetry !== undefined && retryKey !== undefined ? (
        <button type="button" class="kaiki-button" onClick={onRetry}>
          {t(retryKey as never)}
        </button>
      ) : null}
    </div>
  );
}

type Action =
  | { type: 'next'; options: MachineOptions }
  | { type: 'back'; options: MachineOptions }
  | { type: 'patch'; patch: Partial<BookingState> };

/**
 * The reducer is three lines because the machine is where the rules are.
 *
 * `patch` merges — it never replaces the state — which is the mechanical half of
 * WGT-18's guarantee: there is no action in this file that can clear a field.
 */
function reduce(state: BookingState, action: Action): BookingState {
  switch (action.type) {
    case 'next':
      return next(state, action.options);
    case 'back':
      return back(state, action.options);
    case 'patch':
      return { ...state, ...action.patch };
    default:
      return state;
  }
}

function countedPax(state: BookingState): number {
  return Object.values(state.pax).reduce((total, qty) => total + Math.max(0, qty), 0);
}
