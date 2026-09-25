{{--
    TOK-4's generic refusal.

    **One page for every reason.** Not found, expired, purged, malformed — the
    guest sees the same words, and the controller reaches this view through the
    same query in every case, because a found-but-expired token that hits the
    database and a not-found token that short-circuits are distinguishable by
    response time.

    `TokenSecurityTest` asserts the two bodies are **byte-identical**, which is
    why there is nothing dynamic on this page at all: no code echoed back, no
    "we looked for", no reason.

    Deliberately unbranded. A token that did not resolve has no tenant, so there
    is no operator whose colours these could honestly be.

    `languages: false`, for the same reason as everything else here: the
    masthead's language links carry the current address, and the address
    carries the token (2026-09-22). Two refusals would have differed by exactly
    the string the page exists to not reveal.
--}}
@extends('guest.layout', ['title' => __('guest.link.title'), 'languages' => false])

@section('content')
    <div class="card">
        <h1>{{ __('guest.link.title') }}</h1>
        <p class="muted">{{ __('guest.link.body') }}</p>
    </div>
@endsection
