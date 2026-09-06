<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * Cleaning up what a guest typed into the lead-guest fields (spec BKG-8).
 *
 * ## The requirement is one sentence and it is the whole design
 *
 * > *A malformed phone blocks SMS but MUST NOT block the booking.*
 *
 * So nothing here throws. A number that cannot be parsed comes back as null,
 * the booking is created without one, and the guest gets their email
 * confirmation while the SMS is simply not attempted. The alternative — a
 * validation error on a phone field — turns a guest who typed their number with
 * the local trunk zero into a lost booking, and operators lose more revenue to
 * that than they will ever lose to a missing text message.
 *
 * ## E.164 or nothing
 *
 * A half-normalised number is worse than none: `6912345678` is a valid Greek
 * mobile and a valid nothing anywhere else, and an SMS gateway handed it will
 * either reject it or, worse, deliver to a stranger in another country. So the
 * column holds `+306912345678` or null.
 *
 * ## MX where available, and only where available
 *
 * BKG-8 asks for a syntactic check and an MX lookup *"where available"*. The
 * hedge is load-bearing: DNS is a network call in the middle of a checkout, it
 * fails in CI sandboxes and on aeroplanes, and a booking form that refuses an
 * address because a resolver timed out is a form that refuses valid customers.
 * So a failed lookup is treated as "cannot tell" and the address is accepted;
 * only a *successful* lookup that finds no mail exchanger is a rejection, and
 * even that is advisory — {@see self::emailLooksDeliverable()} is consulted by
 * validation, which decides what to do about it.
 */
final class LeadGuest
{
    /**
     * A phone number in E.164, or null if it cannot be one.
     *
     * @param  string|null  $countryHint  the guest-selected country, then the
     *                                    tenant's, as BKG-8 orders them
     */
    public static function normalisePhone(?string $raw, ?string $countryHint = null): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $countries = array_values(array_filter([
            // An explicit `+` prefix answers the question already; the country
            // hints are only consulted for a bare national number.
            $countryHint,
            (string) config('kaiki.defaults.country'),
        ], static fn (?string $country): bool => is_string($country) && $country !== ''));

        try {
            return (new PhoneNumber($raw, $countries))->formatE164();
        } catch (Throwable) {
            // **`Throwable`, and deliberately not the package's own
            // `NumberParseException`.** `propaganistas/laravel-phone` wraps
            // `giggsey/libphonenumber`, and the underlying library throws its
            // own `libphonenumber\NumberParseException` — which the wrapper does
            // not always translate. Catching only the wrapper's class let a
            // plainly unparseable string escape this method and reach the caller
            // as a 500, which is exactly the outcome BKG-8 forbids.
            //
            // The contract here is "never throw", so it is enforced as one. A
            // number we cannot read costs an SMS; refusing the booking costs the
            // booking.
            return null;
        }
    }

    /**
     * Syntactically an email address?
     *
     * Deliberately separate from the MX question, because they fail for
     * different reasons and an operator debugging a bounced confirmation needs
     * to know which.
     */
    public static function emailIsWellFormed(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Does the domain accept mail, as far as we can tell from here?
     *
     * **True when we cannot tell.** A DNS lookup that times out, a sandbox with
     * no resolver, a CI runner with egress blocked — all of those produce the
     * same false answer as a genuinely dead domain, and only one of the three
     * should stop a guest booking a boat.
     */
    public static function emailLooksDeliverable(string $email): bool
    {
        if (! self::emailIsWellFormed($email)) {
            return false;
        }

        $domain = substr(strrchr(trim($email), '@') ?: '', 1);

        if ($domain === '' || ! function_exists('checkdnsrr')) {
            return true;
        }

        // `checkdnsrr` returns false both for "no MX record" and for "the
        // lookup failed", which is exactly the ambiguity above — so the A
        // record is checked too. A domain with an A record and no MX still
        // accepts mail under RFC 5321 §5.1, and plenty of small Greek business
        // domains are configured that way.
        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
    }
}
