{{--
    A row of photographs.

    **Alt text is per image and per locale**, so the Greek page does not describe
    its photographs in English. `HomePageBlock::galleryImages()` falls back to
    the other language rather than to an empty string — a wrong-language
    description is worth more to a screen reader than none.

    No lightbox, no carousel. Both need JavaScript, and HOS-4 says every word of
    content renders without it.
--}}
@php
    $images = $block->galleryImages($locale);
@endphp

@if ($images !== [])
    <section class="block gallery cols-{{ $block->setting('columns') }}">
        @if ($block->heading)
            <h2>{{ $block->heading }}</h2>
        @endif

        <ul class="shots">
            @foreach ($images as $image)
                <li>
                    <img src="{{ Storage::disk('public')->url($image['path']) }}"
                         alt="{{ $image['alt'] }}"
                         loading="lazy">
                </li>
            @endforeach
        </ul>
    </section>
@endif
