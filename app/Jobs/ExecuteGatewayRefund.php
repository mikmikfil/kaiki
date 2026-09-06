<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payments\Gateways\GatewayCallFailed;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Events\BookingRefunded;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Brick\Money\Money;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The gateway half of a refund (spec CXL-10, PAY-12).
 *
 * ## Asynchronous, and unique per payment row
 *
 * CXL-10 asks for all three properties and each one is a line here.
 * **Asynchronous** because a cancellation must not wait on somebody else's
 * network — AVL-46 forbids the call inside the lock and a guest pressing cancel
 * should not watch a spinner while Viva thinks about it. **Idempotent per
 * `Payment` row** is `ShouldBeUnique` on the row id plus the status check
 * below: two dispatches for one row produce one call, and a retry of a job
 * whose call already settled produces none.
 *
 * ## A refusal is not an exception, and neither is treated as success
 *
 * `RefundResult` carries `succeeded: false` for the ordinary refusals — the
 * charge is too old, the balance is short, it has already been refunded — and
 * {@see GatewayCallFailed} is thrown only when the gateway could not be
 * reached. Those are different problems: the first needs an operator, the
 * second needs a retry.
 *
 * **Neither marks the booking refunded.** CXL-10 says so in as many words, and
 * it is the failure this job exists to avoid: a booking that says `refunded`
 * while the money is still in the operator's account is a dispute the operator
 * loses without knowing why.
 *
 * ## The error feed is the failed row plus the code, in both languages
 *
 * PAY-12: raw gateway text never reaches anybody. The code goes through
 * `describeError()`, which produces an operator sentence and a guest sentence
 * in Greek and English, and `failure_code` is stored so the feed can produce
 * them again later without keeping the prose.
 */
class ExecuteGatewayRefund implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then the failure feed.
     *
     * The same reasoning {@see ProcessGatewayWebhook} gives: a refund that has
     * failed three times with backoff is failing for a reason a fourth attempt
     * will not fix, and a person can replay it — which is possible precisely
     * because the row was written before the call.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $paymentId,
        public readonly ?string $reason = null,
    ) {}

    /** One job per refund row, which is CXL-10's "idempotent per Payment row". */
    public function uniqueId(): string
    {
        return 'gateway-refund:' . $this->paymentId;
    }

    public function handle(GatewayResolver $gateways): void
    {
        /** @var Payment|null $payment */
        $payment = Tenancy::withoutTenancy(
            fn (): ?Payment => Payment::query()->find($this->paymentId),
        );

        if ($payment === null || $payment->kind !== PaymentKind::Refund) {
            return;
        }

        if ($payment->status === PaymentStatus::Succeeded) {
            // Already settled. A retry of a job whose call went through must
            // not take the money out a second time.
            return;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($payment->tenant_id),
        );

        if ($tenant === null) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($payment, $gateways): void {
            $this->refund($payment, $gateways);
        });
    }

    private function refund(Payment $payment, GatewayResolver $gateways): void
    {
        $source = $payment->refunds;
        $booking = $payment->booking;

        if (! $source instanceof Payment || ! $booking instanceof Booking) {
            $this->markFailed($payment, 'refund_source_missing');

            return;
        }

        $gateway = $gateways->named($payment->gateway);
        $amount = Money::ofMinor($payment->amount_cents, $payment->currency);

        // Outside any transaction, deliberately (AVL-46). The row lock is taken
        // afterwards, to record what came back.
        $result = $gateway->refund($source, $amount);

        if (! $result->succeeded) {
            $this->markFailed($payment, $result->code ?? 'refund_declined');

            return;
        }

        DB::transaction(function () use ($payment, $booking, $result): void {
            $payment->forceFill([
                'status' => PaymentStatus::Succeeded,
                'gateway_transaction_ref' => $result->reference,
                'refunded_at' => now(),
                'failure_code' => null,
            ])->save();

            $this->settle($booking);
        });

        BookingRefunded::dispatch($booking->refresh(), $payment->amount_cents, RefundMethod::Cash, $this->reason);
    }

    /**
     * Recompute the booking's money from its payment rows (PAY-10).
     *
     * Never incremented: `paid_cents` is the sum of succeeded charges minus
     * succeeded refunds, and {@see Payment::paidCentsFor()} is the one place
     * that sum is written down. A booking whose refund settled for everything
     * it paid becomes `refunded`; a partial refund leaves it `cancelled`, which
     * is the honest description of a trip that is not happening and money that
     * has only partly gone back.
     */
    private function settle(Booking $booking): void
    {
        $paid = Payment::paidCentsFor($booking->getKey());
        $refunded = Payment::refundedCentsFor($booking->getKey());

        $attributes = [
            'paid_cents' => $paid,
            'refunded_cents' => $refunded,
            'balance_cents' => max(0, $booking->total_cents - $paid),
        ];

        if ($paid < 1 && $booking->status->canTransitionTo(BookingStatus::Refunded)) {
            $attributes['status'] = BookingStatus::Refunded;
        }

        $booking->forceFill($attributes)->save();
    }

    /**
     * CXL-10's other half: visible, and not marked refunded.
     *
     * The row goes to `failed` with the gateway's own code, which is what the
     * operator feed reads and what `describeError()` turns into two sentences
     * in two languages. The booking is left exactly as it was.
     */
    private function markFailed(Payment $payment, string $code): void
    {
        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failure_code' => $code,
        ])->save();

        // The id and the code. A booking carries a guest's name, email and
        // phone, and a job that logs the row writes all three on every failure.
        Log::warning('payments.refund_failed', [
            'payment_id' => $payment->getKey(),
            'booking_id' => $payment->booking_id,
            'tenant_id' => $payment->tenant_id,
            'gateway' => $payment->gateway->value,
            'code' => $code,
        ]);
    }

    /** An unreachable gateway is a retry; the feed gets it after `$tries`. */
    public function failed(GatewayCallFailed $exception): void
    {
        Log::error('payments.refund_abandoned', [
            'payment_id' => $this->paymentId,
            'exception' => $exception::class,
        ]);
    }
}
