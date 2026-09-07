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
 * A `<dl>` rather than four paragraphs, because that is what it is: four
 * labelled facts, and a screen reader reads the label with the value.
 */
export function FourLines({ product, t }: { readonly product: ProductSummary; readonly t: Translator }) {
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
      <div>
        <dt>{t('booking.lines.vessel')}</dt>
        <dd>{product.vessel?.name ?? '—'}</dd>
      </div>
    </dl>
  );
}
