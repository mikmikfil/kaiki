{{--
    «Μήνας»: a small month to jump to any day (direction Α's month, kept as a
    jump in Β — docs/mockups/departures-calendar.html).

    A `<details>`, like the header's burger, so it opens and closes with no
    script. Every day is a link to the page starting on that day, and the
    arrows are links that show the next or the previous month — with `?m=` in
    the address, which is what opens the sheet again on arrival.

    Drawn twice (the strip on a wide screen, the tools row on a phone), with
    `$variant` saying which.

    Expects: $month, $monthMarks, $monthOpen, $today, $criteria, $query, $variant.
--}}
@php
    use Illuminate\Support\Carbon;

    $first = $month->copy()->startOfMonth();
    $thisMonth = Carbon::parse($today)->startOfMonth();
    $pageFrom = $criteria->from;
    $pageTo = Carbon::parse($criteria->from)->addDays($criteria->days - 1)->toDateString();
    $monday = $first->copy()->startOfWeek(Carbon::MONDAY);
@endphp

<details class="cal-month cal-month-{{ $variant }}" @if ($monthOpen) open @endif>
    <summary class="cal-chip">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
        <span>{{ $variant === 'desk' ? Carbon::parse($criteria->from)->locale(app()->getLocale())->isoFormat('MMMM') : __('hosted.calendar.month') }}</span>
        <svg class="icon chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
    </summary>

    <div class="cal-month-panel">
        <div class="cal-month-head">
            @if ($first->greaterThan($thisMonth))
                <a class="cal-nav" href="{{ $query->url(['m' => $first->copy()->subMonth()->format('Y-m')]) }}" aria-label="{{ __('hosted.calendar.prev_month') }}" rel="nofollow">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 6-6 6 6 6"/></svg>
                </a>
            @else
                <span class="cal-nav is-off" aria-hidden="true"></span>
            @endif
            <p class="cal-month-name">{{ $first->copy()->locale(app()->getLocale())->isoFormat('MMMM YYYY') }}</p>
            <a class="cal-nav" href="{{ $query->url(['m' => $first->copy()->addMonth()->format('Y-m')]) }}" aria-label="{{ __('hosted.calendar.next_month') }}" rel="nofollow">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        </div>

        <div class="cal-month-grid">
            @for ($i = 0; $i < 7; $i++)
                <span class="wd" aria-hidden="true">{{ $monday->copy()->addDays($i)->locale(app()->getLocale())->isoFormat('dd') }}</span>
            @endfor

            @for ($i = 1; $i < $first->dayOfWeekIso; $i++)
                <span aria-hidden="true"></span>
            @endfor

            @for ($day = $first->copy(); $day->month === $first->month; $day->addDay())
                @php
                    $key = $day->toDateString();
                    $mark = $monthMarks[$key] ?? null;
                @endphp
                @if ($key < $today)
                    <span class="d is-past" aria-hidden="true">{{ $day->day }}</span>
                @else
                    <a @class([
                           'd',
                           'is-on' => $key === $pageFrom,
                           'is-range' => $key > $pageFrom && $key <= $pageTo,
                           'is-empty' => $mark === null || $mark === 'none',
                           'is-weather' => $mark === 'weather',
                       ])
                       href="{{ $query->url(['from' => $key === $today ? null : $key]) }}"
                       aria-label="{{ $day->copy()->locale(app()->getLocale())->isoFormat('dddd D MMMM') }}"
                       rel="nofollow">{{ $day->day }}</a>
                @endif
            @endfor
        </div>
    </div>
</details>
