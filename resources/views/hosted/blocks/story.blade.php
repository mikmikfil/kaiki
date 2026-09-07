{{--
    Heading and prose, with an optional image beside it. The "about us".

    `image_side` is a class rather than a style attribute: HOS-8's policy has no
    `unsafe-inline`, so an inline `style` would be dropped by the browser and
    the operator's choice would silently do nothing.
--}}
<section class="block story side-{{ $block->setting('image_side') }} @if ($block->image_path) has-image @endif">
    @if ($block->image_path)
        <img class="story-image"
             src="{{ \App\Domain\Hosted\Support\HostedAsset::url($block->image_path) }}"
             alt=""
             loading="lazy">
    @endif

    <div class="story-copy">
        @if ($block->heading)
            <h2>{{ $block->heading }}</h2>
        @endif

        @if ($block->body)
            <div class="prose">{{ $block->prose() }}</div>
        @endif
    </div>
</section>
