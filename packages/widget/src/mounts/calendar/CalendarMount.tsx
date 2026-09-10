import { useEffect, useState } from 'preact/hooks';

import { registerMount, type MountProps } from '../../mounts';
import { MonthGrid, startOfMonth, useAvailability } from './MonthGrid';

/**
 * The `calendar` mount (WGT-5): when, and nothing else.
 *
 * ## No price and no booking, deliberately
 *
 * This mount goes on a page that has **already sold the trip** — the operator's
 * own write-up, with their photographs and their words — and the only thing left to
 * say is which days sail. A price here would compete with the one their page
 * already shows, and a booking button would take the guest away from it.
 *
 * So it renders {@see MonthGrid} with no `onSelect`: the same month the booking
 * walk's date step draws, read-only. The grid, the weekday headings, the colours
 * and their legend all live there, because two calendars that disagree about
 * where the week starts is the kind of thing nobody notices for a year.
 *
 * ## Paging a month costs one request, and going back costs none
 *
 * WGT-17: availability is cached in memory for sixty seconds per product and
 * range. A guest clicking forward to August and back to July re-asks for a range
 * the client already holds, so the second month is free. That is the whole reason
 * the cache is keyed on the query string rather than on "the last response".
 */
export function CalendarMount({ client, productUuid, t, analytics }: MountProps) {
  const [month, setMonth] = useState(() => startOfMonth(new Date()));
  const { statuses, loading, failed } = useAvailability(client, productUuid, month);

  useEffect(() => {
    if (!loading && !failed && productUuid !== null) {
      analytics.emit('kaiki:availability-loaded', { product_uuid: productUuid, date: iso(month) });
    }
  }, [loading, failed, productUuid, month, analytics]);

  if (failed) {
    return (
      <div class="kaiki-error" role="alert">
        <p>{t('widget.error.network')}</p>
      </div>
    );
  }

  return <MonthGrid month={month} statuses={statuses} loading={loading} t={t} onMonth={setMonth} />;
}

function iso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`;
}

registerMount('calendar', CalendarMount);
