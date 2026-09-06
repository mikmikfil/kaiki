{{--
    The operator's home page (HOS-1), composed from the blocks of #102.

    **There is no `{!! !!}` in this file or in any block partial.** The operator's
    prose reaches the page through `App\Domain\Hosted\Support\BlockText`, which
    returns an `HtmlString` so that `{{ }}` renders it — the one place in the
    hosted views where operator input becomes markup, and it produces exactly
    `<p>` and `<br>`.

    An operator who has never opened the editor gets the default layout from
    `BuildHomePage`, which is the page #101 already served. So this template has
    no empty state: there is always a page.
--}}
@extends('hosted.layout')

@section('title', $tenant->name)
@section('description', $metaDescription)

@section('content')
    @if ($readOnly)
        {{-- HOS-10, first thing on the page rather than beside a booking button
             that is not there. The sentence says who to contact rather than
             what went wrong. --}}
        <p class="read-only">{{ __('hosted.read_only', ['email' => $tenant->email]) }}</p>
    @endif

    {{-- Every partial receives what it needs already resolved. A template that
         queries is a template that queries once per block, and `BuildHomePage`
         loads the catalogue once for the whole page. --}}
    @foreach ($blocks as $entry)
        @include('hosted.blocks.' . $entry['block']->type->value, [
            'block' => $entry['block'],
            'products' => $entry['products'],
            'meetingPoint' => $entry['meetingPoint'],
            'anchor' => $entry['anchor'],
        ])
    @endforeach
@endsection
