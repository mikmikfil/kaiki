<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Pricing\Actions\IssueVoucher;
use App\Domain\Pricing\Actions\RestoreVoucher;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Enums\VoucherReason;
use App\Events\BookingRefunded;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Giving money back, in the two forms it can take (spec CXL-10, PRC-19.2).
 *
 * ## The voucher half settles here and the cash half does not
 *
 * ADR-0017's split, honoured in the order it has to happen in. Voucher value
 * goes back to the voucher with a database write and is done; cash goes back
 * through a gateway, which is somebody else's network, and CXL-10 requires that
 * to be **asynchronous, idempotent per `Payment` row, and visibly failed when
 * it fails**.
 *
 * So this writes a `pending` refund row and queues {@see ExecuteGatewayRefund}.
 * The row exists **before** the call for PAY-9's reason: the dangerous retry is
 * the one where no response ever came back, and an idempotency key derived from
 * a response cannot deduplicate the request that produced it.
 *
 * ## Idempotency is one open refund row per booking
 *
 * CXL-10's *"idempotent per `Payment` row"*. Calling this twice — a double
 * click, a retried job, a cancellation replayed by a webhook — must not put two
 * refunds through the gateway. An existing open refund row is **reused and
 * repriced** rather than duplicated, the same shape {@see MintBalanceSession}
 * uses for the balance session and for the same reason.
 *
 * A refund that has already **succeeded** stops the whole thing: the money is
 * back, and a second one would take it out of the operator's account twice.
 *
 * ## A waiver moves nothing and is still recorded
 *
 * CXL-5's third override. `RefundMethod::Waived` writes no payment row and
 * issues no voucher — but it still dispatches {@see BookingRefunded}, because
 * "the operator kept the money and here is why" is precisely the decision an
 * audit trail exists for. The `override.applied` row from {@see CancelBooking}
 * carries the reason; this one carries the amount that did not move.
 */
final class RefundBooking
{
    public function __construct(
        private readonly RestoreVoucher $restoreVoucher,
        private readonly IssueVoucher $issueVoucher,
    ) {}

    /**
     * @param  RefundEntitlement  $entitlement  the split, computed once (PRC-19.2)
     * @param  RefundMethod  $method  CXL-5's override, or `Cash` for the policy's own answer
     * @return int the cents this call put in motion, cash and voucher together
     */
    public function __invoke(
        Booking $booking,
        RefundEntitlement $entitlement,
        RefundMethod $method = RefundMethod::Cash,
        ?string $reason = null,
    ): int {
        if ($method === RefundMethod::Waived) {
            // Nothing moves. The record is the point — see the class docblock.
            BookingRefunded::dispatch($booking, 0, RefundMethod::Waived, $reason);

            return 0;
        }

        if ($entitlement->isEmpty()) {
            return 0;
        }

        if ($method === RefundMethod::Voucher) {
            return $this->asVoucher($booking, $entitlement->totalCents, $reason);
        }

        // PRC-19.2: the voucher's own share goes back to the voucher whatever
        // the method, because voucher value is not the operator's cash to
        // return. Only the remainder is a gateway refund.
        $restored = ($this->restoreVoucher)($booking, $entitlement->totalCents, $reason);

        $cash = $this->asCash($booking, $entitlement->cashCents, $reason);

        return $restored + $cash;
    }

    /**
     * CXL-5's "issue a voucher instead of cash".
     *
     * The **whole** entitlement becomes credit, not just the cash share: the
     * operator has chosen not to move money at all, and splitting a voucher
     * override across a restoration and a new voucher would leave the guest
     * holding two codes for one decision.
     */
    private function asVoucher(Booking $booking, int $cents, ?string $reason): int
    {
        $issued = ($this->issueVoucher)(
            booking: $booking,
            cents: $cents,
            reason: VoucherReason::OperatorCancellation,
            note: $reason,
        );

        if ($issued === null) {
            return 0;
        }

        BookingRefunded::dispatch($booking, $cents, RefundMethod::Voucher, $reason);

        return $cents;
    }

    /**
     * A `pending` refund row and a queued gateway call.
     *
     * Nothing is marked refunded here. CXL-10: *"a failed refund surfaces in the
     * operator error feed without silently marking the booking refunded"* — so
     * `bookings.refunded_cents`, the booking's status and
     * {@see BookingRefunded} all wait for {@see ExecuteGatewayRefund} to come
     * back with a settlement.
     */
    private function asCash(Booking $booking, int $cents, ?string $reason): int
    {
        if ($cents < 1) {
            return 0;
        }

        $source = $this->sourcePaymentFor($booking);

        if ($source === null) {
            // Nothing was ever taken through a gateway — a booking marked paid
            // in cash, or one whose whole price was a voucher. There is no
            // charge to reverse, and inventing a refund row against no payment
            // would put an unsettleable job in the queue forever.
            return 0;
        }

        $refund = DB::transaction(function () use ($booking, $source, $cents): ?Payment {
            $settled = Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('kind', PaymentKind::Refund->value)
                ->where('status', PaymentStatus::Succeeded->value)
                ->exists();

            if ($settled) {
                // Already given back. See the class docblock: a second one takes
                // it out of the operator's account twice.
                return null;
            }

            $existing = Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('kind', PaymentKind::Refund->value)
                ->open()
                ->latest('id')
                ->first();

            if ($existing instanceof Payment) {
                $existing->forceFill(['amount_cents' => $cents])->save();

                return $existing;
            }

            $payment = new Payment;

            $payment->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->getKey(),
                'gateway' => $source->gateway,
                'kind' => PaymentKind::Refund,
                'amount_cents' => $cents,
                'currency' => $source->currency,
                'status' => PaymentStatus::Pending,
                // §2.5: which charge this reverses. The gateway needs it, and so
                // does an operator reconciling two rows against one statement.
                'refunds_payment_id' => $source->getKey(),
                // PAY-9. Minted before the call, never derived from a response.
                'idempotency_key' => (string) Str::uuid(),
            ])->save();

            return $payment;
        });

        if (! $refund instanceof Payment) {
            return 0;
        }

        ExecuteGatewayRefund::dispatch($refund->getKey(), $reason);

        return $cents;
    }

    /**
     * The charge this refund reverses.
     *
     * The **largest settled incoming payment**, not the latest: a booking with a
     * deposit and a balance has two, and a partial refund reversed against the
     * smaller one can exceed it and be declined by the gateway for a reason
     * that has nothing to do with the guest. Cash and bank transfer are excluded
     * because there is no gateway to call.
     */
    private function sourcePaymentFor(Booking $booking): ?Payment
    {
        return Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereIn('kind', [
                PaymentKind::Full->value,
                PaymentKind::Deposit->value,
                PaymentKind::Balance->value,
            ])
            ->whereIn('gateway', array_map(
                static fn (PaymentGatewayName $gateway): string => $gateway->value,
                array_filter(PaymentGatewayName::cases(), static fn (PaymentGatewayName $g): bool => $g->isExternal()),
            ))
            ->orderByDesc('amount_cents')
            ->first();
    }
}
