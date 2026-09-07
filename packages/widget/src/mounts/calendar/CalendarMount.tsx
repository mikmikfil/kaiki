import { useEffect, useMemo, useState } from 'preact/hooks';

import { registerMount, type MountProps } from '../../mounts';

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
 * ## Paging a month costs one request, and going back costs none
 *
 * WGT-17: availability is cached in memory for sixty seconds per product and
 * range. A guest clicking forward to August and back to July re-asks for a range
 * the client already holds, so the second month is free. That is the whole reason
 * the cache is keyed on the query string rather than on "the last response".
 */

interface AvailabilityDay {
  readonly local_date: string;
  readonly status: string;
}

export function CalendarMount({ client, productUuid, t, analytics }: MountProps) {
  const [month, setMonth] = useState(() => startOfMonth(new Date()));
  const [days, setDays] = useState<AvailabilityDay[] | null>(null);
  const [failed, setFailed] = useState(false);

  const range = useMemo(() => monthRange(month), [month]);

  useEffect(() => {
    if (productUuid === null) {
      setFailed(true);

      return;
    }

    setDays(null);

    client
      .get<{ data: AvailabilityDay[] }>('/availability', {
        query: { product: productUuid, from: range.from, to: range.to },
      })
      .then((response) => {
        // As in the list mount: an unexpected payload renders nothing rather
        // than throwing into an operator's page.
        setDays(Array.isArray(response.data) ? response.data : []);
        analytics.emit('kaiki:availability-loaded', { product_uuid: productUuid, date: range.from });
      })
      .catch(() => setFailed(true));
  }, [client, productUuid, range.from, range.to, analytics]);

  if (failed) {
    return (
      <div class="kaiki-error" role="alert">
        <p>{t('widget.error.network')}</p>
      </div>
    );
  }

  return (
    <div class="kaiki-calendar">
      <div class="kaiki-calendar-head">
        <button type="button" class="kaiki-button kaiki-button-ghost" onClick={() => setMonth(shift(month, -1))}>
          {t('calendar.previous')}
        </button>

        {/* The month name comes from the guest's own device through `Intl`,
            which is a formatter every browser already has — a month-name table
            in two languages would be twenty-four strings against the budget for
            something the platform does better. */}
        <strong aria-live="polite">{monthLabel(month, t.locale)}</strong>

        <button type="button" class="kaiki-button kaiki-button-ghost" onClick={() => setMonth(shift(month, 1))}>
          {t('calendar.next')}
        </button>
      </div>

      {days === null ? (
        <p class="kaiki-muted" role="status" aria-busy="true">
          {t('widget.loading')}
        </p>
      ) : (
        <ul class="kaiki-days" role="list">
          {days.map((day) => (
            <li
              key={day.local_date}
              class={day.status === 'available' ? 'kaiki-day kaiki-day-open' : 'kaiki-day'}
              // The status is said in words as well as in colour: a calendar
              // that only shades is a calendar a colour-blind guest cannot read
              // (A11Y-1).
              aria-label={`${day.local_date} — ${t(`calendar.status.${day.status}` as never)}`}
            >
              <span class="kaiki-day-number">{Number(day.local_date.slice(-2))}</span>
              <span class="kaiki-day-status">{t(`calendar.status.${day.status}` as never)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function shift(month: Date, by: number): Date {
  return new Date(month.getFullYear(), month.getMonth() + by, 1);
}

function monthRange(month: Date): { from: string; to: string } {
  const last = new Date(month.getFullYear(), month.getMonth() + 1, 0);

  return { from: iso(month), to: iso(last) };
}

function iso(date: Date): string {
  // Built from the local parts rather than `toISOString()`, which converts to
  // UTC and turns the first of the month into the last of the previous one for
  // anybody east of Greenwich — which is everybody this product is for.
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function monthLabel(month: Date, locale: string): string {
  try {
    return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(month);
  } catch {
    return iso(month).slice(0, 7);
  }
}

registerMount('calendar', CalendarMount);
