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

    ## Three sources, in one order

    An **uploaded file** wins, then a **YouTube or Vimeo link**, then the
    photograph on its own. The file is first because it is on our own disk,
    inside our own policy and under a size limit the form states; an operator
    with both has a leftover rather than a preference, and the editor says which
    one plays. {@see \App\Models\HomePageBlock::videoEmbed()}

    A framed video cannot carry a `poster` attribute the way `<video>` can, so
    the photograph is rendered **underneath** it as an ordinary image. That is
    what a visitor sees while the player loads, what they keep if the provider
    is unreachable where they are — a hotel network, a work laptop — and what
    shows instead of the loop when they have asked for reduced motion, which for
    an iframe is the only lever there is. A hero with a link and no photograph
    still reads: `.hero.has-image` is the operator's own colour underneath.

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
    $badges = $block->entries();
    $locale = app()->getLocale();
    // The operator's own buttons (2026-09-16), resolved to URLs by
    // `BuildHomePage`; a hero saved before then has none and keeps its one
    // `settings.cta` button with the label from the lang file.
    $links = $links ?? [];

    $poster = HostedAsset::url($block->image_path);
    $video = HostedAsset::url($block->video_path ?? null);
    $embed = $block->videoEmbed();
@endphp

<section class="block hero @if ($poster || $video || $embed) has-image @endif @if (($pageName ?? 'home') !== 'home') hero-plain @endif">
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
    @else
        @if ($poster)
            <img class="hero-image"
                 src="{{ $poster }}"
                 alt=""
                 {{-- Decorative: the heading beside it says the same thing, and a
                      screen reader announcing both reads the operator's name twice. --}}
                 loading="eager">
        @endif

        @if ($embed)
            {{-- The frame is the operator's, the URL is ours: `VideoEmbed`
                 builds it from a provider we name and an id matched against a
                 pattern, so nothing an operator typed reaches this attribute.

                 `aria-hidden` and `tabindex="-1"` keep a decorative player out
                 of the reading order and out of the tab order — it has no
                 controls to reach anyway, and a keyboard that lands inside an
                 iframe is the worst kind of trap. --}}
            <div class="hero-embed" aria-hidden="true">
                <iframe src="{{ $embed->embedUrl() }}"
                        title="{{ __('hosted.blocks.hero.video') }}"
                        tabindex="-1"
                        loading="lazy"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allow="autoplay; encrypted-media; picture-in-picture"
                        frameborder="0"></iframe>
            </div>
        @endif
    @endif

    {{-- Two columns inside the photograph since 16 September (Mike's pick of
         three): the operator's words on the left, the search on the right as a
         white card with its own title. It used to be a sibling hanging off the
         masthead's lower edge; inside, it needs no negative margin tuned to the
         height of the words above it, and on a phone it simply follows them. --}}
    <div class="hero-inner">
        {{-- Left-aligned over a left-to-right shade, the
             masthead of the operator's WordPress site: a short line, the heading,
             the standfirst, up to two buttons and a row of small trust badges —
             every word of it the operator's, from the editor. --}}
        <div class="hero-copy">
            @if ($block->eyebrow)
                <p class="eyebrow-line">{{ $block->eyebrow }}</p>
            @endif

            @if ($block->heading)
                <h1>{{ $block->heading }}</h1>
            @endif

            @if ($block->body)
                <div class="standfirst">{{ $block->prose() }}</div>
            @endif

            @if ($links !== [])
                <p class="cta">
                    @foreach ($links as $link)
                        <a @class(['button', 'button-accent' => $loop->first, 'button-glass' => ! $loop->first]) href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                    @endforeach
                </p>
            @elseif ($cta !== 'none')
                <p class="cta">
                    <a class="button button-accent" href="#{{ $cta }}">{{ __('hosted.blocks.hero.cta.' . $cta) }}</a>
                </p>
            @endif

            @if ($badges !== [])
                <ul class="hero-badges">
                    @foreach ($badges as $badge)
                        <li>@include('hosted.partials.icon', ['name' => $badge['icon']]){{ \App\Domain\Hosted\Support\BlockItems::text($badge, 'text', $locale) }}</li>
                    @endforeach
                </ul>
            @endif

        </div>

        {{-- The same form the search page carries, with the same fields the
             operator enabled — not a cut-down version of it, because a visitor
             who filters by port on one page and cannot on the other has learnt
             something untrue about the site. --}}
    </div>
</section>

{{-- The search: a horizontal bar under the photograph, overlapping its lower
     edge (Mike, 2026-09-24, option Α of the home-page mockup). It used to be a
     card inside the photograph on the right, which hid half of it.

     The home page's only. On «Σχετικά με εμάς» the visitor came to read about
     the operator, and the search is one menu link away.

     The title is kept for screen readers: a form landmark needs a name, and the
     bar is plain enough to a sighted visitor without one. --}}
@if (($pageName ?? 'home') === 'home')
    <div class="hero-search hero-search-below">
        <h2 class="hero-search-title sr-only">{{ __('hosted.blocks.hero.search_title') }}</h2>
        @include('hosted.partials.search-form', [
            'action' => route('hosted.search', ['operator' => $tenant->slug]),
            'idPrefix' => 'hero',
        ])
    </div>
@endif
