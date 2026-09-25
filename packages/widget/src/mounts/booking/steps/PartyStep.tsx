import { seatedPax } from '../../../booking/machine';
import type { BookingState } from '../../../booking/machine';
import type { Translator } from '../../../i18n';

/**
 * Step two: how many, by age band.
 *
 * The bands come from the product (CAT-8) rather than from a fixed
 * adult/child/infant list, because an operator decides what they sell seats to.
 *
 * **No price appears here.** PRC-1 and WGT-13: the total comes from the server
 * at review, and a widget multiplying a band price by a quantity would be
 * computing one.
 *
 * ## The ceiling is the boat, not a round number
 *
 * Every field was `max="99"`, on every product, whatever was floating. A guest
 * could ask a twelve-seat caique for forty people, walk the rest of the way,
 * and be refused at the far end by `insufficient_capacity` — after the details,
 * after the manifest, at the button that takes money. The seats left on the
 * chosen departure came back with the availability a step earlier
 * (`state.seatsAvailable`), so the ceiling is known here and the refusal can
 * happen where the mistake is made.
 *
 * It is a ceiling on the **party**, not on the field: eleven seats left and
 * eight adults already chosen leaves three for the children. Bands that ride
 * without a seat are not counted against it — see `seatedPax`.
 *
 * Null seats mean nobody told us: a per-vessel window, a quote product, or a
 * day with more than one sailing. Then there is no honest ceiling and the field
 * is left open, exactly as it was.
 */
export function PartyStep({
  state,
  bands,
  t,
  onChange,
}: {
  readonly state: BookingState;
  readonly bands: readonly {
    readonly uuid: string;
    readonly code: string;
    readonly label: string;
    readonly counts_toward_capacity?: boolean;
  }[];
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  const seats = state.seatsAvailable;
  const taken = seatedPax(state, bands);

  return (
    <div class="kaiki-step">
      {/*
        The day and the time already chosen, with a way back to the day's other
        times (2026-09-25). A guest who arrives from the departures calendar
        lands here without having seen the date step, and «Σάββατο 10 Οκτωβρίου
        · 09:30» is what tells them the calendar's choice came with them.
      */}
      {state.localDate !== null && state.localTime !== null ? (
        <p class="kaiki-picked">
          <span>{pickedLabel(state.localDate, state.localTime, t.locale)}</span>
          <button type="button" class="kaiki-link" onClick={() => onChange({ step: 'date' })}>
            {t('booking.party.change')}
          </button>
        </p>
      ) : null}

      <h3 class="kaiki-heading">{t('booking.party.heading')}</h3>

      {bands.map((band) => {
        const qty = state.pax[band.uuid] ?? 0;
        const free = band.counts_toward_capacity === false;

        // What this band alone could be raised to: everything left over once
        // the other seated bands have taken theirs.
        const ceiling = seats === null || free ? undefined : Math.max(qty, seats - (taken - (free ? 0 : qty)));

        // Keyed by uuid, which is what the contract's `PaxSelection` carries.
        // The code is the operator's label for it.
        //
        // Clamped here as well as in `max`, because `max` on a number input is
        // advice to the spinner and no obstacle at all to a typed digit or a
        // paste.
        const set = (asked: number) =>
          onChange({
            pax: {
              ...state.pax,
              [band.uuid]: ceiling === undefined
                ? Math.max(0, asked)
                : Math.min(Math.max(0, asked), ceiling),
            },
          });

        return (
          <label class="kaiki-field kaiki-inline" key={band.uuid}>
            <span>{band.label}</span>

            {/*
              **− αριστερά, ο αριθμός στη μέση, + δεξιά** (Mike, 2026-09-23).

              A bare `type="number"` has no spinner on a phone, so the only way
              to go from one adult to two was to raise the keyboard, which
              covers the sheet, and type. The two buttons put the whole
              adjustment under a thumb, on either side of the figure they
              change, and the field stays typeable for the party of eleven.

              The buttons are `type="button"`: inside a form, a bare button
              submits.
            */}
            <span class="kaiki-stepper">
              <button
                type="button"
                class="kaiki-step-down"
                aria-label={`${t('booking.party.fewer')} — ${band.label}`}
                disabled={qty <= 0}
                onClick={() => set(qty - 1)}
              >
                &minus;
              </button>

              <input
                type="number"
                min="0"
                max={ceiling === undefined ? undefined : String(ceiling)}
                inputMode="numeric"
                value={String(qty)}
                onInput={(event) => set(Number((event.currentTarget as HTMLInputElement).value) || 0)}
              />

              <button
                type="button"
                class="kaiki-step-up"
                aria-label={`${t('booking.party.more')} — ${band.label}`}
                disabled={ceiling !== undefined && qty >= ceiling}
                onClick={() => set(qty + 1)}
              >
                +
              </button>
            </span>
          </label>
        );
      })}

      {/* Only once they are running out. A departure with eleven free has no
          reason to say so — that is pressure without information. */}
      {seats !== null && seats - taken <= 3 ? (
        <p class="kaiki-muted" role="status">
          {left(seats - taken, t)}
        </p>
      ) : null}
    </div>
  );
}

/**
 * «Σάββατο 10 Οκτωβρίου · 09:30», in the guest's language, from `Intl`.
 */
export function pickedLabel(localDate: string, localTime: string, locale: string): string {
  const date = new Date(`${localDate}T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return `${localDate} · ${localTime}`;
  }

  try {
    return `${new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(date)} · ${localTime}`;
  } catch {
    return `${localDate} · ${localTime}`;
  }
}

/**
 * Three sentences rather than one with a number in it.
 *
 * `t()` is a lookup and a `replace`; it has no plural rules, and «Μένουν 1
 * θέσεις» is what pretending otherwise produces. Zero gets its own sentence
 * because it is not a count at all — the party has taken the boat.
 */
function left(seats: number, t: Translator): string {
  if (seats <= 0) {
    return t('booking.party.seats_full');
  }

  return seats === 1
    ? t('booking.party.seats_left_one')
    : t('booking.party.seats_left_many').replace(':count', String(seats));
}
