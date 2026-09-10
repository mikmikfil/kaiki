import { useEffect, useMemo, useState } from 'preact/hooks';

import type { Api } from '../../api-client';
import type { Translator } from '../../i18n';

/**
 * One month, drawn as a month (WGT-5, A11Y-1).
 *
 * ## Why this is shared rather than written twice
 *
 * Two places in the widget ask a guest to look at a month: the `calendar` mount,
 * which only shows which days sail, and the booking walk's date step, where the
 * same grid is the thing you pick from. They were a grid and a bare
 * `<input type="date">` respectively — so the trip page showed a month on one
 * half of the screen and «mm/dd/yyyy» on the other, in a locale that writes
 * neither.
 *
 * A native date input is cheap and accessible, which is why it was chosen, and
 * it has one fatal property here: it cannot say which days are sold out. A guest
 * picking a full Saturday and being refused at the next step is the failure this
 * grid exists to prevent.
 *
 * ## Seven columns, Monday first, every day drawn
 *
 * `Intl` will name the weekdays in the guest's language; it will not agree with
 * itself about where the week starts, and every calendar in Greece begins on
 * Monday. A day the availability endpoint said nothing about is drawn plain and
 * labelled «not sailing» — a hole in a grid is not an answer.
 *
 * ## Colour is never the only signal
 *
 * Green and red, because a guest scanning for a free Saturday reads colour. But
 * a cell has no room for the word, so the status is in each cell's accessible
 * name, a legend says what the two colours mean, and a sold-out day is struck
 * through as well as red — roughly one man in twelve cannot tell the two hues
 * apart.
 */

export interface AvailabilityDay {
  readonly local_date: string;
  readonly status: string;
}

/**
 * Availability for a month, and the two facts a caller needs about the request.
 *
 * WGT-17's sixty-second cache is keyed on the query string, so a guest clicking
 * forward to August and back to July pays for one request rather than two.
 */
export function useAvailability(
  client: Api,
  productUuid: string | null,
  month: Date,
): { statuses: Map<string, string>; loading: boolean; failed: boolean } {
  const range = useMemo(() => monthRange(month), [month]);

  const [days, setDays] = useState<AvailabilityDay[] | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (productUuid === null) {
      setFailed(true);

      return;
    }

    setDays(null);

    let abandoned = false;

    client
      .get<{ data: AvailabilityDay[] }>('/availability', {
        query: { product: productUuid, from: range.from, to: range.to },
      })
      .then((response) => {
        if (!abandoned) {
          // An unexpected payload renders an empty month rather than throwing
          // into an operator's page.
          setDays(Array.isArray(response.data) ? response.data : []);
        }
      })
      .catch(() => {
        if (!abandoned) {
          setFailed(true);
        }
      });

    return () => {
      abandoned = true;
    };
  }, [client, productUuid, range.from, range.to]);

  const statuses = useMemo(() => {
    const map = new Map<string, string>();

    for (const day of days ?? []) {
      map.set(day.local_date, day.status);
    }

    return map;
  }, [days]);

  return { statuses, loading: days === null, failed };
}

