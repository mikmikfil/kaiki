{{--
    The masthead: one image, a heading, a standfirst and one call to action.

    The heading is the page's `<h1>` — the only one, because a page with two is
    a page a screen reader and a crawler both read wrong, and the hero is the
    only block that can be first.

    The call to action goes to a place on this page or to a page of ours. There
    is no free URL field: an operator who can type a URL can type an off-site
    one, and a hero button that leaves the booking flow is the most expensive
    mistake this editor could permit.
--}}
@php
    $cta = $block->setting('cta');
@endphp

<section class="block hero @if ($block->image_path) has-image @endif">
    @if ($block->image_path)
        <img class="hero-image"
             src="{{ Storage::disk('public')->url($block->image_path) }}"
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
    </div>
</section>
