import { useEffect, useState } from 'preact/hooks';

import type { Api } from '../../api-client';
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
 * So by default it renders {@see MonthGrid} with no `onSelect`: the same month the
 * booking walk's date step draws, read-only. The grid, the weekday headings, the
 * colours and their legend all live there, because two calendars that disagree
 * about where the week starts is the kind of thing nobody notices for a year.
 *
 * ## Unless the operator asks for the days to lead somewhere
 *
 * WGT-5 as amended 2026-09-11, the product owner's flow for an operator with a
 * website of their own: the calendar sits on their site, a guest presses a day,
 * and lands on the trip's page on Kaiki with that day already chosen — the
 * party, the extras and the payment happen there. `data-link="trip"` turns it on;
 * without it nothing about this mount changes.
 *
 * The address comes from the API's `canonical_url` for the trip, never from a
 * path assembled here: a URL built in a browser from a config value is a guess
 * about somebody else's deployment. Until it arrives, or if it never does, the
 * days stay read-only rather than leading to a 404.
 *
 * ## Paging a month costs one request, and going back costs none
 *
 * WGT-17: availability is cached in memory for sixty seconds per product and
 * range. A guest clicking forward to August and back to July re-asks for a range
 * the client already holds, so the second month is free. That is the whole reason
 * the cache is keyed on the query string rather than on "the last response".
 */
export function CalendarMount({ client, productUuid, t, analytics, link = null }: MountProps) {
  const [month, setMonth] = useState(() => startOfMonth(new Date()));
  const { statuses, loading, failed } = useAvailability(client, productUuid, month);
  const tripUrl = useTripUrl(client, link === 'trip' ? productUuid : null);

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

  const onSelect =
    tripUrl === null
      ? undefined
      : (date: string) => {
          analytics.emit('kaiki:product-viewed', { product_uuid: productUuid as string });
          window.location.assign(withDate(tripUrl, date));
        };

  return <MonthGrid month={month} statuses={statuses} loading={loading} t={t} onMonth={setMonth} onSelect={onSelect} />;
}

/** The trip's page on Kaiki, as the API names it — or null, which keeps the days read-only. */
function useTripUrl(client: Api, productUuid: string | null): string | null {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    if (productUuid === null) {
      return;
    }

    // The same request the booking mount makes, through the same client, so a
    // page with both pays for it once (WGT-8).
    client
      .get<{ data: { canonical_url?: string | null } }>(`/products/${productUuid}`)
      .then((response) => setUrl(response.data.canonical_url ?? null))
      .catch(() => setUrl(null));
  }, [client, productUuid]);

  return url;
}

/**
 * The trip page's address with the chosen day on it.
 *
 * Through `URL` rather than string concatenation, because the address may
 * already carry `?lang=` and a second `?` would turn the date into part of it.
 */
export function withDate(url: string, date: string): string {
  const target = new URL(url);

  target.searchParams.set('date', date);

  return target.toString();
}

function iso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`;
}

registerMount('calendar', CalendarMount);
