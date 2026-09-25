{{--
    «Από πού φεύγουμε» (2026-09-24): one port from «Λιμάνια», its photograph
    across the page and a white card with the address, the coordinates, the
    port's own instructions and a directions button.

    No photograph: the same card on the operator's deep colour. No port at all:
    nothing — a meeting point with no address is not one.
--}}
@php
    $locale = app()->getLocale();

    $directions = $port?->maps_url
        ?: ($port?->lat !== null && $port?->lng !== null
            ? 'https://www.google.com/maps/dir/?api=1&destination=' . $port->lat . ',' . $port->lng
            : null);

    $coordinates = static function (?string $value, string $positive, string $negative): ?string {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $decimal = abs((float) $value);
        $degrees = (int) floor($decimal);
        $minutes = ($decimal - $degrees) * 60;
        $seconds = (int) round(($minutes - floor($minutes)) * 60);

        return sprintf('%d°%02d′%02d″ %s', $degrees, (int) floor($minutes), min($seconds, 59), (float) $value >= 0 ? $positive : $negative);
    };

    $position = $port ? collect([
        $coordinates($port->lat, __('hosted.about.port.north'), __('hosted.about.port.south')),
        $coordinates($port->lng, __('hosted.about.port.east'), __('hosted.about.port.west')),
    ])->filter()->implode(' · ') : '';

    $instructions = $port ? trim((string) ($port->getTranslation('instructions', $locale, true) ?? '')) : '';
@endphp

@if ($port)
    <section @class(['block', 'band', 'meeting-block', 'has-image' => $port->photo_url !== null])>
        @if ($port->photo_url)
            <img class="meeting-photo" src="{{ $port->photo_url }}" alt="" loading="lazy">
        @endif

        <div class="meeting-card">
            @if ($block->eyebrow)
                <p class="eyebrow-line">{{ $block->eyebrow }}</p>
            @endif
            <h2>{{ $block->heading ?: $port->name }}</h2>
            @if ($port->address)
                <p>{{ $port->address }}</p>
            @endif
            @if ($position !== '')
                <p class="meeting-position">{{ $position }}</p>
            @endif
            @if ($instructions !== '')
                <p class="meeting-hint">@include('hosted.partials.icon', ['name' => 'clock']){{ $instructions }}</p>
            @elseif ($block->body)
                <div class="meeting-hint">@include('hosted.partials.icon', ['name' => 'clock']){{ $block->prose() }}</div>
            @endif
            @if ($directions)
                <p class="meeting-cta"><a class="button button-accent" href="{{ $directions }}" target="_blank" rel="noopener">{{ __('hosted.about.port.directions') }}</a></p>
            @endif
        </div>
    </section>
@endif
