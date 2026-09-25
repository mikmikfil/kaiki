{{--
    «Τα σκάφη μας» (2026-09-24), from «Σκάφη»: nothing here is typed twice.

    A boat with no photograph gets its name on the operator's colour rather
    than a grey box. A figure the operator never filled in (length, say) is
    left out rather than printed as a dash.
--}}
@php
    $locale = app()->getLocale();
    $decimal = $locale === 'el' ? ',' : '.';

    $metres = static function (?int $cm) use ($decimal): ?string {
        if ($cm === null || $cm <= 0) {
            return null;
        }

        $value = number_format($cm / 100, 1, $decimal, '');

        return str_ends_with($value, $decimal . '0') ? substr($value, 0, -2) : $value;
    };
@endphp

@if ($vessels->isNotEmpty())
    <section class="block fleet-block">
        @include('hosted.partials.section-head', [
            'eyebrow' => $block->eyebrow,
            'heading' => $block->heading,
            'lead' => $block->body ? $block->prose() : null,
        ])

        <div @class(['fleet', 'fleet-' . min($vessels->count(), 3)])>
            @foreach ($vessels as $vessel)
                @php $length = $metres($vessel->length_cm); @endphp
                <article class="boat-card">
                    @if ($vessel->photo_url)
                        <img class="boat-photo" src="{{ $vessel->photo_url }}" alt="" loading="lazy">
                    @else
                        <div class="boat-photo boat-photo-empty" aria-hidden="true"><span>{{ $vessel->name }}</span></div>
                    @endif

                    <div class="boat-body">
                        <div>
                            <h3>{{ $vessel->name }}</h3>
                            @if ($vessel->registration_number)
                                <p class="boat-reg">{{ $vessel->registration_number }}</p>
                            @endif
                        </div>

                        <dl class="boat-specs">
                            <div><dt>{{ __('hosted.about.fleet.capacity') }}</dt><dd>{{ $vessel->capacity_max }}</dd></div>
                            @if ($length)
                                <div><dt>{{ __('hosted.about.fleet.length') }}</dt><dd>{{ __('hosted.about.fleet.metres', ['value' => $length]) }}</dd></div>
                            @endif
                        </dl>

                        @if ($vessel->licence_type)
                            <p class="boat-licence">{{ $vessel->licence_type->label() }}</p>
                        @endif

                        {{-- Its trips, each straight to its own page: up to three,
                             and how many more there are. --}}
                        @if ($vessel->trips_on_sale > 0)
                            <ul class="boat-trips" aria-label="{{ trans_choice('hosted.about.fleet.trips', $vessel->trips_on_sale, ['count' => $vessel->trips_on_sale]) }}">
                                @foreach ($vessel->trips_list->take(3) as $trip)
                                    <li><a href="{{ route('hosted.product', ['operator' => $tenant->slug, 'product' => $trip->slug, 'lang' => $locale]) }}">{{ $trip->title }} <span aria-hidden="true">→</span></a></li>
                                @endforeach
                                @if ($vessel->trips_on_sale > 3)
                                    <li class="more">{{ trans_choice('hosted.about.fleet.more', $vessel->trips_on_sale - 3, ['count' => $vessel->trips_on_sale - 3]) }}</li>
                                @endif
                            </ul>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
