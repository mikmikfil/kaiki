{{--
    «Κάλεσμα»: a photographed band with a short line, a heading, a sentence and
    one or two buttons (2026-09-16).

    The buttons arrive as `$links`, already resolved by `BuildHomePage` from a
    target the operator chose — the trips, the search, the contact page, one of
    their trips, or a path on their own hosted site — with the label they wrote.
    No address an operator typed reaches an `href` here, so the band cannot send
    a guest off the operator's site.

    The photograph (required by the editor) sits under a left-to-right shade in a
    deep version of the operator's primary colour, so white type stays readable
    whatever the picture; without one the band is that colour alone. Its
    description is the operator's, like every other photograph on the page.
--}}
@php
    $image = \App\Domain\Hosted\Support\HostedAsset::url($block->image_path);
@endphp

<section @class(['block', 'cta-band', 'has-image' => $image !== null])>
    @if ($image)
        <img class="cta-image" src="{{ $image }}" alt="{{ $block->image_alt ?? '' }}" loading="lazy">
    @endif

    <div class="cta-copy">
        @if ($block->eyebrow)
            <p class="eyebrow-line">{{ $block->eyebrow }}</p>
        @endif

        @if ($block->heading)
            <h2>{{ $block->heading }}</h2>
        @endif

        @if ($block->body)
            <div class="lead">{{ $block->prose() }}</div>
        @endif

        @if ($links !== [])
            <p class="buttons">
                @foreach ($links as $link)
                    <a @class(['button', 'button-light' => $loop->first, 'button-glass' => ! $loop->first]) href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                @endforeach
            </p>
        @endif
    </div>
</section>
