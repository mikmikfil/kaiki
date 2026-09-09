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

    **The results are the same card as everywhere else.** This page used to draw
    its own, sharing the class name and none of the structure — no padding, no
    photograph, no button — so a guest who searched landed on a plainer, worse
    version of the catalogue they had just left. `hosted.partials.trip-card`
    takes the `$result` and answers with this party's price instead of a
    from-price.
--}}
@extends('hosted.layout')

@section('title', __('hosted.search.title') . ' · ' . $tenant->name)
@section('description', $metaDescription)

@section('content')
    @php
        $applied = $criteria->applied;
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

    @if ($browsing)
        {{-- Nobody asked a question, so this is the catalogue rather than an
             answer: every active trip, priced «από», exactly as the home page
             lists them. The form above still shows today and two people, which
             are sensible things to find in the fields — they are just no longer
             applied on a visitor's behalf. --}}
        <p class="result-count">{{ trans_choice('hosted.search.all_count', $catalogue->count(), ['count' => $catalogue->count()]) }}</p>

        <ul class="trips results">
            @foreach ($catalogue as $product)
                @include('hosted.partials.trip-card', ['product' => $product])
            @endforeach
        </ul>
    @elseif ($results === [])
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

        <ul class="trips results">
            @foreach ($results as $result)
                @include('hosted.partials.trip-card', [
                    'product' => $result->product,
                    'result' => $result,
                    'pax' => $criteria->pax,
                ])
            @endforeach
        </ul>
    @endif
@endsection
