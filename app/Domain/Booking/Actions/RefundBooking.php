<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Pricing\Actions\IssueVoucher;
use App\Domain\Pricing\Actions\RestoreVoucher;
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
    /**
     * Marks a refund for people taken off a booking that went ahead, on the
     * row's own idempotency key (still a fresh uuid behind it, so PAY-9 holds).
     * It is how a later cancellation tells those apart from its own.
     */
    public const PARTIAL_KEY_PREFIX = 'p-';

    /**
     * Marks the refund of money that arrived when the booking could no longer
     * take it (2026-09-25): a payment finished after the booking expired or was
     * cancelled, or one that paid a balance already settled. «Χρειάζονται
     * προσοχή» finds these by this prefix, and a later cancellation leaves them
     * alone, the same way it leaves {@see self::PARTIAL_KEY_PREFIX} alone.
     */
    public const LATE_KEY_PREFIX = 'late-';

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
        return $this->settle($booking, $entitlement, $method, $reason, partial: false);
    }

    /**
     * Give back part of what a booking that is **still going ahead** paid —
     * people taken off it (2026-09-17).
     *
     * Different from a cancellation's refund in exactly one guard. A
     * cancellation refunds once, and a second settled refund is refused because
     * it would take the money out twice. A booking that shrinks can shrink
     * twice, and each time owes a real, separate amount back; what must not
     * happen is giving back more than is still held. So: never while another
     * refund is still open, and never more than {@see Payment::paidCentsFor()}.
     *
     * Cash only. The voucher-share restoration is a cancellation's arithmetic,
     * and splitting a small partial refund across a voucher and a card is a
     * statement nobody could reconcile.
     */
    public function partial(Booking $booking, int $cents, ?string $reason = null): int
    {
        if ($cents < 1) {
            return 0;
        }

        return $this->asCash($booking, $cents, $reason, partial: true);
    }

    /**
     * Write the refund of one charge that arrived too late, or on top of a
     * booking already paid (2026-09-25). Back to the card it came from, whole
     * or the surplus.
     *
     * Only the row: the caller holds the booking's lock and queues
     * {@see ExecuteGatewayRefund} after commit (AVL-46), for the row returned.
     * Idempotent per charge — a charge that already has a live late refund gets
     * no second one, so a replayed webhook cannot take the money out twice.
     *
     * @return Payment|null the new pending row; null when there is nothing to write
     */
    public function lateRefundRow(Booking $booking, Payment $charge, int $cents): ?Payment
    {
        $cents = min($cents, $charge->amount_cents);

        if ($cents < 1) {
            return null;
        }

        $exists = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', PaymentKind::Refund->value)
            ->where('refunds_payment_id', $charge->getKey())
            ->where('idempotency_key', 'like', self::LATE_KEY_PREFIX . '%')
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Processing->value,
                PaymentStatus::Succeeded->value,
                // A failed one is in the error feed, where a person retries it.
                PaymentStatus::Failed->value,
            ])
            ->exists();

        if ($exists) {
            return null;
        }

        return $this->newRefundRow($booking, $charge, $cents, self::LATE_KEY_PREFIX);
    }

    private function settle(
        Booking $booking,
        RefundEntitlement $entitlement,
        RefundMethod $method,
        ?string $reason,
        bool $partial,
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

        $cash = $this->asCash($booking, $entitlement->cashCents, $reason, $partial);

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
     * The money half: back the way it came, charge by charge.
     *
     * ## Split by where the money is (2026-09-23)
     *
     * Until then this found the single largest card charge and asked the
     * gateway to refund the **whole** amount against it. Two bookings broke:
     *
     * - **Paid only in cash or by transfer.** No card charge, so it returned 0
     *   and wrote nothing. The booking was cancelled, the guest's email said no
     *   money was coming back, and nothing reminded the operator that it was.
     * - **Card deposit, cash balance.** The gateway was asked for more than the
     *   card had ever been charged, refused it, and the refund landed in the
     *   error feed with the operator none the wiser about why.
     *
     * So every settled incoming charge is a source with what is still left on
     * it (its amount less the refunds already written against it), and the
     * amount is laid over them: card charges first, largest first, then the
     * cash and transfer ones.
     *
     * - A **card** share is a `pending` row and a queued
     *   {@see ExecuteGatewayRefund}, exactly as before. Nothing is marked
     *   refunded until the gateway settles it (CXL-10).
     * - A **cash or transfer** share is a `pending` row and **no job**: there is
     *   nobody to call, and the money goes back across a desk or from the
     *   operator's bank. «Χρειάζονται προσοχή» shows it until somebody presses
     *   «Επιστράφηκε» ({@see ConfirmManualRefund}), and the guest's email says
     *   it will come from the operator.
     *
     * ## Idempotent per source
     *
     * One open refund row per charge, reused and repriced — the old "one open
     * row per booking", narrowed to what the gateway actually deduplicates on.
     * An open row whose charge gets no share this time is withdrawn, but only
     * while still `pending`: a `processing` one is in the gateway's hands.
     */
    private function asCash(Booking $booking, int $cents, ?string $reason, bool $partial = false): int
    {
        if ($cents < 1) {
            return 0;
        }

        $refunds = DB::transaction(function () use ($booking, $cents, $partial): array {
            if ($partial) {
                // See `partial()`: one refund in flight at a time, and never more
                // than the booking still holds.
                $open = Payment::query()
                    ->where('booking_id', $booking->getKey())
                    ->where('kind', PaymentKind::Refund->value)
                    ->open()
                    ->exists();

                if ($open || $cents > Payment::paidCentsFor($booking->getKey())) {
                    return [];
                }

                return $this->lay($booking, $cents, self::PARTIAL_KEY_PREFIX, reuse: false);
            }

            $settled = Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('kind', PaymentKind::Refund->value)
                ->where('status', PaymentStatus::Succeeded->value)
                // Not the refunds for people taken off the booking earlier,
                // while it was still going ahead (`partial()`): those must not
                // stop the cancellation's own refund of what is left.
                ->where('idempotency_key', 'not like', self::PARTIAL_KEY_PREFIX . '%')
                // Nor the refund of a payment that came too late: that money
                // was never the booking's to keep, and the policy's refund is
                // still owed on the rest.
                ->where('idempotency_key', 'not like', self::LATE_KEY_PREFIX . '%')
                ->exists();

            if ($settled) {
                // Already given back. See the class docblock: a second one takes
                // it out of the operator's account twice.
                return [];
            }

            return $this->lay($booking, $cents, '', reuse: true);
        });

        $moved = 0;

        foreach ($refunds as $refund) {
            $moved += $refund->amount_cents;

            if ($refund->gateway->isExternal()) {
                ExecuteGatewayRefund::dispatch($refund->getKey(), $reason);
            }
        }

        return $moved;
    }

    /**
     * Lay `$cents` over the booking's charges and write one refund row each.
     *
     * @return list<Payment> the rows now carrying a share
     */
    private function lay(Booking $booking, int $cents, string $keyPrefix, bool $reuse): array
    {
        $open = $reuse
            ? Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('kind', PaymentKind::Refund->value)
                ->where('idempotency_key', 'not like', self::LATE_KEY_PREFIX . '%')
                ->open()
                ->get()
                ->keyBy('refunds_payment_id')
            : collect();

        $rows = [];
        $left = $cents;

        foreach ($this->sourcesFor($booking) as [$source, $refundable]) {
            if ($left < 1) {
                break;
            }

            $existing = $open->get($source->getKey());

            // A row being repriced gives back its own old share first.
            $room = $refundable + ($existing instanceof Payment ? $existing->amount_cents : 0);
            $share = min($left, $room);

            if ($share < 1) {
                continue;
            }

            if ($existing instanceof Payment) {
                $existing->forceFill(['amount_cents' => $share])->save();
                $rows[] = $existing;
                $open->forget($source->getKey());
            } else {
                $rows[] = $this->newRefundRow($booking, $source, $share, $keyPrefix);
            }

            $left -= $share;
        }

        // An open row that got no share this time: withdrawn, unless the
        // gateway already has it.
        foreach ($open as $stale) {
            if ($stale->status === PaymentStatus::Pending) {
                $stale->forceFill(['status' => PaymentStatus::Cancelled])->save();
            }
        }

        return $rows;
    }

    /** A pending refund row against `$source`, keyed before the call (PAY-9). */
    private function newRefundRow(Booking $booking, Payment $source, int $cents, string $keyPrefix = ''): Payment
    {
        $payment = new Payment;

        $payment->forceFill([
            'uuid' => (string) Str::uuid(),
            'booking_id' => $booking->getKey(),
            // The way the money came in is the way it goes out: a card refund
            // for a card charge, cash across the desk for cash.
            'gateway' => $source->gateway,
            'kind' => PaymentKind::Refund,
            'amount_cents' => $cents,
            'currency' => $source->currency,
            'status' => PaymentStatus::Pending,
            // §2.5: which charge this reverses. The gateway needs it, and so
            // does an operator reconciling two rows against one statement.
            'refunds_payment_id' => $source->getKey(),
            // PAY-9. Minted before the call, never derived from a response.
            'idempotency_key' => $keyPrefix . Str::uuid(),
        ])->save();

        return $payment;
    }

    /**
     * Every settled charge, with what is still refundable on it, in the order
     * money goes back: card first, then cash and transfer; largest first.
     *
     * Largest first for the reason the old single-source rule gave: a partial
     * refund reversed against a small deposit can exceed it and be declined by
     * the gateway for a reason that has nothing to do with the guest.
     *
     * "Refundable" is the charge less every refund written against it that
     * has not failed or been withdrawn — open ones included, so two calls in a
     * row cannot both spend the same room.
     *
     * @return list<array{0: Payment, 1: int}>
     */
    private function sourcesFor(Booking $booking): array
    {
        $charges = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereIn('kind', [
                PaymentKind::Full->value,
                PaymentKind::Deposit->value,
                PaymentKind::Balance->value,
            ])
            ->get();

        $spent = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', PaymentKind::Refund->value)
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Processing->value,
                PaymentStatus::Succeeded->value,
            ])
            ->whereNotNull('refunds_payment_id')
            ->get()
            ->groupBy('refunds_payment_id')
            ->map(static fn ($rows): int => (int) $rows->sum('amount_cents'));

        return $charges
            ->sortBy([
                static fn (Payment $a, Payment $b): int => (int) $b->gateway->isExternal() <=> (int) $a->gateway->isExternal(),
                static fn (Payment $a, Payment $b): int => $b->amount_cents <=> $a->amount_cents,
            ])
            ->map(static fn (Payment $charge): array => [
                $charge,
                max(0, $charge->amount_cents - (int) ($spent[$charge->getKey()] ?? 0)),
            ])
            ->values()
            ->all();
    }
}
