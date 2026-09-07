{{--
    Who is on this sailing (spec OPS-3).

    **Names and party sizes only.** Document numbers are a manifest (OPS-10) and
    a separate, logged action — a list an operator opens forty times a day is not
    where the most sensitive field in the database belongs.
--}}
@php
    use App\Support\Format\MoneyFormatter;
@endphp

@if ($departure === null)
    <p class="text-sm text-gray-500">{{ __('calendar.pax.gone') }}</p>
@else
    <div class="space-y-3">
        <p class="text-sm text-gray-500">
            {{ $departure->product->title }} · {{ $departure->local_date->toDateString() }} {{ $departure->local_time }}
            · {{ __('calendar.pax.seats', ['sold' => $departure->seats_sold, 'capacity' => $departure->capacity]) }}
        </p>

        @if ($bookings->isEmpty())
            <p class="text-sm text-gray-500">{{ __('calendar.pax.nobody') }}</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-1">{{ __('calendar.pax.guest') }}</th>
                        <th class="py-1">{{ __('calendar.pax.people') }}</th>
                        <th class="py-1">{{ __('calendar.pax.reference') }}</th>
                        <th class="py-1 text-right">{{ __('calendar.pax.owed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bookings as $booking)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1.5">{{ $booking->guest_name }}</td>
                            <td class="py-1.5">{{ $booking->pax_total }}</td>
                            <td class="py-1.5 font-mono text-xs">{{ $booking->reference }}</td>
                            <td class="py-1.5 text-right">
                                @if ($booking->balance_cents > 0)
                                    <strong>{{ MoneyFormatter::format($booking->balance_cents, app()->getLocale(), MoneyFormatter::currency()) }}</strong>
                                @else
                                    <span class="text-gray-400">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif
