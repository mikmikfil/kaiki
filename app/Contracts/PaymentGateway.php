<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Payments\Data\RedirectTarget;
use App\Domain\Payments\Data\RefundResult;
use App\Domain\Payments\Data\TranslatableMessage;
use App\Enums\PaymentKind;
use App\Models\Booking;
use App\Models\Payment;
use Brick\Money\Money;
use Illuminate\Http\Request;

/**
 * Taking an operator's money on their own account (spec PAY-1, PAY-2 FIXED,
 * ADR-0004 Option A + D).
 *
 * ## Exactly four methods, and a fifth is an ADR
 *
 * ADR-0004 item 2 fixes the shape, and the reason is not minimalism. **No
 * card-on-file, no stored mandate, no off-session charging** — Stripe supports
 * all three and Viva Smart Checkout supports none of them, so a contract that
 * included them would have one implementation throwing on half its surface.
 * That is not an abstraction; it is a Stripe client with a Viva-shaped hole.
 *
 * The consequence is ADR-0004 Option D and it runs through the whole product: a
 * deposit and a balance are **two independent checkout sessions**, months apart
 * if need be, because "create a checkout session" is the only primitive both
 * gateways actually share. Two gateway fees instead of one is the accepted
 * cost, and PRC-27's balance reminders are mandatory rather than optional
 * because of it.
 *
 * Adding a fifth method is an ADR, not a commit.
 *
 * ## The platform never holds guest money
 *
 * PAY-1, FIXED. Every call here is made with the **operator's own credentials**
 * from `integration_credentials` (#79), against their own merchant account.
 * There is no Stripe Connect, no platform balance and no payout — which is also
 * why `refund()` can only ever return the operator's own money.
 *
 * ## Where the two gateways disagree, they disagree inside their own class
 *
 * They disagree about nearly everything at the edges: Viva returns an order
 * code rather than a URL and has to build the redirect itself; its webhook
 * envelope differs per event type; Stripe signs with a timestamped HMAC and
 * Viva does not sign at all. **None of that widens this interface.** Absorbing
 * it is what the abstraction is for, and the moment a `if ($gateway === 'viva')`
 * appears outside `VivaSmartCheckoutGateway` the abstraction has failed.
 */
interface PaymentGateway
{
    /**
     * Send a guest somewhere they can pay.
     *
     * The `Payment` row must already exist in `pending` with its idempotency
     * key (PAY-8, PAY-9) — minted **before** this call, because the dangerous
     * retry is the one where no response ever came back and a key derived from
     * a response cannot dedupe the request that produced it.
     */
    public function createCheckoutSession(Booking $booking, PaymentKind $kind, Money $amount): RedirectTarget;

    /**
     * Is this inbound request genuinely from the gateway (PAY-5, PAY-6)?
     *
     * Called **before any parsing**, on the raw body. A payload parsed before
     * it is verified is a payload an attacker chose.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Give money back, up to what was actually taken.
     *
     * The *policy* arithmetic is not here — how much a guest is owed comes from
     * the booking's frozen policy snapshot (CXL-2) and lands with cancellation.
     * This moves the amount it is given.
     */
    public function refund(Payment $payment, Money $amount): RefundResult;

    /**
     * Turn a gateway's error code into something a person can act on (PAY-12).
     *
     * Two audiences, two sentences: the guest gets something generic and
     * reassuring, the operator something specific and actionable. **Raw gateway
     * text never reaches a guest** — it is written in English, for a developer,
     * by a company the guest has never heard of.
     */
    public function describeError(string $code): TranslatableMessage;
}
