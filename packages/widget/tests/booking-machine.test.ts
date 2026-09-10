import { describe, expect, it } from 'vitest';

import {
  back,
  canAdvance,
  draftPayload,
  initialState,
  isLastStep,
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
    // Keyed by the band's **uuid**, which is what `PaxSelection` carries.
    pax: { 'adult-uuid': 2, 'child-uuid': 1 },
    extras: { 'extra-uuid': 1 },
    voucherCode: 'SUMMER',
  };
}

describe('the walk', () => {
  it('is date, party, extras — and nothing that asks who you are', () => {
    // ADR-0030. The name, the telephone number, the manifest and the consent
    // are typed on the checkout page, on a full-width screen the guest reaches
    // after this walk rather than inside a 380-pixel embed.
    expect(stepsFor(withExtras)).toEqual(['date', 'party', 'extras']);
  });

  it('skips extras for a product that has none, in both directions', () => {
    expect(stepsFor(withoutExtras)).toEqual(['date', 'party']);

    let state: BookingState = { ...filled(), step: 'date' };

    state = next(state, withoutExtras);
    expect(state.step).toBe('party');

    // The skip is symmetric: a guest going back lands where they came from. An
    // asymmetric skip is how a guest ends up on a step they have never seen.
    state = back(state, withoutExtras);
    expect(state.step).toBe('date');
  });

  it('knows where the walk ends, with extras and without', () => {
    // The button changes from «Συνέχεια» to «Συνέχεια στην κράτηση» here, and a
    // literal `'extras'` would strand a guest on a product that has none.
    expect(isLastStep({ ...filled(), step: 'extras' }, withExtras)).toBe(true);
    expect(isLastStep({ ...filled(), step: 'party' }, withExtras)).toBe(false);
    expect(isLastStep({ ...filled(), step: 'party' }, withoutExtras)).toBe(true);
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

    expect(state.step).toBe('extras');

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
  });

  it('does not go back past the first step or forward past the last', () => {
    const first = { ...filled(), step: 'date' as const };
    const last = { ...filled(), step: 'extras' as const };

    expect(back(first, withExtras).step).toBe('date');
    expect(next(last, withExtras).step).toBe('extras');
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

    // `age_band_uuid`, not `band_code`. These assertions said `band_code` until
    // issue 111's end-to-end run posted a real booking and the server refused
    // it — a mock transport never reads a field name, so the wrong one
    // round-tripped through this suite for two issues.
    expect(payload['pax']).toEqual([
      { age_band_uuid: 'adult-uuid', qty: 2 },
      { age_band_uuid: 'child-uuid', qty: 1 },
    ]);
    expect(payload['extras']).toEqual([{ extra_uuid: 'extra-uuid', qty: 1 }]);
    expect(payload['locale']).toBe('el');
  });

  it('drops a band nobody booked rather than sending a zero', () => {
    const payload = draftPayload({ ...filled(), pax: { 'adult-uuid': 2, 'infant-uuid': 0 } }, 'product-uuid', 'en');

    expect(payload['pax']).toEqual([{ age_band_uuid: 'adult-uuid', qty: 2 }]);
  });

  it('names nobody, and claims no consent', () => {
    // ADR-0030. Both are collected on the checkout page. Sending
    // `terms_accepted: true` from here would stamp a consent for a box that was
    // never on screen — the one kind of evidence worse than none — so the field
    // is absent rather than false.
    const payload = draftPayload(filled(), 'product-uuid', 'en');

    expect(payload).not.toHaveProperty('guest');
    expect(payload).not.toHaveProperty('terms_accepted');
    expect(payload).not.toHaveProperty('special_requests');
  });
});
