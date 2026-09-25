{{--
    «Πώς λειτουργεί»: the steps as a route (Mike, 2026-09-24, from
    docs/mockups/steps-route.html, version Ε). It used to be three white cards
    on a sand-coloured band.

    Every word is the operator's, from the editor — which starts a new block off
    with the three steps every Kaiki booking actually takes (choose, pay online,
    turn up), in both languages, for them to keep, change or delete. Nothing
    here falls back to platform copy, so a step the operator deleted stays gone.

    An `<ol>`, because the order is the content. Each stop is a numbered circle
    on a dashed line, with its step under it; the last circle carries a tick.
    The numbers are drawn for the eye and hidden from a screen reader, which
    already announces the list position.

    `data-animate` is for `/hosted/route.js`, which travels the route once when
    it comes into view: each circle fills in turn and the line to the next grows
    solid. Without the script, or with reduced motion, the route is simply shown
    complete.

    With a photograph, the route sits beside it; without one, under the heading.
--}}
@php
    use App\Domain\Hosted\Support\BlockItems;
    use App\Domain\Hosted\Support\HostedAsset;

    $locale = app()->getLocale();
    $image = HostedAsset::url($block->image_path);

    $steps = collect($block->entries())->map(fn (array $entry): array => [
        'title' => BlockItems::text($entry, 'title', $locale),
        'text' => BlockItems::text($entry, 'text', $locale),
    ]);
@endphp

@if ($steps->isNotEmpty() || $block->heading)
    <section @class(['block', 'steps-block', 'steps-route', 'has-image' => $image !== null])>
        <div class="band-inner">
            <div class="band-copy">
                @include('hosted.partials.section-head', [
                    'eyebrow' => $block->eyebrow,
                    'heading' => $block->heading,
                    'lead' => $block->body ? $block->prose() : null,
                    'center' => $image === null,
                ])

                @if ($steps->isNotEmpty())
                    <ol @class(['route', 'route-' . min($steps->count(), 4)]) data-animate>
                        @foreach ($steps as $step)
                            <li @class(['stop', 'is-last' => $loop->last])>
                                <span class="stop-dot" aria-hidden="true">
                                    @if ($loop->last && $loop->count > 1)
                                        @include('hosted.partials.icon', ['name' => 'check'])
                                    @else
                                        {{ $loop->iteration }}
                                    @endif
                                </span>
                                <div class="stop-copy">
                                    <h3>{{ $step['title'] }}</h3>
                                    @if ($step['text'] !== '')
                                        <p>{{ $step['text'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    <script src="{{ url('/hosted/route.js') }}" defer></script>
                @endif
            </div>

            @if ($image)
                <img class="band-image" src="{{ $image }}" alt="{{ $block->image_alt ?? '' }}" loading="lazy">
            @endif
        </div>
    </section>
@endif
