<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Availability\Support\HoldLock;
use RuntimeException;

/**
 * Another writer held the hold mutex longer than we were willing to wait
 * (spec AVL-37.2, ADR-0005).
 *
 * ## Why this is an explicit failure rather than a longer wait
 *
 * AVL-37 asks for the block to fail *"with an explicit timeout error"*. Waiting
 * indefinitely turns a contended seat into a request that hangs until the web
 * server kills it, which a guest experiences as a broken site and an operator
 * hears about as one — while the actual situation is ordinary and temporary:
 * somebody else is a few hundred milliseconds ahead of them.
 *
 * ## It is not "sold out", and the message must not say so
 *
 * The seat may well still be free. Telling a guest it is gone would send them
 * away from a booking they could still make, so the sentence says to try again
 * rather than to give up — and it comes from a lang file in both languages
 * (CNV-11), never from this class.
 *
 * The key is carried for the log and never for the guest: it names a departure
 * id, which is an internal identifier the guest has no use for and CNV-8 keeps
 * out of anything guest-facing.
 */
final class HoldLockUnavailable extends RuntimeException
{
    private function __construct(string $message, public readonly string $lockKey)
    {
        parent::__construct($message);
    }

    /** @param  string  $key  from {@see HoldLock::forDeparture()} or {@see HoldLock::forVessel()} */
    public static function forKey(string $key): self
    {
        return new self((string) trans('booking.hold.contended'), $key);
    }
}
