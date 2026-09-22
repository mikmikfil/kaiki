import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'preact/hooks';

import type { Analytics } from '../../analytics';
import type { Api } from '../../api-client';
import {
  BookingApi,
  isSoldOut,
  type DraftResult,
  isInvalidDiscountCode,
  isPartyRefused,
  refusalMessage,
} from '../../booking/api';
import { holdState } from '../../booking/countdown';
import { pollForConfirmation, readBookingStatus } from '../../booking/confirmation';
import {
  back,
  canAdvance,
  initialStateOn,
  isLastStep,
  next,
  type BookingState,
  type MachineOptions,
  countedPax,
  type Step,
} from '../../booking/machine';
import { usePriceQuote } from '../../booking/price';
import type { Translator } from '../../i18n';
import { forgetDraft, recallDraft, rememberDraft } from '../../storage';
import { DateStep } from './steps/DateStep';
import { ExtrasStep } from './steps/ExtrasStep';
import { FourLines } from './FourLines';
import { Hold } from './Hold';
import { PartyStep } from './steps/PartyStep';
import { Peek, useSettled, useSheetMode } from './Sheet';

/**
 * The booking mount (WGT-18 as amended by ADR-0030, WGT-19, WGT-20).
 *
 * ## Three questions, then out
 *
 * Date, party, extras — and then the guest leaves for the operator's own
 * checkout page at `/c/{manage_token}`, where the name, the telephone number,
 * the passenger manifest and the consent are typed on a full-width page instead
 * of in a 380-pixel column on somebody else's site. The product owner asked for
 * exactly this, twice, and the second time in the plainest terms: *"στο single
 * page απλά να υπάρχει ημερομηνία, μετά άτομα … και μετά πάμε για checkout"*.
 *
 * What left with the contact and review steps was also the widget's worst bug:
 * `ReviewStep` took a `quote` prop that nothing supplied, so it rendered
 * «Υπολογίζουμε την τιμή σας…» permanently and a guest pressed pay having never
 * been shown a total. The checkout page renders the price from the frozen
 * `price_snapshot`, and `CheckoutPageTest` asserts the figure is on the page.
 *
 * ## Four lines above the date picker, and nothing else
 *
 * Brand decision 3 of 2026-09-04: title, duration, port, vessel. The temptation
 * is a photograph, because the space looks empty — it looked empty in the mockup
 * too, and the decision was made against exactly that.
 *
 * ## Coming back to this page is not evidence of a payment
 *
 * The gateway now returns to the hosted checkout, not here. So a guest who
 * reappears on the embed may have paid, or may simply have pressed Back on the
 * form. The mount asks the booking once which it was, and only polls (WGT-20)
 * when a payment is genuinely under way. A still-`draft` booking gets a line
 * offering the checkout they left, not a spinner and then a promise of an email.
 */

interface BookingMountProps {
  readonly client: Api;
  readonly productUuid: string;
  readonly product: ProductSummary;
  readonly t: Translator;
  readonly analytics: Analytics;
  readonly locale: string;
  /** A day chosen on the operator's own calendar, through the trip page's `?date=`. */
  readonly initialDate?: string | null;
  /** False when the operator chose to leave the boat's name out (`data-vessel="hide"`). */
  readonly showVessel?: boolean;
  /** False for the compact form (`data-details="hide"`): the page already shows the four lines. */
  readonly showDetails?: boolean;
}

export interface ProductSummary {
  /**
   * `per_seat`, `per_vessel` or `quote`. A `quote` trip never reaches this
   * component: the loader in `register.tsx` gives it the enquiry form (BKG-24).
   */
  readonly mode?: string;
  readonly title: string;
  readonly duration_minutes: number;
  /** The cheapest adult price, already rendered by the server. Null on a quote product. */
  readonly from_price_formatted?: string | null;
  readonly meeting_point?: { readonly name?: string } | null;
  readonly vessel?: { readonly name?: string } | null;
  readonly age_bands?: readonly {
    readonly uuid: string;
    readonly code: string;
    readonly label: string;
    /** `false` for a band that rides without a seat — an infant on a lap. */
    readonly counts_toward_capacity?: boolean;
  }[];
  readonly extras?: readonly {
    readonly uuid: string;
    readonly name: string;
    readonly price_formatted?: string;
    readonly is_required?: boolean;
  }[];
}

