{{--
    `/v/{voucher}` — TOK-12.

    > *It MUST NOT reveal the booking or guest it was issued for.*

    So: a code, three amounts, a date and a list of trips. `issued_for_booking_id`
    is on the model and never reaches this file, and neither does anything that
    could be worked back to it — no reference, no name, no date the voucher was
    created against a sailing.

    `VoucherPageTest` asserts that over the **whole rendered body**, because the
    way this leaks is a helpful addition three months from now rather than a
    field somebody puts here today.
--}}
@extends('guest.layout', ['title' => __('guest.voucher.title')])

@php
    $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
@endphp

@section('content')

    <div class="card">
        <h1>{{ __('guest.voucher.title') }}</h1>

        <dl class="rows">
            <dt>{{ __('guest.voucher.code') }}</dt>
            <dd><strong>{{ $voucher->code }}</strong></dd>

            <dt>{{ __('guest.voucher.original') }}</dt>
            <dd>{{ $money($voucher->amount_cents) }}</dd>

            <dt class="total">{{ __('guest.voucher.remaining') }}</dt>
            <dd class="total">{{ $money($voucher->remaining_cents) }}</dd>

            <dt>{{ __('guest.voucher.expires') }}</dt>
            <dd>{{ $voucher->expires_at?->format('d/m/Y') ?? __('guest.voucher.no_expiry') }}</dd>
        </dl>

        {{-- A voucher that has run out still renders, with a sentence saying so.
             A 404 would be indistinguishable from a mistyped code and would send
             the guest to the operator's phone. --}}
        @if (! $spendable)
            <div class="notice">
                {{ $voucher->remaining_cents < 1 ? __('guest.voucher.spent') : __('guest.voucher.expired') }}
            </div>
        @else
            <p class="muted">{{ __('guest.voucher.how') }}</p>
        @endif
    </div>

    @if ($products->isNotEmpty())
        <div class="card">
            <h2>{{ __('guest.voucher.products') }}</h2>
            <ul>
                @foreach ($products as $product)
                    <li>{{ $product->title }}</li>
                @endforeach
            </ul>
        </div>
    @endif

@endsection
