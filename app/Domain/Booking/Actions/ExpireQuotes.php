<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers that ran out (`docs/data-model.md` §4.4, spec BKG-26).
 *
 * ## The sweeper is the tidier, and the read path is the guarantee
 *
 * The same division AVL-38 draws for seat holds. {@see Quote::canBeAccepted()}
 * consults `valid_until` directly, so an offer is dead the instant it lapses
 * whether or not this has run — a backlogged queue must never make a stale
 * price acceptable. What this does is bring the *status* into line, so the
 * operator's "pending quotes" card is not counting offers nobody can take, and
 * the vessel comes back on sale.
 *
 * ## The booking only expires if there is nothing newer
 *
 * §4.4: *"booking → `expired` unless a newer quote exists."* An operator who
 * revised on day six has a live version 2; expiring the booking because version
 * 1 lapsed would cancel the conversation they are in the middle of.
 *
 * ## Cross-tenant, and idempotent per row
 *
 * A platform job, like the hold sweeper and the weather-choice sweeper: it
 * finds rows `withoutTenancy()` and enters each tenant to act. The status
 * change is a conditional update — `where status = 'sent'` — so two overlapping
 * sweeps expire each quote once.
 */
final class ExpireQuotes
{
    /** @return int how many quotes were expired */
    public function __invoke(?int $limit = null): int
    {
        $lapsed = Tenancy::withoutTenancy(static fn () => Quote::query()
            ->lapsed()
            ->orderBy('valid_until')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get());

        $expired = 0;

        foreach ($lapsed as $quote) {
            if ($this->expire($quote)) {
                $expired++;
            }
        }

        return $expired;
    }

    private function expire(Quote $quote): bool
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($quote->tenant_id),
        );

        if ($tenant === null) {
            return false;
        }

        try {
            return (bool) Tenancy::forTenant($tenant, fn (): bool => DB::transaction(function () use ($quote): bool {
                // Conditional, not a check-then-write: two overlapping sweeps,
                // or a sweep racing a guest's decline, and only one wins.
                $claimed = DB::table('quotes')
                    ->where('id', $quote->getKey())
                    ->where('status', QuoteStatus::Sent->value)
                    ->update([
                        'status' => QuoteStatus::Expired->value,
                        'expired_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($claimed < 1) {
                    return false;
                }

                $this->settleBooking($quote->booking_id);

                return true;
            }));
        } catch (Throwable $exception) {
            // Ids and the exception class. A quote reaches a booking, and a
            // booking carries a guest's name, email and phone — a job that
            // logged the row would write all three every minute.
            Log::warning('quote.expiry_failed', [
                'quote_id' => $quote->getKey(),
                'tenant_id' => $quote->tenant_id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * The booking expires only if this was its last live offer (§4.4).
     */
    private function settleBooking(int $bookingId): void
    {
        // The block goes either way: an offer nobody can accept must not keep a
        // boat off sale.
        VesselBlock::query()->where('booking_id', $bookingId)->delete();

        $newerIsLive = Quote::query()
            ->where('booking_id', $bookingId)
            ->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])
            ->exists();

        if ($newerIsLive) {
            return;
        }

        /** @var Booking|null $booking */
        $booking = Booking::query()->lockForUpdate()->find($bookingId);

        if ($booking === null || ! $booking->status->canTransitionTo(BookingStatus::Expired)) {
            return;
        }

        $booking->forceFill(['status' => BookingStatus::Expired])->save();
    }
}
