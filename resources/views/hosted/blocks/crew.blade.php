{{--
    «Οι άνθρωποί μας» (2026-09-24), from «Ομάδα»: captains first, then
    deckhands, each with the photograph and «Λίγα λόγια» the operator gave them.

    Somebody with no photograph shows their initials on the operator's colour.
    With «Με φωτογραφίες» off, nobody has a picture and the cards are names.
--}}
@php
    $locale = app()->getLocale();
    $photos = (bool) $block->setting('photos', true);

    $initials = static function (string $name): string {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = array_map(static fn (string $part): string => mb_substr($part, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters));
    };
@endphp

@if ($crew->isNotEmpty())
    <section @class(['block', 'band', 'band-mist', 'crew-block', 'no-photos' => ! $photos])>
        @include('hosted.partials.section-head', [
            'eyebrow' => $block->eyebrow,
            'heading' => $block->heading,
            'lead' => $block->body ? $block->prose() : null,
        ])

        <ul @class(['crew', 'crew-' . min($crew->count(), 4)])>
            @foreach ($crew as $person)
                <li class="person">
                    @if ($photos)
                        @if ($person->photo_url)
                            <img class="person-photo" src="{{ $person->photo_url }}" alt="" loading="lazy">
                        @else
                            <span class="person-photo person-initials" aria-hidden="true">{{ $initials($person->name) }}</span>
                        @endif
                    @endif
                    <div class="person-copy">
                        <h3>{{ $person->name }}</h3>
                        <p class="person-role">{{ $person->specialty?->label() }}</p>
                        @if ($person->bioIn($locale) !== '')
                            <p>{{ $person->bioIn($locale) }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
