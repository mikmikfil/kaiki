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

        {{--
            **Η περίληψη της εκδρομής, πάνω από τα ονόματα** — κατεύθυνση Β
            (Mike, 2026-09-23: *«ως πλήρωμα, πατάω πάνω σε ένα trip και μου
            βγάζει forbidden· δεν πρέπει να βλέπω κάτι εκεί; σαν summary»*).

            Καμία νέα οθόνη, που ήταν όλο το επιχείρημα του Β: το πλήρωμα
            ανοίγει ήδη αυτή τη λίστα από το ημερολόγιο, και η περίληψη μπαίνει
            ως πλαίσιο για τα ονόματα που ακολουθούν.

            **Ό,τι δεν χωράει μπαίνει σε `<details>`, όχι πίσω από σύνδεσμο.**
            Η μακέτα έδειχνε «Περισσότερα →», αλλά ένας σύνδεσμος θα ήταν
            δεύτερη οθόνη — δηλαδή ακριβώς αυτό που το Β υποσχέθηκε να μην
            κάνει. Ένα `<details>` ανοίγει επί τόπου και δουλεύει χωρίς script.

            **Τίποτα από τιμές, τιμοκαταλόγους ή όρους ακύρωσης** (TEN-8). Η
            ενότητα απαντά στο «τι κάνω σήμερα», όχι στο «τι πουλάμε».
        --}}
        @php
            $trip = $departure->product;
            $zone = $departure->vessel?->tenant?->timezone ?? config('app.timezone');
            $starts = $departure->starts_at_utc?->copy()->setTimezone($zone);
            $boarding = $starts !== null && (int) ($trip?->check_in_offset_minutes ?? 0) > 0
                ? $starts->copy()->subMinutes((int) $trip->check_in_offset_minutes)->format('H:i')
                : null;
            $ends = $departure->ends_at_utc?->copy()->setTimezone($zone)->format('H:i');
            $port = $trip?->meetingPoint ?? $departure->vessel?->homePort;
            $bring = collect($trip?->what_to_bring ?? [])->filter()->values();
            $includes = collect($trip?->includes ?? [])->filter()->values();
        @endphp

        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                @if ($boarding)
                    <span><span class="text-gray-500">{{ __('calendar.pax.brief.boarding') }}</span> <strong class="font-mono">{{ $boarding }}</strong></span>
                @endif
                @if ($ends)
                    <span><span class="text-gray-500">{{ __('calendar.pax.brief.returns') }}</span> <strong class="font-mono">{{ $ends }}</strong></span>
                @endif
                @if ($departure->vessel?->name)
                    <span><span class="text-gray-500">{{ __('calendar.pax.brief.vessel') }}</span> <strong>{{ $departure->vessel->name }}</strong></span>
                @endif
                @if ($port?->name)
                    <span><span class="text-gray-500">{{ __('calendar.pax.brief.where') }}</span> <strong>{{ $port->name }}</strong></span>
                @endif
            </div>

            @if ($includes->isNotEmpty() || $bring->isNotEmpty() || $port?->instructions)
                <details class="mt-2">
                    <summary class="cursor-pointer text-primary-600 dark:text-primary-400">{{ __('calendar.pax.brief.more') }}</summary>

                    <div class="mt-2 space-y-2">
                        @if ($port?->instructions)
                            <p class="text-gray-600 dark:text-gray-300">{{ $port->instructions }}</p>
                        @endif

                        @foreach ([['includes', $includes], ['bring', $bring]] as [$key, $lines])
                            @if ($lines->isNotEmpty())
                                <div>
                                    <p class="font-medium">{{ __('calendar.pax.brief.' . $key) }}</p>
                                    <ul class="list-inside list-disc text-gray-600 dark:text-gray-300">
                                        @foreach ($lines as $line)
                                            <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endif
        </div>

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
