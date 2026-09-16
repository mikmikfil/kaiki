{{--
    «Αριθμοί»: a section of up to four figures (2026-09-16).

    The operator's small line and heading on the left, the figures two by two
    on the right, each a light, large number over a hairline with a few words
    under it — Mike's pick («S3») of three. No icons: the icon field is kept
    in the editor, but a row of glyphs over four numbers was the busy part of
    the earlier version. On a phone the heading sits above the figures.

    Renders nothing without a figure — a heading over no numbers is worse than
    no section. Without a written heading the section still has one for the
    outline, for screen readers only.
--}}
@php
    use App\Domain\Hosted\Support\BlockItems;

    $locale = app()->getLocale();
    $figures = collect($block->entries())
        ->map(fn (array $entry): array => [
            'icon' => $entry['icon'] ?? null,
            'value' => BlockItems::text($entry, 'value', $locale),
            'label' => BlockItems::text($entry, 'label', $locale),
        ])
        ->filter(fn (array $figure): bool => $figure['value'] !== '');
@endphp

@if ($figures->isNotEmpty())
    <section class="block band stats-block">
        @include('hosted.partials.section-head', [
            'eyebrow' => $block->eyebrow,
            'heading' => $block->heading,
            'lead' => null,
            'center' => false,
        ])

        @unless ($block->heading)
            <h2 class="sr-only">{{ __('hosted.blocks.stats.heading') }}</h2>
        @endunless

        <ul @class(['stats', 'stats-' . $figures->count()])>
            @foreach ($figures as $figure)
                <li>
                    <strong>{{ $figure['value'] }}</strong>
                    @if ($figure['label'] !== '')
                        <span class="stat-label">{{ $figure['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
