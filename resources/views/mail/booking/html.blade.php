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
    the card, one button — because a balance reminder that repeats the packing
    list is a reminder nobody finishes reading.

    Every value comes from {@see \App\Mail\Support\BookingMailDetails}, shared
    with the plain-text half, and a section with nothing to say is left out
    rather than printed empty.

    ## Readable without images, because NTF-6 requires it

    There is no image in this template at all — not a logo, not a spacer, not a
    tracking pixel, and **not the QR code**. The mockup draws the QR inside the
    email; this does not, deliberately. Every mail client on the list blocks
    images by default for a sender the recipient has not written to before,
    which is exactly the situation a confirmation email is in, and a boarding
    code that arrives as a grey box is worse than a link that opens the ticket
    PDF, where the QR always is. The ticket block shows the booking code in
    large type and that link.

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
    <title>{{ __("mail.{$template->value}.subject", ['reference' => $booking->reference]) }}</title>
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
                            ]) }}
                        </p>

                        {{-- The card: what, when, and the three times. --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="background:{{ $accent }};margin:0 0 {{ $showTicket ? '0' : '8px' }};">
                            <tr>
                                <td style="padding:18px 20px;{{ $font }}color:#ffffff;">
                                    @if ($d->trip !== null || $d->boat !== null)
                                        <p style="margin:0 0 4px;font-size:14px;color:#ffffff;">
                                            {{ collect([$d->trip, $d->boat])->filter()->implode(' · ') }}
                                        </p>
                                    @endif
                                    <p style="margin:0 0 12px;font-size:22px;font-weight:bold;color:#ffffff;">{{ $d->day }}</p>
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            @if ($d->checkIn !== null)
                                                <td style="{{ $font }}color:#ffffff;font-size:13px;padding-right:24px;">
                                                    {{ __('mail.common.check_in') }}<br>
                                                    <span style="font-size:18px;font-weight:bold;">{{ $d->checkIn }}</span>
                                                </td>
                                            @endif
                                            <td style="{{ $font }}color:#ffffff;font-size:13px;padding-right:24px;">
                                                {{ __('mail.common.departure') }}<br>
                                                <span style="font-size:18px;font-weight:bold;">{{ $d->departure }}</span>
                                            </td>
                                            @if ($d->return !== null)
                                                <td style="{{ $font }}color:#ffffff;font-size:13px;">
                                                    {{ __('mail.common.return') }}<br>
                                                    <span style="font-size:18px;font-weight:bold;">{{ $d->return }}</span>
                                                </td>
                                            @endif
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>

                        {{-- The ticket, under the card: the code, and a link to
                             the PDF that carries the QR. No QR image here — see
                             the file docblock. --}}
                        @if ($showTicket)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="border:1px solid #dbe3e9;border-top:0;margin:0 0 18px;">
                                <tr>
                                    <td style="padding:14px 20px;{{ $font }}">
                                        <p style="margin:0;font-size:13px;{{ $muted }}">{{ __('mail.common.ticket') }}</p>
                                        <p style="margin:0 0 4px;font-size:22px;font-weight:bold;letter-spacing:1px;">{{ $booking->reference }}</p>
                                        <p style="margin:0 0 8px;font-size:13px;{{ $muted }}">{{ __('mail.common.ticket_help') }}</p>
                                        <a href="{{ $d->ticketUrl }}" style="{{ $link }}">{{ __('mail.common.open_ticket') }}</a>
                                    </td>
                                </tr>
                            </table>
                        @else
                            <p style="margin:0 0 18px;font-size:13px;{{ $muted }}">
                                {{ __('mail.common.reference') }}: <strong style="color:#14202b;">{{ $booking->reference }}</strong>
                            </p>
                        @endif

                        @if ($d->refund !== null)
                            <p style="margin:0 0 18px;">
                                {{ __('mail.common.refunded') }}: <strong>{{ $d->refund }}</strong>
                            </p>
                        @endif

                        @if (! $d->full && $d->balance !== null)
                            <p style="margin:0 0 18px;">
                                {{ __('mail.common.balance') }}: <strong>{{ $d->balance }}</strong>
                            </p>
                        @endif

                        {{-- One main action, and it is the guest's own page — except in the
                             review request, where it is the operator's review link.
                             ADR-0004: a gateway URL emailed today is dead by the
                             time a guest opens it in three weeks. --}}
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                            <tr>
                                <td style="background:{{ $accent }};">
                                    <a href="{{ $d->reviewUrl ?? $d->manageUrl }}"
                                       style="display:inline-block;padding:12px 20px;color:#ffffff;{{ $font }}text-decoration:none;font-weight:bold;font-size:15px;">
                                        {{ $d->reviewUrl !== null ? __('mail.common.leave_review') : __('mail.common.manage_booking') }}
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

                            <p style="{{ $section }}">{{ __('mail.common.party') }}</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="border-top:1px solid #e4ecf1;margin:0 0 24px;">
                                @foreach ($d->party as $line)
                                    <tr>
                                        <td style="padding:8px 0;border-bottom:1px solid #e4ecf1;{{ $font }}">{{ $line['label'] }}</td>
                                        <td style="padding:8px 0;border-bottom:1px solid #e4ecf1;text-align:right;{{ $font }}">{{ $line['amount'] }}</td>
                                    </tr>
                                @endforeach
                                @if ($d->total !== null)
                                    <tr>
                                        <td style="padding:8px 0 4px;{{ $font }}font-weight:bold;">{{ __('mail.common.total') }}</td>
                                        <td style="padding:8px 0 4px;text-align:right;{{ $font }}font-weight:bold;">{{ $d->total }}</td>
                                    </tr>
                                @endif
                                @if ($d->paid !== null)
                                    <tr>
                                        <td style="padding:4px 0;{{ $font }}{{ $muted }}">{{ __('mail.common.paid') }}</td>
                                        <td style="padding:4px 0;text-align:right;{{ $font }}{{ $muted }}">{{ $d->paid }}</td>
                                    </tr>
                                @endif
                                @if ($d->balance !== null)
                                    <tr>
                                        <td style="padding:4px 0;{{ $font }}font-weight:bold;">
                                            {{ $d->balanceDue !== null ? __('mail.common.balance_due', ['date' => $d->balanceDue]) : __('mail.common.balance') }}
                                        </td>
                                        <td style="padding:4px 0;text-align:right;{{ $font }}font-weight:bold;">{{ $d->balance }}</td>
                                    </tr>
                                @endif
                            </table>

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
