import { useState } from 'preact/hooks';

import type { Api } from '../../../api-client';
import type { BookingState } from '../../../booking/machine';
import type { Translator } from '../../../i18n';
import { MonthGrid, startOfMonth, useAvailability } from '../../calendar/MonthGrid';

/**
 * Step one: when.
 *
 * ## It was a native date input, and that was the wrong trade
 *
 * `<input type="date">` is keyboard-operable, screen-reader-labelled, localised
 * by the guest's own device and costs nothing against the 80 KB budget (WGT-2).
 * Every one of those is true and it was still wrong, for a reason none of them
 * touch: **it cannot say which days are sold out**. A guest picked a full
 * Saturday, pressed continue, and met a refusal — and on the operator's own trip
 * page they did it while a month grid showing exactly that was on screen beside
 * it, because #108's calendar mount was rendering the same availability the date
 * field knew nothing about.
 *
 * It also rendered as «mm/dd/yyyy» on a Greek page, which is what a native
 * control localised by the *device* means when the device is set to English.
 *
 * So this is {@see MonthGrid} with the days that can be booked as buttons: the
 * same grid, the same colours and the same legend as the calendar mount, because
 * they are the same month.
 *
 * ## Only a day with room is pressable
 *
 * A sold-out day is drawn and labelled and does nothing, rather than being
 * hidden. «Full» is an answer a guest can act on — they look at the next
 * weekend — and a missing day is a guest wondering whether the boat exists.
 */
export function DateStep({
  state,
  client,
  productUuid,
  t,
  onChange,
}: {
  readonly state: BookingState;
  readonly client: Api;
  readonly productUuid: string;
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  // Opened on the month the guest already chose, if they went back a step, and
  // on this month otherwise. Returning to the date step and finding January is
  // WGT-18's promise broken in the one place it is most visible.
  const [month, setMonth] = useState(() =>
    startOfMonth(state.localDate === null ? new Date() : new Date(`${state.localDate}T00:00:00`)),
  );

  const { statuses, departures, loading, failed } = useAvailability(client, productUuid, month);

  /**
   * What picking a day answers, beyond the day.
   *
   * A `per_seat` day with **one** sailing has already answered which sailing,
   * and asking again would be asking a question with one possible answer. So
   * the departure, its local time and its remaining seats are taken with the
   * date — which is what lets the party step cap its stepper and the bar say
   * «09:00».
   *
   * A day with **more than one** is only half answered. It raises
   * `awaitingDeparture`, which stops the walk on this step, and the times
   * appear below the grid for the guest to choose between.
   */
  const pick = (date: string): Partial<BookingState> => {
    const options = departures.get(date) ?? [];
    const only = options.length === 1 ? options[0] : undefined;

    return {
      localDate: date,
      departureUuid: only?.uuid ?? null,
      localTime: only?.window.local_time ?? null,
      seatsAvailable: only?.seats_available ?? null,
      awaitingDeparture: options.length > 1,
    };
  };

  /** The sailings on the chosen day, when there is a choice to make. */
  const choices = state.localDate === null ? [] : (departures.get(state.localDate) ?? []);

  return (
    <div class="kaiki-step">
      {/*
        The heading is read, not seen (Mike, 2026-09-22). A month grid with the
        month's name over it, arrows either side of it and a legend under it is
        already a calendar; the line above it said out loud what the thing
        underneath it plainly is. It stays in the markup because the step is a
        section of a walk and a screen reader arrives at it with no month grid
        to look at.
      */}
      <h3 class="kaiki-heading kaiki-visually-hidden">{t('booking.date.heading')}</h3>

      {failed ? (
        // The grid could not be drawn, so the native control is the fallback
        // rather than nothing at all. It cannot show availability — that is the
        // whole reason it is not the first choice — but a guest who can still
        // type a date can still book, and the server refuses a full one.
        <label class="kaiki-field">
          <span>{t('booking.date.label')}</span>
          <input
            type="date"
            value={state.localDate ?? ''}
            onInput={(event) =>
              // No grid means no departures were read, so the three facts the
              // grid would have supplied are cleared rather than left stale
              // from a day the guest has just replaced.
              onChange({
                localDate: (event.currentTarget as HTMLInputElement).value || null,
                departureUuid: null,
                localTime: null,
                seatsAvailable: null,
              })
            }
          />
        </label>
      ) : (
        <MonthGrid
          month={month}
          statuses={statuses}
          loading={loading}
          t={t}
          onMonth={setMonth}
          selected={state.localDate}
          onSelect={(date) =>
            // Everything the old day answered is replaced, never merged: the
            // departure, its time and its seat count belonged to the day that
            // was chosen before, and carrying any of them forward would book a
            // sailing on a date nobody picked.
            onChange(pick(date))
          }
        />
      )}

      {/* The sailings, when the day has more than one.

          Below the grid rather than on a step of its own: it is the second half
          of one question — «which day, and which of that day's departures» — and
          a step between the calendar and the party would put a whole screen
          between choosing a Tuesday and saying who is coming.

          It appears only when there is a choice. A day that sails once has
          already been answered by the tap on the day, and a list of one is a
          question with no alternative. */}
      {/* Since 2026-09-16 a day with one sailing shows its time too, already
          chosen: a guest asked «what time do we leave?» and the box never
          said. Several sailings are the same chips, and one has to be picked. */}
      {choices.length > 0 ? (
        <fieldset class={choices.length > 1 ? 'kaiki-times' : 'kaiki-times kaiki-times-one'}>
          <legend>{t(choices.length > 1 ? 'booking.date.which_departure' : 'booking.date.departure_time')}</legend>

          {choices.map((option) => (
            <label class="kaiki-time" key={option.uuid}>
              <input
                type="radio"
                name="kaiki-departure"
                checked={state.departureUuid === option.uuid}
                onChange={() =>
                  onChange({
                    departureUuid: option.uuid,
                    localTime: option.window.local_time,
                    seatsAvailable: option.seats_available,
                    awaitingDeparture: false,
                  })
                }
              />
              <span class="kaiki-time-at">{option.window.local_time}</span>

              {/* Only when they are running out, for the same reason the party
                  step only says it then: a sailing with eleven free seats has
                  nothing useful to add here. */}
              {option.seats_available <= 3 ? (
                <span class="kaiki-time-left">
                  {option.seats_available <= 0
                    ? t('booking.party.seats_full')
                    : option.seats_available === 1
                      ? t('booking.party.seats_left_one')
                      : t('booking.party.seats_left_many').replace(':count', String(option.seats_available))}
                </span>
              ) : null}
            </label>
          ))}
        </fieldset>
      ) : null}
    </div>
  );
}
