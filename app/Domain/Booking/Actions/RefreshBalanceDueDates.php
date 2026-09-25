<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * The due dates of the current tenant's open balances, again, after the
 * operator changes how balances are collected (audit 2).
 *
 * A booking confirmed while the balance was collected on board has no due
 * date. Switched to online, it got no reminder and, with the attention list
 * now reading the overdue dates, it was chased nowhere. Switched the other way,
 * the old dates went on sending online reminders. Each booking goes through
 * {@see RecomputeBookingMoney} under its own lock — the one writer of those
 * columns — which gives a missing date one and takes it away where the
 * balance is now collected on board. A date already set stays as it is.
 *
 * Future trips only: after departure the attention list finds an unpaid
 * balance from `balance_cents` alone.
 */
final class RefreshBalanceDueDates
{
    public function __construct(private readonly RecomputeBookingMoney $recomputeMoney) {}

    /** @return int how many bookings were looked at */
    public function __invoke(): int
    {
        $count = 0;

        Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->where('balance_cents', '>', 0)
            ->where('starts_at_utc', '>', now())
            ->select('id')
            ->chunkById(200, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row): void {
                        $locked = Booking::query()->lockForUpdate()->find($row->getKey());

                        if ($locked instanceof Booking) {
                            ($this->recomputeMoney)($locked);
                        }
                    });

                    $count++;
                }
            });

        return $count;
    }
}
