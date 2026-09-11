import { describe, expect, it } from 'vitest';

import { back, initialStateOn } from '../src/booking/machine';
import { withDate } from '../src/mounts/calendar/CalendarMount';

/*
 * The calendar-to-trip-page flow (WGT-5 as amended 2026-09-11).
 *
 * A guest presses a day on the operator's own site, lands on the trip page on
 * Kaiki with `?date=`, and the booking walk opens on the party step with that
 * day chosen. Two small functions carry it, and each is asserted directly.
 */

describe('a walk that arrives with a date', () => {
  it('starts on the party step, with the day already chosen', () => {
    const state = initialStateOn('2026-10-03', { hasExtras: true });

    expect(state.step).toBe('party');
    expect(state.localDate).toBe('2026-10-03');
  });

  it('still lets the guest go back and see the day they chose', () => {
    const options = { hasExtras: false };
    const state = back(initialStateOn('2026-10-03', options), options);

    expect(state.step).toBe('date');
    expect(state.localDate).toBe('2026-10-03');
  });

  it('starts on the date step as it always did when no day was chosen', () => {
    const state = initialStateOn(null, { hasExtras: true });

    expect(state.step).toBe('date');
    expect(state.localDate).toBeNull();
  });
});

describe('the address a calendar day leads to', () => {
  it('adds the day to the trip page address', () => {
    expect(withDate('https://book.kaiki.app/aegean-blue/sunset', '2026-10-03')).toBe(
      'https://book.kaiki.app/aegean-blue/sunset?date=2026-10-03',
    );
  });

  it('keeps a language that is already on the address', () => {
    // A second `?` would have made the date part of the language.
    expect(withDate('https://book.kaiki.app/aegean-blue/sunset?lang=en', '2026-10-03')).toBe(
      'https://book.kaiki.app/aegean-blue/sunset?lang=en&date=2026-10-03',
    );
  });
});
