{{--
    The catalogue, or the slice of it this block selected.

    The only block that reads another table — and it reads the one collection
    `BuildHomePage` loaded for the whole page, so two trips blocks are two
    filters rather than two queries. The anchor is decided there too, so the
    second trips block on a page cannot silently steal the hero's target.

    ## Featured in a rail, the rest in a grid

    A home page that opens with eleven equal cards asks a visitor to compare
    eleven things before they know what any of them are. So the operator's
    featured trips — up to six — go in a scroll-snapped rail that reads as a
    recommendation, and everything else follows underneath as an ordinary grid
    for the visitor who wants the whole list.

    An operator who has flagged nothing gets their first six in the order they
    arranged. That is the right answer for a fleet of four and a sensible one for
    a fleet of twenty.
--}}
<section class="block trips-block" @if ($anchor) id="{{ $anchor }}" @endif>
    @php
        $featured = $products->where('is_featured', true)->take(6)->values();

        if ($featured->isEmpty()) {
            $featured = $products->take(6)->values();
        }

        $featuredIds = $featured->pluck('id')->all();
        $rest = $products->reject(fn ($product) => in_array($product->id, $featuredIds, true))->values();
    @endphp

    {{-- The heading and the way out of it on one line. A visitor who wants the
         whole catalogue rather than the six recommendations should not have to
         find the search page in the navigation; and a rail that scrolls
         sideways needs to say, somewhere, that there is more than what fits. --}}
    @if ($block->heading)
        <div class="block-head">
            <h2>{{ $block->heading }}</h2>
            <a class="see-all" href="{{ route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.index.see_all') }}</a>
        </div>
    @endif

    @if ($products->isEmpty())
        <p>{{ __('hosted.index.no_trips') }}</p>
    @else
        {{-- A scroll-snapped rail, and **no JavaScript**. The hosted pages are
             readable without it (HOS-2, WGT-23), and a native horizontal
             scroller already swipes on a phone, scrolls on a trackpad, and
             answers the arrow keys once it is focused. Arrow buttons would need
             a script, and a script here would need an exception to HOS-8's
             policy for something the browser does already.

             `tabindex="0"` is what makes it keyboard-scrollable: a scroll
             container that cannot be focused cannot be scrolled without a
             mouse, which is a real failure and an easy one to miss. --}}
        {{-- A frame around the rail, purely so the arrows have something that
             does not scroll to hang from. `::scroll-button()` is generated on
             the scroll container, and an absolutely positioned box resolves
             against its nearest positioned ancestor — put that on the rail
             itself and the buttons slide away with the cards. --}}
        <div class="trips-rail-frame">
        <ul class="trips trips-rail"
            tabindex="0"
            aria-label="{{ $block->heading ?: __('hosted.index.trips') }}">
            @foreach ($featured as $product)
                @include('hosted.partials.trip-card', ['product' => $product])
            @endforeach
        </ul>
        </div>

        @if ($rest->isNotEmpty())
            @php
                // Twenty a page, asked for directly. A catalogue this size fits
                // in memory either way (`docs/data-model.md` sizes a tenant at
                // tens of products, and `BuildHomePage` has already loaded all
                // of them once for the whole page) — so this slices a
                // collection rather than issuing a second query.
                //
                // `trips` rather than `page`: the parameter names *this* list,
                // so a second paged thing on the same page cannot move it, and
                // the anchor keeps a visitor where they were reading instead of
                // returning them to the hero.
                $perPage = 20;
                $pages = (int) ceil($rest->count() / $perPage);
                $page = min(max(1, (int) request()->query('trips', 1)), max(1, $pages));
                $shown = $rest->forPage($page, $perPage);
                $fragment = $anchor ? '#' . $anchor : '';
                $pageUrl = fn (int $n): string => request()->fullUrlWithQuery(['trips' => $n]) . $fragment;
            @endphp

            <h3 class="trips-more">{{ __('hosted.index.all_trips') }}</h3>

            <ul class="trips">
                @foreach ($shown as $product)
                    @include('hosted.partials.trip-card', ['product' => $product])
                @endforeach
            </ul>

            @if ($pages > 1)
                {{-- Plain links, like everything else here: HOS-4 promises these
                     pages work with no JavaScript, and a pager is a list of
                     URLs. `rel=prev/next` because a crawler reading a paginated
                     catalogue should know it is one. --}}
                <nav class="pager" aria-label="{{ __('hosted.index.all_trips') }}">
                    @if ($page > 1)
                        <a class="pager-step" rel="prev" href="{{ $pageUrl($page - 1) }}">{{ __('pagination.previous') }}</a>
                    @else
                        <span class="pager-step is-off">{{ __('pagination.previous') }}</span>
                    @endif

                    <ol class="pager-pages">
                        @for ($n = 1; $n <= $pages; $n++)
                            <li>
                                @if ($n === $page)
                                    <span class="is-current" aria-current="page">{{ $n }}</span>
                                @else
                                    <a href="{{ $pageUrl($n) }}">{{ $n }}</a>
                                @endif
                            </li>
                        @endfor
                    </ol>

                    @if ($page < $pages)
                        <a class="pager-step" rel="next" href="{{ $pageUrl($page + 1) }}">{{ __('pagination.next') }}</a>
                    @else
                        <span class="pager-step is-off">{{ __('pagination.next') }}</span>
                    @endif
                </nav>
            @endif
        @endif
    @endif
</section>
