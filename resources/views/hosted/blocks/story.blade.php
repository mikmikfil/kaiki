{{--
    Heading and prose, with an optional image beside it. The "about us".

    ## The copy comes first in the DOM, and the image is moved by CSS

    Not cosmetic. The heading is what a screen reader and a search engine should
    reach first — an illustration ahead of its own `<h2>` is the wrong reading
    order in both — and on a narrow screen the single column then reads heading,
    prose, photograph, which is the order somebody would write it in.

    It is also what makes `image_side` mean anything. When the `<img>` came
    first, `side-left`'s `order: -1` moved it to where it already was and
    `side-right` left it there: the control did nothing and every story block
    rendered image-left, whichever way the operator set it. With the copy first,
    the default is image-right and `side-left` is the one that moves.

    `image_side` is a class rather than a `style` attribute because HOS-8's
    policy has no `unsafe-inline`, so an inline style would be dropped by the
    browser and the operator's choice would silently do nothing.
--}}
<section class="block story side-{{ $block->setting('image_side') }} @if ($block->image_path) has-image @endif">
    <div class="story-copy">
        @if ($block->heading)
            <h2>{{ $block->heading }}</h2>
        @endif

        @if ($block->body)
            <div class="prose">{{ $block->prose() }}</div>
        @endif
    </div>

    @if ($block->image_path)
        <img class="story-image"
             src="{{ \App\Domain\Hosted\Support\HostedAsset::url($block->image_path) }}"
             alt=""
             loading="lazy">
    @endif
</section>
