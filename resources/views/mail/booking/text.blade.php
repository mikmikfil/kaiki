{{--
    The plain-text half (spec NTF-6).

    Mandatory, not optional. A message with no text part is a message that reads
    as an empty screen in a client with HTML off, scores worse with every spam
    filter, and gives a screen reader nothing but markup. `GuestMail` sets this
    on every send by construction, so no template can ship without one.

    Written to be read, not to be a transcript of the HTML: the same facts in
    the order somebody actually needs them, with the link on its own line where
    a mail client will make it tappable.
--}}
@php
    $operator = $brand['tenant']['name'] ?? config('app.name');
    $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' EUR';
@endphp
{{ __("mail.{$template->value}.heading") }}

{{ __("mail.{$template->value}.body", [
    'name' => $booking->guest_name,
    'reference' => $booking->reference,
    'date' => $booking->local_date->format('d/m/Y'),
    'time' => substr((string) $booking->local_time, 0, 5),
]) }}

{{ __('mail.common.reference') }}: {{ $booking->reference }}
{{ __('mail.common.when') }}: {{ $booking->local_date->format('d/m/Y') }} {{ substr((string) $booking->local_time, 0, 5) }}
@if ($booking->product?->meetingPoint)
{{ __('mail.common.meeting_point') }}: {{ $booking->product->meetingPoint->name }}
@endif
@if ($booking->balance_cents > 0)
{{ __('mail.common.balance') }}: {{ $money($booking->balance_cents) }}
@endif

{{ __('mail.common.open_booking') }}:
{{ route('guest.booking', ['token' => $booking->manage_token]) }}

--
{{ $operator }}
{{ __('mail.common.transactional') }}
