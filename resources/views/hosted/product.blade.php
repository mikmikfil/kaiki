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

        {{-- The two columns begin at the **title**, not below it. A booking card
             that starts where the prose does sits a screen lower than the thing
             a visitor came to do — and on a wide monitor it is below the fold
             while the space beside the heading is empty. --}}
        <div class="product-body">
            <div class="product-main">

        <header class="product-head">
            <h1>{{ $product->title }}</h1>

            @if ($product->summary)
                <p class="standfirst">{{ $product->summary }}</p>
            @endif

            {{-- Five facts, each under its own icon. They used to be one line of
                 values with dots between them, which is a sentence a visitor has
                 to read in order to find the one thing they came for. Stacked,
                 the eye lands on the clock or the pin without reading anything.

                 Every icon is `aria-hidden` and sits above a value that already
                 says what it is, so nothing here is announced twice. --}}
            <ul class="facts">
                <li>
                    @include('hosted.partials.icon', ['name' => 'clock'])
                    <span>{{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}</span>
                </li>
                <li>
                    @include('hosted.partials.icon', ['name' => 'type'])
                    <span>{{ $product->category->label() }}</span>
                </li>
                @if ($port)
                    <li>
                        @include('hosted.partials.icon', ['name' => 'pin'])
                        <span>{{ $port->name }}</span>
                    </li>
                @endif
                @if ($product->vessel)
                    <li>
                        @include('hosted.partials.icon', ['name' => 'boat'])
                        <span>{{ $product->vessel->name }}</span>
                    </li>
                @endif
                @if (! $isQuote)
                    <li>
                        @include('hosted.partials.icon', ['name' => 'users'])
                        <span>{{ __('hosted.product.max_pax', ['count' => $product->max_pax]) }}</span>
                    </li>
                @endif
            </ul>
        </header>


        {{-- Not when it repeats the standfirst word for word.

             The two fields are for two different jobs — the summary is the one
             line that goes on a card, the description is the page — and an
             operator writing the trip up for the first time very reasonably
             types the same sentence into both. The demo operator did, and the
             page printed it twice, 470 pixels apart, under a heading promising
             more. Compared loosely, because «…μεγάλα.» and «…μεγάλα» are the
             same sentence to a reader. --}}
        @php
            $summarised = $product->summary !== null
                && preg_replace('/\s+|[.·!?]+$/u', '', mb_strtolower((string) $product->summary))
                    === preg_replace('/\s+|[.·!?]+$/u', '', mb_strtolower((string) $product->description));
        @endphp

        @if ($product->description && ! $summarised)
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

        {{-- Three sections of the same kind — when it sails, where you meet
             it, what you sail on — as tabs rather than as three headings a
             visitor scrolls past. Departures first and open, because it is the
             one a person came to look at.

             Radio inputs and labels, so this works with no JavaScript like
             everything else on these pages (HOS-4). What that costs, said
             plainly: a screen reader announces three radio buttons rather than
             an ARIA tablist, because a real tablist needs a script to manage
             focus and `aria-selected`. Arrow keys move between them, every
             panel is in the document, and a printed page shows the open one.

             A tab whose section has nothing in it is not drawn at all. --}}
        @php
            $tabs = array_filter([
                'departures' => $departures->isNotEmpty(),
                'meeting' => (bool) $port,
                'vessel' => (bool) $product->vessel,
            ]);
            $first = array_key_first($tabs);
        @endphp

        @if ($tabs !== [])
            <div class="tabs">
                @foreach ($tabs as $key => $on)
                    <input class="tab-radio" type="radio" name="trip-tabs" id="tab-{{ $key }}" @checked($key === $first)>
                @endforeach

                <div class="tablist">
                    @foreach ($tabs as $key => $on)
                        <label class="tab-label" for="tab-{{ $key }}">{{ __('hosted.product.tabs.' . $key) }}</label>
                    @endforeach
                </div>

                <div class="tabpanels">
                    @isset($tabs['departures'])
                        <section class="tabpanel tabpanel-departures" aria-label="{{ __('hosted.product.tabs.departures') }}">
                <ul class="departures">
                    @foreach ($departures as $departure)
                        @php
                            $local = $departure->starts_at_utc->copy()->setTimezone($timezone);

                            // Three states rather than two. Green and red are
                            // what a booking site usually shows; the amber in
                            // between is the one that changes a decision, and
                            // leaving it out means a date with two seats looks
                            // exactly like a date with forty.
                            $left = $departure->seatsAvailable();
                            $state = $left <= 0 ? 'out' : ($left <= 3 ? 'few' : 'open');
                        @endphp
                        <li class="is-{{ $state }}">
                            {{-- The dot is `aria-hidden` and every state carries
                                 words as well, because colour alone is not a
                                 label — and red-green is the one pair a
                                 colour-blind visitor is most likely to miss. --}}
                            <span class="dot" aria-hidden="true"></span>
                            <span class="when">{{ $local->format('d/m/Y') }} · {{ $local->format('H:i') }}</span>
                            @if ($state === 'out')
                                <span class="sold-out">{{ __('hosted.product.sold_out') }}</span>
                            @elseif ($state === 'few')
                                <span class="few-left">{{ trans_choice('hosted.product.seats_left', $left, ['count' => $left]) }}</span>
                            @else
                                <span class="sr-only">{{ __('hosted.product.available') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                        </section>
                    @endisset

                    @isset($tabs['meeting'])
                        <section class="tabpanel tabpanel-meeting" aria-label="{{ __('hosted.product.tabs.meeting') }}">
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

                {{--
                    The map itself, when the port has a location to draw.

                    **The link above stays.** On a phone the useful action is not
                    looking at a map — it is handing the address to the app that
                    does turn-by-turn, and an embedded frame cannot do that. The
                    two are different jobs and the section does both.

                    `loading="lazy"` because this sits below the fold on a page
                    whose whole design brief was that it renders without
                    JavaScript and loads fast; a third-party frame fetched
                    eagerly would be the heaviest thing on it.

                    `referrerpolicy="no-referrer"` so the map provider is not
                    told which operator's page a visitor was reading. It does
                    not make the frame private — a guest who scrolls this far is
                    seen by them either way, which is a consent question for the
                    operator rather than something this template can fix.

                    No attribution line of our own under it. The ODbL does
                    require credit, and the embed already carries it inside the
                    frame — "© OpenStreetMap contributors", with the licence
                    behind it, in every tile set they serve. A second copy
                    directly below is the same sentence twice, forty pixels
                    apart, which is the note the widget's credit already has.
                --}}
                @if ($port->mapsEmbedUrl())
                    <div class="map-embed">
                        <iframe
                            src="{{ $port->mapsEmbedUrl() }}"
                            title="{{ __('hosted.product.map_title', ['place' => $port->name]) }}"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            allowfullscreen
                        ></iframe>
                    </div>
                @endif
                        </section>
                    @endisset

                    @isset($tabs['vessel'])
                        <section class="tabpanel tabpanel-vessel" aria-label="{{ __('hosted.product.tabs.vessel') }}">
                            @include('hosted.partials.vessel-details', ['vessel' => $product->vessel])
                <div class="prose">{{ \App\Domain\Hosted\Support\BlockText::paragraphs($product->vessel->description) }}</div>
                        </section>
                    @endisset
                </div>
            </div>
        @endif

        {{-- This trip's questions plus the operator's, its own first (#103) —
             and the cancellation policy as one more of them.

             It used to be a section of its own, open, between the boat and the
             questions: a heading, a policy name, a sentence, a list of refund
             tiers and a line about the weather, all of it printed at a visitor
             who has not asked. "Can I cancel?" is a question, it was sitting
             directly above the place where questions are answered, and it is
             the only one of them the operator did not write. --}}
            @include('hosted.partials.faq', [
                'entries' => $faqs,
                'heading' => __('hosted.blocks.faq.heading'),
                'anchor' => 'faq',
                'policy' => $policy,
            ])

            {{-- The rest of the operator's photographs, last on the page.

                 This page has promised them in a comment since #104 and never
                 rendered any: only `$images[0]` was ever used, as the lead at
                 the top. Everything after it belongs here, at the bottom —
                 somebody still reading at this point has already decided the
                 trip interests them, and photographs are what they linger on
                 rather than what they need in order to choose.

                 **Masonry, in CSS columns.** No JavaScript (HOS-4) and no fixed
                 ratio: each photograph keeps its own proportions, which is the
                 whole reason to lay them out this way rather than in a grid of
                 identical crops — a wall of 3:2 boxes is a contact sheet. The
                 seeder stores these at their natural size for the same reason;
                 the card and the lead crop them with `object-fit` where they
                 need a fixed box. --}}
            @if (count($images) > 1)
                @php $shots = array_values(array_slice($images, 1)); @endphp

                <section class="section" id="gallery">
                    <h2>{{ __('hosted.product.gallery') }}</h2>

                    <ul class="shots shots-masonry">
                        @foreach ($shots as $i => $shot)
                            <li>
                                <a class="shot-open" href="#shot-{{ $i }}" aria-label="{{ $shot['alt'] ?: __('hosted.product.gallery') }}">
                                    <img src="{{ \App\Domain\Hosted\Support\HostedAsset::relative($shot['url']) }}"
                                         alt="{{ $shot['alt'] ?? '' }}"
                                         loading="lazy">
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    {{-- The lightbox, in CSS alone.

                         `:target` is what opens it: each photograph links to the
                         id of its own full-size panel, and the panel is hidden
                         until the URL names it. No script, which is not a
                         preference here — HOS-4 promises these pages carry none
                         and `HostedPageLocaleTest` fails the build if one
                         appears.

                         What that costs, stated rather than hidden: this is not
                         a real modal. Focus is not trapped inside it and Escape
                         does not close it, because both need a script. Back
                         does close it, the close link is the first thing in the
                         panel, and every photograph is still reachable and
                         readable with the lightbox never opened at all. --}}
                    @foreach ($shots as $i => $shot)
                        <div class="lightbox" id="shot-{{ $i }}" role="dialog" aria-modal="true"
                             aria-label="{{ $shot['alt'] ?: __('hosted.product.gallery') }}">
                            <a class="lightbox-scrim" href="#gallery" aria-label="{{ __('hosted.product.close') }}"></a>

                            <figure>
                                <img src="{{ \App\Domain\Hosted\Support\HostedAsset::relative($shot['url']) }}"
                                     alt="{{ $shot['alt'] ?? '' }}"
                                     loading="lazy">
                            </figure>

                            <a class="lightbox-close" href="#gallery">{{ __('hosted.product.close') }}</a>

                            <nav class="lightbox-step" aria-label="{{ __('hosted.product.gallery') }}">
                                @if ($i > 0)
                                    <a class="prev" href="#shot-{{ $i - 1 }}" rel="prev">&#8249;</a>
                                @endif
                                @if ($i < count($shots) - 1)
                                    <a class="next" href="#shot-{{ $i + 1 }}" rel="next">&#8250;</a>
                                @endif
                            </nav>
                        </div>
                    @endforeach
                </section>
            @endif
            </div>

            {{-- The booking card is **sticky**, so it is still on screen when a
                 visitor has read to the bottom of the itinerary. A booking form
                 below three screens of prose is one nobody scrolls back up
                 to. --}}
            <aside class="product-aside">
                <section class="booking" id="book" aria-labelledby="booking-heading">
            <h2 id="booking-heading" class="sr-only">{{ __('hosted.product.booking.heading') }}</h2>

            {{-- The price first, because it is the fact a visitor is deciding
                 on. It used to sit under four lines of what they are already
                 looking at. --}}
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

            <dl class="four-lines">
                <div><dt>{{ __('hosted.product.booking.trip') }}</dt><dd>{{ $product->title }}</dd></div>
                <div><dt>{{ __('hosted.product.booking.duration') }}</dt><dd>{{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}</dd></div>
                <div><dt>{{ __('hosted.product.booking.port') }}</dt><dd>{{ $port?->name ?? '—' }}</dd></div>
                <div><dt>{{ __('hosted.product.booking.vessel') }}</dt><dd>{{ $product->vessel?->name ?? '—' }}</dd></div>
            </dl>

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

                    {{--
                        The widget itself.

                        Until this tag existed the mount point above was the
                        whole booking experience on a hosted page: the markup
                        described a widget that was never loaded, so every guest
                        got the "email us" fallback and the pages could not sell
                        anything. #111's end-to-end run did not catch it because
                        it drives a fixture host page rather than this one.

                        `data-key` is minted per response and is not a stored
                        credential — see HostedEmbedToken for why a hosted page
                        cannot simply carry a publishable key.

                        The tag sits **inside** the mount and last, with no
                        `data-target`: the widget inserts itself where the
                        script is, so the host element lands in this container
                        rather than somewhere a selector happened to point. The
                        fallback above it is hidden by CSS once the host
                        appears, which needs no second script and no exception
                        to HOS-8's policy.
                    --}}
                    <script src="{{ \App\Domain\Hosted\Support\HostedWidget::bundleUrl() }}"
                            data-key="{{ $embedToken }}"
                            data-mount="{{ $isQuote ? 'enquiry' : 'booking' }}"
                            data-product="{{ $product->uuid }}"
                            data-locale="{{ $locale }}"
                            {{-- «Με την τεχνολογία του Kaiki» is already in this
                                 page's footer. The line inside the widget is
                                 there to say whose booking form this is on
                                 somebody else's website; on ours it is the same
                                 sentence twice, forty pixels apart. --}}
                            data-credit="false"
                            defer></script>
                </div>
            @endif

            {{-- What a visitor asks with their hand over the button: how many of
                 us fit, which boat is it, what does a child pay, what is
                 included, and can I get out of it.

                 **Folded, and under the button rather than above it.** All five
                 answers used to be open, between the price and the form — so the
                 card was eight hundred pixels tall, the thing a visitor came to
                 do was at the bottom of it, and on a laptop the button was below
                 the fold on a card whose whole purpose is to keep it in view.
                 Folded, the card is short enough to be seen whole, and the guest
                 with a five-year-old still gets their answer in one click
                 without leaving the button.

                 A `<details>` rather than a scripted panel: it opens with no
                 JavaScript, it is a disclosure to a screen reader without an
                 attribute anybody has to remember, and the browser's own
                 find-in-page opens it (`hidden="until-found"` is the default for
                 details content in Chromium). HOS-8 removed `unsafe-inline`
                 from the policy and this needs no exception to it. --}}
            <div class="booking-more">
                @php
                    $includes = is_array($product->includes)
                        ? array_values(array_filter($product->includes, fn ($item) => is_string($item) && trim($item) !== ''))
                        : [];
                @endphp

                @if (! $isQuote || $product->vessel || $includes !== [])
                    <details class="fold">
                        <summary>{{ __('hosted.product.booking.details') }}</summary>
                        <div class="fold-body">
                            @if (! $isQuote)
                                <div class="extra">
                                    @include('hosted.partials.icon', ['name' => 'users'])
                                    <div>
                                        <span class="label">{{ __('hosted.product.booking.capacity') }}</span>
                                        <span>{{ __('hosted.product.max_pax', ['count' => $product->max_pax]) }}</span>
                                    </div>
                                </div>
                            @endif

                            @if ($product->vessel)
                                <div class="extra">
                                    @include('hosted.partials.icon', ['name' => 'boat'])
                                    <div>
                                        <span class="label">{{ __('hosted.product.vessel') }}</span>
                                        <span>{{ $product->vessel->type->label() }}</span>
                                        <span class="muted">{{ __('hosted.product.vessel_capacity', ['count' => $product->vessel->capacity_max]) }}</span>
                                    </div>
                                </div>
                            @endif

                            @if ($includes !== [])
                                <div class="extra">
                                    @include('hosted.partials.icon', ['name' => 'check'])
                                    <div>
                                        <span class="label">{{ __('hosted.product.includes') }}</span>
                                        <ul>
                                            @foreach (array_slice($includes, 0, 3) as $item)
                                                <li>{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                        @if (count($includes) > 3)
                                            <span class="more">{{ __('hosted.product.booking.and_more', ['count' => count($includes) - 3]) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </details>
                @endif

                {{-- Who pays what. A guest with a five-year-old wants this before
                     they pick a date, not after — and a band that takes no seat
                     is the one they most need told about. --}}
                @if ($product->ageBands->isNotEmpty())
                    <details class="fold">
                        <summary>{{ __('hosted.product.age_bands') }}</summary>
                        <div class="fold-body">
                            <div class="extra">
                                @include('hosted.partials.icon', ['name' => 'tickets'])
                                <div>
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
                                </div>
                            </div>
                        </div>
                    </details>
                @endif

            </div>
                </section>
            </aside>
        </div>

        @if ($schema)
            {{-- HOS-2's `Product` and `Event` graph. Nonced for the same reason
                 the FAQ block is: `script-src` applies whatever the type, and an
                 un-nonced block is dropped unread. --}}
            <script type="application/ld+json" nonce="{{ $nonce }}">{{ $schema }}</script>
        @endif
    </article>
@endsection