export function MonthGrid({
  month,
  statuses,
  loading,
  t,
  onMonth,
  selected = null,
  onSelect,
}: {
  readonly month: Date;
  readonly statuses: Map<string, string>;
  readonly loading: boolean;
  readonly t: Translator;
  readonly onMonth: (month: Date) => void;
  readonly selected?: string | null;
  /** Given, the days that can be booked become buttons. Omitted, the grid is read-only. */
  readonly onSelect?: (date: string) => void;
}) {
  const cells = monthCells(month);

  return (
    <div class="kaiki-calendar">
      <div class="kaiki-calendar-head">
        <button
          type="button"
          class="kaiki-calendar-step"
          aria-label={t('calendar.previous')}
          onClick={() => onMonth(shift(month, -1))}
        >
          ‹
        </button>

        {/* The month name comes from the guest's own device through `Intl`,
            which is a formatter every browser already has — a month-name table
            in two languages would be twenty-four strings against the budget for
            something the platform does better. */}
        <strong aria-live="polite">{monthLabel(month, t.locale)}</strong>

        <button
          type="button"
          class="kaiki-calendar-step"
          aria-label={t('calendar.next')}
          onClick={() => onMonth(shift(month, 1))}
        >
          ›
        </button>
      </div>

      {/* The headings are a row of the same grid rather than a table: there is
          no data in them, and a `<table>` would have a screen reader announce
          «row 3, column 5» for a thing a person reads as a shape. */}
      <div class="kaiki-weekdays" aria-hidden="true">
        {weekdayNames(t.locale).map((name) => (
          <span key={name}>{name}</span>
        ))}
      </div>

      {loading ? (
        <p class="kaiki-muted" role="status" aria-busy="true">
          {t('widget.loading')}
        </p>
      ) : (
        <ul class="kaiki-days" role="list">
          {cells.map((date, index) => {
            if (date === null) {
              // The days before the first, so the first lands on its own
              // weekday. Out of the accessibility tree: they are spacing, and a
              // screen reader reading four empty list items before Monday is
              // reading the layout rather than the month.
              // eslint-disable-next-line react/no-array-index-key
              return <li key={`blank-${index}`} class="kaiki-day kaiki-day-blank" aria-hidden="true" />;
            }

            const status = statuses.get(date) ?? 'unavailable';
            const label = `${date} — ${t(`calendar.status.${status}` as never)}`;
            const bookable = onSelect !== undefined && (status === 'available' || status === 'on_request');

            return (
              <li
                key={date}
                class={`kaiki-day kaiki-day-${status}${date === selected ? ' kaiki-day-picked' : ''}`}
                // On the cell when the cell is all there is, and on the button
                // when there is one — a label on both would have a screen
                // reader read the date twice before saying it can be pressed.
                aria-label={bookable ? undefined : label}
              >
                {bookable ? (
                  // A real button, so the grid is walkable with a keyboard and
                  // announced as something that can be pressed. `aria-pressed`
                  // rather than a class alone, because "which day did I choose"
                  // is the question a screen-reader user has no colour to answer
                  // it with.
                  <button
                    type="button"
                    class="kaiki-day-pick"
                    aria-label={label}
                    aria-pressed={date === selected}
                    onClick={() => onSelect(date)}
                  >
                    <span class="kaiki-day-number">{Number(date.slice(-2))}</span>
                  </button>
                ) : (
                  <span class="kaiki-day-number">{Number(date.slice(-2))}</span>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {/* What the colours mean, once, under the grid. Without it the green and
          the red are a convention the guest is expected to already know. */}
      <ul class="kaiki-legend" role="list">
        <li>
          <span class="kaiki-swatch kaiki-day-available" aria-hidden="true" />
          {t('calendar.status.available')}
        </li>
        <li>
          <span class="kaiki-swatch kaiki-day-sold_out" aria-hidden="true" />
          {t('calendar.status.sold_out')}
        </li>
      </ul>
    </div>
  );
}

export function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function shift(month: Date, by: number): Date {
  return new Date(month.getFullYear(), month.getMonth() + by, 1);
}

function monthRange(month: Date): { from: string; to: string } {
  const last = new Date(month.getFullYear(), month.getMonth() + 1, 0);

  return { from: iso(month), to: iso(last) };
}

/**
 * The cells of the grid: leading blanks, then every day of the month.
 *
 * Monday first, so `getDay()`'s Sunday-as-zero is shifted by one. Greece — and
 * most of Europe — starts the week on Monday, and a calendar whose weekend is
 * split across both ends is a calendar nobody can scan for a free Saturday.
 */
function monthCells(month: Date): (string | null)[] {
  const first = startOfMonth(month);
  const lead = (first.getDay() + 6) % 7;
  const length = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();

  const cells: (string | null)[] = Array.from({ length: lead }, () => null);

  for (let day = 1; day <= length; day += 1) {
    cells.push(iso(new Date(month.getFullYear(), month.getMonth(), day)));
  }

  return cells;
}

export function iso(date: Date): string {
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

/**
 * Monday to Sunday, in the guest's language, from `Intl`.
 *
 * Any Monday will do as the seed — 5 January 1970 was one — because only the
 * weekday matters. The fallback is two-letter Greek, which is the default
 * locale and a better wrong answer than seven empty columns.
 */
function weekdayNames(locale: string): string[] {
  const monday = Date.UTC(1970, 0, 5);

  try {
    const format = new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' });

    return Array.from({ length: 7 }, (_, index) => format.format(new Date(monday + index * 86_400_000)));
  } catch {
    return ['Δε', 'Τρ', 'Τε', 'Πε', 'Πα', 'Σα', 'Κυ'];
  }
}
