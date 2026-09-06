{{--
    `/q/{quote_token}` — TOK-11, BKG-26, §4.4.

    A quote in any status renders as a page. Only a token that does not resolve
    gets TOK-4's refusal — a guest holding last week's link is in a different
    situation from somebody guessing at tokens, and only one of those is a
    security question.

    The line items are the operator's own words, in the guest's language, and
    the total is the number they typed. Nothing here recomputes anything: what
    was offered is what is shown.
--}}
@extends('guest.layout', ['title' => __('guest.quote.title')])

@php
    use App\Enums\QuoteStatus;

    $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
@endphp

@section('content')

    <div class="card">
        <h1>{{ __('guest.quote.title') }}</h1>
        <p class="muted">{{ __('guest.quote.from', ['reference' => $booking->reference]) }}</p>

        @if ($quote->message)
            <p>{{ $quote->message }}</p>
        @endif
    </div>

    @if (session('quote_error') === 'vessel_unavailable')
        {{-- §4.4's re-check refused. A quote is not a hold, and the boat went
             while the guest was thinking — said in a sentence, with a way
             forward, rather than as a failure. --}}
        <div class="notice bad">{{ __('guest.quote.unavailable') }}</div>
    @endif

    @if ($wasReplaced)
        <div class="notice">{{ __('guest.quote.replaced') }}</div>
    @elseif ($quote->status === QuoteStatus::Expired)
        <div class="notice">{{ __('guest.quote.expired') }}</div>
    @elseif ($quote->status === QuoteStatus::Accepted)
        <div class="notice">{{ __('guest.quote.accepted') }}</div>
    @elseif ($quote->status === QuoteStatus::Declined)
        <div class="notice">{{ __('guest.quote.declined') }}</div>
    @endif

    <div class="card">
        <dl class="rows">
            @foreach ($lines as $line)
                <dt>{{ $line->label }}{{ $line->qty > 1 ? ' × ' . $line->qty : '' }}</dt>
                <dd>{{ $line->kind->signum() < 0 ? '−' : '' }}{{ $money($line->total_cents) }}</dd>
            @endforeach

            <dt class="total">{{ __('guest.quote.total') }}</dt>
            <dd class="total">{{ $money($quote->total_cents) }}</dd>

            @if ($quote->deposit_cents > 0)
                <dt>{{ __('guest.quote.deposit') }}</dt>
                <dd>{{ $money($quote->deposit_cents) }}</dd>
            @endif
        </dl>

        <p class="muted">{{ __('guest.quote.valid_until', ['date' => $quote->valid_until->format('d/m/Y')]) }}</p>

        @if ($quote->terms)
            <p class="muted">{{ $quote->terms }}</p>
        @endif
    </div>

    @if ($canAccept)
        <div class="card">
            <form method="post" action="{{ route('guest.quote.accept', ['token' => $token]) }}">
                @csrf
                <button class="btn" type="submit">{{ __('guest.quote.accept') }}</button>
            </form>

            <form method="post" action="{{ route('guest.quote.decline', ['token' => $token]) }}">
                @csrf
                <label for="reason">{{ __('guest.quote.decline_reason') }}</label>
                <textarea id="reason" name="reason" rows="2"></textarea>
                <p></p>
                <button class="btn secondary" type="submit">{{ __('guest.quote.decline') }}</button>
            </form>
        </div>
    @else
        {{--
            TOK-11: an expired quote is read-only **with a way forward**. It
            creates an Enquiry rather than a new quote, because a quote is
            something an operator writes and a guest asking for one is asking a
            question — and it lands in the inbox they already watch.
        --}}
        <div class="card">
            <h2>{{ __('guest.quote.request_new') }}</h2>
            <p class="muted">{{ __('guest.quote.request_new_body') }}</p>

            <form method="post" action="{{ route('guest.quote.request-new', ['token' => $token]) }}">
                @csrf
                <textarea name="message" rows="3"></textarea>
                <p></p>
                <button class="btn" type="submit">{{ __('guest.quote.request_new') }}</button>
            </form>
        </div>
    @endif

@endsection
