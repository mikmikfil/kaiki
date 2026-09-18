{{--
    `/b/{manage_token}` — TOK-6, TOK-7, TOK-13, in direction Β2 (2026-09-18).

    The page a guest opens three weeks after booking, on a phone, to find out
    where to be and at what time. So it reads in that order: the trip, the three
    times, what is still owed, where to stand — and only at the very bottom, in
    a quiet zone of its own, the things nobody should press by accident.

    One column, sections divided by a hairline rather than boxed into cards, and
    two doses of the operator's colour: the rule at the top of the sheet and the
    strip that carries the times. Both live in `guest/layout.blade.php`, which
    is why the other four token pages change with this one.

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
    $point = $booking->product?->meetingPoint;
    $owesBalance = $booking->balance_cents > 0 && $booking->status === BookingStatus::Confirmed;
@endphp

@section('content')

    <section>
        {{-- The same link, and the same decision, as the checkout page. --}}
        @if ($backUrl)
            <p class="back-to-site"><a href="{{ $backUrl }}">{{ __('guest.back_to_site') }}</a></p>
        @endif

        <p class="kicker">{{ __('guest.booking.title') }} · {{ $booking->reference }}</p>
        <h1>{{ $booking->product?->title ?? __('guest.booking.title') }}</h1>
        <p class="muted">
            {{ $booking->local_date->translatedFormat('l j F Y') }}
            · {{ __('guest.booking.people', ['count' => $booking->pax_total]) }}
            @if ($booking->vessel?->name)
                · {{ $booking->vessel->name }}
            @endif
        </p>

        @unless ($booking->status === BookingStatus::Confirmed)
            <p class="muted">{{ __('guest.booking.status') }}: {{ $booking->status->label() }}</p>
        @endunless
    </section>

    {{-- Β2's strip. Check-in first and in the same colour as the rest, because
         the mistake this page exists to prevent is a guest reading the
         departure time and arriving as the boat leaves. --}}
    <div class="facts">
        @if ($times['checkIn'])
            <div><span>{{ __('guest.booking.times.check_in') }}</span><b>{{ $times['checkIn'] }}</b></div>
        @endif
        <div><span>{{ __('guest.booking.times.departure') }}</span><b>{{ $times['departure'] }}</b></div>
        @if ($times['return'])
            <div><span>{{ __('guest.booking.times.return') }}</span><b>{{ $times['return'] }}</b></div>
        @endif
    </div>

    @if ($owesBalance)
        <section>
            <span class="label">{{ __('guest.booking.price.balance') }}</span>
            <dl class="rows">
                <dt class="total">{{ $money($booking->balance_cents) }}</dt>
                <dd class="total">
                    @if ($booking->balance_due_at)
                        {{ __('guest.booking.balance_due', ['date' => $booking->balance_due_at->format('d/m/Y')]) }}
                    @endif
                </dd>
            </dl>

            {{-- ADR-0004 Option D: the session is minted when this is pressed,
                 priced at that moment — not at confirmation, and never emailed
                 as a gateway URL that would have expired weeks ago. --}}
            <form method="post" action="{{ route('guest.booking.pay-balance', ['token' => $token]) }}">
                @csrf
                <button class="btn" type="submit">{{ __('guest.booking.pay_balance') }}</button>
            </form>
        </section>
    @endif

    @if ($weatherChoiceDue)
        <section>
            <span class="label">{{ __('guest.booking.weather.heading') }}</span>
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
        </section>
    @elseif ($booking->weather_choice !== null)
        <section><div class="notice">{{ __('guest.booking.weather.chosen') }}</div></section>
    @endif

    @if ($point)
        <section>
            <span class="label">{{ __('guest.booking.meeting_point') }}</span>
            <p><strong>{{ $point->name }}</strong></p>
            @if ($point->address)
                <p class="muted">{{ $point->address }}</p>
            @endif

            {{--
                The map is drawn here now (2026-09-18) rather than only linked.
                `referrerpolicy="no-referrer"` on the frame, and `rel` on the
                link beside it, for the reason TOK-3 sets the header: without
                them the whole address of this page — token and all — reaches a
                map provider, and the token is the credential.
            --}}
            @if ($point->mapsEmbedUrl())
                <div class="map-embed">
                    <iframe src="{{ $point->mapsEmbedUrl() }}"
                            title="{{ $point->name }}"
                            loading="lazy"
                            referrerpolicy="no-referrer"></iframe>
                </div>
            @endif

            @if ($point->instructions)
                <p class="muted">{{ $point->instructions }}</p>
            @endif

            @php
                $mapUrl = $point->maps_url
                    ?: ($point->lat && $point->lng
                        ? 'https://www.openstreetmap.org/?mlat=' . $point->lat . '&mlon=' . $point->lng
                        : null);
            @endphp

            @if ($mapUrl)
                <p><a href="{{ $mapUrl }}" rel="noreferrer noopener" target="_blank">{{ __('guest.booking.map') }}</a></p>
            @endif
        </section>
    @endif

    {{--
        The ticket and the calendar, together: both are «take this with you»,
        and a guest doing one usually does the other.

        TOK-6's downloadable e-ticket is drawn only when there is a ticket to
        give — a cancelled booking and an operator who boards nobody get
        nothing rather than a button that answers "this link is not valid". The
        file is rendered on demand if the queue has not made it yet.

        The Google link opens in a new tab with `no-referrer`, and carries no
        token in its own query string either; see
        `BookingCalendarInvite::googleUrl()`.
    --}}
    @if ($ticketUrl || $calendar)
        <section>
            <span class="label">{{ __('guest.booking.take_with_you') }}</span>
            @if ($ticketUrl)
                <p class="muted">{{ __('guest.booking.ticket_help') }}</p>
            @elseif ($calendar)
                <p class="muted">{{ __('guest.booking.calendar.body') }}</p>
            @endif

            <div class="btn-row">
                @if ($ticketUrl)
                    <a class="btn secondary" href="{{ $ticketUrl }}">{{ __('guest.booking.ticket') }}</a>
                @endif
                @if ($calendar)
                    <a class="btn secondary" href="{{ $calendar['ics'] }}">{{ __('guest.booking.calendar.ics') }}</a>
                    <a class="btn secondary" href="{{ $calendar['google'] }}"
                       rel="noreferrer noopener" target="_blank">{{ __('guest.booking.calendar.google') }}</a>
                @endif
            </div>
        </section>
    @endif

    <section>
        <span class="label">{{ __('guest.booking.price.heading') }}</span>
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
    </section>

    {{-- The quiet end of the page. Changing your telephone number and
         cancelling your trip have nothing in common except that neither should
         be met on the way to the meeting point. --}}
    <section class="quiet">
        <span class="label">{{ __('guest.booking.manage') }}</span>

        <details>
            <summary>{{ __('guest.booking.contact.heading') }}</summary>

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
        </details>

        @if (! $booking->status->isLive())
            <p class="muted">{{ __('guest.booking.cancel.done') }}</p>
        @elseif ($isOver)
            {{-- CXL-4: after departure this is the operator's to record by hand,
                 with its own trail. --}}
            <p class="muted">{{ __('guest.booking.cancel.past') }}</p>
        @elseif ($canCancel)
            <details>
                <summary>{{ __('guest.booking.cancel.heading') }}</summary>

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
            </details>
        @endif
    </section>

@endsection
