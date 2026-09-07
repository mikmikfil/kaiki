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
 */
export function PartyStep({
  state,
  bands,
  t,
  onChange,
}: {
  readonly state: BookingState;
  readonly bands: readonly { readonly code: string; readonly label: string }[];
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  return (
    <div class="kaiki-step">
      <h3 class="kaiki-heading">{t('booking.party.heading')}</h3>

      {bands.map((band) => (
        <label class="kaiki-field kaiki-inline" key={band.code}>
          <span>{band.label}</span>
          <input
            type="number"
            min="0"
            max="99"
            inputMode="numeric"
            value={String(state.pax[band.code] ?? 0)}
            onInput={(event) =>
              onChange({
                pax: {
                  ...state.pax,
                  [band.code]: Math.max(0, Number((event.currentTarget as HTMLInputElement).value) || 0),
                },
              })
            }
          />
        </label>
      ))}
    </div>
  );
}
