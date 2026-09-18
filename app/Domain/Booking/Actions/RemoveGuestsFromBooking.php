<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\SeatCommitment;
use App\Domain\Pricing\Actions\RestoreVoucher;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Models\Vessel;
use App\Support\Money\Cents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking people off a booking that is still going ahead (product owner,
 * 2026-09-17): four booked, three coming.
 *
 * ## The price is the one the guest was quoted, not today's
 *
 * PRC-8's snapshot is the contract. Each person comes off at the unit price
 * frozen on the booking's own `pax_breakdown` — the same figures the email and
 * the invoice already show — rather than re-running the price engine against a
 * rate plan the operator may have edited since. Repricing would make removing a
 * child change what the adults pay.
 *
 * Extras and discounts are left as they are. An extra bought "per booking"
 * does not shrink with the party, and one bought per person was a choice the
 * guest made in a quantity; guessing which to reduce is worse than leaving it
 * for the operator to adjust.
 *
 * ## Which people, not how many (product owner, 2026-09-18)
 *
 * The first version took a count per age band and chose the rows itself —
 * unnamed first, then the last position. That is the right rule when nobody
 * knows who is staying home, and the wrong one the moment somebody does: an
 * operator on the telephone is told «ο Γιώργος δεν έρχεται», not «ένας
 * ενήλικας λιγότερος», and a manifest that keeps the wrong name is a manifest
 * the coastguard reads. Pass `guestIds` and exactly those people come off; pass
 * counts and the old rule still applies, because the API and an imported
 * booking may have no passenger rows to point at.
 *
 * ## The voucher's share goes back to the voucher (product owner, 2026-09-18)
 *
 * PRC-19.2's rule, which cancellation has honoured since #84 and this did not:
 * value that came off a voucher is not the operator's cash to hand back. So the
 * refund is split the way {@see RefundBooking::settle()} splits it — the
 * voucher's share restored to the voucher, the remainder put through the
 * gateway — rather than being sent to a card in full.
 *
 * ## Money, in one direction only
 *
 * The total goes down, never up. What has been paid beyond the new total is
 * given back through {@see RefundBooking::partial()}, whose guard is "never
 * more than is still held". The balance is whatever the new total leaves
 * unpaid, and the deposit can only shrink with it.
 *
 * ## Seats in the same transaction as the counts
 *
 * CXL-9's rule, applied to part of a party: the seats go back to the departure
 * in the transaction that lowers `pax_capacity_total`, in AVL-45's lock order.
 * A person who does not take a seat (an infant on a lap) releases nothing.
 */
final class RemoveGuestsFromBooking
{
    public function __construct(
        private readonly RefundBooking $refundBooking,
        private readonly RestoreVoucher $restoreVoucher,
    ) {}

