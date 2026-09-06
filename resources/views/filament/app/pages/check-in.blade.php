{{--
    Check-in, built for a phone (spec BKG-20, TEN-8).

    The single-column layout is not responsive politeness — it is the only
    layout. Everything on this page is read one-handed, outdoors, by somebody
    with a person waiting in front of them, so the scan box is first and
    focused, the touch targets are full-width, and there is nothing to scroll
    past before the thing you came to do.

    **No price, no payment status, no document number.** TEN-8 gives crew the
    pax list and the check-in action and explicitly nothing else. Those fields
    are absent from this template rather than hidden behind a condition — a
    condition is one edit away from being wrong.
--}}
<x-filament-panels::page>

    <x-filament::section>
        <p class="text-sm">{{ __('checkin.subtitle') }}</p>

        {{--
            Bound to the query string, so a phone camera opening the ticket's QR
            arrives here with the code already in place and nothing to submit.
            `autofocus` matters: a hardware scanner types the code and presses
            return, and a page whose input is not focused loses the first scan.
        --}}
        <div class="mt-3">
            <label for="ticket" class="block text-sm font-medium">{{ __('checkin.scan.label') }}</label>
            <input
                id="ticket"
                type="text"
                inputmode="text"
                autocomplete="off"
                autofocus
                wire:model.live.debounce.300ms="ticket"
                placeholder="{{ __('checkin.scan.placeholder') }}"
                class="mt-1 w-full rounded-lg border-gray-300 text-base"
            >
            <p class="mt-1 text-xs text-gray-500">{{ __('checkin.scan.help') }}</p>
        </div>
    </x-filament::section>

    @php($scanned = $this->scannedGuest())

    @if (trim($ticket) !== '' && $scanned === null)
        <x-filament::section>
            {{-- One sentence for "no such code" and for "the booking is gone",
                 the same reasoning TOK-4 applies to the guest pages. --}}
            <p class="text-sm font-medium text-danger-600">{{ __('checkin.refused.unknown_ticket') }}</p>
        </x-filament::section>
    @endif

    @if ($scanned !== null)
        @php($booking = $scanned->booking)
        <x-filament::section>
            <div class="flex flex-col gap-3">
                <div>
                    <p class="text-lg font-semibold">{{ $scanned->full_name ?? __('checkin.guest.unnamed') }}</p>
                    <p class="text-sm text-gray-500">
                        {{ $booking?->product?->title }} · {{ $booking?->reference }}
                    </p>
                    @if ($booking !== null)
                        <p class="text-sm text-gray-500">
                            {{ __('checkin.actions.override.help', ['time' => $this->windowFor($booking)]) }}
                        </p>
                    @endif
                </div>

                @if ($scanned->checked_in_at !== null)
                    <p class="text-sm font-medium text-success-600">
                        {{ __('checkin.guest.checked_in_at', ['time' => $scanned->checked_in_at->format('H:i')]) }}
                    </p>
                @else
                    <div class="flex flex-wrap gap-2">
                        {{ ($this->checkInAction)(['guest' => $scanned->getKey()]) }}
                        {{ ($this->overrideAction)(['guest' => $scanned->getKey()]) }}
                        {{ ($this->noShowAction)(['guest' => $scanned->getKey()]) }}
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('checkin.today.heading')">
        @php($bookings = $this->todaysBookings())

        @if ($bookings->isEmpty())
            <p class="text-sm">{{ __('checkin.today.none') }}</p>
        @else
            <div class="flex flex-col gap-4">
                @foreach ($bookings as $booking)
                    @php($guests = $booking->guests->sortBy('position'))
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="font-medium">{{ $booking->product?->title }}</span>
                            <span class="text-sm text-gray-500">{{ $booking->local_time }}</span>
                        </div>
                        <p class="text-xs text-gray-500">
                            {{ $booking->reference }} ·
                            {{-- The number a crew member actually wants: it is
                                 what tells them when they can cast off. --}}
                            {{ __('checkin.today.aboard', [
                                'checked' => $guests->whereNotNull('checked_in_at')->count(),
                                'total' => $guests->count(),
                            ]) }}
                        </p>

                        <ul class="mt-2 flex flex-col gap-2">
                            @foreach ($guests as $guest)
                                <li class="flex items-center justify-between gap-2">
                                    <span class="text-sm">
                                        {{ $guest->full_name ?? __('checkin.guest.unnamed') }}
                                        @if ($guest->no_show)
                                            <span class="text-xs text-danger-600">· {{ __('checkin.guest.no_show') }}</span>
                                        @endif
                                    </span>

                                    @if ($guest->checked_in_at !== null)
                                        <span class="text-xs text-success-600">
                                            {{ __('checkin.guest.checked_in_at', ['time' => $guest->checked_in_at->format('H:i')]) }}
                                        </span>
                                    @else
                                        <span class="flex gap-1">
                                            {{ ($this->checkInAction)(['guest' => $guest->getKey()]) }}
                                            {{ ($this->noShowAction)(['guest' => $guest->getKey()]) }}
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

</x-filament-panels::page>
