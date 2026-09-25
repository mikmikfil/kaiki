{{--
    The departures calendar's filters and days (2026-09-25): everything under
    the band, in one wrapper.

    A partial because it is rendered two ways. As part of the page, and — when
    `calendar.js` asks with `X-Requested-With` — on its own, so a filter pressed
    with scripts on swaps this block in place instead of reloading the page
    (Mike, 25/9: «τα φίλτρα δεν γίνεται να φορτώνουν με ajax;»). The links
    inside are the same links either way; with no script they simply navigate.

    Expects: $tenant, $locale, $criteria, $result, $today, $query, $month,
    $monthMarks, $monthOpen.
--}}
@php
    use Illuminate\Support\Carbon;

    $loc = app()->getLocale();
    $from = Carbon::parse($criteria->from)->locale($loc);
    $to = $from->copy()->addDays(count($result->days) - 1);
    $range = $from->month === $to->month
        ? $from->isoFormat('D') . ' – ' . $to->isoFormat('D MMMM')
        : $from->isoFormat('D MMMM') . ' – ' . $to->isoFormat('D MMMM');
    $prevFrom = Carbon::parse($criteria->from)->subDays(7)->toDateString();
    $prevFrom = $prevFrom < $today ? $today : $prevFrom;
    $nextFrom = Carbon::parse($criteria->from)->addDays(7)->toDateString();
    $empty = $result->unfilteredCount() === 0;
    $noMatch = ! $empty && $result->count() === 0;
@endphp

