import { useEffect, useState } from 'preact/hooks';

import type { BookingState } from '../../../booking/machine';
import type { Translator } from '../../../i18n';
import type { ProductSummary } from '../BookingMount';

/**
 * Step five: what it costs, before anybody pays.
 *
 * ## Every number on this screen came from the server
 *
 * WGT-13 is FIXED and this is the screen it is about: *"Every displayed amount
 * comes from `POST /price-quote` or from the draft booking response."* The
 * widget shows the breakdown it was handed, line by line, and adds nothing up.
 * `npm run widget:guards` greps the source for arithmetic on a `*_cents` value
 * precisely so this file cannot quietly start summing.
 *
 * A guest reading a total that the checkout then disagrees with is the failure
 * this rule exists to prevent, and it is the one failure that costs an operator
 * a booking *and* the trust that would have brought the next one.
 *
 * ## The quote is fetched here rather than carried from earlier
 *
 * A price computed at the party step and shown at review is a price that has had
 * a minute to go stale — a season boundary, an operator's edit, a voucher that
 * expired. Asking now means the number the guest agrees to is the number the
 * draft will be created with, and `price_token` is what makes the server say so
 * (`409 price_changed`) if it moved between the two.
 */
export function ReviewStep({
  state,
  product,
  t,
  quote,
}: {
  readonly state: BookingState;
  readonly product: ProductSummary;
  readonly t: Translator;
  readonly quote?: PriceQuote | null;
}) {
  const [shown, setShown] = useState<PriceQuote | null>(quote ?? null);

  useEffect(() => {
    if (quote !== undefined && quote !== null) {
      setShown(quote);
    }
  }, [quote]);

  return (
    <div class="kaiki-step">
      <h3 class="kaiki-heading">{t('booking.review.heading')}</h3>

      <dl class="kaiki-summary">
        <div>
          <dt>{t('booking.lines.trip')}</dt>
          <dd>{product.title}</dd>
        </div>
        <div>
          <dt>{t('booking.review.when')}</dt>
          <dd>{state.localDate ?? '—'}</dd>
        </div>
        <div>
          <dt>{t('booking.review.who')}</dt>
          <dd>{state.guest.full_name}</dd>
        </div>
      </dl>

      {shown === null ? (
        <p class="kaiki-muted">{t('booking.review.pending')}</p>
      ) : (
        <>
          <ul class="kaiki-lines">
            {shown.lines.map((line, index) => (
              <li key={index}>
                <span>
                  {line.label}
                  {line.qty > 1 ? ` × ${line.qty}` : ''}
                </span>
                {/* Printed, never computed. */}
                <span class="kaiki-amount">{line.total_formatted}</span>
              </li>
            ))}
          </ul>

          <p class="kaiki-total">
            <strong>{shown.total_formatted}</strong>
            {/* Brand decision 4 of 2026-09-04: the sentence, never the rate. */}
            <span class="kaiki-muted">{t('booking.review.vat_included')}</span>
          </p>

          {shown.deposit_formatted === null ? null : (
            <p class="kaiki-muted">
              {t('booking.review.deposit').replace(':amount', shown.deposit_formatted)}
            </p>
          )}
        </>
      )}
    </div>
  );
}

export interface PriceQuoteLine {
  readonly label: string;
  readonly qty: number;
  readonly total_formatted: string;
}

export interface PriceQuote {
  readonly lines: readonly PriceQuoteLine[];
  readonly total_formatted: string;
  readonly deposit_formatted: string | null;
}
