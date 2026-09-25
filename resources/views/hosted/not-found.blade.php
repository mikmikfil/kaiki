{{--
    A page that does not exist, on the operator's own site: their brand, their
    language, and the way back to their trips (24/9). See NotFoundPage.
--}}
@extends('hosted.layout')

@section('title', __('hosted.not_found.title') . ' — ' . $tenant->name)
@section('description', __('hosted.not_found.lede'))

@section('content')
    @php
        $back = \App\Domain\Hosted\Support\HostedUrl::homeEnabledFor($tenant)
            ? route('hosted.index', ['operator' => $tenant->slug, 'lang' => $locale])
            : route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]);
    @endphp

    <div class="page-head not-found">
        <img class="not-found-buoy" src="{{ asset('images/nautical/lifebuoy.svg') }}" alt="" aria-hidden="true">
        <h1>{{ __('hosted.not_found.title') }}</h1>
        <p class="lede">{{ __('hosted.not_found.lede') }}</p>
        <p><a class="button button-accent" href="{{ $back }}">{{ __('hosted.not_found.back') }}</a></p>
    </div>

    <style nonce="{{ $nonce }}">
        .not-found { padding-block: 3rem 4rem; }
        .not-found-buoy { width: 4.5rem; height: 4.5rem; opacity: .35; margin-bottom: 1rem; }
        .not-found .button { margin-top: 1.5rem; }
    </style>
@endsection