<div class="cal" data-cal data-from="{{ $criteria->from }}">
    <aside class="cal-aside" aria-label="{{ __('hosted.calendar.filters') }}">
        @include('hosted.partials.calendar-filters', ['withTitle' => true])
    </aside>

    <div class="cal-main">
        {{-- The strip: the page's fourteen days, each a jump to its own
             heading, with the weeks either side of it. Sticky under the
             header, so the days are always one tap away. --}}
        <nav class="cal-strip" aria-label="{{ __('hosted.calendar.days') }}">
            @if ($criteria->from > $today)
                <a class="cal-nav cal-week" href="{{ $query->url(['from' => $prevFrom === $today ? null : $prevFrom]) }}" aria-label="{{ __('hosted.calendar.prev_week') }}" rel="nofollow">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 6-6 6 6 6"/></svg>
                </a>
            @else
                <span class="cal-nav cal-week is-off" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 6-6 6 6 6"/></svg>
                </span>
            @endif

            <ol class="cal-pills">
                @foreach ($result->days as $day)
                    @php
                        $date = Carbon::parse($day->localDate)->locale($loc);
                        $dot = $day->dot();
                    @endphp
                    <li>
                        {{-- The chosen day is filled in the operator's colour and
                             says so to a screen reader: the first day of the page
                             until another is pressed (`calendar.js` moves it, and
                             reads a `#d-…` already in the address). --}}
                        <a href="#d-{{ $day->localDate }}"
                           data-day="{{ $day->localDate }}"
                           @class(['cal-pill', 'is-on' => $loop->first, 'is-none' => $day->rows === []])
                           @if ($loop->first) aria-current="date" @endif
                           aria-label="{{ $date->isoFormat('dddd D MMMM') }}">
                            <small>{{ $date->isoFormat('ddd') }}</small>
                            <b>{{ $date->day }}</b>
                            <span @class(['dot', 'dot-' . $dot => $dot !== null]) aria-hidden="true"></span>
                        </a>
                    </li>
                @endforeach
            </ol>

            <a class="cal-nav cal-week" href="{{ $query->url(['from' => $nextFrom]) }}" aria-label="{{ __('hosted.calendar.next_week') }}" rel="nofollow">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
            </a>

            @include('hosted.partials.calendar-month', ['variant' => 'desk'])
        </nav>

        {{-- A phone's row of tools, under the strip: the filters behind one
             chip, the month, and the three parts of the day one tap away. --}}
        <div class="cal-tools">
            <details class="cal-sheet">
                <summary class="cal-chip">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>
                    <span>{{ trans_choice('hosted.calendar.filters_summary', $criteria->pax, ['count' => $criteria->pax]) }}</span>
                </summary>
                <div class="cal-sheet-panel">
                    @include('hosted.partials.calendar-filters', ['withTitle' => false])
                </div>
            </details>

            @include('hosted.partials.calendar-month', ['variant' => 'phone'])

            @foreach (\App\Data\Availability\DepartureCalendarCriteria::PARTS as $part)
                <a @class(['cal-chip', 'is-on' => $criteria->part === $part])
                   href="{{ $query->url(['part' => $criteria->part === $part ? null : $part]) }}"
                   rel="nofollow"
                   @if ($criteria->part === $part) aria-current="true" @endif>{{ __('hosted.calendar.parts.' . $part) }}</a>
            @endforeach
        </div>

        @if ($empty)
            {{-- Nothing sails in these fourteen days at all — the season
                 has closed, or has not opened. Say when the next sailing
                 is, rather than drawing fourteen empty days. --}}
            <section class="cal-state">
                <h2>{{ __('hosted.calendar.closed.heading', ['range' => $range]) }}</h2>
                @if ($result->nextDate)
                    @php $next = Carbon::parse($result->nextDate)->locale($loc)->isoFormat('dddd D MMMM'); @endphp
                    <p>{{ __('hosted.calendar.closed.next', ['date' => $next]) }}</p>
                    <p class="cal-state-actions">
                        <a class="cal-book" href="{{ $query->url(['from' => $result->nextDate]) }}">{{ __('hosted.calendar.closed.next_button', ['date' => $next]) }}</a>
                        <a class="cal-chip" href="{{ route('hosted.contact', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.contact.nav') }}</a>
                    </p>
                @else
                    <p>{{ __('hosted.calendar.closed.none') }}</p>
                    <p class="cal-state-actions">
                        <a class="cal-chip" href="{{ route('hosted.contact', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.contact.nav') }}</a>
                    </p>
                @endif
            </section>
        @elseif ($noMatch)
            {{-- The filters found nothing, though boats sail. What to change
                 is the one thing worth saying. --}}
            <section class="cal-state">
                <h2>{{ __('hosted.calendar.empty_filtered.heading', ['range' => $range]) }}</h2>
                <p>{{ __('hosted.calendar.empty_filtered.body') }}</p>
                <p class="cal-state-actions">
                    <a class="cal-chip" href="{{ $query->cleared() }}" rel="nofollow">{{ __('hosted.calendar.clear') }}</a>
                </p>
            </section>
        @else
            @foreach ($result->days as $day)
                @php
                    $date = Carbon::parse($day->localDate)->locale($loc);
                    $count = $day->departureCount();
                    $weather = $day->weather();
                @endphp
                <section class="cal-day" id="d-{{ $day->localDate }}" aria-labelledby="h-{{ $day->localDate }}">
                    <header class="cal-day-h">
                        <h2 id="h-{{ $day->localDate }}">{{ $date->isoFormat('dddd D MMMM') }}</h2>
                        @if ($count > 0)
                            <span>{{ trans_choice('hosted.calendar.count', $count, ['count' => $count]) }}</span>
                        @endif
                    </header>

                    <div class="cal-rows">
                        @if ($weather !== null)
                            <div class="cal-wx" role="note">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 17.5h10a4 4 0 0 0 .6-8A5.5 5.5 0 0 0 7 8.8a4.4 4.4 0 0 0 0 8.7Z"/><path d="M8 21l1-1.6M12 21l1-1.6M16 21l1-1.6"/></svg>
                                <p><b>{{ __('hosted.calendar.weather.' . $weather) }}</b>{{ __('hosted.calendar.weather.' . $weather . '_body') }}</p>
                            </div>
                        @endif

                        @if ($day->rows === [])
                            <p class="cal-empty">{{ $day->unfilteredCount > 0 ? __('hosted.calendar.empty_day_filtered') : __('hosted.calendar.empty_day') }}</p>
                        @else
                            <ul class="cal-list">
                                @foreach ($day->rows as $row)
                                    @include('hosted.partials.calendar-row', ['row' => $row, 'pax' => $criteria->pax])
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            @endforeach

            <p class="cal-more">
                <a class="cal-chip" href="{{ $query->url(['from' => $nextFrom]) }}" rel="nofollow">
                    {{ __('hosted.calendar.next_week') }}
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
                </a>
            </p>
        @endif
    </div>
</div>
