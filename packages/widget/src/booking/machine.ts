/**
 * The booking walk (WGT-18), as a machine rather than a pile of flags.
 *
 * ## Back navigation never loses anything, and that is the whole design
 *
 * WGT-18: *"Back navigation between steps never loses entered data."* The
 * single most commonly broken thing in a multi-step form, and it breaks the
 * same way every time — each step owns its own state, going back unmounts the
 * component, and the state goes with it.
 *
 * So the state is **one object, owned by the machine**, and the steps are views
 * onto it. `back()` moves a cursor; it does not touch a field. There is no code
 * path in this file that clears an answer, which is what makes the guarantee
 * checkable: `machine.test.ts` walks to the end, walks back to the beginning and
 * asserts every field survived.
 *
 * ## Extras are skipped rather than shown empty
 *
 * A product with no extras has nothing to ask about, and a step that says "no
 * extras available" is a click a guest pays for with attention. `next()` and
 * `back()` both skip it, so the skip is symmetric — a guest going back from
 * contact lands on party, which is where they came from.
 *
 * ## The machine knows nothing about HTTP
 *
 * It holds answers and a cursor. The mount does the asking. That is what lets
 * every rule here be tested without a server, a browser or a clock.
 */

export const STEPS = ['date', 'party', 'extras', 'contact', 'review'] as const;

export type Step = (typeof STEPS)[number];

export interface PaxSelection {
  readonly age_band_uuid: string;
  readonly qty: number;
}

export interface ExtraSelection {
  readonly extra_uuid: string;
  readonly qty: number;
}

export interface GuestDetails {
  full_name: string;
  email: string;
  phone: string;
  /** Free text the operator reads verbatim; never translated (§3.4). */
  special_requests: string;
  /** Recorded as proof the guest saw the policy before paying. */
  terms_accepted: boolean;
}

export interface BookingState {
  step: Step;
  departureUuid: string | null;
  localDate: string | null;
  localTime: string | null;
  pax: Record<string, number>;
  extras: Record<string, number>;
  voucherCode: string;
  guest: GuestDetails;
}

export interface MachineOptions {
  /** A product with none skips the extras step entirely. */
  readonly hasExtras: boolean;
}

export function initialState(): BookingState {
  return {
    step: 'date',
    departureUuid: null,
    localDate: null,
    localTime: null,
    pax: {},
    extras: {},
    voucherCode: '',
    guest: { full_name: '', email: '', phone: '', special_requests: '', terms_accepted: false },
  };
}

/**
 * The steps this product actually has.
 *
 * Derived rather than stored, so a product whose extras arrive after the first
 * render does not leave the machine with a stale idea of its own shape.
 */
export function stepsFor(options: MachineOptions): Step[] {
  return STEPS.filter((step) => step !== 'extras' || options.hasExtras);
}

export function canAdvance(state: BookingState, options: MachineOptions): boolean {
  switch (state.step) {
    case 'date':
      // A date without a departure is a per-vessel window; both are answers.
      return state.departureUuid !== null || state.localDate !== null;
    case 'party':
      return countedPax(state) > 0;
    case 'extras':
      // Extras are optional by definition. The step exists to offer, not to ask.
      return true;
    case 'contact':
      return (
        state.guest.full_name.trim() !== '' &&
        // The pattern is deliberately loose: the server validates properly and
        // a widget that refuses a valid address it does not recognise is worse
        // than one that lets the server say so.
        /.+@.+\..+/.test(state.guest.email.trim()) &&
        state.guest.terms_accepted
      );
    case 'review':
      return true;
    default:
      return false;
  }
}

export function next(state: BookingState, options: MachineOptions): BookingState {
  if (!canAdvance(state, options)) {
    return state;
  }

  const steps = stepsFor(options);
  const index = steps.indexOf(state.step);

  // Nothing is cleared on the way forward either: a guest who advances, goes
  // back and advances again meets their own answers, not a reset form.
  return index < 0 || index === steps.length - 1 ? state : { ...state, step: steps[index + 1] as Step };
}

export function back(state: BookingState, options: MachineOptions): BookingState {
  const steps = stepsFor(options);
  const index = steps.indexOf(state.step);

  return index <= 0 ? state : { ...state, step: steps[index - 1] as Step };
}

export function goTo(state: BookingState, step: Step, options: MachineOptions): BookingState {
  return stepsFor(options).includes(step) ? { ...state, step } : state;
}

/** How many people the party has, which is what capacity is measured in. */
export function countedPax(state: BookingState): number {
  return Object.values(state.pax).reduce((total, qty) => total + Math.max(0, qty), 0);
}

/**
 * The party in the shape `POST /bookings` wants (§3.4).
 *
 * **Keyed by the band's uuid, not its code.** It was the code until issue 111's
 * end-to-end run posted a real booking and the server refused it: the contract's
 * `PaxSelection` requires `age_band_uuid`, and a code is an operator-facing
 * label that two tenants may both call `adult`. Every unit test until then
 * mocked the transport, so the wrong field name round-tripped happily through a
 * mock that never read it.
 */
export function paxSelections(state: BookingState): PaxSelection[] {
  return Object.entries(state.pax)
    .filter(([, qty]) => qty > 0)
    .map(([age_band_uuid, qty]) => ({ age_band_uuid, qty }));
}

export function extraSelections(state: BookingState): ExtraSelection[] {
  return Object.entries(state.extras)
    .filter(([, qty]) => qty > 0)
    .map(([extra_uuid, qty]) => ({ extra_uuid, qty }));
}

/**
 * The request body for `POST /bookings`.
 *
 * **No prices.** The contract is explicit that the server recomputes from the
 * same inputs it would have priced, and PRC-1 says the widget never computes
 * one. Sending a total would be offering the server a number it must then
 * decide whether to trust.
 */
export function draftPayload(state: BookingState, productUuid: string, locale: string): Record<string, unknown> {
  return {
    product_uuid: productUuid,
    departure_uuid: state.departureUuid,
    window:
      state.departureUuid === null && state.localDate !== null
        ? { local_date: state.localDate, local_time: state.localTime }
        : null,
    pax: paxSelections(state),
    extras: extraSelections(state),
    voucher_code: state.voucherCode.trim() === '' ? null : state.voucherCode.trim(),
    guest: {
      // `name`, because that is what `LeadGuest` is called in the contract. The
      // widget's own form field stays `full_name` — it is a clearer label for a
      // person filling one in — and this is the one place the two meet.
      name: state.guest.full_name.trim(),
      email: state.guest.email.trim(),
      phone: state.guest.phone.trim() === '' ? null : state.guest.phone.trim(),
    },
    special_requests: state.guest.special_requests.trim() === '' ? null : state.guest.special_requests.trim(),
    locale,
    terms_accepted: state.guest.terms_accepted,
  };
}
