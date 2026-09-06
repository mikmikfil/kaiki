<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Notifications\Data\SmsResult;
use App\Enums\NotificationProvider;

/**
 * Sending a text message (spec NTF-2, FIXED).
 *
 * ## Three implementations, and one of them sends nothing
 *
 * *"`ApifonGateway`, `TwilioGateway` and `NullGateway`; the operator chooses and
 * the platform provides a fallback."* The null one is the fallback, and it is a
 * real implementation rather than an error path: a tenant who has not
 * configured SMS still gets every message **composed, logged and visible**,
 * with `null_gateway` in the provider column. An operator can then see that
 * their reminders are being written and dropped, which is a different problem
 * from reminders that were never attempted — and one they can fix themselves.
 *
 * ## Two methods, and the second is why this is an interface
 *
 * Apifon prices per segment and Twilio prices per message; both report a cost,
 * and neither reports it the same way. `send()` returns what happened;
 * everything else about the two providers — their auth, their envelopes, their
 * error vocabularies — stays inside their own class, exactly as
 * {@see PaymentGateway} keeps Viva's order codes away from Stripe's sessions.
 *
 * The moment an `if ($provider === 'apifon')` appears outside `ApifonGateway`,
 * the abstraction has failed.
 */
interface SmsGateway
{
    /**
     * Send one message.
     *
     * A refusal is a {@see SmsResult}, not an exception — a number that is not
     * a mobile, a message the provider rejected, an account out of credit are
     * all ordinary outcomes an operator needs to be *told about* rather than a
     * 500 in the middle of a reminder sweep. Exceptions are reserved for the
     * provider being unreachable, which is a different problem with a different
     * remedy.
     *
     * @param  string  $to  E.164, normalised by the caller
     */
    public function send(string $to, string $body): SmsResult;

    /** Which provider this is, for the log's `provider` column. */
    public function provider(): NotificationProvider;
}
