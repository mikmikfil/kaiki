import type { BookingState } from '../../../booking/machine';
import type { Translator } from '../../../i18n';

/**
 * Step one: when.
 *
 * A native `<input type="date">` rather than a hand-built calendar, and the
 * reason is the 80 KB budget (WGT-2) meeting A11Y-1: the native control is
 * keyboard-operable, screen-reader-labelled and localised by the guest's own
 * device, and it costs nothing. #108's calendar mount is where a month grid with
 * availability shading belongs.
 */
export function DateStep({
  state,
  t,
  onChange,
}: {
  readonly state: BookingState;
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  return (
    <div class="kaiki-step">
      <h3 class="kaiki-heading">{t('booking.date.heading')}</h3>

      <label class="kaiki-field">
        <span>{t('booking.date.label')}</span>
        <input
          type="date"
          value={state.localDate ?? ''}
          onInput={(event) => onChange({ localDate: (event.currentTarget as HTMLInputElement).value || null })}
        />
      </label>
    </div>
  );
}
