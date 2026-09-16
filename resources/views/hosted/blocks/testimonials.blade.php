{{--
    «Κριτικές»: up to three guest reviews (2026-09-16).

    No starting content, unlike the steps and the reasons: a review the operator
    did not receive is not something this platform may write for them. With no
    reviews entered the section shows its heading only if the operator wrote one.

    The stars are drawing — five characters in a span hidden from assistive
    technology — and the rating is said in words beside them for a screen reader.
    No structured-data claim is made from these: a `Review` in a search result
    that the operator typed themselves is exactly what search engines penalise.

    The small round photograph is optional; without one the circle carries the
    first letter of the name. It is decoration beside the name, so `alt=""`.
--}}
@php
    use App\Domain\Hosted\Support\BlockItems;
    use App\Domain\Hosted\Support\HostedAsset;

    $locale = app()->getLocale();
    $reviews = collect($block->entries())->map(fn (array $entry): array => [
        'quote' => BlockItems::text($entry, 'quote', $locale),
        'name' => (string) ($entry['name'] ?? ''),
        'trip' => BlockItems::text($entry, 'trip', $locale),
        'rating' => (int) ($entry['rating'] ?? 5),
        'avatar' => HostedAsset::url($entry['avatar'] ?? null),
    ]);
@endphp

@if ($reviews->isNotEmpty() || $block->heading)
    <section class="block testimonials-block">
        @include('hosted.partials.section-head', [
            'eyebrow' => $block->eyebrow,
            'heading' => $block->heading,
            'lead' => $block->body ? $block->prose() : null,
            'center' => true,
        ])

        @if ($reviews->isNotEmpty())
            <ul class="quotes">
                @foreach ($reviews as $review)
                    <li>
                        <figure class="quote">
                            <p class="quote-stars">
                                <span aria-hidden="true">{{ str_repeat('★', $review['rating']) }}{{ str_repeat('☆', 5 - $review['rating']) }}</span>
                                <span class="sr-only">{{ trans_choice('hosted.blocks.testimonials.rating', $review['rating'], ['count' => $review['rating']]) }}</span>
                            </p>

                            <blockquote>{{ $review['quote'] }}</blockquote>

                            @if ($review['name'] !== '' || $review['trip'] !== '')
                                <figcaption>
                                    @if ($review['avatar'])
                                        <img class="avatar" src="{{ $review['avatar'] }}" alt="" loading="lazy">
                                    @elseif ($review['name'] !== '')
                                        <span class="avatar" aria-hidden="true">{{ mb_substr($review['name'], 0, 1) }}</span>
                                    @endif
                                    <span>
                                        @if ($review['name'] !== '')
                                            <b>{{ $review['name'] }}</b>
                                        @endif
                                        @if ($review['trip'] !== '')
                                            {{ $review['trip'] }}
                                        @endif
                                    </span>
                                </figcaption>
                            @endif
                        </figure>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
