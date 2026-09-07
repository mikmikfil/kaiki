import type { BookingState } from '../../../booking/machine';
import type { Translator } from '../../../i18n';

/**
 * Step three: what else.
 *
 * Skipped entirely for a product with no extras — the machine drops the step
 * rather than this component rendering an empty one, so a guest going back from
 * contact lands where they came from.
 *
 * The price beside each extra is the **server's formatted string**, printed as
 * received. Formatting cents here would be one decimal separator away from
 * telling a Greek guest a €15 lunch costs 15.000 €.
 */
export function ExtrasStep({
  state,
  extras,
  t,
  onChange,
}: {
  readonly state: BookingState;
  readonly extras: readonly { readonly uuid: string; readonly name: string; readonly price_formatted?: string }[];
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  return (
    <div class="kaiki-step">
      <h3 class="kaiki-heading">{t('booking.extras.heading')}</h3>

      {extras.map((extra) => (
        <label class="kaiki-field kaiki-inline" key={extra.uuid}>
          <span>
            {extra.name}
            {extra.price_formatted === undefined ? null : <span class="kaiki-muted"> · {extra.price_formatted}</span>}
          </span>
          <input
            type="number"
            min="0"
            max="99"
            inputMode="numeric"
            value={String(state.extras[extra.uuid] ?? 0)}
            onInput={(event) =>
              onChange({
                extras: {
                  ...state.extras,
                  [extra.uuid]: Math.max(0, Number((event.currentTarget as HTMLInputElement).value) || 0),
                },
              })
            }
          />
        </label>
      ))}
    </div>
  );
}
