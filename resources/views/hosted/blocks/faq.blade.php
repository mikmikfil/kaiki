{{--
    The FAQ block on the operator's home page (#102's editor, #103's rows).

    A mount rather than a block with content of its own: everything below the
    heading comes from the `faqs` table, so an operator edits their answers in
    one screen and they appear everywhere the FAQ is shown.

    `BuildHomePage` hands over the **tenant-wide** entries only — see
    `App\Enums\HomeBlockType::Faq` for why a product-specific answer has no place
    on a page with no product on it.
--}}
@include('hosted.partials.faq', [
    'entries' => $faqs,
    // The operator's own heading when they wrote one, and the generic line
    // otherwise. Unlike the gallery, this block is not headless-by-default: a
    // run of questions with no title, halfway down a scrolling page, reads as
    // part of whatever is above it.
    'heading' => $block->heading ?: __('hosted.blocks.faq.heading'),
    'anchor' => $anchor,
])
