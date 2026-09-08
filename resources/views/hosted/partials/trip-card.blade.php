{{--
    One trip, as a card.

    A partial rather than a loop body written twice: the home page shows its
    featured trips in a rail and the rest in a grid below, and two copies of this
    markup would drift the first time somebody improved one of them.

    ## A picture, a price and a way in

    A row of text cards is a list; a row of photographs is a shop. The image is
    the operator's own first product photo, and a trip that has none gets a
    tinted panel in their own colours rather than a broken box or a stock
    photograph of somebody else's boat.

    **A quote product shows no price at all** (BKG-24) — not a hidden one and not
    a dash. `price_from_cents` is null for one, so the element is never rendered.
--}}
@php
    use App\Domain\Hosted\Support\HostedAsset;
    use App\Support\Format\MoneyFormatter;

    $url = route('hosted.product', ['operator' => $tenant->slug, 'product' => $product->slug, 'lang' => $locale]);
    $first = $product->images[0] ?? null;
    $imageUrl = is_array($first) && isset($first['path']) ? HostedAsset::url((string) $first['path']) : null;
@endphp

<li class="trip">
    {{-- Hidden from the accessibility tree: the heading below is the same link,
         and somebody listing the links on this page should hear each trip
         once. --}}
    <a class="trip-image @if ($imageUrl === null) is-empty @endif"
       href="{{ $url }}"
       aria-hidden="true"
       tabindex="-1">
        @if ($imageUrl !== null)
            <img src="{{ $imageUrl }}" alt="" loading="lazy">
        @endif
    </a>

    <div class="trip-body">
        {{-- The heading is the link rather than a "read more" underneath it: a
             row of identical "read more" links is what a screen-reader user
             hears otherwise. --}}
        <h3><a href="{{ $url }}">{{ $product->title }}</a></h3>

        @if ($product->summary)
            <p class="summary">{{ $product->summary }}</p>
        @endif

        {{-- Two facts, each behind its own icon rather than a row of values
             separated by dots. A card is scanned, not read: the clock and the
             pin let somebody find the duration and the departure point without
             parsing a sentence, and the two of them together fit on one line
             where three dot-separated values wrapped. --}}
        <p class="facts">
            <span>@include('hosted.partials.icon', ['name' => 'clock']){{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}</span>
            @if ($product->meetingPoint || $product->vessel)
                <span>@include('hosted.partials.icon', ['name' => 'pin'])@if ($product->meetingPoint){{ $product->meetingPoint->name }}@endif@if ($product->meetingPoint && $product->vessel) · @endif@if ($product->vessel){{ $product->vessel->name }}@endif</span>
            @endif
        </p>

        {{-- Pinned to the bottom by `margin-top: auto`, so a row of cards has
             its prices and its buttons on one line however long the summaries
             are. Cards whose buttons sit at different heights read as a mistake
             (settled 4 September). --}}
        <div class="trip-foot">
            @if ($product->price_from_cents !== null)
                <p class="trip-price">
                    <span class="from">{{ __('hosted.index.from') }}</span>
                    <strong>{{ MoneyFormatter::format($product->price_from_cents, $locale, MoneyFormatter::currency()) }}</strong>
                </p>
            @endif

            <a class="button button-small arrow" href="{{ $url }}">{{ __('hosted.index.view') }}</a>
        </div>
    </div>
</li>
