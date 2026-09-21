<?php

declare(strict_types=1);

namespace App\Domain\Channels\Data;

/**
 * The Basic-auth pair we just minted, in the clear, for the only moment it exists.
 *
 * ## It is returned rather than stored, and that is the whole design
 *
 * Only the sha256 of the password reaches the database (ADR-0013's rule for API
 * keys, applied here for the same reasons). So this object is the single moment
 * in the system's life where the password is readable, and the screen that
 * receives it must show it then or never.
 *
 * There is deliberately no way back. An operator who loses it presses the
 * button again and pastes the new pair into GetYourGuide — which costs them a
 * minute, against a password that cannot leak from a database dump, a log line
 * or a support ticket, because it is not there to leak.
 */
final class InboundCredentialsData
{
    public function __construct(
        /** What GetYourGuide sends as the Basic username. Stored; also our tenant lookup. */
        public readonly string $username,
        /** The password. Stored only as a hash — this is the one and only sight of it. */
        public readonly string $password,
    ) {}

    /** The last four, which is all the operator sees on the screen afterwards. */
    public function lastFour(): string
    {
        return substr($this->password, -4);
    }
}
