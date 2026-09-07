{{--
    The catalogue, or the slice of it this block selected.

    The only block that reads another table — and it reads the one collection
    `BuildHomePage` loaded for the whole page, so two trips blocks are two
    filters rather than two queries. The anchor is decided there too, so the
    second trips block on a page cannot silently steal the hero's target.
--}}
<section class="block trips-block" @if ($anchor) id="{{ $anchor }}" @endif>
    @if ($block->heading)
        <h2>{{ $block->heading }}</h2>
    @endif

    @if ($products->isEmpty())
        <p>{{ __('hosted.index.no_trips') }}</p>
    @else
        <ul class="trips">
            @foreach ($products as $product)
                <li class="trip">
                    {{-- The card became a link in #104, when there was somewhere
                         for it to go. The whole heading is the target rather
                         than a "read more" underneath it: a row of identical
                         "read more" links is what a screen-reader user hears
                         when they list the links on the page. --}}
                    <h3>
                        <a href="{{ route('hosted.product', ['operator' => $tenant->slug, 'product' => $product->slug, 'lang' => $locale]) }}">{{ $product->title }}</a>
                    </h3>
                    @if ($product->summary)
                        <p class="summary">{{ $product->summary }}</p>
                    @endif
                    <p class="facts">
                        {{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}
                        @if ($product->meetingPoint)
                            · {{ $product->meetingPoint->name }}
                        @endif
                        @if ($product->vessel)
                            · {{ $product->vessel->name }}
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</section>
