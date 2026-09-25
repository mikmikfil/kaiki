{{--
    The e-ticket (spec BKG-13.1, BKG-20, SEC-14, I18N-1, I18N-2).

    ## One page per guest, and the QR is per guest

    BKG-21 transitions a booking on the *first* check-in and BKG-23 marks a
    no-show *per person*, so a family of four needs four scannable codes. One
    code for the booking would make both requirements impossible and would put
    the whole party aboard the moment one of them arrived.

    ## No images, and no external anything

    SEC-14 runs this through a Chromium with no local file access, so there is
    nothing here to fetch: the QR is an inline `<svg>`, the operator's identity
    is carried by their name and accent colour, and the type is the system
    stack. A ticket that needed a font from Google would print blank on a boat
    with no signal — which is where tickets are actually opened.

    ## No `text-transform: uppercase`

    I18N-2, and this is the surface where it bites hardest: the guest's own name
    is printed here, and uppercasing Greek drops the accents. ΆΝΝΑ becomes
    ΑΝΝΑ, and the passenger holding it has to explain that it is her.

    ## Everything a person on a quay needs, and nothing else

    Since 2026-09-23 that includes the rest of the trip — when the boat is back,
    how to find the meeting point, what to bring, how to reach the operator —
    because the PDF now travels attached to the email and is what opens with no
    signal. Still no price, no payment status, no policy text. A ticket is shown to a crew
    member in the sun; the manage page (`/b/{manage_token}`) is where the money
    lives, and printing a total on something photographed and left on a seat
    serves nobody.
--}}
@php
    /** @var \App\Models\Booking $booking */
    use App\Domain\Booking\Support\CheckInWindow;
    use App\Domain\Booking\Support\TicketQr;
    use App\Support\Format\DateTimeFormatter;

    $locale = $booking->locale;
    $accent = $brand['colors']['primary'] ?? '#0B2740';
    $operator = $brand['tenant']['name'] ?? config('app.name');
    // Null, not `config('app.timezone')`: `DateTimeFormatter` resolves the
    // **tenant's** zone when it is handed none, and the application default is
    // UTC. Falling back to it would print a Greek guest a departure time three
    // hours early — on the one document they read standing at a quay.
    $timezone = $brand['tenant']['timezone'] ?? null;

    $window = CheckInWindow::forBooking($booking, $booking->product);
    $meetingPoint = $booking->product?->meetingPoint;

    $guests = $booking->guests->sortBy('position')->values();

    // Off for an operator who boards from the passenger list (BKG-20, amended
    // 2026-09-11). Absent means on, which is every ticket before the switch.
    $showQr = $qr ?? true;

    // The rest of the trip (2026-09-23): the same answers the confirmation
    // email gives, from the same place, so the two cannot disagree about when
    // the boat is back or what to bring. The PDF is what opens on a quay with
    // no signal, so it has to be enough on its own.
    $trip = \App\Mail\Support\BookingMailDetails::for($booking, \App\Enums\NotificationTemplate::BookingConfirmed, $locale);
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('ticket.title', [], $locale) }} — {{ $booking->reference }}</title>
    <style>
        /*
         * Margin zero on the page and the inset on the ticket itself (product
         * owner, 2026-09-22: *«βάλε λίγο κενό δεξιά αριστερά, είναι τσίτα»*).
         *
         * The page's own margin was the renderer's default, which a browser
         * ignores — so the ticket looked flush to the paper everywhere it was
         * read on screen, and the two never agreed. The invoice has always done
         * it this way; now both do.
         */
        @page { size: A4; margin: 0; }

        body {
            margin: 0;
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #16202a;
            -webkit-print-color-adjust: exact;
        }

        .ticket { padding: 14mm 15mm 10mm; }
        /* One guest per page, and no trailing blank page after the last. */
        .ticket + .ticket { page-break-before: always; }

        .band {
            border-top: 4px solid {{ $accent }};
            padding-top: 5mm;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }

        .operator { font-size: 15pt; font-weight: 700; letter-spacing: -.01em; }
        .reference { font-family: "SFMono-Regular", Menlo, Consolas, monospace; font-size: 12pt; }

        h1 { font-size: 18pt; margin: 6mm 0 1mm; letter-spacing: -.015em; }
        .sub { color: #5a6b7a; margin: 0 0 6mm; }

        .grid { display: flex; gap: 8mm; align-items: flex-start; }
        .facts { flex: 1 1 auto; }
        .qr { flex: 0 0 42mm; text-align: center; }
        .qr svg { width: 38mm; height: 38mm; }
        .qr .code {
            font-family: "SFMono-Regular", Menlo, Consolas, monospace;
            font-size: 8pt; color: #5a6b7a; word-break: break-all; margin-top: 2mm;
        }

        dl { margin: 0; display: grid; grid-template-columns: 34mm 1fr; row-gap: 2.5mm; column-gap: 4mm; }
        dt { color: #5a6b7a; font-size: 9.5pt; }
        dd { margin: 0; font-weight: 600; }

        .note {
            margin-top: 6mm; padding: 3mm 4mm;
            background: #f2f5f7; border-left: 3px solid {{ $accent }};
            font-size: 10pt; color: #35485a;
        }

        /* The rest of the trip: three short blocks side by side, calm and
           smaller than the facts, which are what a crew member reads. */
        .more { display: flex; gap: 8mm; margin-top: 8mm; }
        .more .block { flex: 1 1 0; min-width: 0; }
        .more h2 { font-size: 10.5pt; margin: 0 0 1.5mm; color: #16202a; }
        .more p, .more li { font-size: 9.5pt; color: #35485a; margin: 0 0 1mm; overflow-wrap: anywhere; }
        .more ul { margin: 0; padding-left: 4mm; }
        .more .strong { font-weight: 600; color: #16202a; }
        .more a { color: {{ $accent }}; text-decoration: none; font-weight: 600; }

        footer {
            margin-top: 6mm; padding-top: 3mm;
            border-top: 1px solid #d8e0e6;
            font-size: 8.5pt; color: #78889a;
            display: flex; justify-content: space-between;
        }
    </style>
</head>
<body>

@foreach ($guests as $guest)
    <section class="ticket">

        <div class="band">
            <span class="operator">{{ $operator }}</span>
            <span class="reference">{{ $booking->reference }}</span>
        </div>

        <h1>{{ $booking->product?->title ?? __('ticket.trip', [], $locale) }}</h1>
        <p class="sub">
            {{ __('ticket.passenger_of', ['n' => $guest->position, 'total' => $guests->count()], $locale) }}
        </p>

        <div class="grid">
            <div class="facts">
                <dl>
                    <dt>{{ __('ticket.fields.guest', [], $locale) }}</dt>
                    {{-- Null until the guest-details form is submitted (§2.5). A
                         dash rather than a blank, so a crew member can tell the
                         difference between "not supplied" and a rendering fault. --}}
                    <dd>{{ $guest->full_name ?? '—' }}</dd>

                    <dt>{{ __('ticket.fields.date', [], $locale) }}</dt>
                    <dd>{{ DateTimeFormatter::longDate($booking->starts_at_utc, $locale, $timezone) }}</dd>

                    <dt>{{ __('ticket.fields.departs', [], $locale) }}</dt>
                    <dd>{{ DateTimeFormatter::time($booking->starts_at_utc, $locale, $timezone) }}</dd>

                    @if ($trip->return)
                        <dt>{{ __('ticket.fields.returns', [], $locale) }}</dt>
                        <dd>{{ $trip->return }}</dd>
                    @endif

                    {{-- BKG-22's own window, on the ticket. The single most
                         useful line on it: "be there at" is what a guest reads,
                         and computing it themselves from an offset they cannot
                         see is not something anybody should have to do. --}}
                    <dt>{{ __('ticket.fields.check_in', [], $locale) }}</dt>
                    <dd>{{ DateTimeFormatter::time($window->opensAt, $locale, $timezone) }}</dd>

                    @if ($meetingPoint !== null)
                        <dt>{{ __('ticket.fields.meeting_point', [], $locale) }}</dt>
                        <dd>{{ $meetingPoint->name }}</dd>
                    @endif

                    @if ($booking->vessel !== null)
                        <dt>{{ __('ticket.fields.vessel', [], $locale) }}</dt>
                        <dd>{{ $booking->vessel->name }}</dd>
                    @endif
                </dl>
            </div>

            @if ($showQr)
                <div class="qr">
                    {!! TicketQr::svgFor($guest) !!}
                    <div class="code">{{ $guest->ticket_code }}</div>
                </div>
            @endif
        </div>

        @if ($meetingPoint?->address)
            <div class="note">{{ $meetingPoint->address }}</div>
        @endif

        <div class="more">
            @if ($trip->meetingInstructions || $trip->mapUrl)
                <div class="block">
                    <h2>{{ __('ticket.more.how_to_find', [], $locale) }}</h2>
                    @if ($trip->meetingInstructions)
                        <p>{{ $trip->meetingInstructions }}</p>
                    @endif
                    @if ($trip->mapUrl)
                        <p><a href="{{ $trip->mapUrl }}">{{ __('ticket.more.open_map', [], $locale) }}</a></p>
                    @endif
                </div>
            @endif

            @if ($trip->bring !== [])
                <div class="block">
                    <h2>{{ __('ticket.more.bring', [], $locale) }}</h2>
                    <ul>
                        @foreach ($trip->bring as $thing)
                            <li>{{ $thing }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($trip->phone || $trip->email)
                <div class="block">
                    <h2>{{ __('ticket.more.contact', [], $locale) }}</h2>
                    <p>{{ $operator }}</p>
                    @if ($trip->phone)
                        <p class="strong">{{ $trip->phone }}</p>
                    @endif
                    @if ($trip->email)
                        <p class="strong">{{ $trip->email }}</p>
                    @endif
                </div>
            @endif
        </div>

        <footer>
            <span>{{ __('ticket.footer.show_on_arrival', [], $locale) }}</span>
            <span>{{ $operator }}</span>
        </footer>

    </section>
@endforeach

</body>
</html>
