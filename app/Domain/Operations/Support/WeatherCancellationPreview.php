<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Booking\Actions\CancelDeparture;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\Departure;
use Illuminate\Support\Collection;

/**
 * Who is affected, and what each of them is owed, **before** anything is sent
 * (spec OPS-6, OPS-7, CXL-6, CXL-7).
 *
 * ## The preview is the feature
 *
 * The sending already exists — {@see CancelDeparture} has been complete since
 * #84. What did not exist is the thing an operator needs at nine in the evening
 * with a forecast on their phone: a list of exactly who is affected and exactly
 * what each of them gets, before they press anything. Without it they ring every
 * guest instead, which is the work this product is meant to remove.
 *
 * ## Each booking's own snapshot, never the policy as it stands now
 *
 * CXL-6, and it is the whole reason this loops rather than computing one
 * percentage and multiplying. Two guests on the same boat who booked in
 * different months, under a policy the operator has since edited, are owed
 * different proportions of what they paid. A preview that read the *current*
 * policy would show two identical figures, the operator would approve them, and
 * the real refunds would differ from the screen they approved.
 *
 * {@see RefundEntitlement::forWeather()} reads the snapshot on the booking, so
 * the preview and the money are the same arithmetic rather than two
 * implementations that agree today.
 *
 * ## An already-cancelled departure contributes nothing
 *
 * Selecting yesterday's cancelled sailing along with tomorrow's is an ordinary
 * mis-click on a list. It shows as affecting nobody rather than as an error,
 * because `CancelDeparture` is idempotent and will do nothing to it — the
 * preview says what will happen, and nothing is what will happen.
 */
final class WeatherCancellationPreview
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function __construct(
        public readonly array $rows,
        public readonly int $departures,
        public readonly int $alreadyCancelled,
    ) {}

    /**
     * @param  Collection<int, Departure>  $departures
     */
    public static function for(Collection $departures): self
    {
        $live = $departures->reject(
            static fn (Departure $departure): bool => $departure->status === DepartureStatus::Cancelled,
        );

        $rows = [];

        foreach ($live as $departure) {
            foreach (self::bookingsOn($departure) as $booking) {
                $entitlement = RefundEntitlement::forWeather($booking);

                $rows[] = [
                    'departure' => $departure,
                    'booking' => $booking,
                    'reference' => $booking->reference,
                    'guest' => $booking->guest_name,
                    'pax' => $booking->pax_total,
                    'paid_cents' => $booking->paid_cents,
                    // The three parts, because they are three different
                    // conversations: cash back to a card, value back onto a
                    // voucher the guest already held, and the total the guest
                    // is told about.
                    'refund_cents' => $entitlement->totalCents,
                    'cash_cents' => $entitlement->cashCents,
                    'voucher_cents' => $entitlement->voucherCents,
                    'percent' => $entitlement->percent,
                ];
            }
        }

        return new self(
            rows: $rows,
            departures: $live->count(),
            alreadyCancelled: $departures->count() - $live->count(),
        );
    }

    public function guests(): int
    {
        return count($this->rows);
    }

    public function pax(): int
    {
        return (int) array_sum(array_column($this->rows, 'pax'));
    }

    public function paidCents(): int
    {
        return (int) array_sum(array_column($this->rows, 'paid_cents'));
    }

    public function refundCents(): int
    {
        return (int) array_sum(array_column($this->rows, 'refund_cents'));
    }

    /**
     * The bookings a cancellation would actually touch.
     *
     * The same predicate {@see CancelDeparture} uses — live statuses only — so
     * the preview cannot list somebody the cancellation will skip. A guest on
     * the screen who then hears nothing is worse than one who was never on it.
     *
     * @return Collection<int, Booking>
     */
    private static function bookingsOn(Departure $departure): Collection
    {
        return Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereIn('status', array_map(
                static fn (BookingStatus $status): string => $status->value,
                array_filter(BookingStatus::cases(), static fn (BookingStatus $s): bool => $s->isLive()),
            ))
            ->orderBy('guest_name')
            ->get();
    }
}
