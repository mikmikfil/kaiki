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
    cannot disagree about a time (design A, 2026-09-17).
--}}
@php
    $operator = $brand['tenant']['name'] ?? config('app.name');
    $d = \App\Mail\Support\BookingMailDetails::for($booking, $template, app()->getLocale(), $extra ?? []);
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
]) }}

@if ($d->trip !== null || $d->boat !== null)
{{ collect([$d->trip, $d->boat])->filter()->implode(' · ') }}
@endif
{{ $d->day }}
@if ($d->checkIn !== null)
{{ __('mail.common.check_in') }}: {{ $d->checkIn }}
@endif
{{ __('mail.common.departure') }}: {{ $d->departure }}
@if ($d->return !== null)
{{ __('mail.common.return') }}: {{ $d->return }}
@endif
{{ __('mail.common.reference') }}: {{ $booking->reference }}
@if ($d->refund !== null)
{{ __('mail.common.refunded') }}: {{ $d->refund }}
@endif
@if (! $d->full && $d->balance !== null)
{{ __('mail.common.balance') }}: {{ $d->balance }}
@endif

@if ($d->reviewUrl !== null)
{{ __('mail.common.leave_review') }}:
{!! $d->reviewUrl !!}

@endif
{{ __('mail.common.manage_booking') }}:
{!! $d->manageUrl !!}
@if ($d->full && $d->ticketUrl !== null)

{{ __('mail.common.ticket') }} — {{ __('mail.common.ticket_help') }}
{!! $d->ticketUrl !!}
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

{{ __('mail.common.party') }}
@foreach ($d->party as $line)
{{ $line['label'] }}@if ($line['amount'] !== ''): {{ $line['amount'] }}@endif
@endforeach
@if ($d->total !== null)
{{ __('mail.common.total') }}: {{ $d->total }}
@endif
@if ($d->paid !== null)
{{ __('mail.common.paid') }}: {{ $d->paid }}
@endif
@if ($d->balance !== null)
{{ $d->balanceDue !== null ? __('mail.common.balance_due', ['date' => $d->balanceDue]) : __('mail.common.balance') }}: {{ $d->balance }}
@endif
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
