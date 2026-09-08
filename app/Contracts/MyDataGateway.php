<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Compliance\Data\MyDataResult;
use App\Models\Invoice;

/**
 * Sends one document to AADE and reports what came back (spec MYD-1, MYD-5,
 * MYD-11, MYD-14, MYD-15).
 *
 * ## Why this is a seam
 *
 * Three reasons, and the third is the one that decided it.
 *
 * **MYD-11** selects a development or production endpoint *per environment,
 * never per request*. That is a wiring decision, and a wiring decision belongs
 * behind an interface rather than inside an `if` in the middle of a job.
 *
 * **MYD-1** issues under the *operator's* credentials — the platform is never
 * the issuer — so the object that holds a connection is per tenant and
 * resolved, not injected once at boot.
 *
 * **And the credentials do not exist yet.** The product owner has no AADE test
 * account today, which would idle the whole milestone if the client were welded
 * into the issuing path. Behind this interface, the null implementation lets
 * every other part of M6 be built and proved, and a credential arriving in
 * November is a config change rather than a rewrite. That is the same argument
 * `App\Contracts\SmsGateway` was written for, and it held there.
 *
 * ## What an implementation must not do
 *
 * **Never throw for a refusal.** AADE saying no is an answer, and
 * {@see MyDataResult::refused()} carries it. An exception would put a
 * predictable business outcome into the retry ladder, where it would be tried
 * eight times over a day and appear to an operator as an outage.
 *
 * **Never let a credential out.** MYD-15. `rawResponse` is stored on the
 * invoice for support, so the implementation redacts before it returns, not
 * afterwards and not somewhere else.
 */
interface MyDataGateway
{
    /**
     * Submit the document. Returns an outcome; does not throw for a refusal.
     *
     * The invoice arrives with its number already allocated (MYD-4.2) — the
     * gateway sends, it does not decide.
     */
    public function submit(Invoice $invoice): MyDataResult;

    /**
     * Which AADE endpoint this instance talks to.
     *
     * MYD-11 requires the panel to display it, so an operator can see they are
     * in test mode rather than discovering it from an empty tax register.
     * `live` or `dev`, matching `invoices.environment`.
     */
    public function environment(): string;
}
