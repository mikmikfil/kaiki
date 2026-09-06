<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Booking\Support\SeatCommitment;
use RuntimeException;

/**
 * The conditional counter update matched no rows (spec AVL-43.2, AVL-44).
 *
 * **The exception the whole engine exists to be able to throw.** It means the
 * `WHERE capacity - seats_sold - seats_held >= :n` clause was false at the
 * moment of writing — the seats were there when this transaction read the row
 * and gone when it tried to take them.
 *
 * Thrown from {@see SeatCommitment}'s callers, inside the transaction, so the
 * whole confirmation rolls back. Nothing partial survives: no payment row, no
 * status change, no counter movement.
 *
 * ## It maps to 409, not 500
 *
 * `docs/api.md` §4.2's `insufficient_capacity`. This is not a failure of the
 * system; it is the system working. Somebody was faster, and the guest needs to
 * be told that in a sentence they can act on rather than shown an error page.
 *
 * The message comes from a lang file in both languages (CNV-11).
 */
final class CapacityExceeded extends RuntimeException
{
    /** The API error code this maps to (`docs/api.md` §4.2). */
    public const CODE = 'insufficient_capacity';

    private function __construct(string $message, public readonly int $requestedSeats)
    {
        parent::__construct($message);
    }

    public static function forDeparture(int $seats): self
    {
        return new self((string) trans('booking.capacity.exceeded', ['seats' => $seats]), $seats);
    }
}
