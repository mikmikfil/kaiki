{{--
    «Γιατί εμάς»: up to four reasons, each behind an icon (2026-09-16).

    Like the steps, a new block starts with four reasons in both languages for
    the operator to keep, change or delete, and nothing here falls back to
    platform copy.

    `settings.dark` puts it on a band in a deep shade of the operator's own
    primary colour — the navy section of their WordPress site. That is a dark
    **section** on a light page, not a dark theme: guest surfaces stay light.

    The icon is a name from a fixed list (`BlockItems::ICONS`), drawn by the
    icon partial and hidden from assistive technology, because the title beside
    it already says the same thing. An optional photograph sits beside the
    reasons, with the description the operator wrote for it.
--}}
@php
    use App\Domain\Hosted\Support\BlockItems;
    use App\Domain\Hosted\Support\HostedAsset;

    $locale = app()->getLocale();
    $image = HostedAsset::url($block->image_path);
    $dark = (bool) $block->setting('dark', false);

    $reasons = collect($block->entries())->map(fn (array $entry): array => [
        'icon' => $entry['icon'],
        'title' => BlockItems::text($entry, 'title', $locale),
        'text' => BlockItems::text($entry, 'text', $locale),
    ]);
@endphp

@if ($reasons->isNotEmpty() || $block->heading)
    <section @class(['block', 'band', 'features-block', 'band-dark' => $dark, 'band-mist' => ! $dark, 'has-image' => $image !== null])>
        <div class="band-inner">
            <div class="band-copy">
                @include('hosted.partials.section-head', [
                    'eyebrow' => $block->eyebrow,
                    'heading' => $block->heading,
                    'lead' => $block->body ? $block->prose() : null,
                    'center' => $image === null,
                ])

                @if ($reasons->isNotEmpty())
                    <ul @class(['features', 'features-' . $reasons->count()])>
                        @foreach ($reasons as $reason)
                            <li class="feature">
                                <span class="feature-icon">@include('hosted.partials.icon', ['name' => $reason['icon']])</span>
                                <h3>{{ $reason['title'] }}</h3>
                                @if ($reason['text'] !== '')
                                    <p>{{ $reason['text'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($image)
                <img class="band-image" src="{{ $image }}" alt="{{ $block->image_alt ?? '' }}" loading="lazy">
            @endif
        </div>
    </section>
@endif
