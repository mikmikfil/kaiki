{{--
    «Χρονολόγιο» (2026-09-24): the operator's years, in the order they wrote
    them. A real sequence, so the years are the markers — no numbering.

    Across on a wide screen, down on a phone; the last year is filled in, as
    the one that is now.
--}}
@php
    use App\Domain\Hosted\Support\BlockItems;

    $locale = app()->getLocale();

    $years = collect($block->entries())->map(fn (array $entry): array => [
        'year' => (string) $entry['year'],
        'title' => BlockItems::text($entry, 'title', $locale),
        'text' => BlockItems::text($entry, 'text', $locale),
    ]);
@endphp

@if ($years->isNotEmpty())
    <section class="block timeline-block">
        @include('hosted.partials.section-head', [
            'eyebrow' => $block->eyebrow,
            'heading' => $block->heading,
            'lead' => null,
        ])

        <ol @class(['timeline', 'timeline-' . min($years->count(), 5)])>
            @foreach ($years as $year)
                <li @class(['is-now' => $loop->last])>
                    <span class="timeline-year">{{ $year['year'] }}</span>
                    <h3>{{ $year['title'] }}</h3>
                    @if ($year['text'] !== '')
                        <p>{{ $year['text'] }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
