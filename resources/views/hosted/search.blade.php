{{--
    The catalogue search (#105).

    **A plain `GET` form.** No JavaScript, no widget mount, no hydration: the
    filters submit to this same URL and the server renders the answer. The query
    string is the state, which is also what makes a search shareable — "Saturday,
    four of us, from Piraeus" is a link a guest can send to whoever they are
    travelling with.

    **Only the filters the operator enabled are drawn**, and that is the *second*
    half of the rule rather than the whole of it: a disabled filter arriving in
    the query string is already gone by the time this template runs, dropped by
    `SearchPageController` through `SearchFilters`. Hiding it here and honouring
    it there is the version that passes a screenshot review.
--}}
@extends('hosted.layout')

@section('title', __('hosted.search.title') . ' · ' . $tenant->name)
@section('description', $metaDescription)

@section('content')
    @php
        use App\Domain\Catalog\Support\SearchFilters;
        use App\Support\Format\MoneyFormatter;

        $applied = $criteria->applied;
        $money = static fn (?int $cents): ?string => $cents === null
            ? null
            : MoneyFormatter::format($cents, app()->getLocale(), MoneyFormatter::currency());
    @endphp

    <header class="search-head">
        <h1>{{ __('hosted.search.title') }}</h1>
        <p class="standfirst">{{ __('hosted.search.standfirst') }}</p>
    </header>

    @include('hosted.partials.search-form', [
        'applied' => $applied,
        'dateValue' => $criteria->date->toDateString(),
        'paxValue' => $criteria->pax,
    ])

    @if ($results === [])
        {{-- Not an empty grid. The one thing a guest needs here is what to
             change, and the two things that actually change an answer are the
             date and the party size. --}}
        <section class="empty">
            <h2>{{ __('hosted.search.empty.heading') }}</h2>
            <p>{{ __('hosted.search.empty.body') }}</p>
            <p class="muted">{{ __('hosted.search.empty.contact', ['email' => $tenant->email]) }}</p>
        </section>
    @else
        <p class="result-count">{{ trans_choice('hosted.search.count', count($results), ['count' => count($results)]) }}</p>

        <ul class="trips">
            @foreach ($results as $result)
                @php $product = $result->product; @endphp
                <li class="trip">
                    <h3>
                        <a href="{{ route('hosted.product', ['operator' => $tenant->slug, 'product' => $product->slug, 'lang' => $locale]) }}">{{ $product->title }}</a>
                    </h3>

                    @if ($product->summary)
                        <p class="summary">{{ $product->summary }}</p>
                    @endif

                    <p class="facts">
                        {{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}
                        @if ($product->meetingPoint)
                            · {{ $product->meetingPoint->name }}
                        @endif
                        @if ($result->nextDeparture)
                            · {{ substr((string) $result->nextDeparture->local_time, 0, 5) }}
                        @endif
                    </p>

                    {{-- Pinned to the bottom of the card by `margin-top: auto`,
                         so a row of cards has its prices on one line — settled
                         on 4 September, and the reason is that prices at
                         different heights read as a mistake. --}}
                    <p class="party-price">
                        @if ($result->isOnRequest())
                            <span class="on-request">{{ __('hosted.search.on_request') }}</span>
                        @else
                            <strong>{{ $money($result->partyPriceCents) }}</strong>
                            <span class="for-party">{{ trans_choice('hosted.search.for_party', $criteria->pax, ['count' => $criteria->pax]) }}</span>
                            <span class="vat">{{ __('hosted.product.price.vat_included') }}</span>
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
