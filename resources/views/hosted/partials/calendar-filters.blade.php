{{--
    The departures calendar's filters (2026-09-25), drawn twice like the
    header's links: in the column beside the days on a wide screen, and inside
    «Φίλτρα» on a phone. Each copy is `display: none` where it does not belong.

    **Every control is a link.** The page runs with no JavaScript (HOS-4), and
    a link is a filter that works the moment it is pressed — no «Εφαρμογή»
    button to find, no form to submit. `CalendarQuery` builds each one as the
    current query with exactly one thing changed.

    Expects: $criteria, $query, $result, $withTitle.
--}}
@php
    $slugs = $result->listed->pluck('slug')->all();
@endphp

<div class="cal-filters">
    @if ($withTitle)
        <h2 class="cal-filters-title">{{ __('hosted.calendar.filters') }}</h2>
    @endif

    <div class="cal-f">
        <p class="cal-f-label">{{ __('hosted.calendar.pax') }}</p>
        <span class="cal-stepper">
            @if ($criteria->pax > 1)
                <a href="{{ $query->url(['pax' => $criteria->pax - 1 === 2 ? null : $criteria->pax - 1]) }}" aria-label="{{ __('hosted.calendar.fewer') }}" rel="nofollow">&minus;</a>
            @else
                <span class="is-off" aria-hidden="true">&minus;</span>
            @endif
            <span class="cal-stepper-value">{{ trans_choice('hosted.calendar.pax_value', $criteria->pax, ['count' => $criteria->pax]) }}</span>
            <a href="{{ $query->url(['pax' => $criteria->pax + 1 === 2 ? null : $criteria->pax + 1]) }}" aria-label="{{ __('hosted.calendar.more') }}" rel="nofollow">+</a>
        </span>
    </div>

    <div class="cal-f">
        <p class="cal-f-label">{{ __('hosted.calendar.part') }}</p>
        @foreach (['all', 'morning', 'afternoon', 'evening'] as $part)
            @php $on = $part === 'all' ? $criteria->part === null : $criteria->part === $part; @endphp
            <a class="cal-check is-radio" href="{{ $query->url(['part' => $part === 'all' ? null : $part]) }}" rel="nofollow" @if ($on) aria-current="true" @endif>
                <span class="box" aria-hidden="true"></span>
                <span>{{ __('hosted.calendar.parts.' . $part) }}</span>
                @if ($part !== 'all')
                    <small>{{ __('hosted.calendar.part_hints.' . $part) }}</small>
                @endif
            </a>
        @endforeach
    </div>

    @if (count($slugs) > 1)
        <div class="cal-f">
            <p class="cal-f-label">{{ __('hosted.calendar.trips') }}</p>
            @foreach ($result->listed as $product)
                <a class="cal-check" href="{{ $query->toggleTrip($product->slug, $slugs) }}" rel="nofollow" @if ($query->tripIsOn($product->slug)) aria-current="true" @endif>
                    <span class="box" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
                    <span>{{ $product->title }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="cal-f">
        <a class="cal-check" href="{{ $query->url(['free' => $criteria->onlyFree ? null : 1]) }}" rel="nofollow" @if ($criteria->onlyFree) aria-current="true" @endif>
            <span class="box" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
            <span>{{ __('hosted.calendar.only_free') }}</span>
        </a>
    </div>

    @if ($criteria->isFiltered() || $criteria->pax !== 2)
        <a class="cal-chip cal-clear" href="{{ $query->cleared() }}" rel="nofollow">{{ __('hosted.calendar.clear') }}</a>
    @endif
</div>
