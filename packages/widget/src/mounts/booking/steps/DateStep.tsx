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

  const { statuses, loading, failed } = useAvailability(client, productUuid, month);

  return (
    <div class="kaiki-step">
      <h3 class="kaiki-heading">{t('booking.date.heading')}</h3>

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
            onInput={(event) => onChange({ localDate: (event.currentTarget as HTMLInputElement).value || null })}
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
            // The departure is cleared with the date: it belonged to the day
            // that was chosen before, and carrying it forward would book a
            // sailing on a date nobody picked.
            onChange({ localDate: date, departureUuid: null })
          }
        />
      )}
    </div>
  );
}