    /**
     * The counts that removing these particular people amounts to.
     *
     * Rows that are not this booking's, and rows for somebody already aboard,
     * are ignored rather than rejected: the form is drawn from the same table
     * and a stale tab is not worth an error page.
     *
     * @param  list<int>  $guestIds
     * @return array<string, int> age band code => how many
     */
    public static function countsFor(Booking $booking, array $guestIds): array
    {
        if ($guestIds === []) {
            return [];
        }

        $counts = [];

        $rows = BookingGuest::query()
            ->where('booking_id', $booking->getKey())
            ->whereIn('id', $guestIds)
            ->whereNull('checked_in_at')
            ->get();

        foreach ($rows as $guest) {
            $code = (string) $guest->age_band_code;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * What removing these people would do, without doing it — the form's
     * preview, and the same arithmetic the removal itself uses.
     *
     * @param  array<string, int>  $removeByCode  age band code => how many to take off
     * @return array{removed: int, seats: int, removed_cents: int, new_total_cents: int, refund_cents: int, new_balance_cents: int}
     */
    public static function preview(Booking $booking, array $removeByCode): array
    {
        $removed = 0;
        $seats = 0;
        $removedCents = 0;

        foreach ($booking->pax_breakdown as $line) {
            $code = (string) ($line['code'] ?? '');
            $take = max(0, min((int) ($line['qty'] ?? 0), (int) ($removeByCode[$code] ?? 0)));

            $removed += $take;
            $removedCents += $take * (int) ($line['unit_price_cents'] ?? 0);

            if ((bool) ($line['counts_toward_capacity'] ?? true)) {
                $seats += $take;
            }
        }

        $newTotal = max(0, $booking->total_cents - $removedCents);

        return [
            'removed' => $removed,
            'seats' => $seats,
            'removed_cents' => $booking->total_cents - $newTotal,
            'new_total_cents' => $newTotal,
            'refund_cents' => max(0, $booking->paid_cents - $newTotal),
            'new_balance_cents' => max(0, $newTotal - $booking->paid_cents),
        ];
    }

    /**
     * @param  array<string, int>  $removeByCode  age band code => how many to take off
     * @param  list<int>  $guestIds  the exact people, when the operator picked them
     * @return array{removed: int, refund_cents: int, refund_started_cents: int, voucher_cents: int, new_total_cents: int}
     *
     * @throws ValidationException when the change is not one this booking can take
     */
    public function __invoke(Booking $booking, array $removeByCode, ?string $reason = null, array $guestIds = []): array
    {
        if ($guestIds !== []) {
            $removeByCode = self::countsFor($booking, $guestIds);
        }

        $this->guard($booking, $removeByCode);

        $preview = self::preview($booking, $removeByCode);

        $changed = DB::transaction(function () use ($booking, $removeByCode, $guestIds, $preview): Booking {
            // AVL-45's order — vessel, departure, booking.
            if ($booking->vessel_id !== null) {
                Vessel::query()->lockForUpdate()->find($booking->vessel_id);
            }

            $departure = $booking->departure_id === null
                ? null
                : Departure::query()->lockForUpdate()->find($booking->departure_id);

            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if ($departure instanceof Departure && $locked->status->committingSeats()) {
                SeatCommitment::release($departure, $preview['seats']);
            }

            $breakdown = [];

            foreach ($locked->pax_breakdown as $line) {
                $code = (string) ($line['code'] ?? '');
                $take = max(0, min((int) ($line['qty'] ?? 0), (int) ($removeByCode[$code] ?? 0)));

                if ($take > 0) {
                    $this->dropGuestRows($locked, $code, $take, $guestIds);
                }

                $qty = (int) ($line['qty'] ?? 0) - $take;

                if ($qty < 1) {
                    continue;
                }

                $line['qty'] = $qty;
                $line['total_cents'] = $qty * (int) ($line['unit_price_cents'] ?? 0);
                $breakdown[] = $line;
            }

            $newTotal = $preview['new_total_cents'];

            $locked->forceFill([
                'pax_breakdown' => $breakdown,
                'pax_total' => $locked->pax_total - $preview['removed'],
                'pax_capacity_total' => max(0, $locked->pax_capacity_total - $preview['seats']),
                'subtotal_cents' => max(0, $locked->subtotal_cents - $preview['removed_cents']),
                'total_cents' => $newTotal,
                'vat_cents' => $locked->vat_rate_bp > 0
                    ? $newTotal - Cents::netOfInclusive($newTotal, $locked->vat_rate_bp)
                    : 0,
                'deposit_cents' => min($locked->deposit_cents, $newTotal),
                'balance_cents' => max(0, $newTotal - $locked->paid_cents),
            ])->save();

            return $locked;
        });

        // PRC-19.2: the voucher's share first, because that value never was
        // the operator's cash — only what is left goes to a card.
        $restored = $preview['refund_cents'] > 0
            ? ($this->restoreVoucher)($changed, $preview['refund_cents'], $reason)
            : 0;

        $cash = max(0, $preview['refund_cents'] - $restored);

        $started = $cash > 0
            ? $this->refundBooking->partial($changed, $cash, $reason)
            : 0;

        return [
            'removed' => $preview['removed'],
            'refund_cents' => $preview['refund_cents'],
            'refund_started_cents' => $started,
            'voucher_cents' => $restored,
            'new_total_cents' => $preview['new_total_cents'],
        ];
    }

    /**
     * The rows for the people who are not coming.
     *
     * When the operator picked them, those exact rows go and nothing else is
     * guessed at. Otherwise the old rule stands: the last ones in that band
     * first, and among those the ones nobody has filled in yet — a name the
     * guest typed is kept over an empty row, because a count is all the
     * operator gave and the guest, not the operator, knows who stayed home.
     *
     * @param  list<int>  $guestIds
     */
    private function dropGuestRows(Booking $booking, string $code, int $take, array $guestIds = []): void
    {
        $query = BookingGuest::query()
            ->where('booking_id', $booking->getKey())
            ->where('age_band_code', $code)
            ->whereNull('checked_in_at');

        if ($guestIds !== []) {
            $query->whereIn('id', $guestIds);
        } else {
            $query
                ->orderByRaw('CASE WHEN full_name IS NULL OR full_name = \'\' THEN 0 ELSE 1 END')
                ->orderByDesc('position');
        }

        $query->limit($take)
            ->get()
            ->each(static fn (BookingGuest $guest): ?bool => $guest->delete());
    }

    /**
     * @param  array<string, int>  $removeByCode
     *
     * @throws ValidationException
     */
    private function guard(Booking $booking, array $removeByCode): void
    {
        $fail = static fn (string $key): ValidationException => ValidationException::withMessages([
            'remove' => [trans("bookings.remove_guests.validation.{$key}")],
        ]);

        if ($booking->mode !== BookingMode::PerSeat) {
            throw $fail('per_seat_only');
        }

        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::PendingPayment], true)
            || $booking->starts_at_utc->isPast()) {
            throw $fail('not_changeable');
        }

        $preview = self::preview($booking, $removeByCode);

        if ($preview['removed'] < 1) {
            throw $fail('nobody');
        }

        $remaining = $booking->pax_total - $preview['removed'];

        if ($remaining < 1) {
            throw $fail('everyone');
        }

        $minimum = (int) ($booking->product->min_booking_pax ?? 1);

        if ($remaining < $minimum) {
            throw ValidationException::withMessages([
                'remove' => [trans('bookings.remove_guests.validation.below_minimum', ['minimum' => $minimum])],
            ]);
        }

        // A child who needs an adult cannot be left without one.
        $adultsLeft = 0;
        $needAdult = 0;
        $bands = $booking->product?->ageBands()->get()->keyBy('code');

        foreach ($booking->pax_breakdown as $line) {
            $code = (string) ($line['code'] ?? '');
            $left = (int) ($line['qty'] ?? 0) - max(0, (int) ($removeByCode[$code] ?? 0));
            $band = $bands?->get($code);

            if ($band === null || $left < 1) {
                continue;
            }

            if ($band->requires_adult) {
                $needAdult += $left;
            } elseif ($band->is_base) {
                $adultsLeft += $left;
            }
        }

        if ($needAdult > 0 && $adultsLeft < 1) {
            throw $fail('needs_adult');
        }
    }
}
