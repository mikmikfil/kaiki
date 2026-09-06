<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Integrations\Data\VerificationResult;
use App\Domain\Integrations\Support\VerifierRegistry;
use App\Models\IntegrationCredential;

/**
 * Proves a stored credential set actually works (spec EXT-2, CNV-11).
 *
 * ## Why this is a seam and not a method on the gateway
 *
 * Because five of the seven providers are not gateways. myDATA, three SMS
 * vendors and Postmark all need the same question asked — *do these keys
 * authenticate?* — and `App\Contracts\PaymentGateway` (PAY-2) is fixed at four
 * methods by ADR-0004, none of which is this one. Verification is a property of
 * a credential set, so it hangs off the credential.
 *
 * ## What an implementation must and must not do
 *
 * It makes the cheapest authenticated call the provider offers and returns a
 * {@see VerificationResult}. It **never throws** for a refused credential —
 * that is an answer, not an exception — and it never puts a credential value
 * into the failure it returns. The whole point of a verify button is that an
 * operator who has pasted the wrong key finds out here, in Greek, rather than
 * in front of a guest at checkout.
 *
 * Implementations are resolved through {@see VerifierRegistry},
 * which is where a provider without one yet is answered honestly instead of
 * silently reported as verified.
 */
interface CredentialVerifier
{
    public function verify(IntegrationCredential $credential): VerificationResult;
}
