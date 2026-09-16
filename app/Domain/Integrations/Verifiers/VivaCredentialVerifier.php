<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Verifiers;

use App\Contracts\CredentialVerifier;
use App\Domain\Integrations\Data\VerificationResult;
use App\Domain\Payments\Gateways\GatewayCallFailed;
use App\Domain\Payments\Gateways\VivaSmartCheckoutGateway;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationCredential;

/**
 * Does this operator's Viva account accept these four values? (PAY-4)
 *
 * ## Why it had to exist before a payment could be taken
 *
 * `verified_at` is not decoration. `IntegrationCredential::usableForRealCall()`
 * requires it, and `GatewayResolver` refuses to hand a checkout an unverified
 * gateway — so with no verifier registered for Viva the timestamp stayed null
 * for ever, every real credential set was treated as unusable, and a tenant in
 * sandbox mode quietly fell through to the fake checkout. A live tenant would
 * have been told its payment could not be started at all. The registry said as
 * much in Greek («ο έλεγχος δεν είναι ακόμη διαθέσιμος»), which was honest, but
 * the consequence of the gap was two screens away from the sentence describing
 * it.
 *
 * ## It checks both pairs, because Viva takes two
 *
 * The OAuth2 client credentials create orders; Merchant ID and API key read the
 * webhook verification key. They are issued on the same dashboard page and are
 * easy to paste into each other's fields, and a check that exercised only one
 * would report success over exactly that mistake. So both are called, and the
 * verify button means *the four values in front of you are the right four*.
 *
 * A refusal is a returned result, never an exception: pressing verify after
 * pasting the wrong key is the ordinary path, not an error.
 */
final class VivaCredentialVerifier implements CredentialVerifier
{
    public function __construct(private readonly VivaSmartCheckoutGateway $gateway) {}

    public function verify(IntegrationCredential $credential): VerificationResult
    {
        try {
            $this->gateway->checkCredentials($credential);
        } catch (GatewayCallFailed $failed) {
            // «Δεν μπορέσαμε να επικοινωνήσουμε» against «απέρριψε αυτά τα
            // στοιχεία». Nothing came back is not the operator's fault and no
            // different key would fix it, so it must not read as a rejection —
            // that sends somebody to re-copy credentials that are already right.
            return VerificationResult::failure(
                $failed->unreachable ? 'integrations.verify.unreachable' : 'integrations.verify.rejected',
                ['provider' => IntegrationProvider::Viva->label()],
                // Viva's own words, kept for the operator's support ticket with
                // them and never shown on their own (PAY-12).
                $failed->unreachable ? null : $failed->getMessage(),
            );
        }

        return VerificationResult::success();
    }
}
