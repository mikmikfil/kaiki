{{--
    One line of the departures calendar: time, trip, seats in words, price, and
    the way in. The same markup on every screen — a grid of five columns on a
    desk, two lines on a phone — so there is one row to keep correct.

    «Κράτηση» leads to the trip's own page with the day and the departure in the
    address: the booking widget there opens on «Άτομα» with both already chosen
    (docs/mockups/departures-calendar.html, «Τι γίνεται μετά την Κράτηση»). A
    trip sold by quote gets «Δείτε την εκδρομή» instead, to the plain trip page.

    Expects: $row (DepartureCalendarRow), $tenant, $locale, $pax.
--}}
@php
    use App\Data\Availability\DepartureCalendarRow as Row;
    use App\Domain\Hosted\Support\TripDuration;
    use App\Support\Format\MoneyFormatter;

    /** @var Row $row */
    $product = $row->product;
    $charter = $row->kind === Row::KIND_CHARTER;

    $tone = match ($row->status) {
        Row::AVAILABLE => 'ok',
        Row::FEW, Row::NO_FIT, Row::TOO_MANY, Row::TOO_FEW => 'few',
        Row::FULL, Row::BOOKED => 'full',
        Row::CANCELLED => 'cxl',
        Row::ON_REQUEST => 'req',
        default => 'past',
    };

    $label = match ($row->status) {
        Row::AVAILABLE => $charter ? __('hosted.calendar.status.free_boat') : __('hosted.calendar.status.available'),
        Row::FEW => trans_choice('hosted.calendar.status.few', $row->seatsAvailable, ['count' => $row->seatsAvailable]),
        Row::NO_FIT => trans_choice('hosted.calendar.status.no_fit', $row->seatsAvailable, ['count' => $row->seatsAvailable]),
        Row::TOO_MANY => __('hosted.calendar.status.too_many', ['count' => $row->limit]),
        Row::TOO_FEW => __('hosted.calendar.status.too_few', ['count' => $row->limit]),
        Row::CANCELLED => $row->isWeather() ? __('hosted.calendar.status.weather') : __('hosted.calendar.status.cancelled'),
        default => __('hosted.calendar.status.' . $row->status),
    };

    $tripUrl = route('hosted.product', ['operator' => $tenant->slug, 'product' => $product->slug, 'lang' => $locale]);

    $bookUrl = route('hosted.product', array_filter([
        'operator' => $tenant->slug,
        'product' => $product->slug,
        'lang' => $locale,
        'date' => $row->localDate,
        'departure' => $row->departureUuid,
    ]));

    $meta = array_filter([
        $charter ? __('hosted.calendar.whole_boat') : TripDuration::format((int) $product->duration_minutes),
        $row->vesselName,
    ]);
@endphp

<li @class([
        'cal-row',
        'is-dim' => in_array($tone, ['full', 'cxl', 'past'], true),
        'is-charter' => $charter,
    ])>
    <span class="cal-t">{{ $charter ? __('hosted.calendar.all_day') : $row->localTime }}</span>

    <div class="cal-nm">
        <a href="{{ $tripUrl }}" @class(['strike' => $row->isCancelled()])>{{ $product->title }}</a>
        @if ($meta !== [])
            <span class="cal-meta">{{ implode(' · ', $meta) }}</span>
        @endif
    </div>

    <span class="cal-pr">
        @if ($row->showsPrice())
            <b>{{ MoneyFormatter::format((int) $row->priceCents, $locale, MoneyFormatter::currency()) }}</b>
            <small>{{ $row->priceUnit() === 'boat' ? __('hosted.calendar.per_boat') : __('hosted.calendar.per_person') }}</small>
        @endif
    </span>

    {{-- The status and the button: one line under the name on a phone, two columns of the row on a desk (`display: contents`). --}}
    <div class="cal-sub">
        <span class="cal-st st-{{ $tone }}">{{ $label }}</span>

        <span class="cal-act">
            @if ($row->isBookable())
                <a class="cal-book" href="{{ $bookUrl }}">
                    {{ __('hosted.calendar.book') }}<span class="sr-only">: {{ $product->title }}, {{ $charter ? __('hosted.calendar.all_day') : $row->localTime }}</span>
                </a>
            @elseif ($row->status === Row::ON_REQUEST)
                {{-- The trip's own page, at the top like any other trip — not
                     its enquiry form (Mike, 25/9): no day, no anchor. --}}
                <a class="cal-book is-ghost" href="{{ $tripUrl }}">
                    {{ __('hosted.calendar.see_trip') }}<span class="sr-only">: {{ $product->title }}</span>
                </a>
            @endif
        </span>
    </div>
</li>
