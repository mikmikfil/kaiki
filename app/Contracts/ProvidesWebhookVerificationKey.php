<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Payments\Gateways\GatewayCallFailed;
use App\Models\IntegrationCredential;

/**
 * A gateway that authenticates its *receiver* rather than signing its messages.
 *
 * Stripe signs the body; there is nothing to fetch and nothing to print back.
 * Viva does the opposite — it calls the webhook address and expects the
 * account's verification key in the answer — so the endpoint has to be able to
 * produce that key on demand, which is a capability and not something every
 * gateway has. Hence a separate contract rather than another method on
 * {@see PaymentGateway} that most implementations would have to refuse.
 *
 * The key is fetched with the operator's own credentials, never asked of them:
 * it is readable with the token their client id and secret already mint, which
 * is why Viva's own plugins ask for those two and nothing more.
 */
interface ProvidesWebhookVerificationKey
{
    /**
     * This credential set's verification key, fetched if it is not stored yet.
     *
     * @throws GatewayCallFailed when the provider refuses
     */
    public function webhookVerificationKey(IntegrationCredential $credential): string;
}
