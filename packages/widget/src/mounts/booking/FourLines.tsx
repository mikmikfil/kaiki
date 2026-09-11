import type { Translator } from '../../i18n';
import type { ProductSummary } from './BookingMount';

/**
 * The four lines above the date picker (brand decision 3 of 2026-09-04).
 *
 * Title, duration, port, vessel. **No photograph and no summary** — the space
 * looks empty and the decision was made against exactly that temptation, twice:
 * once in the mockup and once on the hosted product page, which carries the same
 * four lines above the same mount.
 *
 * The vessel line is the operator's to leave out (`data-vessel="hide"`, the
 * WordPress plugin's «Show the boat's name» box, 2026-09-11): one boat that does
 * every trip, or a boat they would rather not name, is a line that says nothing
 * to a guest. The other three stay whatever the setting.
 *
 * A `<dl>` rather than four paragraphs, because that is what it is: labelled
 * facts, and a screen reader reads the label with the value.
 */
export function FourLines({
  product,
  t,
  showVessel = true,
}: {
  readonly product: ProductSummary;
  readonly t: Translator;
  readonly showVessel?: boolean;
}) {
  return (
    <dl class="kaiki-four-lines">
      <div>
        <dt>{t('booking.lines.trip')}</dt>
        <dd>{product.title}</dd>
      </div>
      <div>
        <dt>{t('booking.lines.duration')}</dt>
        <dd>{t('booking.lines.minutes').replace(':minutes', String(product.duration_minutes))}</dd>
      </div>
      <div>
        <dt>{t('booking.lines.port')}</dt>
        <dd>{product.meeting_point?.name ?? '—'}</dd>
      </div>
      {showVessel ? (
        <div>
          <dt>{t('booking.lines.vessel')}</dt>
          <dd>{product.vessel?.name ?? '—'}</dd>
        </div>
      ) : null}
    </dl>
  );
}
