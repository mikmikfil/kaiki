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

    {{-- `method="get"` and no `action`: the form submits to this URL, which is
         the whole of the interaction. --}}
    <form class="search-form" method="get">
        <div class="field">
            <label for="f-date">{{ __('hosted.search.fields.date') }}</label>
            <input type="date" id="f-date" name="date" value="{{ $criteria->date->toDateString() }}">
        </div>

        <div class="field">
            <label for="f-pax">{{ __('hosted.search.fields.pax') }}</label>
            <input type="number" id="f-pax" name="pax" min="1" max="500" value="{{ $criteria->pax }}">
        </div>

        @if ($filters[SearchFilters::PORT] && $ports->isNotEmpty())
            <div class="field">
                <label for="f-port">{{ __('hosted.search.fields.port') }}</label>
                <select id="f-port" name="port">
                    <option value="">{{ __('hosted.search.any') }}</option>
                    @foreach ($ports as $port)
                        <option value="{{ $port->uuid }}" @selected(($applied['port'] ?? null) === $port->uuid)>{{ $port->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($filters[SearchFilters::TYPE])
            <div class="field">
                <label for="f-type">{{ __('hosted.search.fields.type') }}</label>
                <select id="f-type" name="type">
                    <option value="">{{ __('hosted.search.any') }}</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" @selected(($applied['type'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($filters[SearchFilters::DURATION])
            <div class="field">
                <label for="f-duration">{{ __('hosted.search.fields.duration_max') }}</label>
                <input type="number" id="f-duration" name="duration_max" min="30" step="30"
                       value="{{ $applied['duration_max'] ?? '' }}">
            </div>
        @endif

        @if ($filters[SearchFilters::PRICE])
            <div class="field">
                {{-- Euros, because that is what a guest types. The controller
                     turns it into cents, which is what everything touching money
                     works in. --}}
                <label for="f-price">{{ __('hosted.search.fields.price_max') }}</label>
                <input type="number" id="f-price" name="price_max" min="0" step="10"
                       value="{{ $applied['price_max'] ?? '' }}">
            </div>
        @endif

        @if ($filters[SearchFilters::VESSEL] && $vessels->isNotEmpty())
            <div class="field">
                <label for="f-vessel">{{ __('hosted.search.fields.vessel') }}</label>
                <select id="f-vessel" name="vessel">
                    <option value="">{{ __('hosted.search.any') }}</option>
                    @foreach ($vessels as $vessel)
                        <option value="{{ $vessel->uuid }}" @selected(($applied['vessel'] ?? null) === $vessel->uuid)>{{ $vessel->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- The locale travels with the submission, or a Greek visitor lands
             back on the operator's default language after every search. --}}
        <input type="hidden" name="lang" value="{{ $locale }}">

        <div class="field submit">
            <button type="submit" class="button">{{ __('hosted.search.submit') }}</button>
        </div>
    </form>

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
