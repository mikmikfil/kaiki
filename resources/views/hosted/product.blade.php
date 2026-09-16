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

    {{-- Only when this page is actually going to run it — a read-only tenant
         renders a sentence instead of a widget, and preloading a bundle nothing
         executes is a wasted megabyte off somebody's data plan. --}}
    @if (! $readOnly)
        @push('head')
            <link rel="preload" as="script" href="{{ \App\Domain\Hosted\Support\HostedWidget::bundleUrl() }}">
        @endpush
    @endif

    <article class="product">

        {{--
            The hero: a way back, the title, the standfirst, the facts as chips,
            and the photographs as one mosaic directly under them — the same
            structure the WordPress plugin's single-trip template uses, so a trip
            reads the same on the operator's own site and on this page
            (Mike, 2026-09-16).

            **Full width, above the two columns.** The booking card used to start
            beside the title; it now starts beside the first section, under the
            photographs. Its behaviour does not change — sticky on a desktop, the
            bottom sheet on a phone — only where its column begins.

            **The mosaic replaces the gallery that sat at the foot of the page.**
            No photograph is lost: every one is still in the lightbox below, and
            when there are more than the mosaic shows, a button over its last
            tile opens them.
        --}}
        @php
            $shots = array_values($images);
            $shotCount = count($shots);
            // Four photographs show three: 2fr + 1fr + 1fr with four would leave
            // an empty cell in the corner of the grid.
            $tiles = match (true) {
                $shotCount >= 5 => 5,
                $shotCount >= 3 => 3,
                default => $shotCount,
            };
            $startTime = $product->default_start_time ? substr((string) $product->default_start_time, 0, 5) : null;
        @endphp

        <header class="trip-hero">
            <nav class="crumbs" aria-label="{{ __('hosted.product.breadcrumb') }}">
                <a href="{{ route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]) }}"><span aria-hidden="true">←</span> {{ __('hosted.product.all_trips') }}</a>
            </nav>

            <h1>{{ $product->title }}</h1>

            @if ($product->summary)
                <p class="standfirst">{{ $product->summary }}</p>
            @endif

            {{-- The facts, as chips. Every icon is `aria-hidden` and sits beside
                 a value that already says what it is, so nothing here is
                 announced twice. The guest count stays off a quote trip, as it
                 always has: a charter priced by asking is sized by asking. --}}
            <ul class="facts">
                <li>
                    @include('hosted.partials.icon', ['name' => 'clock'])
                    <span>{{ __('hosted.index.duration', ['minutes' => $product->duration_minutes]) }}</span>
                </li>
                @if ($startTime)
                    <li>
                        @include('hosted.partials.icon', ['name' => 'sun'])
                        <span>{{ __('hosted.product.departs', ['time' => $startTime]) }}</span>
                    </li>
                @endif
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

            @if ($shotCount > 0)
                <div class="mosaic mosaic-{{ $tiles }}" id="gallery">
                    <ul>
                        @foreach (array_slice($shots, 0, $tiles) as $i => $shot)
                            <li>
                                <a class="shot-open" href="#shot-{{ $i }}" aria-label="{{ $shot['alt'] ?: ($i === 0 ? $product->title : __('hosted.product.gallery')) }}">
                                    <img src="{{ \App\Domain\Hosted\Support\HostedAsset::relative($shot['url']) }}"
                                         alt="{{ $shot['alt'] ?? ($i === 0 ? $product->title : '') }}"
                                         @if ($i === 0) fetchpriority="high" @endif>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    {{-- More photographs than tiles. On a phone only three tiles
                         show, so the button appears there from four photographs;
                         on a desktop, from six. --}}
                    @if ($shotCount > 3)
                        <a class="mosaic-all{{ $shotCount <= 5 ? ' mosaic-all-narrow' : '' }}" href="#shot-0">
                            {{ __('hosted.product.all_photos', ['count' => $shotCount]) }}
                        </a>
                    @endif
                </div>
            @endif
        </header>

        <div class="product-body">
            <div class="product-main">


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

        {{--
            The trip page's optional content (2026-09-16): «Τι θα ζήσετε», the
            programme, what is and is not included, and what to bring.

            **Every section only when it has something in it.** A list is its
            non-blank lines in this locale; a list with none — null, an empty
            array, or lines that are only spaces — renders nothing at all, the
            heading included, because a heading over nothing reads as a page
            that failed to load. The operator fills in what they want and the
            rest of the page closes up around it.

            The marks beside each line (a star, a tick, a cross, a bag) are
            decoration, `aria-hidden`, and the heading above the list already
            says which kind of list it is.
        --}}
        @php
            $lines = static fn (mixed $items): array => is_array($items)
                ? array_values(array_filter($items, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''))
                : [];

            $highlights = $lines($product->highlights);
            $includes = $lines($product->includes);
            $excludes = $lines($product->excludes);
            $bring = $lines($product->what_to_bring);

            $timeline = array_values(array_filter(
                $stops,
                static fn (mixed $stop): bool => is_array($stop) && is_string($stop['name'] ?? null) && trim($stop['name']) !== '',
            ));
        @endphp

        @if ($highlights !== [])
            <section class="section trip-content">
                <h2>{{ __('hosted.product.highlights') }}</h2>
                <ul class="trip-list trip-list-star">
                    @foreach ($highlights as $line)
                        <li><span class="trip-mark" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg></span>{{ $line }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($timeline !== [])
            <section class="section trip-content">
                <h2>{{ __('hosted.product.itinerary') }}</h2>
                <ol class="itinerary trip-timeline">
                    @foreach ($timeline as $stop)
                        <li>
                            @if (is_string($stop['time'] ?? null) && $stop['time'] !== '')
                                <span class="trip-time">{{ $stop['time'] }}</span>
                            @endif
                            <h3>{{ $stop['name'] }}</h3>
                            @if (is_string($stop['description'] ?? null) && $stop['description'] !== '')
                                <p>{{ $stop['description'] }}</p>
                            @endif
                            @if (isset($stop['duration_minutes']) && is_numeric($stop['duration_minutes']) && (int) $stop['duration_minutes'] > 0)
                                <p class="muted">{{ __('hosted.index.duration', ['minutes' => (int) $stop['duration_minutes']]) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        @if ($includes !== [] || $excludes !== [])
            <section class="section trip-content lists">
                @if ($includes !== [])
                    <div>
                        <h2>{{ __('hosted.product.includes') }}</h2>
                        <ul class="trip-list trip-list-yes includes">
                            @foreach ($includes as $line)
                                <li><span class="trip-mark" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($excludes !== [])
                    <div>
                        <h2>{{ __('hosted.product.excludes') }}</h2>
                        <ul class="trip-list trip-list-no excludes">
                            @foreach ($excludes as $line)
                                <li><span class="trip-mark" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" focusable="false"><path d="M7 7l10 10M17 7 7 17"/></svg></span>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        @if ($bring !== [])
            <section class="section trip-content">
                <h2>{{ __('hosted.product.what_to_bring') }}</h2>
                <ul class="trip-list trip-list-bag what_to_bring">
                    @foreach ($bring as $line)
                        <li><span class="trip-mark" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg></span>{{ $line }}</li>
                    @endforeach
                </ul>
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
                {{-- The address and the way to it on one row.

                     A button rather than a third line of the address, because
                     it is the one thing on this tab somebody actually does —
                     they are standing somewhere trying to reach a quay — and a
                     link wrapped under a postcode is not where a thumb goes.
                     Beside the address rather than under it, so the row is one
                     answer to one question instead of a stack. --}}
                <p>
                    <strong>{{ $port->name }}</strong>
                    @if ($port->address)
                        <br>{{ $port->address }}
                    @endif
                </p>

                @if ($port->mapsUrl())
                    <p class="map-open">
                        <a class="button ghost small" href="{{ $port->mapsUrl() }}" rel="noopener noreferrer" target="_blank">
                            @include('hosted.partials.icon', ['name' => 'pin-solid'])
                            {{ __('hosted.blocks.contact.open_in_maps') }}
                        </a>
                    </p>
                @endif

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

            {{-- The lightbox for the mosaic at the top of the page. Every
                 photograph the operator uploaded is here, including the ones the
                 mosaic has no tile for, so replacing the old gallery at the foot
                 of the page lost none of them. --}}
            @if ($shotCount > 0)
                <div class="lightboxes">
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

                    {{-- Arrow keys and Escape, once a photograph is open.

                         A file rather than an inline script, and not for
                         tidiness: these pages send `script-src 'self'` with no
                         nonce and no `'unsafe-inline'`. The `$nonce` this
                         template already carries belongs to `style-src`, so an
                         inline script signed with it is dropped silently —
                         which is what happened to the first version of this.
                         From the app's own origin it needs no CSP change, and a
                         gallery does not justify widening a policy.

                         Everything still works without it: opening, closing and
                         stepping are links and `:target`, so HOS-4's promise
                         survives the file being blocked or never requested. --}}
                    <script src="{{ url('/hosted/gallery.js') }}" defer></script>
                </div>
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
                            {{-- The day pressed on the operator's own calendar
                                 (WGT-5 as amended 2026-09-11): the booking box
                                 opens on it, at the party step. --}}
                            @if ($initialDate ?? null)
                                data-date="{{ $initialDate }}"
                            @endif
                            {{-- «Powered by Kaiki» is already in this
                                 page's footer. The line inside the widget is
                                 there to say whose booking form this is on
                                 somebody else's website; on ours it is the same
                                 sentence twice, forty pixels apart. --}}
                            data-credit="false"
                            {{-- This page paints itself in the operator's
                                 colours already, and WGT-9's custom properties
                                 inherit through the shadow boundary — so the
                                 widget is standing in them before it draws.
                                 Asking `GET /branding` for the same values cost
                                 796 ms of the page's own booking bar waiting to
                                 be replaced by an identical one. --}}
                            data-branding="inherit"
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
                     is the one they most need told about. Not on a trip that
                     is priced by enquiry: there is no ticket to pay for yet,
                     and the operator's quote says what the party costs. --}}
                @if (! $isQuote && $product->ageBands->isNotEmpty())
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

                {{-- A second card under the booking one, for the visitor who is
                     nearly ready and has a question first.

                     It is separate rather than folded into the booking card on
                     purpose: everything in that card is part of choosing a date
                     and paying, and a «ring us» line inside it is an exit in the
                     middle of a checkout. Underneath, it catches the person who
                     was about to close the tab instead.

                     Only drawn when there is something to reach the operator
                     with — an operator with no phone and no email gets no card
                     rather than a heading over nothing. --}}
                @if ($tenant->email || $tenant->phone)
                    <section class="ask">
                        @include('hosted.partials.icon', ['name' => 'support'])
                        <h2>{{ __('hosted.product.ask.heading') }}</h2>
                        <p>{{ __('hosted.product.ask.body') }}</p>

                        <p class="ask-actions">
                            @if ($tenant->phone)
                                <a class="button" href="tel:{{ $tenant->phone }}">{{ __('hosted.product.ask.call') }}</a>
                            @endif

                            {{-- The contact page, carrying this trip's uuid, rather
                                 than a `mailto:`. Two reasons, and the second is
                                 the one that matters: a `mailto:` opens whatever
                                 mail client the visitor's phone thinks it has,
                                 which on a shared laptop is nothing at all — and
                                 the answer lands in an inbox instead of in
                                 `enquiries`, where it has a status and somebody
                                 whose job it is to close it (BKG-29). The uuid
                                 attaches the question to the trip it is about, so
                                 the operator is not reading «is this available?»
                                 with no idea what «this» is. --}}
                            <a class="button ghost"
                               href="{{ route('hosted.contact', ['operator' => $tenant->slug, 'lang' => $locale, 'product' => $product->uuid]) }}">{{ __('hosted.product.ask.write') }}</a>
                        </p>
                    </section>
                @endif
            </aside>
        </div>

        @if (! $readOnly)
            {{-- The bar that is there before the widget is (ADR-0033).

                 The sheet is the widget's, and the widget cannot exist until it
                 has loaded 80 KB and answered two API calls — branding and the
                 product. On a phone on a quay that is a visible wait during
                 which the trip page has, once again, no way to book anything.

                 So the page draws the bar itself, in the HTML, and it is a
                 plain link to `#book`. It costs no request, it is correct the
                 moment the first paint happens, and it keeps working with
                 JavaScript blocked — which is WGT-23's requirement and HOS-4's,
                 and the reason this could never have been the widget's job.

                 When the widget does arrive and decides it can pin itself, it
                 writes `data-kaiki-sheet` on its host and the rule in the
                 stylesheet takes this one off the screen. Until then, and on
                 any page where a transformed ancestor traps `position: fixed`,
                 this is what a visitor gets. --}}
            {{-- Built to the widget's peek bar, line for line.

                 It was one line — a price and a button — and the widget's is
                 two, a price over the next thing to do. So the handover was
                 visible: the bar appeared, sat there, then grew a second line
                 when the bundle finished. It read as the page loading twice.

                 Same two lines, same words, same height, so the moment the
                 widget takes over nothing moves. What changes is that the
                 second line stops being a fixed sentence and starts tracking
                 what the guest has chosen.

                 «Same words» is the part that took a second pass. The bar was
                 built from `price.from` and `booking.heading` — the aside's
                 keys — and the widget's peek says something else: «από» against
                 «Από», «Συνέχεια» against «Κράτηση». Both bars were the same
                 shape in the same place, so what a visitor saw at ~490 ms was
                 the words changing under a bar that had not moved, which reads
                 as the page correcting itself. `hosted.product.bar.*` exists so
                 these three strings can track the widget's peek without
                 dragging the aside's copy along: `booking.peek.from`,
                 `booking.next` and `enquiry.submit` in the widget's locales are
                 the other half of each pair, and the two move together. --}}
            <p class="book-bar">
                {{-- The widget's tab, in the widget's place. Here it is
                     decoration and nothing else — this bar is an anchor to
                     `#book` and has nothing to expand — but it has to be drawn
                     all the same, or it appears out of nowhere at the handover
                     and the bar that was meant to be the same shape changes
                     shape after half a second. --}}
                <span class="book-bar-tab" aria-hidden="true">
                    <svg viewBox="0 0 24 14" width="100%" height="100%" focusable="false">
                        <path d="M2.6 11.1 12 2.9l9.4 8.2" fill="none" stroke="currentColor"
                              stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>

                <span class="book-bar-text">
                    <span class="book-bar-price">
                        @if (! $isQuote && $fromPriceFormatted)
                            <span class="from">{{ __('hosted.product.bar.from') }}</span>
                            <strong>{{ $fromPriceFormatted }}</strong>
                        @else
                            <strong>{{ __('hosted.product.price.on_request') }}</strong>
                        @endif
                    </span>

                    <span class="book-bar-summary">
                        {{ $isQuote ? __('hosted.product.booking.enquire_summary') : __('hosted.product.booking.pick_date') }}
                    </span>
                </span>

                <a class="button button-small" href="#book">
                    {{ $isQuote ? __('hosted.product.bar.enquire_action') : __('hosted.product.bar.action') }}
                </a>
            </p>
        @endif

        @if ($schema)
            {{-- HOS-2's `Product` and `Event` graph. Nonced for the same reason
                 the FAQ block is: `script-src` applies whatever the type, and an
                 un-nonced block is dropped unread. --}}
            <script type="application/ld+json" nonce="{{ $nonce }}">{{ $schema }}</script>
        @endif
    </article>
@endsection
