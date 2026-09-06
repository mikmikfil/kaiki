<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\Vessel;
use App\Models\Voucher;

/**
 * AVL-45's lock ordering, in one enforceable place.
 *
 * > **vessel row, then departure row, then booking row.** Any code taking these
 * > locks in a different order is a review blocker.
 *
 * ## Why an order matters at all
 *
 * Two transactions that take the same two locks in opposite orders deadlock —
 * each holds what the other is waiting for. MySQL detects it and kills one
 * after a timeout, so the symptom is not a hang but a random failed
 * confirmation under load, which is exactly the condition nobody can reproduce.
 *
 * A fixed order makes it impossible rather than unlikely.
 *
 * ## The voucher comes last, and that is a decision
 *
 * AVL-45 names three rows and PRC-20 adds a fourth: the voucher, locked inside
 * the confirmation transaction so two bookings cannot spend it twice. It goes
 * **after** the booking, because it is the only one of the four that a
 * transaction might not take at all — most bookings have no voucher — and an
 * optional lock in the middle of a sequence is an order that is only sometimes
 * followed.
 *
 * ## This class does not take the locks
 *
 * It names them and ranks them. `LockDisciplineTest` reads {@see self::RANK}
 * and checks the order of `lockForUpdate()` calls in the confirmation Actions
 * against it — an ordering enforced by a comment is an ordering that lasts
 * until the next person who has not read the comment.
 */
final class LockOrder
{
    /**
     * The fixed order, lowest first (AVL-45, PRC-20).
     *
     * @var array<class-string, int>
     */
    public const RANK = [
        Vessel::class => 1,
        Departure::class => 2,
        Booking::class => 3,
        Voucher::class => 4,
    ];

    /**
     * The models in the order they must be locked.
     *
     * @return list<class-string>
     */
    public static function sequence(): array
    {
        $order = self::RANK;
        asort($order);

        return array_keys($order);
    }

    /** Where a model sits in the order, or null if it takes no such lock. */
    public static function rankOf(string $model): ?int
    {
        return self::RANK[$model] ?? null;
    }
}
