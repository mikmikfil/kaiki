{{--
    `/b/{manage_token}` — TOK-6, TOK-7, TOK-13.

    The page a guest opens three weeks after booking, on a phone, to find out
    where to be and at what time. So the summary comes first and the actions
    come last: somebody looking for the meeting point should not have to scroll
    past a cancel button to find it.

    Every action is a POST with `@csrf` (TOK-13). None of them is idempotent
    *here* — the Actions behind them are, each in its own way — because
    idempotency implemented in a view would protect this page and leave the API
    and the panel exposed to the same double click.
--}}
@extends('guest.layout', ['title' => __('guest.booking.title')])

@php
    use App\Enums\BookingStatus;

    $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
    $isOver = $booking->starts_at_utc->isPast();
@endphp

@section('content')

    <div class="card">
        <h1>{{ $booking->product?->title ?? __('guest.booking.title') }}</h1>
        <p class="muted">{{ __('guest.common.reference') }}: <strong>{{ $booking->reference }}</strong></p>

        <dl class="rows">
            <dt>{{ __('guest.booking.when') }}</dt>
            <dd>{{ $booking->local_date->format('d/m/Y') }} · {{ substr((string) $booking->local_time, 0, 5) }}</dd>

            <dt>{{ __('guest.booking.party') }}</dt>
            <dd>{{ __('guest.booking.people', ['count' => $booking->pax_total]) }}</dd>

            <dt>{{ __('guest.booking.status') }}</dt>
            <dd>{{ $booking->status->label() }}</dd>
        </dl>
    </div>

    @if ($booking->product?->meetingPoint)
        <div class="card">
            <h2>{{ __('guest.booking.meeting_point') }}</h2>
            <p>{{ $booking->product->meetingPoint->name }}</p>
            @if ($booking->product->meetingPoint->address)
                <p class="muted">{{ $booking->product->meetingPoint->address }}</p>
            @endif

            @php
                $point = $booking->product->meetingPoint;
                $mapUrl = $point->maps_url
                    ?: ($point->lat && $point->lng
                        ? 'https://www.openstreetmap.org/?mlat=' . $point->lat . '&mlon=' . $point->lng
                        : null);
            @endphp

            @if ($mapUrl)
                {{--
                    The map link is exactly why TOK-3 sets `Referrer-Policy:
                    no-referrer`: without it, tapping this sends the whole URL —
                    token and all — to a map provider in the `Referer` header.
                    `rel` says it again on the link itself, because a guest who
                    saved the page has the anchor and not the header.
                --}}
                <p><a class="btn secondary" href="{{ $mapUrl }}"
                      rel="noreferrer noopener" target="_blank">{{ __('guest.booking.map') }}</a></p>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>{{ __('guest.booking.price.heading') }}</h2>
        <dl class="rows">
            <dt>{{ __('guest.booking.price.total') }}</dt>
            <dd>{{ $money($booking->total_cents) }}</dd>

            <dt>{{ __('guest.booking.price.paid') }}</dt>
            <dd>{{ $money($booking->paid_cents) }}</dd>

            @if ($booking->refunded_cents > 0)
                <dt>{{ __('guest.booking.price.refunded') }}</dt>
                <dd>{{ $money($booking->refunded_cents) }}</dd>
            @endif

            @if ($booking->balance_cents > 0)
                <dt class="total">{{ __('guest.booking.price.balance') }}</dt>
                <dd class="total">{{ $money($booking->balance_cents) }}</dd>
            @endif
        </dl>

        @if ($booking->balance_cents > 0 && $booking->status === BookingStatus::Confirmed)
            @if ($booking->balance_due_at)
                <p class="muted">{{ __('guest.booking.balance_due', ['date' => $booking->balance_due_at->format('d/m/Y')]) }}</p>
            @endif

            {{-- ADR-0004 Option D: the session is minted when this is pressed,
                 priced at that moment — not at confirmation, and never emailed
                 as a gateway URL that would have expired weeks ago. --}}
            <form method="post" action="{{ route('guest.booking.pay-balance', ['token' => $token]) }}">
                @csrf
                <button class="btn" type="submit">{{ __('guest.booking.pay_balance') }}</button>
            </form>
        @endif
    </div>

    @if ($weatherChoiceDue)
        <div class="card">
            <h2>{{ __('guest.booking.weather.heading') }}</h2>
            <p>{{ __('guest.booking.weather.body', [
                'amount' => $money($entitlement->totalCents),
                'deadline' => $booking->weather_choice_due_at?->format('d/m/Y') ?? '—',
            ]) }}</p>

            {{-- CXL-7's three options. Each is its own submit rather than a
                 radio group, because a guest on a phone choosing and then
                 confirming is two taps where one will do. --}}
            <form method="post" action="{{ route('guest.booking.weather-choice', ['token' => $token]) }}">
                @csrf
                <button class="btn" type="submit" name="choice" value="refund">{{ __('guest.booking.weather.refund') }}</button>
                <button class="btn secondary" type="submit" name="choice" value="voucher">{{ __('guest.booking.weather.voucher') }}</button>
                <button class="btn secondary" type="submit" name="choice" value="rebook">{{ __('guest.booking.weather.rebook') }}</button>
            </form>
        </div>
    @elseif ($booking->weather_choice !== null)
        <div class="notice">{{ __('guest.booking.weather.chosen') }}</div>
    @endif

    <div class="card">
        <h2>{{ __('guest.booking.contact.heading') }}</h2>

        {{-- Contact details only. Not the party, not the date, not anything
             that would change what was sold — a guest who wants a different
             trip is making a new booking. --}}
        <form method="post" action="{{ route('guest.booking.contact', ['token' => $token]) }}">
            @csrf
            <label for="guest_name">{{ __('guest.booking.contact.name') }}</label>
            <input id="guest_name" name="guest_name" value="{{ old('guest_name', $booking->guest_name) }}" required>

            <label for="guest_email">{{ __('guest.booking.contact.email') }}</label>
            <input id="guest_email" name="guest_email" type="email" value="{{ old('guest_email', $booking->guest_email) }}" required>

            <label for="guest_phone">{{ __('guest.booking.contact.phone') }}</label>
            <input id="guest_phone" name="guest_phone" value="{{ old('guest_phone', $booking->guest_phone) }}">

            <p></p>
            <button class="btn secondary" type="submit">{{ __('guest.booking.contact.save') }}</button>
        </form>
    </div>

    <div class="card">
        <h2>{{ __('guest.booking.cancel.heading') }}</h2>

        @if (! $booking->status->isLive())
            <p class="muted">{{ __('guest.booking.cancel.done') }}</p>
        @elseif ($isOver)
            {{-- CXL-4: after departure this is the operator's to record by hand,
                 with its own trail. --}}
            <p class="muted">{{ __('guest.booking.cancel.past') }}</p>
        @elseif ($canCancel)
            {{--
                TOK-7. The nil-refund case is **shown**, says so plainly, and
                still releases the seat: an operator would far rather have the
                place back to resell than have a guest decide the button is
                broken and not turn up.
            --}}
            @if ($entitlement->totalCents > 0)
                <p>{{ __('guest.booking.cancel.refund', ['amount' => $money($entitlement->totalCents)]) }}</p>
            @else
                <p>{{ __('guest.booking.cancel.no_refund') }}</p>
            @endif

            <form method="post" action="{{ route('guest.booking.cancel', ['token' => $token]) }}">
                @csrf
                <button class="btn danger" type="submit">{{ __('guest.booking.cancel.confirm') }}</button>
            </form>
        @endif
    </div>

    <div class="card">
        {{-- The PDF itself is #88's; TOK-6 asks for the download to be here and
             this is the honest placeholder until it is. --}}
        <p class="muted">{{ __('guest.booking.ticket_soon') }}</p>
    </div>

@endsection
