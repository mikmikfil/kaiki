<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Domain\Payments\Data\RedirectTarget;
use App\Domain\Payments\Data\RefundResult;
use App\Domain\Payments\Data\TranslatableMessage;
use App\Domain\Payments\Support\GatewayErrorDictionary;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Models\Booking;
use App\Models\Payment;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A gateway that takes no money and calls nothing (spec SAA-9, TST-4).
 *
 * ## This is a deliverable, not test scaffolding
 *
 * Three real things depend on it. #81 confirms bookings through it, which is
 * what lets the AVL-44 overselling guarantee be tested without a network. M3's
 * Playwright run needs a checkout that completes deterministically. And SAA-9's
 * onboarding wizard ends with *"a test booking in sandbox mode"* — an operator
 * finishing setup should see the flow work before they have a merchant account.
 *
 * ## The contract test runs against all three implementations, including this
 *
 * Which is the point. A fake that drifts from the real gateways is a fake that
 * passes while production does not — and since this one carries the concurrency
 * work, that drift would make AVL-44 a test of nothing. Running one shared
 * contract test runs against Viva and this, which is what keeps it honest.
 *
 * ## It fails on demand, because the failure paths need exercising too
 *
 * A fake that only succeeds tests half a checkout. `failNext()` makes the next
 * session throw, so PAY-12's error dictionary and the operator's failure feed
 * can be exercised without provoking a real gateway into declining a card.
 */
final class FakeGateway implements PaymentGateway
{
    /**
     * Where a sandbox checkout sends the browser.
     *
     * **This used to be `https://gateway.kaiki.test`, unresolvable on purpose**,
     * and that was right while there was nowhere real to go: a fixture leaking
     * into a browser failed visibly rather than reaching a stranger.
     *
     * Issue 111 gave it somewhere real. SAA-9 ends onboarding with *"a test
     * booking in sandbox mode"*, and a test booking that redirects to a host
     * that cannot resolve is a promise the product could not keep — the
     * operator sees a browser error where a payment should be. The destination
     * is now a page **we serve**, on our own origin, which refuses anything but
     * a test booking.
     */
    public static function checkoutUrl(string $reference): string
    {
        return route('sandbox.checkout', ['reference' => $reference]);
    }

    private ?string $nextFailureCode = null;

    private bool $refundsFail = false;

    /**
     * The next `createCheckoutSession()` throws with this gateway code.
     *
     * A code rather than a boolean, so a test can drive a *specific* dictionary
     * entry rather than only the unmapped path.
     */
    public function failNext(string $code = 'processing_error'): void
    {
        $this->nextFailureCode = $code;
    }

    public function refundsAlwaysFail(bool $fail = true): void
    {
        $this->refundsFail = $fail;
    }

    public function createCheckoutSession(Booking $booking, PaymentKind $kind, Money $amount): RedirectTarget
    {
        if ($this->nextFailureCode !== null) {
            $code = $this->nextFailureCode;
            $this->nextFailureCode = null;

            throw GatewayCallFailed::forCode(PaymentGatewayName::Viva, $code);
        }

        $reference = 'fake_' . Str::lower(Str::random(24));

        return new RedirectTarget(
            // Our own sandbox page, which refuses anything that is not a test
            // booking. See {@see self::checkoutUrl()} for why this stopped
            // being an unresolvable host.
            url: self::checkoutUrl($reference),
            reference: $reference,
            context: [
                'booking_uuid' => $booking->uuid,
                'kind' => $kind->value,
                'amount_cents' => (int) $amount->getMinorAmount()->toInt(),
            ],
        );
    }

    /**
     * Always true.
     *
     * A fake that verified signatures would need a fake signing secret and a
     * fake signature algorithm, which is a second implementation to keep in
     * step for no benefit — the real verification is tested against recorded
     * fixtures in the real gateways, where it belongs.
     */
    public function verifyWebhook(Request $request): bool
    {
        return true;
    }

    public function refund(Payment $payment, Money $amount): RefundResult
    {
        if ($this->refundsFail) {
            return RefundResult::failure('charge_already_refunded');
        }

        return RefundResult::success(
            refundedCents: (int) $amount->getMinorAmount()->toInt(),
            reference: 'fake_re_' . Str::lower(Str::random(20)),
        );
    }

    /**
     * Borrows the real gateway's dictionary rather than inventing one.
     *
     * A second vocabulary would be a second thing to keep in step, and the
     * codes a fake produces are only ever the ones a test asked for.
     */
    public function describeError(string $code): TranslatableMessage
    {
        return GatewayErrorDictionary::describe(PaymentGatewayName::Viva, $code);
    }
}
