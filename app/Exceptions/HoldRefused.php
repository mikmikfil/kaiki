<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Availability\Actions\HoldSeats;
use RuntimeException;

/**
 * The hold could not be taken (spec AVL-38, AVL-39, AVL-41).
 *
 * Thrown from {@see HoldSeats}, which is one of the only three writers of a
 * hold. Each named constructor is a different thing to tell a guest, and they
 * are kept apart because "we could not hold your seats" is a sentence nobody
 * can act on:
 *
 * - **not enough seats** — somebody was faster. The guest should pick another
 *   time, and the widget re-renders availability behind the message (AVL-39).
 * - **hold expired** — their own hold ran out and could not be re-acquired.
 *   The distinction matters: the first is bad luck, the second is a page left
 *   open over lunch, and the second carries a fresh availability payload so the
 *   guest is not thrown back to the start.
 * - **quote mode** and **nothing to hold** are programmer errors and say so.
 *   They are still exceptions rather than assertions because a rule enforced
 *   only at the call site is a rule the second call site forgets.
 *
 * Every sentence comes from `lang/*\/booking.php` (CNV-11), never from a
 * literal here.
 */
final class HoldRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /** Somebody else took them first. */
    public static function notEnoughSeats(int $requested, int $available): self
    {
        return new self(
            (string) trans('booking.hold.not_enough_seats', [
                'requested' => $requested,
                'available' => $available,
            ]),
            'not_enough_seats',
        );
    }

    /**
     * The guest's own hold ran out and the seats are gone.
     *
     * AVL-39's `HOLD_EXPIRED`. The caller pairs this with a fresh availability
     * payload — the requirement is explicit that the widget must be able to
     * re-render *without losing the guest*, and an error with no data to
     * re-render from cannot.
     */
    public static function expiredAndUnavailable(): self
    {
        return new self((string) trans('booking.hold.expired'), 'hold_expired');
    }

    /** AVL-41: holds are never created for `quote` mode products. */
    public static function quoteModeHoldsNothing(): self
    {
        return new self((string) trans('booking.hold.quote_mode'), 'quote_mode');
    }

    /**
     * AVL-25, and the one refusal an operator override cannot lift (#89).
     *
     * BKG-32 lets a manual booking exceed the departure's own capacity with an
     * explicit confirmation. It does not — and cannot — let a boat sail
     * illegally full: `capacity_max` is a certificate rather than a commercial
     * decision, and the infants a commercial capacity does not count are
     * exactly the ones a coastguard does.
     */
    public static function legalCapacityExceeded(): self
    {
        return new self((string) trans('booking.hold.legal_capacity'), 'legal_capacity');
    }

    /** A party of zero capacity-counting pax occupies no seats to hold. */
    public static function nothingToHold(): self
    {
        return new self((string) trans('booking.hold.nothing_to_hold'), 'nothing_to_hold');
    }
}
