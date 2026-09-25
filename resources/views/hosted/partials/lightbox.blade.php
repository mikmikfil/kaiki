{{--
    One lightbox, used twice on the trip page: for the trip's own photographs
    (the mosaic at the top) and for the boat's (the rail in «Το σκάφος»).
    Mike, 2026-09-25: «Οι φωτό του σκάφους στην εκδρομή να ανοίγουν με
    lightbox» — the same one, not a second implementation.

    ## In CSS alone, with a script on top

    `:target` is what opens it: each photograph links to the id of its own
    full-size panel, and the panel is hidden until the URL names it. With no
    script, opening, closing and stepping are plain links, Back closes it, and
    every photograph is still reachable with the lightbox never opened at all.

    `/hosted/gallery.js` adds what needs a script: arrow keys, Escape, swipe,
    focus moved into the panel and back to the photograph that opened it, and
    Tab kept inside the open panel.

    ## Groups

    `data-lightbox` names the set a panel belongs to, so the arrows and the
    swipe walk the boat's photographs without wandering into the trip's.

    Expects:
      $shots  list of ImagePayload entries (url, alt)
      $group  the id prefix and the group name: `shot`, `boat-shot`
      $back   the id the close link returns to
      $label  what the set is called, for the dialog and its navigation
--}}
@php
    $total = count($shots);
@endphp

@foreach ($shots as $i => $shot)
    <div class="lightbox" id="{{ $group }}-{{ $i }}" data-lightbox="{{ $group }}" role="dialog" aria-modal="true"
         aria-label="{{ $shot['alt'] ?: $label }}">
        {{-- The dark ground closes it on a click. Out of the tab order and
             hidden from a screen reader: the close link below is the one
             control for that, and two would be announced as a duplicate. --}}
        <a class="lightbox-scrim" href="#{{ $back }}" tabindex="-1" aria-hidden="true"></a>

        <figure>
            <img src="{{ \App\Domain\Hosted\Support\HostedAsset::relative($shot['url']) }}"
                 alt="{{ $shot['alt'] ?? '' }}"
                 loading="lazy">

            @if (($shot['alt'] ?? '') !== '')
                <figcaption>{{ $shot['alt'] }}</figcaption>
            @endif
        </figure>

        @if ($total > 1)
            <p class="lightbox-count">{{ __('hosted.product.photo_count', ['current' => $i + 1, 'total' => $total]) }}</p>
        @endif

        <a class="lightbox-close" href="#{{ $back }}">{{ __('hosted.product.close') }}</a>

        <nav class="lightbox-step" aria-label="{{ $label }}">
            @if ($i > 0)
                <a class="prev" href="#{{ $group }}-{{ $i - 1 }}" rel="prev" aria-label="{{ __('hosted.product.photo_previous') }}"><span aria-hidden="true">&#8249;</span></a>
            @endif
            @if ($i < $total - 1)
                <a class="next" href="#{{ $group }}-{{ $i + 1 }}" rel="next" aria-label="{{ __('hosted.product.photo_next') }}"><span aria-hidden="true">&#8250;</span></a>
            @endif
        </nav>
    </div>
@endforeach
