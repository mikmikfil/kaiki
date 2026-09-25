{{--
    «Ημερολόγιο» — every departure, day by day (2026-09-25).

    Direction Β of docs/mockups/departures-calendar.html, «Λίστα ανά ημέρα»:
    the days one under the other, each sailing a line with its time, its trip,
    its seats in words, its price and «Κράτηση». Above them a strip of the
    fourteen days that stays in place and jumps to a day, and «Μήνας» — Α's
    month, kept as a way to reach a day further off.

    **No JavaScript**, like the search page (HOS-4): the filters, the weeks, the
    days and the month are all links, the filters live in the address, and the
    address is something a guest can send. The two things that open and close
    — «Φίλτρα» on a phone and «Μήνας» — are `<details>`, as the header's burger
    is.

    **Seats are words.** «Διαθέσιμη» until they run low, then «Τελευταίες 3
    θέσεις»; «Μόνο 1 θέση» and no button when the party does not fit. A number
    on every line — «22 από 24 ελεύθερες» — advertises an empty boat.
--}}
@extends('hosted.layout')

@section('title', __('hosted.calendar.title') . ' · ' . $tenant->name)
@section('description', $metaDescription)

@section('content')
    @push('head')
        <style nonce="{{ $nonce }}">
            @include('hosted.partials.calendar-styles')
        </style>
    @endpush

    @include('hosted.partials.page-top', [
        'eyebrow' => __('hosted.calendar.eyebrow'),
        'title' => __('hosted.calendar.title'),
        'lede' => __('hosted.calendar.lede'),
        'modifier' => 'cal-top',
    ])

    @include('hosted.partials.calendar-body')

    {{-- Filters without a reload, where scripts run (see the file). The page is
         complete without it: every control is a link. --}}
    <script src="{{ url('/hosted/calendar.js') }}" defer></script>
@endsection
