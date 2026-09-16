{{--
    «Πώς λειτουργεί»: numbered steps on a sand-coloured band (2026-09-16).

    Every word is the operator's, from the editor — which starts a new block off
    with the three steps every Kaiki booking actually takes (choose, pay online,
    turn up), in both languages, for them to keep, change or delete. Nothing
    here falls back to platform copy, so a step the operator deleted stays gone.

    An `<ol>`, because the order is the content. The number in the circle is
    drawn for the eye and hidden from a screen reader, which already announces
    the list position.

    With a photograph, the steps sit beside it; without one, centred under the
    heading.
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
    <section @class(['block', 'band', 'band-sand', 'steps-block', 'has-image' => $image !== null])>
        <div class="band-inner">
            <div class="band-copy">
                @include('hosted.partials.section-head', [
                    'eyebrow' => $block->eyebrow,
                    'heading' => $block->heading,
                    'lead' => $block->body ? $block->prose() : null,
                    'center' => $image === null,
                ])

                @if ($steps->isNotEmpty())
                    <ol @class(['steps', 'steps-' . $steps->count()])>
                        @foreach ($steps as $step)
                            <li class="step">
                                <span class="step-number" aria-hidden="true">{{ $loop->iteration }}</span>
                                <h3>{{ $step['title'] }}</h3>
                                @if ($step['text'] !== '')
                                    <p>{{ $step['text'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            @if ($image)
                <img class="band-image" src="{{ $image }}" alt="{{ $block->image_alt ?? '' }}" loading="lazy">
            @endif
        </div>
    </section>
@endif
