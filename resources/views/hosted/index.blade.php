{{--
    The operator's landing page (HOS-1).

    **The trips list here is a placeholder the block system replaces.** The
    editable home page is its own issue; this is the default that issue falls
    back to for an operator who has configured nothing — so it is written to be
    the real default rather than scaffolding to delete.
--}}
@extends('hosted.layout')

@section('title', $tenant->name)
@section('description', __('hosted.index.meta_description', ['operator' => $tenant->name]))

@section('content')
    <h1>{{ $tenant->name }}</h1>

    @if ($readOnly)
        {{-- HOS-10. The page is complete; only the booking is unavailable, and
             the sentence says who to contact rather than what went wrong. --}}
        <p class="read-only">{{ __('hosted.read_only', ['email' => $tenant->email]) }}</p>
    @endif

    <h2>{{ __('hosted.index.trips') }}</h2>

    @if ($products->isEmpty())
        <p>{{ __('hosted.index.no_trips') }}</p>
    @else
        <ul class="trips">
            @foreach ($products as $product)
                <li class="trip">
                    <div class="trip-body">
                        <h3>{{ $product->title }}</h3>
                        @if ($product->summary)
                            <p class="summary">{{ $product->summary }}</p>
                        @endif
                        <p class="facts">
                            {{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}
                            @if ($product->meetingPoint)
                                · {{ $product->meetingPoint->name }}
                            @endif
                            @if ($product->vessel)
                                · {{ $product->vessel->name }}
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