type Phase = 'walking' | 'submitting' | 'redirecting' | 'confirmed' | 'pending' | 'sold_out' | 'expired' | 'failed';

export function BookingMount({
  client,
  productUuid,
  product,
  t,
  analytics,
  locale,
  initialDate = null,
  showVessel = true,
  showDetails = true,
}: BookingMountProps) {
  const options: MachineOptions = { hasExtras: (product.extras ?? []).length > 0 };
  const api = useMemo(() => new BookingApi(client), [client]);

  // A guest who pressed a day on the operator's own calendar starts on the
  // party step with that day chosen (WGT-5 as amended 2026-09-11).
  const [state, dispatch] = useReducer(reduce, null, () => initialStateOn(initialDate, options));
  const [phase, setPhase] = useState<Phase>('walking');
  const [draft, setDraft] = useState<DraftResult | null>(null);
  const [resumeUrl, setResumeUrl] = useState<string | null>(null);
  const [codeRefused, setCodeRefused] = useState(false);
  /** The server's sentence when it refused this party (AVL-26, AVL-26b). */
  const [partyRefusal, setPartyRefusal] = useState<string | null>(null);
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
  // the guest press "continue" and meet a refusal they cannot interpret.
  useEffect(() => {
    if (hold.phase === 'expired' && phase === 'walking') {
      setPhase('expired');
      client.invalidate();
      forgetDraft();
    }
  }, [hold.phase, phase, client, tick]);

  const advance = useCallback(() => dispatch({ type: 'next', options }), [options]);
  const retreat = useCallback(() => dispatch({ type: 'back', options }), [options]);

  /**
   * The draft is created when the guest leaves for checkout, and not before.
   *
   * It used to be created on arrival at the review step so that WGT-19's
   * countdown had something to count. There is no review step now, and the
   * hold's whole purpose is to cover the minutes a guest spends filling in the
   * checkout form — which begin here. Creating it a step earlier would hold
   * seats while somebody browses extras and then abandons.
   *
   * The idempotency key is bound to the party and the date (see
   * `IdempotencyKeys`), so a double click, or a guest who goes back, changes
   * nothing and presses again, does not hold a second set of seats.
   */
  const submit = useCallback(async () => {
    setPhase('submitting');

    try {
      const created = draft ?? (await api.createDraft(state, productUuid, locale));

      if (created.manageToken !== null) {
        // §2.1 footnote 1: this is the one moment the token exists. It is
        // stored before anything else can fail, and it is stored **with** the
        // uuid because reading the booking back needs both.
        rememberDraft(created.uuid, created.manageToken, created.checkoutUrl);
      }

      setDraft(created);
      analytics.emit('kaiki:booking-started', { product_uuid: productUuid, pax: countedPax(state) });

      if (created.checkoutUrl === null) {
        // The server did not say where to send them. Nothing here can invent
        // the address — a URL assembled in a browser from a config value is a
        // guess about somebody else's deployment — so this is reported rather
        // than papered over with a redirect that 404s.
        setPhase('failed');
        analytics.emit('kaiki:error', { product_uuid: productUuid, error_code: 'checkout_failed' });

        return;
      }

      analytics.emit('kaiki:checkout-started', {
        product_uuid: productUuid,
        value_cents: created.totalCents ?? undefined,
      });

      setPhase('redirecting');
      window.location.assign(created.checkoutUrl);
    } catch (error) {
      if (isInvalidDiscountCode(error)) {
        // Nothing was created, so the guest simply stays where they are with
        // a sentence under the code field.
        setCodeRefused(true);
        setPhase('walking');

        return;
      }

      if (isPartyRefused(error)) {
        // AVL-26 and AVL-26b: the party itself is not allowed to sail — no
        // adult with the children, or nobody in a seat at all. Nothing was
        // created, so the guest stays where they are and reads why, exactly
        // like a refused discount code.
        setPartyRefusal(refusalMessage(error));
        setPhase('walking');
        analytics.emit('kaiki:error', { product_uuid: productUuid, error_code: 'party_refused' });

        return;
      }

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
    async (remembered: { uuid: string; token: string; checkoutUrl: string | null }) => {
      const status = await readBookingStatus(client, remembered.uuid, remembered.token);

      if (status === 'confirmed' || status === 'checked_in' || status === 'completed') {
        analytics.emit('kaiki:booking-confirmed', { product_uuid: productUuid });
        forgetDraft();
        setPhase('confirmed');

        return;
      }

      if (status === null || status === 'cancelled' || status === 'expired') {
        // Gone, or unanswerable. Either way there is nothing to resume, and the
        // guest gets a working form rather than an explanation of a booking
        // they may not remember making.
        forgetDraft();
        setPhase('walking');

        return;
      }

      if (status === 'pending_payment') {
        // A payment is genuinely under way: this is the case WGT-20 is about.
        //
        // The checkout address is kept whatever the poll decides, because the
        // guest who is here *because they did not pay* needs somewhere to go —
        // and from this side the two cases are indistinguishable.
        setResumeUrl(remembered.checkoutUrl);
        setPhase('submitting');

        const result = await pollForConfirmation(client, remembered.uuid, remembered.token);

        if (result.outcome === 'confirmed') {
          analytics.emit('kaiki:booking-confirmed', { product_uuid: productUuid });
          forgetDraft();
          setPhase('confirmed');

          return;
        }

        // WGT-20: not a claim. An email will follow when the webhook lands.
        setPhase(result.outcome === 'cancelled' ? 'failed' : 'pending');

        return;
      }

      // Still a draft. They left the checkout page without paying, so the offer
      // is the page they left — not a spinner, and not a fresh empty form that
      // pretends the last five minutes did not happen.
      setResumeUrl(remembered.checkoutUrl);
      setPhase('walking');
    },
    [client, analytics, productUuid],
  );

  useEffect(() => {
    // What survives a page load is `sessionStorage` — **not** the URL. This
    // branch used to read `?kaiki_booking=` and `?kaiki_token=` from the query
    // string, and nothing anywhere ever wrote them, so it could not fire.
    // Issue 111's end-to-end run is what found that; query parameters were also
    // the wrong place on their own terms — see `storage.ts` on why a manage
    // token does not belong in a URL.
    const remembered = recallDraft();

    if (remembered !== null && remembered.token !== null) {
      void resume({ uuid: remembered.uuid, token: remembered.token, checkoutUrl: remembered.checkoutUrl });
    }
  }, [resume]);

  if (phase === 'confirmed') {
    return <Outcome t={t} heading="booking.confirmed.heading" body="booking.confirmed.body" />;
  }

  if (phase === 'pending') {
    // **Both sentences at once, and a way out of the second.**
    //
    // `pending_payment` covers two opposite situations — the money is on its way
    // and the webhook has not landed, or the guest left the gateway's page
    // without paying — and this screen cannot tell them apart, because only the
    // gateway can. It used to say «Η πληρωμή στάλθηκε», which is a claim, and
    // for the guest who never paid it was simply untrue: they were told their
    // payment was sent and given nothing to press.
    //
    // (The server now asks the gateway on the read behind this screen, so most
    // of the time the guest never sees it — they get «Η κράτηση έγινε» instead.
    // This is what is left when the gateway itself has no settled answer yet.)
    return (
      <Outcome
        t={t}
        heading="booking.pending.heading"
        body="booking.pending.body"
        onRetry={resumeUrl === null ? undefined : () => { globalThis.location.href = resumeUrl; }}
        retryKey={resumeUrl === null ? undefined : 'booking.pending.resume'}
        // **Not a cancel.** It forgets the booking *here* and gives the guest a
        // working form back; it does not touch the booking itself. Cancelling
        // would be the one dangerous button on this screen: nobody on this side
        // knows whether the money moved, and «not settled yet» becomes «paid»
        // for anybody finishing 3-D Secure in another tab. The seats are
        // released by `ExpireAbandonedCheckoutsJob` within the hour either way,
        // and the booking stays reachable from its own link and from the
        // operator's panel.
        onDismiss={() => {
          forgetDraft();
          setResumeUrl(null);
          setPhase('walking');
        }}
        dismissKey="booking.pending.dismiss"
      />
    );
  }

  if (phase === 'sold_out') {
    return <Outcome t={t} heading="booking.sold_out.heading" body="booking.sold_out.body" onRetry={() => setPhase('walking')} retryKey="booking.sold_out.retry" />;
  }

  if (phase === 'expired') {
    return (
      <Outcome
        t={t}
        heading="booking.expired.heading"
        body="booking.expired.body"
        onRetry={() => {
          // **Back to the date.** The seats went back to the boat, so the date
          // the guest picked may no longer have room. Everything they chose
          // survives (WGT-18); only the step moves.
          setDraft(null);
          setResumeUrl(null);
          dispatch({ type: 'patch', patch: { step: 'date', departureUuid: null } });
          setPhase('walking');
          client.invalidate();
        }}
        retryKey="booking.expired.retry"
      />
    );
  }

  if (phase === 'failed') {
    return <Outcome t={t} heading="widget.error.generic" body="widget.error.contact" onRetry={() => setPhase('walking')} retryKey="widget.retry" />;
  }

  const last = isLastStep(state, options);

  // ---- the bottom sheet (ADR-0033) ------------------------------------

  const rootRef = useRef<HTMLDivElement>(null);
  const sheet = useSheetMode(rootRef);
  const settled = useSettled(sheet);
  const [open, setOpen] = useState(false);

  const quote = usePriceQuote(client, state, productUuid, locale);

  /**
   * What the bar says, and when it stops hedging.
   *
   * `από 55,00 €` until a real total exists, then the total alone. The prefix is
   * not decoration: it is the difference between an indication and a promise,
   * and a bar still carrying it after two adults have been chosen is lying on
   * every scroll. A quote that failed falls back rather than blanking — the
   * checkout page renders the frozen snapshot and needs no request at all.
   */
  const peekPrice =
    quote.total ??
    (product.from_price_formatted == null
      ? t('booking.peek.price_unknown')
      : t('booking.peek.from').replace(':amount', product.from_price_formatted));

  const peekSummary = useMemo(() => {
    if (state.localDate === null) {
      return t('booking.peek.pick_date');
    }

    const parts = [dayLabel(state.localDate, locale)];

    if (state.localTime !== null) {
      parts.push(state.localTime);
    }

    const people = countedPax(state);

    if (people > 0) {
      parts.push(
        people === 1
          ? t('booking.peek.people_one')
          : t('booking.peek.people_many').replace(':count', String(people)),
      );
    }

    return parts.join(' · ');
  }, [state, locale, t]);

  // Escape closes, and the focus goes back to the bar that opened it rather
  // than to the top of somebody's page (WGT-21).
  useEffect(() => {
    if (!sheet || !open) {
      return;
    }

    const root = rootRef.current;
    const view = root?.ownerDocument?.defaultView;

    if (view == null) {
      return;
    }

    const onKey = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        setOpen(false);
        root?.querySelector<HTMLButtonElement>('.kaiki-peek')?.focus();
      }
    };

    view.addEventListener('keydown', onKey);

    return () => {
      view.removeEventListener('keydown', onKey);
    };
  }, [sheet, open]);

  // A walk that has started is a walk worth showing. Opening on the first
  // choice spares a guest a second tap to see what they just did.
  useEffect(() => {
    if (sheet && state.step !== 'date') {
      setOpen(true);
    }
  }, [sheet, state.step]);

  return (
    <>
      {/* The page behind an open sheet is not scrolled past and not tapped
          through. A button rather than a div, so Escape is not the only way out
          for somebody who never reaches for Escape. */}
      {sheet && open ? (
        <button type="button" class="kaiki-scrim" aria-label={t('booking.sheet.close')} onClick={() => setOpen(false)} />
      ) : null}

      <div class="kaiki-booking" ref={rootRef} data-sheet={sheet} data-open={sheet && open} data-settled={settled}>
        {sheet ? (
          <Peek
            price={peekPrice}
            summary={peekSummary}
            action={last ? t('booking.checkout') : t('booking.next')}
            ready={canAdvance(state, options)}
            open={open}
            onToggle={() => setOpen((was) => !was)}
          />
        ) : null}

        <div class="kaiki-sheet-scroll">
          {showDetails ? <FourLines product={product} t={t} showVessel={showVessel} /> : null}

          {draft !== null ? <Hold hold={hold} t={t} /> : null}

          {resumeUrl !== null ? (
            <p class="kaiki-resume">
              <a href={resumeUrl}>{t('booking.resume')}</a>
            </p>
          ) : null}

          <StepView
            step={state.step}
            state={state}
            product={product}
            client={client}
            productUuid={productUuid}
            t={t}
            onChange={(patch) => {
              // Any change to the party is a new answer to the rule that
              // refused the last one, so the sentence goes as the guest acts.
              setPartyRefusal(null);
              dispatch({ type: 'patch', patch });
            }}
          />

          {partyRefusal !== null ? (
            // The server's own sentence — «Σε αυτή την εκδρομή τα παιδιά
            // ταξιδεύουν με συνοδό ενήλικα», or whatever rule the catalogue
            // grows next. The widget keeps no wording of its own for these on
            // purpose: the rule and its explanation ship together.
            <p class="kaiki-error" role="alert">
              {partyRefusal}
            </p>
          ) : null}
        </div>

        {last ? (
          <label class="kaiki-field kaiki-discount">
            <span>{t('booking.discount.label')}</span>
            <input
              type="text"
              maxLength={32}
              autoComplete="off"
              value={state.voucherCode}
              onInput={(event) => {
                setCodeRefused(false);
                dispatch({ type: 'patch', patch: { voucherCode: (event.currentTarget as HTMLInputElement).value } });
              }}
            />
            {codeRefused ? (
              <span class="kaiki-error" role="alert">
                {t('booking.discount.refused')}
              </span>
            ) : null}
          </label>
        ) : null}

        <div class="kaiki-actions">
          {state.step !== 'date' ? (
            <button type="button" class="kaiki-button kaiki-button-ghost" onClick={retreat}>
              {t('booking.back')}
            </button>
          ) : null}

          {last ? (
            <button
              type="button"
              class="kaiki-button"
              onClick={() => void submit()}
              disabled={phase !== 'walking' || !canAdvance(state, options)}
            >
              {t(phase === 'walking' ? 'booking.checkout' : 'booking.submitting')}
            </button>
          ) : (
            <button type="button" class="kaiki-button" onClick={advance} disabled={!canAdvance(state, options)}>
              {t('booking.next')}
            </button>
          )}
        </div>
      </div>
    </>
  );
}

