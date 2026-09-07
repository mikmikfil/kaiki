import { describe, expect, it } from 'vitest';

import {
  back,
  canAdvance,
  draftPayload,
  initialState,
  next,
  stepsFor,
  type BookingState,
  type MachineOptions,
} from '../src/booking/machine';

/*
 * WGT-18, and the one line of it that matters: *"Back navigation between steps
 * never loses entered data."*
 *
 * It is the single most commonly broken thing in a multi-step form, and it
 * breaks the same way every time — each step owns its own state, going back
 * unmounts the component, and the answers go with it. So the state is one object
 * the machine owns and the steps are views onto it, and this file walks to the
 * end, walks back to the beginning and asserts every field survived.
 */

const withExtras: MachineOptions = { hasExtras: true };
const withoutExtras: MachineOptions = { hasExtras: false };

function filled(): BookingState {
  return {
    ...initialState(),
    localDate: '2026-07-18',
    departureUuid: 'departure-uuid',
    pax: { adult: 2, child: 1 },
    extras: { 'extra-uuid': 1 },
    voucherCode: 'SUMMER',
    guest: {
      full_name: 'Μαρία Παπαδοπούλου',
      email: 'maria@example.com',
      phone: '+306912345678',
      special_requests: 'Ένα παιδί έχει αλλεργία στα θαλασσινά.',
      terms_accepted: true,
    },
  };
}

describe('the walk', () => {
  it('is date, party, extras, contact, review', () => {
    expect(stepsFor(withExtras)).toEqual(['date', 'party', 'extras', 'contact', 'review']);
  });

  it('skips extras for a product that has none, in both directions', () => {
    expect(stepsFor(withoutExtras)).toEqual(['date', 'party', 'contact', 'review']);

    let state: BookingState = { ...filled(), step: 'party' };

    state = next(state, withoutExtras);
    expect(state.step).toBe('contact');

    // The skip is symmetric: a guest going back from contact lands on party,
    // which is where they came from. An asymmetric skip is how a guest ends up
    // on a step they have never seen.
    state = back(state, withoutExtras);
    expect(state.step).toBe('party');
  });
});

describe('back navigation', () => {
  it('preserves every field through a full round trip', () => {
    const original = filled();
    let state: BookingState = { ...original, step: 'date' };

    // Forwards to the end.
    for (const _ of stepsFor(withExtras)) {
      state = next(state, withExtras);
    }

    expect(state.step).toBe('review');

    // And all the way back.
    for (const _ of stepsFor(withExtras)) {
      state = back(state, withExtras);
    }

    expect(state.step).toBe('date');

    // Field by field, because "toEqual on the whole object" would pass on a
    // machine that reset everything to the same defaults it started with.
    expect(state.localDate).toBe(original.localDate);
    expect(state.departureUuid).toBe(original.departureUuid);
    expect(state.pax).toEqual(original.pax);
    expect(state.extras).toEqual(original.extras);
    expect(state.voucherCode).toBe(original.voucherCode);
    expect(state.guest).toEqual(original.guest);
  });

  it('does not go back past the first step or forward past the last', () => {
    const first = { ...filled(), step: 'date' as const };
    const last = { ...filled(), step: 'review' as const };

    expect(back(first, withExtras).step).toBe('date');
    expect(next(last, withExtras).step).toBe('review');
  });
});

describe('what each step needs before it will advance', () => {
  it('will not leave the date step with no date', () => {
    expect(canAdvance(initialState(), withExtras)).toBe(false);
    expect(canAdvance({ ...initialState(), localDate: '2026-07-18' }, withExtras)).toBe(true);
  });

  it('will not leave the party step with nobody in it', () => {
    const empty = { ...filled(), step: 'party' as const, pax: {} };

    expect(canAdvance(empty, withExtras)).toBe(false);
    expect(canAdvance({ ...empty, pax: { adult: 1 } }, withExtras)).toBe(true);
  });

  it('lets a guest past extras without choosing any', () => {
    // The step exists to offer, not to ask.
    expect(canAdvance({ ...filled(), step: 'extras', extras: {} }, withExtras)).toBe(true);
  });

  it('requires a name, an email and the consent before contact is done', () => {
    const contact = { ...filled(), step: 'contact' as const };

    expect(canAdvance(contact, withExtras)).toBe(true);
    expect(canAdvance({ ...contact, guest: { ...contact.guest, terms_accepted: false } }, withExtras)).toBe(false);
    expect(canAdvance({ ...contact, guest: { ...contact.guest, full_name: '  ' } }, withExtras)).toBe(false);
    expect(canAdvance({ ...contact, guest: { ...contact.guest, email: 'not-an-email' } }, withExtras)).toBe(false);
  });

  it('refuses to advance rather than advancing with a gap', () => {
    const empty = initialState();

    // `next()` is a no-op when the step is not satisfied: the guard and the
    // move are the same function, so a caller cannot skip the check.
    expect(next(empty, withExtras)).toBe(empty);
  });
});

describe('the draft payload', () => {
  it('carries no price of any kind', () => {
    const payload = JSON.stringify(draftPayload(filled(), 'product-uuid', 'el'));

    // PRC-1 and WGT-13. The server recomputes from the same inputs; a total in
    // this body would be a number the server has to decide whether to trust.
    expect(payload).not.toContain('cents');
    expect(payload).not.toContain('total');
    expect(payload).not.toContain('price');
  });

  it('sends the party and the extras as the contract shapes them', () => {
    const payload = draftPayload(filled(), 'product-uuid', 'el');

    expect(payload['pax']).toEqual([
      { band_code: 'adult', qty: 2 },
      { band_code: 'child', qty: 1 },
    ]);
    expect(payload['extras']).toEqual([{ extra_uuid: 'extra-uuid', qty: 1 }]);
    expect(payload['terms_accepted']).toBe(true);
    expect(payload['locale']).toBe('el');
  });

  it('drops a band nobody booked rather than sending a zero', () => {
    const payload = draftPayload({ ...filled(), pax: { adult: 2, infant: 0 } }, 'product-uuid', 'en');

    expect(payload['pax']).toEqual([{ band_code: 'adult', qty: 2 }]);
  });
});
