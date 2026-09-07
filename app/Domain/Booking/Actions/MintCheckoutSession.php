<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Payments\Data\RedirectTarget;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Exceptions\CheckoutRefused;
use App\Models\Booking;
use App\Models\Payment;
use Brick\Money\Money;

/**
 * `POST /bookings/{uuid}/checkout`, end to end (`docs/api.md` §5; ADR-0004).
 *
 * ## Why this exists beside {@see StartCheckout}
 *
 * `StartCheckout` is the part that must not call anything: it moves seats from
 * held to sold under AVL-45's locks, and AVL-46 forbids an external call inside
 * that transaction. It therefore ends with a `pending` payment row and no
 * redirect, and #82's note on it says the caller mints the session afterwards.
 *
 * This is that caller, in one place rather than in a controller and a Blade
 * page and, later, a widget endpoint — three copies of "commit, then call the
 * gateway, then write back the reference" is three chances to write back to the
 * wrong row.
 *
 * ## The three `kind` values are three different situations
 *
 * - **`balance`** is ADR-0004's second session, minted months later against a
 *   `confirmed` booking. It has its own Action ({@see MintBalanceSession})
 *   because it prices the balance *now* rather than at confirmation, and this
 *   delegates rather than duplicating that.
 * - **`deposit`** is refused with `deposit_not_available` where the rate plan
 *   defines none. The contract requires the refusal; the alternative — quietly
 *   charging the full amount — takes more of a guest's money than they agreed
 *   to, which is the worst possible way to be helpful.
 * - **`full`** on a booking that *has* a deposit is a legitimate guest choice:
 *   pay it all now. `StartCheckout` picks the deposit by default, so the row it
 *   created is corrected here — before any gateway has seen it, while it is
 *   still `pending` and still carries the idempotency key PAY-9 minted.
 *
 * ## A zero-total booking returns no redirect, and that is not an error
 *
 * BKG-19: a booking a voucher covers entirely is confirmed by `StartCheckout`
 * without a payment row. The caller gets a null target and renders the booking
 * rather than a gateway URL — sending a guest to a payment page for €0.00
 * produces a checkout that cannot complete.
 */
final class MintCheckoutSession
{
    public function __construct(
        private readonly StartCheckout $startCheckout,
        private readonly MintBalanceSession $mintBalance,
        private readonly GatewayResolver $gateways,
    ) {}

    /**
     * @return array{payment: Payment|null, target: RedirectTarget|null, booking: Booking}
     *
     * @throws CheckoutRefused when the requested kind cannot be paid for this booking
     */
    public function __invoke(Booking $booking, PaymentKind $kind, ?PaymentGatewayName $gateway = null, ?string $returnUrl = null): array
    {
        if ($kind === PaymentKind::Balance) {
            $target = ($this->mintBalance)($booking);

            if ($target === null) {
                throw CheckoutRefused::noBalanceDue();
            }

            return [
                'booking' => $booking->refresh(),
                'payment' => $this->latestPayment($booking, PaymentKind::Balance),
                'target' => $target,
            ];
        }

        if ($kind === PaymentKind::Deposit && ! $this->hasADeposit($booking)) {
            throw CheckoutRefused::depositNotAvailable();
        }

        $result = ($this->startCheckout)($booking, $gateway ?? PaymentGatewayName::Viva);

        $payment = $result['payment'];
        $booking = $result['booking'];

        if (! $payment instanceof Payment) {
            // BKG-19. Confirmed already, nothing to pay, nowhere to send them.
            return ['booking' => $booking, 'payment' => null, 'target' => null];
        }

        if ($kind === PaymentKind::Full && $payment->kind === PaymentKind::Deposit) {
            // The guest asked to settle the whole thing. Still `pending`, still
            // holding PAY-9's key, and no gateway has seen it yet.
            $payment->forceFill([
                'kind' => PaymentKind::Full,
                'amount_cents' => $booking->total_cents,
            ])->save();
        }

        $client = $this->gateways->forBooking($booking);

        if ($client === null) {
            throw CheckoutRefused::noGatewayConfigured();
        }

        $target = $client->createCheckoutSession(
            $booking,
            $payment->kind,
            Money::ofMinor($payment->amount_cents, 'EUR'),
        );

        $payment->forceFill([
            'gateway_ref' => $target->reference,
            // Un-indexed and short-lived (§2.5): what this redirect is built
            // from, never what a link emailed later is built from (ADR-0004).
            'checkout_url' => $target->url,
            // Validated against the key's origins by `CheckoutRequest` before it
            // ever reaches here, because whatever is stored in this column is a
            // place a browser will be sent (issue 111).
            'return_url' => $returnUrl,
        ])->save();

        return ['booking' => $booking, 'payment' => $payment->refresh(), 'target' => $target];
    }

    /**
     * Does the rate plan actually define a deposit for this booking?
     *
     * A `deposit_cents` equal to the total is "pay in full" wearing a deposit's
     * name, and offering it as a deposit would show a guest two buttons that do
     * the same thing.
     */
    private function hasADeposit(Booking $booking): bool
    {
        return $booking->deposit_cents > 0 && $booking->deposit_cents < $booking->total_cents;
    }

    private function latestPayment(Booking $booking, PaymentKind $kind): ?Payment
    {
        return Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', $kind->value)
            ->latest('id')
            ->first();
    }
}