function StepView({
  step,
  state,
  product,
  client,
  productUuid,
  t,
  onChange,
}: {
  readonly step: Step;
  readonly state: BookingState;
  readonly product: ProductSummary;
  readonly client: Api;
  readonly productUuid: string;
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  switch (step) {
    case 'date':
      // The date step reads availability of its own: it draws a month with the
      // sold-out days marked, which is the whole reason it is no longer a
      // native date input.
      return <DateStep state={state} client={client} productUuid={productUuid} t={t} onChange={onChange} />;
    case 'party':
      return <PartyStep state={state} bands={product.age_bands ?? []} t={t} onChange={onChange} />;
    case 'extras':
      return <ExtrasStep state={state} extras={product.extras ?? []} t={t} onChange={onChange} />;
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
  onDismiss,
  dismissKey,
}: {
  readonly t: Translator;
  readonly heading: string;
  readonly body: string;
  readonly onRetry?: () => void;
  readonly retryKey?: string;
  /** A quieter second way out, where an outcome has two honest answers. */
  readonly onDismiss?: () => void;
  readonly dismissKey?: string;
}) {
  return (
    // `alert` rather than `status`: this replaced what the guest was doing, and
    // a screen reader should interrupt rather than wait for a pause (A11Y-1).
    //
    // `h3` and not a styled paragraph, matching the steps: an outcome that looks
    // like a heading and is not one is invisible to somebody navigating by
    // headings, which is how a screen-reader user reads a page they did not
    // write. It is the same level the steps use, because it replaces one.
    <div class="kaiki-error" role="alert">
      <h3 class="kaiki-heading">{t(heading as never)}</h3>
      <p>{t(body as never)}</p>
      {onRetry !== undefined && retryKey !== undefined ? (
        <button type="button" class="kaiki-button" onClick={onRetry}>
          {t(retryKey as never)}
        </button>
      ) : null}

      {onDismiss !== undefined && dismissKey !== undefined ? (
        <button type="button" class="kaiki-button kaiki-button-quiet" onClick={onDismiss}>
          {t(dismissKey as never)}
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


/**
 * «16 Σεπ» — the day and the month, in the guest's language.
 *
 * `Intl` rather than a table of month names: the bundles carry sentences, not
 * calendars, and a date is one of the few things every browser already knows
 * how to say. The year is left out because the bar has one line and a trip
 * being booked is nearly always this one or next.
 */
function dayLabel(localDate: string, locale: string): string {
  const date = new Date(`${localDate}T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return localDate;
  }

  try {
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(date);
  } catch {
    // An unknown locale tag is not a reason to show nothing.
    return localDate;
  }
}
