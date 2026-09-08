<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Contracts\PaymentGateway;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\VivaSmartCheckoutGateway;
use App\Enums\CredentialEnvironment;
use App\Enums\PaymentGatewayName;
use App\Models\Booking;

/**
 * Which gateway takes this booking's money (spec PAY-4, PAY-11).
 *
 * ## The default is data, not configuration
 *
 * An operator with more than one gateway picks with `is_default` (#79), and
 * an operator with exactly one was never asked — {@see CredentialRepository::defaultPaymentGateway()}
 * handles both, and refusing to take a sale over a flag nobody was shown would
 * be absurd.
 *
 * ## The environment comes from the booking, and never from a request
 *
 * PAY-11: *sandbox mode MUST be impossible to enable accidentally on a live
 * tenant.* `bookings.is_test` is written at creation and never changes, so the
 * environment a payment runs in is a fact about the booking rather than a
 * parameter somebody can pass. A caller that could ask for `test` could ask for
 * it on a live booking, and the guest would be charged nothing while the
 * operator watched a confirmation arrive.
 *
 * ## A tenant with no gateway gets the fake, and only in a sandbox
 *
 * SAA-9's onboarding ends with a test booking before the operator has a
 * merchant account. That is the *only* case: on a live booking a missing
 * gateway is a refusal, because a fake that quietly succeeded would confirm a
 * booking nobody paid for.
 */
final class GatewayResolver
{
    public function __construct(
        private readonly CredentialRepository $credentials,
        private readonly FakeGateway $fake,
        private readonly VivaSmartCheckoutGateway $viva,
    ) {}

    /**
     * The gateway for a booking, or null when it cannot be paid.
     *
     * Null rather than an exception: the caller decides whether that is a
     * refusal (a live booking with no gateway) or a zero-total booking that
     * needs no gateway at all (BKG-19).
     */
    public function forBooking(Booking $booking): ?PaymentGateway
    {
        $environment = $this->environmentFor($booking);
        $credential = $this->credentials->defaultPaymentGateway($environment, $booking->tenant_id);

        if ($credential === null) {
            // Sandbox only. A live booking with no configured gateway is
            // unpayable, and saying so is better than pretending.
            return $environment->isTest() ? $this->fake : null;
        }

        // One external gateway today. The match stays a match rather than a
        // constant so that adding a second is a case, which is the property
        // ADR-0004 bought with the interface.
        return $this->named(match ($credential->provider->value) {
            default => PaymentGatewayName::Viva,
        });
    }

    /** A named gateway, for the webhook path where the booking is not yet known. */
    public function named(PaymentGatewayName $gateway): PaymentGateway
    {
        return match ($gateway) {
            PaymentGatewayName::Viva => $this->viva,
            // Cash and bank transfer never call anything (BKG-33). They reach
            // here only through a programming error, and the fake is the
            // harmless answer — it takes no money either.
            default => $this->fake,
        };
    }

    /**
     * The environment, decided by the booking and nothing else.
     *
     * See the class docblock: PAY-11 is the reason this takes no argument.
     */
    public function environmentFor(Booking $booking): CredentialEnvironment
    {
        return $booking->is_test ? CredentialEnvironment::Test : CredentialEnvironment::Live;
    }
}
