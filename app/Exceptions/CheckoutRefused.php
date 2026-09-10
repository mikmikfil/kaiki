<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Booking\Actions\MintCheckoutSession;
use App\Domain\Booking\Actions\StartCheckout;
use RuntimeException;

/**
 * The checkout could not be started (`docs/api.md` §4.2, §5).
 *
 * Thrown from {@see MintCheckoutSession}. Each constructor is a different thing
 * for a guest to do next, which is why they are separate rather than one
 * "checkout failed":
 *
 * - **deposit not available** — the rate plan defines none. The guest should
 *   pay in full, and the client can offer exactly that. Quietly charging the
 *   full amount instead would take more of their money than they agreed to,
 *   which is the worst possible way to be helpful.
 * - **no balance due** — nothing is owed, or the booking is in a state where a
 *   balance cannot be taken. A guest with an old link must not be able to pay
 *   for a trip that is not happening.
 * - **no gateway configured** — the operator has not connected one. This is not
 *   the guest's fault and not something they can fix, so the sentence says to
 *   contact the operator rather than describing a configuration problem.
 * - **lead guest required** — the draft holds seats but nobody has said who
 *   they are for (ADR-0030). The guest should be sent to the checkout page,
 *   which is the form that asks. Filling one in server-side is not an option:
 *   there is nothing to fill it in *from*.
 *
 * Every sentence comes from `lang/*\/api.php` (CNV-11), and `code` is the
 * stable contract clients branch on (§4.1).
 */
final class CheckoutRefused extends RuntimeException
{
    /**
     * `$errorCode`, and not `$code`.
     *
     * `Exception::$code` is an untyped, read-write `int` slot and PHP will not
     * let a subclass narrow it. Reusing the name to carry §4.1's string code
     * looks tidier and is the kind of tidiness that produces an `int` where a
     * caller expected a slug.
     */
    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function depositNotAvailable(): self
    {
        return new self((string) trans('api.errors.deposit_not_available'), 'deposit_not_available');
    }

    public static function noBalanceDue(): self
    {
        return new self((string) trans('api.errors.no_balance_due'), 'no_balance_due');
    }

    public static function noGatewayConfigured(): self
    {
        return new self((string) trans('api.errors.no_gateway_configured'), 'no_gateway_configured');
    }

    /**
     * ADR-0030's invariant, and the reason a draft may have no lead guest.
     *
     * The rule used to be "no draft without a lead guest", which a name typed
     * into an abandoned draft satisfied and which said nothing about the moment
     * money moves. It is now "no payment without one", asserted in
     * {@see StartCheckout} — the single point
     * the API and the hosted checkout page share on the way to a gateway.
     */
    public static function leadGuestRequired(): self
    {
        return new self((string) trans('api.errors.lead_guest_required'), 'lead_guest_required');
    }
}
