{{--
    Check-in, built for a phone (spec BKG-20, TEN-8).

    The single-column layout is not responsive politeness — it is the only
    layout. Everything on this page is read one-handed, outdoors, by somebody
    with a person waiting in front of them, so the scan box is first and
    focused, the touch targets are full-width, and there is nothing to scroll
    past before the thing you came to do.

    **No price, no document number.** TEN-8 gives crew the pax list and the
    check-in action and explicitly nothing else. Those fields are absent from
    this template rather than hidden behind a condition — a condition is one
    edit away from being wrong.

    The one exception (Mike, 2026-09-25): «Οφείλει €X» with «Πληρώθηκε» on a
    booking that still owes, so the balance can be collected on the boat. The
    open balance only — not the total, not what was paid, not how.
--}}
<x-filament-panels::page>

    @php($qrEnabled = $this->qrEnabled())

    {{-- An operator who boards from the passenger list (BKG-20, amended
         2026-09-11) gets the list and one sentence saying how to use it. --}}
    @unless ($qrEnabled)
        <x-filament::section>
            <p class="text-sm">{{ __('checkin.subtitle_list') }}</p>
        </x-filament::section>
    @endunless

    @if ($qrEnabled)
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
                class="mt-1 w-full rounded-lg border-gray-300 text-base dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder-gray-500"
            >
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('checkin.scan.help') }}</p>
        </div>
    </x-filament::section>

    @php($scanned = $this->scannedGuest())

    @if (trim($ticket) !== '' && $scanned === null)
        <x-filament::section>
            {{-- One sentence for "no such code" and for "the booking is gone",
                 the same reasoning TOK-4 applies to the guest pages. --}}
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ __('checkin.refused.unknown_ticket') }}</p>
        </x-filament::section>
    @endif

    @if ($scanned !== null)
        @php($booking = $scanned->booking)
        <x-filament::section>
            <div class="flex flex-col gap-3">
                <div>
                    <p class="text-lg font-semibold">{{ $scanned->full_name ?? __('checkin.guest.unnamed') }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $booking?->product?->title }} · {{ $booking?->reference }}
                    </p>
                    @if ($booking !== null)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('checkin.actions.override.help', ['time' => $this->windowFor($booking)]) }}
                        </p>
                    @endif
                </div>

                {{-- «Οφείλει €X» (2026-09-25): the one figure on this page. --}}
                @if ($booking !== null && $this->owesOnBoard($booking))
                    <div class="kc-owes flex flex-wrap items-center justify-between gap-2 rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-400/10">
                        <span class="text-sm font-semibold text-warning-700 dark:text-warning-400">{{ \App\Filament\App\Support\CollectBalanceAction::owes($booking) }}</span>
                        <span class="kc-actions">{{ ($this->collectBalanceAction)(['booking' => $booking->getKey()]) }}</span>
                    </div>
                @endif

                @php($scannedDetailsUrl = $booking === null ? null : \App\Domain\Booking\Support\GuestDetailsTracking::urlWhilePending($booking))
                @if ($scannedDetailsUrl !== null)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-400/10">
                        <span class="text-sm font-semibold text-warning-700 dark:text-warning-400">{{ __('bookings.guest_details.missing') }}</span>
                        <a href="{{ $scannedDetailsUrl }}" target="_blank" rel="noopener" class="inline-block py-1.5 text-sm font-semibold text-primary-600 dark:text-primary-400">{{ __('bookings.guest_details.fill_in') }}</a>
                    </div>
                @endif

                @if ($scanned->checked_in_at !== null)
                    <p class="text-sm font-medium text-success-600 dark:text-success-400">
                        {{ __('checkin.guest.checked_in_at', ['time' => \App\Domain\Availability\LocalDateTimeResolver::inTenantZone($scanned->checked_in_at)?->format('H:i')]) }}
                    </p>
                @else
                    <div class="kc-actions grid gap-2 sm:flex sm:flex-wrap">
                        {{ ($this->checkInAction)(['guest' => $scanned->getKey()]) }}
                        {{ ($this->overrideAction)(['guest' => $scanned->getKey()]) }}
                        {{ ($this->noShowAction)(['guest' => $scanned->getKey()]) }}
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif
    @endif

    <x-filament::section :heading="__('checkin.today.heading')">
        @php($bookings = $this->todaysBookings())

        {{-- The way to the page that works without a signal. It used to be
             reached only through a ticket's QR, which an operator without QR
             boarding does not print. --}}
        <p class="mb-3 text-sm">
            <a href="{{ route('filament.app.boarding') }}" class="inline-block py-1.5 font-semibold text-primary-600 dark:text-primary-400">{{ __('checkin.offline_link') }}</a>
        </p>

        @if ($bookings->isEmpty())
            <p class="text-sm">{{ __('checkin.today.none') }}</p>
        @else
            <div class="flex flex-col gap-4">
                @foreach ($bookings as $booking)
                    @php($guests = $booking->guests->sortBy('position'))
                    @php($early = $this->isEarly($booking))
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="font-medium">{{ $booking->product?->title }}</span>
                            {{-- Hours and minutes. The column is a `time` and
                                 printed raw it read «08:30:00» — seconds nobody
                                 on a quay has ever needed. --}}
                            <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ substr((string) $booking->local_time, 0, 5) }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $booking->reference }} ·
                            {{-- The number a crew member actually wants: it is
                                 what tells them when they can cast off. --}}
                            {{ __('checkin.today.aboard', [
                                'checked' => $guests->whereNotNull('checked_in_at')->count(),
                                'total' => $guests->count(),
                            ]) }}
                        </p>

                        {{-- «Οφείλει €X» and «Πληρώθηκε» (2026-09-25), once per
                             booking rather than per passenger: the balance is
                             the booking's, and one button cannot be pressed
                             twice for it from two rows. --}}
                        @if ($this->owesOnBoard($booking))
                            <div class="kc-owes mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-400/10">
                                <span class="text-sm font-semibold text-warning-700 dark:text-warning-400">{{ \App\Filament\App\Support\CollectBalanceAction::owes($booking) }}</span>
                                <span class="kc-actions">{{ ($this->collectBalanceAction)(['booking' => $booking->getKey()]) }}</span>
                            </div>
                        @endif

                        {{-- «Λείπουν στοιχεία επιβατών» (Mike, 25/9): the sale went
                             through, the list is still owed. The guest's own form,
                             on this phone, to fill in with them before casting off. --}}
                        @php($detailsUrl = \App\Domain\Booking\Support\GuestDetailsTracking::urlWhilePending($booking))
                        @if ($detailsUrl !== null)
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-400/10">
                                <span class="text-sm font-semibold text-warning-700 dark:text-warning-400">{{ __('bookings.guest_details.missing') }}</span>
                                <a href="{{ $detailsUrl }}" target="_blank" rel="noopener" class="inline-block py-1.5 text-sm font-semibold text-primary-600 dark:text-primary-400">{{ __('bookings.guest_details.fill_in') }}</a>
                            </div>
                        @endif

                        <ul class="mt-2 flex flex-col gap-2">
                            @foreach ($guests as $guest)
                                {{-- Below `sm` the name has the full width and the two
                                     buttons sit under it, side by side: in a row
                                     beside them it wrapped to five lines and the
                                     buttons ran off the card (audit 2026-09-23). --}}
                                <li class="flex flex-col gap-2 border-t border-gray-100 pt-2 first:border-t-0 first:pt-0 sm:flex-row sm:items-center sm:justify-between sm:border-t-0 sm:pt-0 dark:border-white/5">
                                    <span class="min-w-0 text-sm">
                                        {{ $guest->full_name ?? __('checkin.guest.unnamed') }}
                                        @php($answerLine = \App\Models\BookingAnswer::joined([...($guest->is_lead ? $booking->answers->whereNull('booking_guest_id')->all() : []), ...$guest->answers->all()]))
                                        @if ($answerLine !== '')
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $answerLine }}</span>
                                        @endif
                                        {{-- «Φοιτητής», «ΑμεΑ»: a group by status the crew check
                                             with their eyes, since checkout asked no number
                                             (2026-09-24). --}}
                                        @if ($guest->ageBand?->requires_proof)
                                            <span class="block text-xs font-medium text-warning-700 dark:text-warning-400">{{ __('checkin.guest.proof', ['group' => $guest->ageBand->label]) }}</span>
                                        @endif
                                        @if ($guest->no_show)
                                            <span class="text-xs text-danger-600 dark:text-danger-400">· {{ __('checkin.guest.no_show') }}</span>
                                        @endif
                                    </span>

                                    @if ($guest->checked_in_at !== null)
                                        <span class="shrink-0 text-xs text-success-600 dark:text-success-400">
                                            {{ __('checkin.guest.checked_in_at', ['time' => \App\Domain\Availability\LocalDateTimeResolver::inTenantZone($guest->checked_in_at)?->format('H:i')]) }}
                                        </span>
                                    @else
                                        <span class="kc-actions grid grid-cols-2 gap-2 sm:flex sm:shrink-0">
                                            {{-- Before the window opens a plain tap is refused,
                                                 so the row offers the way that is not. --}}
                                            @if ($early)
                                                {{ ($this->overrideAction)(['guest' => $guest->getKey()]) }}
                                            @else
                                                {{ ($this->checkInAction)(['guest' => $guest->getKey()]) }}
                                            @endif
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

    {{-- Every check-in button a thumb can hit on a moving deck: 44px tall, and
         below `sm` as wide as its cell, so the pair reads as two halves of
         one row rather than two chips. --}}
    <style>
        .kc-actions .fi-btn { min-height: 2.75rem; }
        /* Early boarding is amber, and white on amber is 2–3:1 — in the sun,
           unreadable. Dark type on it is above 5:1 in both themes. */
        .kc-actions .fi-btn.fi-color-warning:not(.fi-btn-outlined),
        .kc-actions .fi-btn.fi-color-warning:not(.fi-btn-outlined) .fi-btn-icon { color: rgb(var(--gray-950)); }
        @media (max-width: 639.98px) {
            .kc-actions .fi-btn { width: 100%; }
        }
    </style>

</x-filament-panels::page>
