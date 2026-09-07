{{--
    One trip's hosted page (HOS-1, HOS-2, HOS-4).

    **Everything here renders without JavaScript.** The only thing a visitor with
    scripts blocked loses is the date picker, and the booking area says so and
    gives them the operator's telephone number instead — which is what HOS-4
    means by "work without JavaScript for all content".

    **There is no `{!! !!}` in this file.** Operator prose reaches the page
    through `App\Domain\Hosted\Support\BlockText` and the structured data through
    `App\Domain\Hosted\Support\JsonLd`; both return an `HtmlString`, so `{{ }}`
    renders them and the question of provenance arises in exactly two places
    rather than everywhere.

    Sections are ordered the way a guest decides: what it is, what it costs and
    when, what happens on the day, and only then the terms.
--}}
@extends('hosted.layout')

@section('title', $metaTitle . ' · ' . $tenant->name)
@section('description', $metaDescription)
@section('canonical', $canonical)

@section('content')
    @php
        /** @var \App\Models\Product $product */
        $timezone = $tenant->timezone ?: 'Europe/Athens';
        $port = $product->meetingPoint ?? $product->vessel?->homePort;
        $policy = $product->effectiveCancellationPolicy();
        $images = \App\Domain\Media\Support\ImagePayload::collection($product->images, $locale);
        $stops = $product->itineraryStopsFor($locale);
        $isQuote = $product->mode === \App\Enums\BookingMode::Quote;
    @endphp

    <nav class="crumbs" aria-label="{{ __('hosted.product.breadcrumb') }}">
        <a href="{{ route('hosted.index', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ $tenant->name }}</a>
        <span aria-hidden="true">›</span>
        <span>{{ $product->title }}</span>
    </nav>

    <article class="product">

        {{-- The lead photograph, full width and cropped to a letterbox. A trip
             page whose first element is a heading over a grid of four small
             photographs is a catalogue entry; one that opens with the view is a
             trip. The rest of the operator's photographs follow further down. --}}
        @if ($images !== [])
            <div class="product-lead">
                <img src="{{ \App\Domain\Hosted\Support\HostedAsset::relative($images[0]['url']) }}"
                     alt="{{ $images[0]['alt'] ?? $product->title }}"
                     loading="eager">
            </div>
        @endif

        <header class="product-head">
            <h1>{{ $product->title }}</h1>

            @if ($product->summary)
                <p class="standfirst">{{ $product->summary }}</p>
            @endif

            <ul class="facts">
                <li>{{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}</li>
                <li>{{ $product->category->label() }}</li>
                @if ($port)
                    <li>{{ $port->name }}</li>
                @endif
                @if ($product->vessel)
                    <li>{{ $product->vessel->name }}</li>
                @endif
                @if (! $isQuote)
                    <li>{{ __('hosted.product.max_pax', ['count' => $product->max_pax]) }}</li>
                @endif
            </ul>
        </header>

        {{--
            The booking area. Brand decision 3 of 2026-09-04 is **four lines
            above the date picker** — title, duration, port, vessel — and no
            photograph and no summary. The space looks empty in the mockup too,
            and the decision was made against exactly that temptation.
        --}}
        {{-- Two columns on a desktop: what the trip is on the left, how to book
             it on the right, and the booking card **sticky** so it is still on
             screen when a visitor has read to the bottom of the itinerary. That
             is the whole reason for the layout — a booking form below three
             screens of prose is a booking form nobody scrolls back up to. --}}
        <div class="product-body">
            <aside class="product-aside">
                <section class="booking" id="book" aria-labelledby="booking-heading">
            <h2 id="booking-heading" class="sr-only">{{ __('hosted.product.booking.heading') }}</h2>

            <dl class="four-lines">
                <div><dt>{{ __('hosted.product.booking.trip') }}</dt><dd>{{ $product->title }}</dd></div>
                <div><dt>{{ __('hosted.product.booking.duration') }}</dt><dd>{{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}</dd></div>
                <div><dt>{{ __('hosted.product.booking.port') }}</dt><dd>{{ $port?->name ?? '—' }}</dd></div>
                <div><dt>{{ __('hosted.product.booking.vessel') }}</dt><dd>{{ $product->vessel?->name ?? '—' }}</dd></div>
            </dl>

            @if (! $isQuote && $fromPriceFormatted)
                <p class="price">
                    <span class="from">{{ __('hosted.product.price.from') }}</span>
                    <strong>{{ $fromPriceFormatted }}</strong>
                    {{-- Brand decision 4: the sentence, never the rate. The rate
                         is an invoice matter (M6) and the open question with the
                         accountant therefore does not block this page. --}}
                    <span class="vat">{{ __('hosted.product.price.vat_included') }}</span>
                </p>
            @endif

            @if ($readOnly)
                {{-- HOS-10, and the guest is told who to contact rather than
                     what went wrong with somebody else's subscription. --}}
                <p class="read-only">{{ __('hosted.read_only', ['email' => $tenant->email]) }}</p>
            @else
                {{--
                    The widget's mount point (#106, #107, #108). Its contents are
                    the no-JavaScript answer and stay in the markup until the
                    widget replaces them — so a crawler, a blocked script and a
                    bad connection all get a way to book rather than an empty box.
                --}}
                <div class="mount"
                     data-kaiki-mount="{{ $isQuote ? 'enquiry' : 'booking' }}"
                     data-kaiki-product="{{ $product->uuid }}">
                    <p class="no-js">
                        {{ $isQuote ? __('hosted.product.booking.enquiry_fallback') : __('hosted.product.booking.fallback') }}
                    </p>
                    <p class="contact-cta">
                        <a class="button" href="mailto:{{ $tenant->email }}">{{ __('hosted.product.booking.email_us') }}</a>
                        @if ($tenant->phone)
                            <a class="button ghost" href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a>
                        @endif
                    </p>
                </div>
            @endif
                </section>
            </aside>

            <div class="product-main">

        @if ($product->description)
            <section class="section">
                <h2>{{ __('hosted.product.about') }}</h2>
                <div class="prose">{{ \App\Domain\Hosted\Support\BlockText::paragraphs($product->description) }}</div>
            </section>
        @endif

        @php
            $lists = [
                'includes' => $product->includes,
                'excludes' => $product->excludes,
                'what_to_bring' => $product->what_to_bring,
            ];
        @endphp

        @if (collect($lists)->filter(fn ($items) => is_array($items) && $items !== [])->isNotEmpty())
            <section class="section lists">
                @foreach ($lists as $key => $items)
                    @if (is_array($items) && $items !== [])
                        <div>
                            <h2>{{ __('hosted.product.' . $key) }}</h2>
                            <ul class="ticks {{ $key }}">
                                @foreach ($items as $item)
                                    @if (is_string($item) && trim($item) !== '')
                                        <li>{{ $item }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            </section>
        @endif

        @if ($stops !== [])
            <section class="section">
                <h2>{{ __('hosted.product.itinerary') }}</h2>
                <ol class="itinerary">
                    @foreach ($stops as $stop)
                        @if (is_array($stop) && is_string($stop['name'] ?? null))
                            <li>
                                <h3>{{ $stop['name'] }}</h3>
                                @if (is_string($stop['description'] ?? null) && $stop['description'] !== '')
                                    <p>{{ $stop['description'] }}</p>
                                @endif
                                @if (isset($stop['duration_minutes']) && is_numeric($stop['duration_minutes']))
                                    <p class="muted">{{ __('hosted.index.duration', ['minutes' => (int) $stop['duration_minutes']]) }}</p>
                                @endif
                            </li>
                        @endif
                    @endforeach
                </ol>
            </section>
        @endif

        @if ($departures->isNotEmpty())
            {{--
                The real departures, server-rendered. The widget will let a guest
                pick one; this list is what a visitor with no JavaScript — and the
                `Event` graph below — see, and the two cannot disagree because
                they are built from the same collection.
            --}}
            <section class="section">
                <h2>{{ __('hosted.product.departures') }}</h2>
                <ul class="departures">
                    @foreach ($departures as $departure)
                        @php $local = $departure->starts_at_utc->copy()->setTimezone($timezone); @endphp
                        <li>
                            <span class="when">{{ $local->format('d/m/Y') }} · {{ $local->format('H:i') }}</span>
                            @if ($departure->seatsAvailable() <= 0)
                                <span class="sold-out">{{ __('hosted.product.sold_out') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($port)
            <section class="section">
                <h2>{{ __('hosted.product.meeting_point') }}</h2>
                <p>
                    <strong>{{ $port->name }}</strong>
                    @if ($port->address)
                        <br>{{ $port->address }}
                    @endif
                    @if ($port->mapsUrl())
                        <br><a href="{{ $port->mapsUrl() }}" rel="noopener noreferrer">{{ __('hosted.blocks.contact.open_in_maps') }}</a>
                    @endif
                </p>
                @if ($port->instructions)
                    <p class="muted">{{ $port->instructions }}</p>
                @endif
                <p class="muted">{{ __('hosted.product.check_in', ['minutes' => $product->check_in_offset_minutes]) }}</p>
            </section>
        @endif

        @if ($product->vessel)
            <section class="section">
                <h2>{{ __('hosted.product.vessel') }}</h2>
                <p><strong>{{ $product->vessel->name }}</strong> — {{ $product->vessel->type->label() }}</p>
                @if ($product->vessel->description)
                    <div class="prose">{{ \App\Domain\Hosted\Support\BlockText::paragraphs($product->vessel->description) }}</div>
                @endif
                <p class="muted">{{ __('hosted.product.vessel_capacity', ['count' => $product->vessel->capacity_max]) }}</p>
            </section>
        @endif

        @if ($product->ageBands->isNotEmpty())
            <section class="section">
                <h2>{{ __('hosted.product.age_bands') }}</h2>
                <ul class="bands">
                    @foreach ($product->ageBands as $band)
                        <li>
                            <strong>{{ $band->label }}</strong>
                            <span class="muted">
                                {{ $band->max_age !== null
                                    ? __('hosted.product.age_range', ['from' => $band->min_age, 'to' => $band->max_age])
                                    : __('hosted.product.age_from', ['from' => $band->min_age]) }}
                                @unless ($band->counts_toward_capacity)
                                    · {{ __('hosted.product.no_seat') }}
                                @endunless
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($policy)
            <section class="section">
                <h2>{{ __('hosted.product.cancellation') }}</h2>
                <p><strong>{{ $policy->name }}</strong></p>
                @if ($policy->summary)
                    <p>{{ $policy->summary }}</p>
                @endif
                @if ($policy->free_cancellation_hours)
                    <p>{{ __('hosted.product.free_cancellation', ['hours' => $policy->free_cancellation_hours]) }}</p>
                @endif
                @if ($policy->tiers->isNotEmpty())
                    <ul class="tiers">
                        @foreach ($policy->tiers as $tier)
                            <li>{{ __('hosted.product.tier', ['days' => $tier->days_before, 'percent' => $tier->refund_percent]) }}</li>
                        @endforeach
                    </ul>
                @endif
                <p class="muted">{{ __('hosted.product.weather_refund', ['percent' => $policy->weather_refund_percent]) }}</p>
            </section>
        @endif

        {{-- This trip's questions plus the operator's, its own first (#103). --}}
            @include('hosted.partials.faq', [
                'entries' => $faqs,
                'heading' => __('hosted.blocks.faq.heading'),
                'anchor' => 'faq',
            ])
            </div>
        </div>

        @if ($schema)
            {{-- HOS-2's `Product` and `Event` graph. Nonced for the same reason
                 the FAQ block is: `script-src` applies whatever the type, and an
                 un-nonced block is dropped unread. --}}
            <script type="application/ld+json" nonce="{{ $nonce }}">{{ $schema }}</script>
        @endif
    </article>
@endsection
