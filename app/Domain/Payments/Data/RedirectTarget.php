<?php

declare(strict_types=1);

namespace App\Domain\Payments\Data;

use Spatie\LaravelData\Data;

/**
 * Where to send a guest to pay, and what to remember about it (ADR-0004.2).
 *
 * ## A URL and a reference, because the two gateways hand back different things
 *
 * Stripe Checkout returns a session id **and** a hosted URL. Viva Smart Checkout
 * returns an **order code** and nothing else — the URL is built from it by the
 * gateway class, which is exactly the kind of difference ADR-0004 refuses to
 * let leak into the contract.
 *
 * So this carries both: the `url` a guest is sent to, and the `reference` we
 * store in `payments.gateway_ref` and later match a webhook against through
 * `payments_gateway_ref_idx`. Without the reference a webhook cannot find its
 * payment; without the URL there is nowhere to send anybody.
 *
 * ## The URL is short-lived and is not the record
 *
 * `payments.checkout_url` stores it un-indexed and PAY-4 item 3 is explicit that
 * emailed balance links point at `/b/{manage_token}` and **never** at a gateway
 * URL that can go stale. This object is what a redirect is built from now, not
 * what a link is built from later.
 */
final class RedirectTarget extends Data
{
    /**
     * @param  string  $url  where the guest goes
     * @param  string  $reference  the gateway's own id for this session, stored
     *                             in `payments.gateway_ref` and matched by webhooks
     * @param  array<string, mixed>  $context  anything the gateway needs to
     *                                         recognise its own session later
     */
    public function __construct(
        public readonly string $url,
        public readonly string $reference,
        public readonly array $context = [],
    ) {}
}
