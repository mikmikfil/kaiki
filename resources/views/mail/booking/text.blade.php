{{--
    The plain-text half (spec NTF-6).

    Mandatory, not optional. A message with no text part is a message that reads
    as an empty screen in a client with HTML off, scores worse with every spam
    filter, and gives a screen reader nothing but markup. `GuestMail` sets this
    on every send by construction, so no template can ship without one.

    Written to be read, not to be a transcript of the HTML: the same facts in
    the order somebody actually needs them, with each link on its own line where
    a mail client will make it tappable. The facts come from the same
    {@see \App\Mail\Support\BookingMailDetails} as the HTML, so the two halves
    cannot disagree about a time, a refund or a deadline (design A, 2026-09-17).
--}}
@php
    $operator = $brand['tenant']['name'] ?? config('app.name');
    $d = \App\Mail\Support\BookingMailDetails::for($booking, $template, app()->getLocale(), $extra ?? []);
    // The booking page as a second link, where the main button goes somewhere
    // else about this booking (the details form, the review page). A voucher
    // and a quote are not about a booking the guest can manage.
    $alsoManage = $d->actionUrl !== $d->manageUrl && ! in_array($template, [
        \App\Enums\NotificationTemplate::VoucherExpiry30d,
        \App\Enums\NotificationTemplate::VoucherExpiry7d,
        \App\Enums\NotificationTemplate::QuoteSent,
    ], true);
@endphp
@if ($d->greeting !== null)
{{ $d->greeting }}

@endif
{{ __("mail.{$template->value}.heading") }}

{{ __("mail.{$template->value}.body", [
    'name' => $booking->guest_name,
    'reference' => $booking->reference,
    'date' => $booking->local_date->format('d/m/Y'),
    'time' => $d->departure,
    'operator' => $d->operator,
    'deadline' => $d->deadline ?? $booking->local_date->format('d/m/Y'),
]) }}

@if ($d->cardEyebrow !== null)
{{ $d->cardEyebrow }}
@endif
{{ $d->cardTitle }}
@foreach ($d->cardRows as $row)
{{ $row['label'] }}: {{ $row['value'] }}
@endforeach
@foreach ($d->facts as $fact)
{{ $fact['label'] }}: {{ $fact['value'] }}
@endforeach
@if ($d->factsNote !== null)
{{ $d->factsNote }}
@endif
@if (! $d->full && $d->showParty)

@include('mail.booking.partials.party-text')
@endif

{{ $d->actionLabel }}:
{!! $d->actionUrl !!}
@if ($alsoManage)

{{ __('mail.common.manage_booking') }}:
{!! $d->manageUrl !!}
@endif
@if ($d->full && $d->ticketUrl !== null)

{{ __('mail.common.ticket') }} — {{ __('mail.common.ticket_help') }}
@if ($d->boardingPasses !== [])
{{ trans_choice('mail.common.boarding_text', count($d->boardingPasses)) }}
@endif
@if ($ticketPdfAttached ?? false)
{{ __('mail.common.ticket_attached') }}
@endif
{!! $d->ticketUrl !!}
@endif
@if ($d->calendarUrl !== null)

{{ __('mail.common.add_to_calendar') }}
{{ __('mail.common.calendar_ics') }}: {!! $d->calendarUrl !!}
{{ __('mail.common.calendar_google') }}: {!! $d->calendarGoogleUrl !!}
@endif
@if ($d->full)
@if ($d->meetingName !== null)

{{ __('mail.common.meeting_point') }}
{{ $d->meetingName }}
@if ($d->meetingAddress !== null)
{{ $d->meetingAddress }}
@endif
@if ($d->meetingInstructions !== null)
{{ __('mail.common.how_to_find') }}: {{ $d->meetingInstructions }}
@endif
@if ($d->mapUrl !== null)
{!! $d->mapUrl !!}
@endif
@endif
@if ($d->detailsUrl !== null)

{{ __('mail.common.details_title') }}
{{ __('mail.common.details_body') }}@if ($d->detailsBy !== null) {{ __('mail.common.details_by', ['date' => $d->detailsBy]) }}@endif

{!! $d->detailsUrl !!}
@endif

@include('mail.booking.partials.party-text')
@if ($d->bring !== [])

{{ __('mail.common.bring') }}
@foreach ($d->bring as $item)
- {{ $item }}
@endforeach
@endif
@if ($d->policy !== null)

{{ __('mail.common.cancellation') }}
{{ $d->policy }}
@endif
@if ($d->phone !== null || $d->email !== null)

{{ __('mail.common.contact') }}
{{ collect([$operator, $d->phone, $d->email])->filter()->implode(' · ') }}
@endif
@endif

--
{{ $operator }}
{{ __('mail.common.transactional') }}
