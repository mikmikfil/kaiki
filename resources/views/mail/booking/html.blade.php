{{--
    The HTML half of every transactional email (spec NTF-1, NTF-6, I18N-2).

    ## Design A, «Κάρτα εισιτηρίου» (product owner, 2026-09-17)

    The mockup is `docs/mockups/booking-email.html`. The confirmation, a change
    and the day-before reminder carry the whole trip: a card in the operator's
    colour with the trip, the boat, the day and the check-in, departure and
    return times; the ticket; the meeting point with its directions and a map
    link; the passenger-details notice while they are missing; the party and
    what was paid; what to bring; the cancellation terms; how to reach the
    operator. Every other message keeps the short form — a heading, a sentence,
    the card with the few facts that message is about under it (a refund, a
    deadline, what is left to pay, a voucher's code, a quote's items), and one
    button that does what it asks — because a balance reminder that repeats the
    packing list is a reminder nobody finishes reading.

    Every value comes from {@see \App\Mail\Support\BookingMailDetails}, shared
    with the plain-text half, and a section with nothing to say is left out
    rather than printed empty.

    ## Readable without images, because NTF-6 requires it

    There is no remote image in this template at all — not a logo, not a
    spacer, not a tracking pixel. Every mail client on the list blocks remote
    images by default for a sender the recipient has not written to before,
    which is exactly the situation a confirmation email is in.

    The one image is **the boarding QR, one per passenger** (product owner,
    2026-09-23, reversing the 2026-09-17 call to leave it out). It is a PNG
    carried inside the message as a `cid:` part — nothing is fetched, so
    nothing is blocked, and no URL that serves a boarding code exists for
    anybody to guess. PNG because Gmail and Outlook do not draw SVG
    ({@see \App\Domain\Booking\Support\TicketQr}). It appears only where the
    ticket PDF and the booking page would show one
    ({@see \App\Domain\Booking\Support\BoardingPasses}): QR boarding on for
    this operator, a ticketed booking, a trip not yet sailed. The message still
    reads without it: the booking code in large type and the link to the
    ticket PDF sit above it, and each image has its passenger in the alt text.

    The operator's identity is carried by the **name and the accent colour**,
    both of which survive an image block.

    ## Tables, inline styles, and no `<style>` block

    Not nostalgia. Outlook on Windows renders through Word, which supports
    neither flexbox nor grid and discards most of a `<style>` block; Gmail
    strips `<style>` on forwarded mail. A single centred table with inline
    styles is the only construction that renders the same in Gmail, Outlook and
    Apple Mail, which is NTF-6's own list. System fonts only: a web font is a
    remote fetch, blocked the same way an image is.

    ## No `text-transform: uppercase`, and no underlined links

    I18N-2. Uppercasing Greek drops the accents — ΆΝΝΑ becomes ΑΝΝΑ — and
    browsers and mail clients disagree about whether the final sigma changes.
    Links are coloured and never underlined, as everywhere guest-facing.
--}}
@php
    /** @var \App\Models\Booking $booking */
    /** @var \App\Enums\NotificationTemplate $template */
    $accent = $brand['colors']['primary'] ?? '#0B2740';
    $operator = $brand['tenant']['name'] ?? config('app.name');
    $d = \App\Mail\Support\BookingMailDetails::for($booking, $template, app()->getLocale(), $extra ?? []);
    $showTicket = $d->full && $d->ticketUrl !== null;
    $font = 'font-family:Arial,Helvetica,sans-serif;';
    $section = 'margin:0 0 8px;font-size:16px;font-weight:bold;color:#14202b;';
    $muted = 'color:#5a6b7a;';
    $link = 'color:' . $accent . ';text-decoration:none;font-weight:bold;';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __("mail.{$template->value}.subject", ['reference' => $booking->reference, 'trip' => (string) $booking->product?->title]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f5f7;">
    <tr>
        <td align="center" style="padding:24px 12px;">

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px;background:#ffffff;border:1px solid #dbe3e9;">

                {{-- The operator's name, in their own colour. No logo image:
                     see the file docblock. --}}
                <tr>
                    <td style="padding:20px 24px;border-bottom:3px solid {{ $accent }};{{ $font }}font-size:16px;font-weight:bold;color:#14202b;">
                        {{ $operator }}
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px;{{ $font }}font-size:15px;line-height:1.55;color:#14202b;">

                        @if ($d->greeting !== null)
                            <p style="margin:0 0 6px;">{{ $d->greeting }}</p>
                        @endif

                        <p style="margin:0 0 10px;font-size:19px;font-weight:bold;">
                            {{ __("mail.{$template->value}.heading") }}
                        </p>

                        <p style="margin:0 0 18px;">
                            {{ __("mail.{$template->value}.body", [
                                'name' => $booking->guest_name,
                                'reference' => $booking->reference,
                                'date' => $booking->local_date->format('d/m/Y'),
                                'time' => $d->departure,
                                'operator' => $d->operator,
                                'deadline' => $d->deadline ?? $booking->local_date->format('d/m/Y'),
                            ]) }}
                        </p>

                        {{-- The card, in the operator's colour: what and when for a
                             booking, the code and what is left for a voucher. --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="background:{{ $accent }};margin:0;">
                            <tr>
                                <td style="padding:18px 20px;{{ $font }}color:#ffffff;">
                                    @if ($d->cardEyebrow !== null)
                                        <p style="margin:0 0 4px;font-size:14px;color:#ffffff;">{{ $d->cardEyebrow }}</p>
                                    @endif
                                    <p style="margin:0 0 12px;font-size:22px;font-weight:bold;color:#ffffff;">{{ $d->cardTitle }}</p>
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            @foreach ($d->cardRows as $row)
                                                <td style="{{ $font }}color:#ffffff;font-size:13px;padding-right:24px;">
                                                    {{ $row['label'] }}<br>
                                                    <span style="font-size:18px;font-weight:bold;">{{ $row['value'] }}</span>
                                                </td>
                                            @endforeach
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>

                        {{-- Under the card, the stub: the ticket on the full messages,
                             and on every other one the few facts that message is
                             about. The QR codes follow in their own block. --}}
                        @if ($showTicket)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="border:1px solid #dbe3e9;border-top:0;margin:0 0 18px;">
                                <tr>
                                    <td style="padding:14px 20px;{{ $font }}">
                                        <p style="margin:0;font-size:13px;{{ $muted }}">{{ __('mail.common.ticket') }}</p>
                                        <p style="margin:0 0 4px;font-size:22px;font-weight:bold;letter-spacing:1px;">{{ $booking->reference }}</p>
                                        <p style="margin:0 0 8px;font-size:13px;{{ $muted }}">
                                            {{ __('mail.common.ticket_help') }}
                                            {{-- Only when `GuestMail::send()` really attached it (2026-09-23). --}}
                                            @if ($ticketPdfAttached ?? false)
                                                {{ __('mail.common.ticket_attached') }}
                                            @endif
                                        </p>
                                        <a href="{{ $d->ticketUrl }}" style="{{ $link }}">{{ __('mail.common.open_ticket') }}</a>
                                    </td>
                                </tr>
                            </table>
                        @elseif ($d->facts !== [] || $d->factsNote !== null)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="border:1px solid #dbe3e9;border-top:0;margin:0 0 18px;">
                                <tr>
                                    <td style="padding:10px 20px 12px;{{ $font }}">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach ($d->facts as $fact)
                                                <tr>
                                                    <td style="padding:6px 12px 6px 0;{{ $font }}font-size:14px;{{ $muted }}">{{ $fact['label'] }}</td>
                                                    <td style="padding:6px 0;text-align:right;{{ $font }}font-size:15px;font-weight:bold;color:#14202b;">{{ $fact['value'] }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                        @if ($d->factsNote !== null)
                                            <p style="margin:6px 0 0;font-size:14px;color:#14202b;">{{ $d->factsNote }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        @else
                            <div style="height:18px;line-height:18px;font-size:1px;">&nbsp;</div>
                        @endif

                        {{-- The boarding codes (2026-09-23): one per passenger,
                             each a PNG inside the message (see the file
                             docblock). 200 px on screen, drawn at about twice
                             that so a phone's screen keeps the edges hard, with
                             the white quiet zone in the image itself. Side by
                             side where there is room, one under the other on a
                             phone — and in Outlook, which stacks the blocks. --}}
                        @if ($d->boardingPasses !== [])
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="border:1px solid #dbe3e9;margin:0 0 18px;">
                                <tr>
                                    <td style="padding:14px 20px 6px;{{ $font }}">
                                        <p style="margin:0;font-size:13px;{{ $muted }}">{{ __('mail.common.boarding_heading') }}</p>
                                        <p style="margin:0 0 10px;font-size:14px;color:#14202b;">{{ trans_choice('mail.common.boarding_help', count($d->boardingPasses)) }}</p>
                                        @foreach ($d->boardingPasses as $pass)
                                            @php
                                                $png = \App\Domain\Booking\Support\TicketQr::pngFor($pass['guest']);
                                                // `$message` is the mailer's own, on a real send and on
                                                // `Mailable::render()`; a bare `view()` has none.
                                                $qrSrc = isset($message)
                                                    ? $message->embedData($png, 'boarding-' . $loop->iteration . '.png', 'image/png')
                                                    : 'data:image/png;base64,' . base64_encode($png);
                                            @endphp
                                            <div style="display:inline-block;vertical-align:top;width:200px;margin:0 16px 12px 0;">
                                                <img src="{{ $qrSrc }}" width="200" height="200"
                                                     alt="{{ __('mail.common.boarding_alt', ['name' => $pass['name']]) }}"
                                                     style="display:block;width:200px;height:200px;border:0;outline:none;background:#ffffff;">
                                                <p style="margin:6px 0 0;font-size:14px;font-weight:bold;color:#14202b;">{{ $pass['name'] }}</p>
                                                <p style="margin:0;font-size:11px;{{ $muted }}word-break:break-all;">{{ $pass['code'] }}</p>
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                        @endif

                        {{-- «Προσθήκη στο ημερολόγιο» (2026-09-18). Two links and
                             not one, because they serve two different guests: the
                             `.ics` is what a phone hands to iOS or Android with no
                             account in the way, and the Google link is one click
                             for somebody reading in a browser tab. Text links
                             rather than a second button — the card already has one
                             main action and this is not it. --}}
                        @if ($d->calendarUrl !== null)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="border:1px solid #dbe3e9;margin:0 0 18px;">
                                <tr>
                                    <td style="padding:12px 20px;{{ $font }}font-size:14px;">
                                        <span style="{{ $muted }}">{{ __('mail.common.add_to_calendar') }}</span><br>
                                        <a href="{{ $d->calendarUrl }}" style="{{ $link }}">{{ __('mail.common.calendar_ics') }}</a>
                                        <span style="{{ $muted }}"> · </span>
                                        <a href="{{ $d->calendarGoogleUrl }}" style="{{ $link }}">{{ __('mail.common.calendar_google') }}</a>
                                    </td>
                                </tr>
                            </table>
                        @endif

                        @if (! $d->full && $d->showParty)
                            @include('mail.booking.partials.party-html')
                        @endif

                        {{-- One main action, and it does what the message asks: the
                             guest's own page, the details form, the quote, the
                             voucher, the operator's review page.
                             ADR-0004: a gateway URL emailed today is dead by the
                             time a guest opens it in three weeks. --}}
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                            <tr>
                                <td style="background:{{ $accent }};">
                                    <a href="{{ $d->actionUrl }}"
                                       style="display:inline-block;padding:12px 20px;color:#ffffff;{{ $font }}text-decoration:none;font-weight:bold;font-size:15px;">
                                        {{ $d->actionLabel }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                        @if ($d->full)

                            @if ($d->meetingName !== null)
                                <p style="{{ $section }}">{{ __('mail.common.meeting_point') }}</p>
                                <p style="margin:0 0 2px;font-weight:bold;">{{ $d->meetingName }}</p>
                                @if ($d->meetingAddress !== null)
                                    <p style="margin:0 0 8px;{{ $muted }}">{{ $d->meetingAddress }}</p>
                                @endif
                                @if ($d->meetingInstructions !== null)
                                    <p style="margin:0 0 2px;font-size:13px;{{ $muted }}">{{ __('mail.common.how_to_find') }}</p>
                                    <p style="margin:0 0 8px;">{{ $d->meetingInstructions }}</p>
                                @endif
                                <p style="margin:0 0 24px;">
                                    @if ($d->mapUrl !== null)
                                        <a href="{{ $d->mapUrl }}" style="{{ $link }}">{{ __('mail.common.open_map') }}</a>
                                    @endif
                                </p>
                            @endif

                            @if ($d->detailsUrl !== null)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                       style="background:#fff6e0;border:1px solid #f0d58a;margin:0 0 24px;">
                                    <tr>
                                        <td style="padding:14px 16px;{{ $font }}color:#5c4400;">
                                            <p style="margin:0 0 4px;font-weight:bold;">{{ __('mail.common.details_title') }}</p>
                                            <p style="margin:0 0 8px;">
                                                {{ __('mail.common.details_body') }}
                                                @if ($d->detailsBy !== null)
                                                    {{ __('mail.common.details_by', ['date' => $d->detailsBy]) }}
                                                @endif
                                            </p>
                                            <a href="{{ $d->detailsUrl }}" style="color:#5c4400;text-decoration:none;font-weight:bold;">{{ __('mail.common.details_button') }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @include('mail.booking.partials.party-html')

                            @if ($d->bring !== [])
                                <p style="{{ $section }}">{{ __('mail.common.bring') }}</p>
                                <p style="margin:0 0 24px;">
                                    @foreach ($d->bring as $item)
                                        · {{ $item }}@if (! $loop->last)<br>@endif
                                    @endforeach
                                </p>
                            @endif

                            @if ($d->policy !== null)
                                <p style="{{ $section }}">{{ __('mail.common.cancellation') }}</p>
                                <p style="margin:0 0 4px;">{{ $d->policy }}</p>
                                <p style="margin:0 0 24px;"><a href="{{ $d->manageUrl }}" style="{{ $link }}">{{ __('mail.common.full_policy') }}</a></p>
                            @endif

                            @if ($d->phone !== null || $d->email !== null)
                                <p style="{{ $section }}">{{ __('mail.common.contact') }}</p>
                                <p style="margin:0 0 24px;">
                                    {{ $operator }}<br>
                                    @if ($d->phone !== null)
                                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $d->phone) }}" style="{{ $link }}">{{ $d->phone }}</a>
                                    @endif
                                    @if ($d->phone !== null && $d->email !== null) · @endif
                                    @if ($d->email !== null)
                                        <a href="mailto:{{ $d->email }}" style="{{ $link }}">{{ $d->email }}</a>
                                    @endif
                                </p>
                            @endif

                        @endif

                        <p style="margin:0;color:#5a6b7a;font-size:13px;">
                            {{ __('mail.common.transactional') }}
                        </p>
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>
</body>
</html>
