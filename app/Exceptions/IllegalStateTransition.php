<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingStatus;
use RuntimeException;

/**
 * A status change §4.1's table does not allow.
 *
 * §4's convention: *"every Action asserts the transition is legal
 * (`$from->canTransitionTo($to)`) and throws `IllegalStateTransition`
 * otherwise. The allowed map lives on the enum, so it is testable in
 * isolation."*
 *
 * ## Why this is an exception and not a silent no-op
 *
 * Because every one of these is a bug, and the bugs are expensive: confirming
 * an already-cancelled booking, checking in a guest whose payment failed,
 * expiring a booking somebody has already paid for. A no-op would let a
 * double-delivered webhook quietly re-confirm a refunded booking and leave two
 * plausible states with no record of which was intended.
 *
 * The message names both states because "invalid transition" is the one thing
 * that cannot be debugged from a log line. It is deliberately a developer's
 * message: no path reaches this that a guest should ever see, and the guest-
 * facing refusals ({@see CapacityExceeded}, {@see HoldRefused}) are separate
 * classes with lang-file sentences.
 */
final class IllegalStateTransition extends RuntimeException
{
    private function __construct(string $message, public readonly string $from, public readonly string $to)
    {
        parent::__construct($message);
    }

    public static function forBooking(BookingStatus $from, BookingStatus $to): self
    {
        return new self(
            sprintf('A booking cannot go from %s to %s (docs/data-model.md §4.1).', $from->value, $to->value),
            $from->value,
            $to->value,
        );
    }
}
