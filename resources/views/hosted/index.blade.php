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

    @include('hosted.partials.blocks', ['blocks' => $blocks])
@endsection
