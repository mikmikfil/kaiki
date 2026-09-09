{{--
    How to reach the operator, from the operator's own record.

    Nothing here is retyped into the block. The phone number, the email and the
    address come from `tenants`, and the meeting point is a `ports` row — so
    this block cannot disagree with the footer, the product pages or the
    confirmation email about where the boat leaves from.
--}}
<section class="block contact @if ($block->image_path) has-image @endif" @if ($anchor) id="{{ $anchor }}" @endif>
    @if ($block->image_path)
        {{-- A banner behind the details, not a picture beside them. Decorative:
             everything it could say is said in words below it, and a screen
             reader reading "boat at a quay" before a telephone number is noise
             in front of the one thing somebody came here for. --}}
        <img class="contact-image"
             src="{{ \App\Domain\Hosted\Support\HostedAsset::url($block->image_path) }}"
             alt=""
             loading="lazy">
    @endif

    {{-- Two columns on a wide screen: what to say on the left, how to reach
         them on the right. One wrapper rather than two, so the grid has
         something to be a grid of. --}}
    <div class="contact-inner">
        <div>
            @if ($block->heading)
                {{-- The operator's name in small letterspaced capitals above
                the heading. It is a label, not a heading: this block is
                the one place on the page where a visitor who arrived from
                a search engine finds out whose boats these are, and the
                `<h2>` beneath it is about the story rather than the
                company. Hidden from the accessibility tree because the
                name is already the first link in the page's header, and
                hearing it twice teaches nothing. --}}
                <p class="eyebrow" aria-hidden="true">{{ $tenant->name }}</p>
                <h2>{{ $block->heading }}</h2>
            @endif

            @if ($block->body)
                <div class="prose">{{ $block->prose() }}</div>
            @endif

            {{-- No button here any more.

                 There was a «Γράψτε μας» and a «Πάρτε τηλέφωνο» at the top of
                 this panel, duplicating the email address and the phone number
                 printed a few centimetres to the right of them as real,
                 tappable links. On a phone both went to the same place; the
                 buttons only made the panel look like a form. The details are
                 the action.

                 The trip page keeps its own call-to-action, where there is a
                 booking to be asked about and the operator's details are not
                 already on screen. --}}
            @php
                /** @var array<string, string> $social */
                $social = collect((array) data_get($tenant->settings, 'social', []))
                    ->filter(static fn (mixed $url, mixed $key): bool => is_string($key) && is_string($url) && str_starts_with($url, 'https://'))
                    ->all();
            @endphp

            @if ($social !== [])
                {{-- Operator configuration out of `tenants.settings`, not
                     columns: the list of networks is open-ended and a column per
                     network is a migration every time somebody joins a new one.

                     `https://` only, checked here rather than trusted: this is
                     a value an operator types, it becomes a link on their public
                     page, and `javascript:` in an `href` is the oldest trick
                     there is. --}}
                <ul class="social">
                    @foreach ($social as $network => $url)
                        <li>
                            {{-- The mark alone, with the network's name as the
                                 accessible name rather than as visible text: a
                                 row of three labelled pills was wider than the
                                 heading above it and read as navigation. The
                                 `aria-label` is not optional decoration — an
                                 icon-only link with no accessible name is a
                                 link announced as its own URL. --}}
                            <a href="{{ $url }}"
                               rel="noopener noreferrer me"
                               target="_blank"
                               aria-label="{{ __('hosted.blocks.contact.social.' . $network) }}"
                               title="{{ __('hosted.blocks.contact.social.' . $network) }}">
                                @include('hosted.partials.icon', ['name' => 'social-' . $network])
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- An icon on each row rather than a letterspaced label above it.

             Four rows of «ΤΗΛΈΦΩΝΟ», «EMAIL», «ΔΙΕΎΘΥΝΣΗ», «ΣΗΜΕΊΟ ΣΥΝΆΝΤΗΣΗΣ»
             is eight lines to say four things, and the labels were the smallest
             and palest text in the panel while being the least useful part of
             it — nobody needs telling that a phone number is a phone number.
             The icon carries the same meaning in a fifth of the space, and the
             label survives for screen readers, which is where it was doing real
             work. The trip cards already read this way. --}}
        <ul class="contact-list">
            @if ($block->setting('show_phone') && $tenant->phone)
                <li>
                @include('hosted.partials.icon', ['name' => 'phone'])
                <span class="sr-only">{{ __('hosted.blocks.contact.phone') }}</span>
                <a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a>
                </li>
            @endif

            @if ($block->setting('show_email') && $tenant->email)
                <li>
                @include('hosted.partials.icon', ['name' => 'mail'])
                <span class="sr-only">{{ __('hosted.blocks.contact.email') }}</span>
                <a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a>
                </li>
            @endif

            @if ($block->setting('show_address') && $tenant->address_line1)
                <li>
                @include('hosted.partials.icon', ['name' => 'pin'])
                <span class="sr-only">{{ __('hosted.blocks.contact.address') }}</span>
                {{-- Street, then a comma, then postcode and town. Joined with
                     spaces it read «Ακτή Θεμιστοκλέους 42 18538 Πειραιάς» —
                     two numbers with nothing between them, which is where a
                     reader stops. Each part is optional, so the comma only
                     appears when there is something on both sides of it.

                     And it is a link, like the phone number and the email
                     beside it: an address a visitor has to select and copy is
                     the one row in this panel that does not act. It opens a map
                     search rather than a pinned coordinate, because a postal
                     address is what the operator typed and geocoding it here
                     would be guessing at a pin. --}}
                @php
                    $postal = collect([$tenant->address_line1, trim($tenant->postcode . ' ' . $tenant->city)])
                        ->filter(static fn (?string $part): bool => $part !== null && trim($part) !== '')
                        ->implode(', ');
                @endphp
                <a href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($postal) }}"
                   rel="noopener noreferrer"
                   target="_blank">{{ $postal }}</a>
                </li>
            @endif

            @if ($meetingPoint)
                <li>
                @include('hosted.partials.icon', ['name' => 'boat'])
                <span class="sr-only">{{ __('hosted.blocks.contact.meeting_point') }}</span>
                <span>
                    {{ $meetingPoint->name }}
                    @if ($meetingPoint->mapsUrl())
                        — <a href="{{ $meetingPoint->mapsUrl() }}" rel="noopener noreferrer">{{ __('hosted.blocks.contact.open_in_maps') }}</a>
                    @endif
                </span>
                @if ($meetingPoint->instructions)
                    <span class="instructions">{{ $meetingPoint->instructions }}</span>
                @endif
                </li>
            @endif
        </ul>
    </div>
</section>
