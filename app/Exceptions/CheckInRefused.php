<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Booking\Actions\CheckInGuest;
use App\Enums\BookingStatus;
use RuntimeException;

/**
 * The scan did not check anybody in (spec BKG-20, BKG-21, BKG-22).
 *
 * Thrown from {@see CheckInGuest}. Every one of these reaches a **crew member
 * standing on a quay** in front of somebody holding a phone, which is why they
 * are not {@see IllegalStateTransition}: that class says so itself — it is a
 * developer's message, and the refusals a person reads are separate classes
 * with sentences from lang files (CNV-11).
 *
 * Each constructor is a different thing to do next, and that is the reason they
 * are kept apart rather than collapsed into "cannot check in":
 *
 * - **not opened yet** — wait, or get a manager to override with a reason
 *   (BKG-22). The only refusal here with a way through.
 * - **already ended** — send them to the office; there is no override, because
 *   a check-in recorded after the boat came back is a false manifest.
 * - **wrong status** — the booking was cancelled or never paid for. The crew
 *   member cannot fix that on the pier and needs to know it is not their
 *   problem to solve.
 * - **unknown ticket** — a code that resolves to nothing. Deliberately the same
 *   sentence whether the code never existed or the booking was deleted; see the
 *   note on {@see self::unknownTicket()}.
 */
final class CheckInRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /** BKG-22's early edge — the one an override can rescue. */
    public static function windowNotOpen(string $opensAtLocal): self
    {
        return new self(
            (string) trans('checkin.refused.not_open', ['time' => $opensAtLocal]),
            'window_not_open',
        );
    }

    /** BKG-22's late edge. No override reaches this. */
    public static function tripHasEnded(): self
    {
        return new self((string) trans('checkin.refused.trip_ended'), 'trip_ended');
    }

    /** Cancelled, unpaid, or still a quote. */
    public static function bookingNotCheckable(BookingStatus $status): self
    {
        return new self(
            (string) trans('checkin.refused.status', ['status' => $status->label()]),
            'status',
        );
    }

    /**
     * A ticket code that resolves to nothing.
     *
     * One sentence for "no such code" and for "the booking behind it is gone",
     * the same reasoning TOK-4 applies to the guest pages: a scanner that said
     * *"that code exists but its booking was deleted"* would confirm a valid
     * code to somebody holding a printed forgery. A crew member does not need
     * the distinction; whoever is standing there goes to the office either way.
     */
    public static function unknownTicket(): self
    {
        return new self((string) trans('checkin.refused.unknown_ticket'), 'unknown_ticket');
    }
}
