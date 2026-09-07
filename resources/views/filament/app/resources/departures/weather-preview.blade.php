{{--
    Who is affected and what each of them is owed (spec OPS-7).

    Everything on this screen was computed from **each booking's own policy
    snapshot** (CXL-6). Two guests on the same boat who booked in different
    months can be owed different proportions of what they paid, and a screen that
    showed one figure for both would be a screen the operator approved and the
    refunds then disagreed with.
--}}
@php
    use App\Support\Format\MoneyFormatter;

    $money = fn (int $cents): string => MoneyFormatter::format($cents, app()->getLocale(), MoneyFormatter::currency());
@endphp

<div class="space-y-4 text-sm">
    @if ($preview->alreadyCancelled > 0)
        <p class="text-gray-500">
            {{ __('availability.departure.weather.already_cancelled', ['count' => $preview->alreadyCancelled]) }}
        </p>
    @endif

    @if ($preview->guests() === 0)
        <p class="text-gray-500">{{ __('availability.departure.weather.nobody') }}</p>
    @else
        <p>
            {{ __('availability.departure.weather.summary', [
                'departures' => $preview->departures,
                'guests' => $preview->guests(),
                'pax' => $preview->pax(),
            ]) }}
        </p>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-1 pr-3">{{ __('availability.departure.weather.col.guest') }}</th>
                        <th class="py-1 pr-3">{{ __('availability.departure.weather.col.trip') }}</th>
                        <th class="py-1 pr-3 text-right">{{ __('availability.departure.weather.col.pax') }}</th>
                        <th class="py-1 pr-3 text-right">{{ __('availability.departure.weather.col.paid') }}</th>
                        <th class="py-1 text-right">{{ __('availability.departure.weather.col.refund') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview->rows as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1.5 pr-3">
                                {{ $row['guest'] }}
                                <span class="block font-mono text-xs text-gray-400">{{ $row['reference'] }}</span>
                            </td>
                            <td class="py-1.5 pr-3">
                                {{ $row['departure']->product->title }}
                                <span class="block text-xs text-gray-400">
                                    {{ $row['departure']->local_date->toDateString() }} {{ $row['departure']->local_time }}
                                </span>
                            </td>
                            <td class="py-1.5 pr-3 text-right">{{ $row['pax'] }}</td>
                            <td class="py-1.5 pr-3 text-right">{{ $money($row['paid_cents']) }}</td>
                            <td class="py-1.5 text-right">
                                <strong>{{ $money($row['refund_cents']) }}</strong>
                                {{-- The percentage is the operator's check on the
                                     screen: two different numbers in this column
                                     for two guests on the same boat is correct
                                     and looks like a bug until you see why. --}}
                                <span class="block text-xs text-gray-400">{{ $row['percent'] }}%</span>
                                @if ($row['voucher_cents'] > 0)
                                    <span class="block text-xs text-gray-400">
                                        {{ __('availability.departure.weather.col.of_which_voucher', ['amount' => $money($row['voucher_cents'])]) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 font-semibold dark:border-gray-700">
                        <td class="py-2" colspan="3">{{ __('availability.departure.weather.col.total') }}</td>
                        <td class="py-2 pr-3 text-right">{{ $money($preview->paidCents()) }}</td>
                        <td class="py-2 text-right">{{ $money($preview->refundCents()) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-gray-500">{{ __('availability.departure.weather.what_happens') }}</p>
    @endif
</div>
