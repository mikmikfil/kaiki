{{--
    The masthead: an image or a video, a heading, a standfirst, one call to
    action — and the search a visitor came here to use.

    The heading is the page's `<h1>` — the only one, because a page with two is a
    page a screen reader and a crawler both read wrong, and the hero is the only
    block that can be first.

    The call to action goes to a place on this page or to a page of ours. There
    is no free URL field: an operator who can type a URL can type an off-site
    one, and a hero button that leaves the booking flow is the most expensive
    mistake this editor could permit.

    ## The video is decoration, and it behaves like it

    `muted`, `loop`, `playsinline` and `autoplay` — the four together are what
    makes a background video play on a phone at all; without `muted` and
    `playsinline`, iOS refuses and Android opens it full screen.

    **The image is its poster**, which is why the hero keeps both fields. It is
    what a visitor sees before the video has loaded, on a connection too slow to
    play it, and whenever the browser declines to autoplay — which is the common
    case on a data saver.

    And it does **not** autoplay for somebody who asked for reduced motion. A
    looping video behind text is a vestibular trigger, the operating system
    already knows the answer, and `@media (prefers-reduced-motion)` cannot switch
    an attribute off — so the markup is chosen here rather than styled later.

    ## The search bar belongs here

    A visitor arriving at a boat operator's home page has a date and a number of
    people in mind. Making them find a navigation link first is making them work
    for something the page could have asked. It is a plain `GET` to the search
    page — the same two fields `SearchFilters` always keeps on — so it needs no
    JavaScript and its result is a URL they can send to whoever they are
    travelling with.
--}}
@php
    use App\Domain\Hosted\Support\HostedAsset;

    $cta = $block->setting('cta');
    $poster = HostedAsset::url($block->image_path);
    $video = HostedAsset::url($block->video_path ?? null);
@endphp

<section class="block hero @if ($poster || $video) has-image @endif">
    @if ($video)
        <video class="hero-image hero-video"
               autoplay
               muted
               loop
               playsinline
               preload="metadata"
               @if ($poster) poster="{{ $poster }}" @endif
               aria-hidden="true"
               tabindex="-1">
            <source src="{{ $video }}" type="{{ str_ends_with($video, '.webm') ? 'video/webm' : 'video/mp4' }}">
        </video>
    @elseif ($poster)
        <img class="hero-image"
             src="{{ $poster }}"
             alt=""
             {{-- Decorative: the heading beside it says the same thing, and a
                  screen reader announcing both reads the operator's name twice. --}}
             loading="eager">
    @endif

    <div class="hero-copy">
        @if ($block->heading)
            <h1>{{ $block->heading }}</h1>
        @endif

        @if ($block->body)
            <div class="standfirst">{{ $block->prose() }}</div>
        @endif

        @if ($cta !== 'none')
            <p class="cta">
                <a class="button" href="#{{ $cta }}">{{ __('hosted.blocks.hero.cta.' . $cta) }}</a>
            </p>
        @endif

        {{-- The same form the search page carries, with the same fields the
             operator enabled — not a cut-down version of it, because a visitor
             who filters by port on one page and cannot on the other has learnt
             something untrue about the site. --}}
        <div class="hero-search">
            @include('hosted.partials.search-form', [
                'action' => route('hosted.search', ['operator' => $tenant->slug]),
                'idPrefix' => 'hero',
            ])
        </div>
    </div>
</section>
