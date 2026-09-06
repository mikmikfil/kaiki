{{--
    TOK-4's rate limit, as a page rather than a bare 429.

    A guest who has refreshed too fast on a slow train is the person most likely
    to see this, so it says what happened and what to do, in their own language.
    It says nothing about *which* limit was hit and nothing about how much
    budget is left — the `Retry-After` header carries the number, and a page
    that told a guesser exactly how long their allowance lasts would be a small
    gift.
--}}
@extends('guest.layout', ['title' => __('guest.throttled.title')])

@section('content')
    <div class="card">
        <h1>{{ __('guest.throttled.title') }}</h1>
        <p class="muted">{{ __('guest.throttled.body') }}</p>
    </div>
@endsection
