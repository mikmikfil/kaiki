<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Payments\Data\GatewayTransaction;
use App\Domain\Payments\Gateways\GatewayCallFailed;
use App\Models\IntegrationCredential;

/**
 * A gateway that can be *asked* what happened to a payment.
 *
 * Webhooks are how a payment is normally confirmed, and they are a delivery
 * somebody else has to make: a guest closes the tab on the gateway's page, a
 * proxy eats the call, a tunnel dies mid-flight, a webhook is registered for the
 * wrong event — each leaves money taken and a booking unconfirmed, which is the
 * worst state this system has. Every one of those was hit on 2026-09-16 in a
 * single afternoon of testing against a real account.
 *
 * `docs/api.md` item 12 has required this since M0: *"a mandatory server-side
 * re-fetch of the transaction; the webhook body is never trusted for amounts.
 * The re-fetch stands whatever the handshake turns out to be."* This contract is
 * that re-fetch, and it makes webhooks an optimisation rather than the single
 * point of failure they were.
 */
interface ProvidesTransactionStatus
{
    /**
     * What the gateway says about this reference, or null if it knows nothing.
     *
     * Null is *not* "unpaid": it is "no answer", and a caller must leave the
     * payment alone rather than mark it failed. A gateway that is down must not
     * be able to cancel bookings.
     *
     * @param  string  $reference  the gateway's own reference — `payments.gateway_ref`
     *
     * @throws GatewayCallFailed when the call itself fails
     */
    public function transactionFor(IntegrationCredential $credential, string $reference): ?GatewayTransaction;
}
