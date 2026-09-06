<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Gateways;

use App\Contracts\SmsGateway;
use App\Domain\Notifications\Data\SmsResult;
use App\Enums\NotificationProvider;

/**
 * The platform's fallback (spec NTF-2, FIXED).
 *
 * ## It is a provider, not an error path
 *
 * NTF-2 says the platform *"provides a fallback"*, and this is it. A tenant who
 * has not configured SMS still gets every message **composed, counted and
 * logged**, with `null_gateway` in the provider column — so an operator looking
 * at their own notification log can see that their reminders are being written
 * and dropped, which is a problem they can fix in five minutes.
 *
 * The alternative — throwing, or skipping the send silently — produces a tenant
 * whose guests never get a text and whose log says nothing about why. That is
 * indistinguishable, from the operator's side, from a broken platform.
 *
 * ## It still returns a reference
 *
 * So that the row is complete and the code path is identical. A log row with a
 * null reference beside three that have one reads as a failure; a row that says
 * `null:…` reads as what it is.
 */
final class NullSmsGateway implements SmsGateway
{
    public function send(string $to, string $body): SmsResult
    {
        // Deliberately nothing. The composition already happened, the log row
        // already exists, and the operator can see both.
        return SmsResult::swallowed();
    }

    public function provider(): NotificationProvider
    {
        return NotificationProvider::NullGateway;
    }
}
