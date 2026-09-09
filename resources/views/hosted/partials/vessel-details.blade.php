{{--
    What the operator has actually filled in about the boat.

    ## Why there is no link to a vessel page

    Because there is no vessel page. Kaiki has no public route for a boat — no
    controller, no template, no structured data — and inventing one here would
    be a new guest-facing surface with its own canonical URLs, its own hreflang
    and its own tests, which is an issue rather than a partial. The request was
    that the boat's details be reachable if they exist; they exist on the
    `vessels` row, so they are shown here instead.

    ## Every field is optional and an empty one is absent

    `registration_number`, `length_cm`, `crew_count`, `captain_name` and `specs`
    are all nullable, and most operators fill in some of them. A dash where a
    length should be tells a guest nothing except that the operator did not
    finish; the row simply does not appear.

    `max_wind_bft` is deliberately **not** here. It is the force this boat stops
    sailing in (ADR-0027) — an operations figure that decides whether the
    operator cancels, not a specification a guest is choosing between boats on,
    and printing it invites the question "so will it sail on Tuesday?" which
    this page cannot answer.

    Expects: $vessel.
--}}
@php
    /** @var \App\Models\Vessel $vessel */
    $facts = array_filter([
        'type' => $vessel->type->label(),
        'length' => $vessel->length_cm
            ? __('hosted.product.boat.metres', ['length' => rtrim(rtrim(number_format($vessel->length_cm / 100, 1, ',', ''), '0'), ',')])
            : null,
        'capacity' => $vessel->capacity_max
            ? __('hosted.product.max_pax', ['count' => $vessel->capacity_max])
            : null,
        'crew' => $vessel->crew_count > 0 ? (string) $vessel->crew_count : null,
        'captain' => $vessel->captain_name,
        'registration' => $vessel->registration_number,
    ], static fn (?string $v): bool => $v !== null && $v !== '');

    // `specs` is the operator's own free key/value list, so this template
    // cannot know every key. It knows the common ones — the panel offers them
    // and the demo uses them — and anything else is humanised rather than
    // printed raw: a guest reading «cruising_speed_kn: 9» has been shown a
    // column name, which is a thing an operator should never be able to leak
    // onto their own page by filling a field in.
    $specs = collect(is_array($vessel->specs) ? $vessel->specs : [])
        ->filter(static fn (mixed $v, mixed $k): bool => is_string($k) && is_scalar($v) && (string) $v !== '')
        ->mapWithKeys(function (mixed $value, string $key): array {
            $label = __('hosted.product.boat.specs.' . $key);

            if ($label === 'hosted.product.boat.specs.' . $key) {
                $label = ucfirst(str_replace('_', ' ', $key));
            }

            // Units belong to the key, not to what the operator typed: they
            // enter 4.2 and the page says 4,2 m. A value that already carries
            // its own unit — «2 × 180 hp» — is left exactly as it was written.
            $suffix = match ($key) {
                'beam_m', 'draft_m' => ' m',
                'cruising_speed_kn', 'max_speed_kn' => ' ' . __('hosted.product.boat.knots'),
                default => '',
            };

            $printed = $suffix !== '' && is_numeric($value)
                ? str_replace('.', ',', (string) $value) . $suffix
                : (string) $value;

            return [$label => $printed];
        });

    $shots = \App\Domain\Media\Support\ImagePayload::collection($vessel->images, $locale);
@endphp

<p class="boat-name"><strong>{{ $vessel->name }}</strong></p>

@if ($facts !== [] || $specs->isNotEmpty())
    <dl class="boat-facts">
        @foreach ($facts as $key => $value)
            <div>
                <dt>{{ __('hosted.product.boat.' . $key) }}</dt>
                <dd>{{ $value }}</dd>
            </div>
        @endforeach

        @foreach ($specs as $label => $value)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif

@if ($shots !== [])
    {{-- The boat's own photographs, not the trip's. An operator who has
         uploaded pictures of the deck has answered "what am I sailing on"
         better than any list of measurements. --}}
    <ul class="shots boat-shots">
        @foreach ($shots as $shot)
            <li>
                <img src="{{ \App\Domain\Hosted\Support\HostedAsset::relative($shot['url']) }}"
                     alt="{{ $shot['alt'] ?? '' }}"
                     loading="lazy">
            </li>
        @endforeach
    </ul>
@endif
