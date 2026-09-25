<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Contracts\ProvidesTransactionStatus;
use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Payments\Gateways\GatewayCallFailed;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Ask the gateway about payments the webhook never told us about (PAY-5, item 12).
 *
 * ## Why this exists
 *
 * A webhook is a delivery somebody else has to make. On 2026-09-16, testing
 * against a real Viva demo account, every way it can fail happened in one
 * afternoon: the endpoint refused verification because the key was fetched from
 * the wrong host; the address was registered against «Order Updated», an event
 * that never fires on a payment; and before either, a shared address could not
 * answer the verification call at all. Each time the money moved and the booking
 * sat at «Στη σελίδα πληρωμής» — taken and unconfirmed, which is the worst state
 * this system has, because the guest has paid and nobody knows.
 *
 * `docs/api.md` item 12 has asked for this since M0. It makes the webhook an
 * optimisation — the fast path — rather than the only path.
 *
 * ## What it will not do
 *
 * **It never fails a payment on silence.** A gateway that is down, a credential
 * that stopped working, an order it has never heard of — all produce "no answer",
 * and no answer leaves the payment exactly as it was. Only the gateway saying
 * *this attempt is over and unpaid* fails one, because a reconciler that treats
 * unreachable as unpaid would cancel bookings every time a provider had an
 * outage.
 *
 * **It never confirms an amount it did not check.** The gateway's figure is
 * compared with what the payment is for, and a mismatch is logged and left alone
 * for a person. Confirming a booking against a number nobody compared is the
 * same mistake as trusting a webhook body, made one step later.
 *
 * It is also deliberately dull about ordering: it reuses `ConfirmFromWebhook`,
 * so a payment confirmed here goes through the identical transition, events and
 * notifications as one confirmed by a webhook, and a webhook arriving afterwards
 * is the idempotent no-op it always was (AVL-47).
 */
final class ReconcilePendingPayments
{
    /**
     * How long a payment is left alone before it is asked about.
     *
     * Long enough that an ordinary guest is still on the gateway's page — 3-D
     * Secure on a phone is not quick — and short enough that a missed webhook is
     * caught while the guest is still expecting an email.
     */
    private const GRACE_MINUTES = 5;

    /** How long after that we stop asking, rather than asking about 2019 forever. */
    private const HORIZON_HOURS = 72;

    /**
     * How long a charge closed on our side is still asked about: longer than a
     * gateway page stays payable (`checkout_expiry_minutes`, 60 by default),
     * with room for a late webhook that never came.
     */
    private const CLOSED_WINDOW_MINUTES = 180;

    public function __construct(
        private readonly GatewayResolver $gateways,
        private readonly ConfirmFromWebhook $confirm,
    ) {}

    /**
     * @return array{checked: int, confirmed: int, failed: int, unanswered: int}
     */
    public function __invoke(): array
    {
        $tally = ['checked' => 0, 'confirmed' => 0, 'failed' => 0, 'unanswered' => 0];

        foreach ($this->candidates() as $payment) {
            $tally['checked']++;

            $outcome = $this->reconcile($payment);

            if ($outcome !== null) {
                $tally[$outcome]++;
            } else {
                $tally['unanswered']++;
            }
        }

        return $tally;
    }

    /**
     * One payment, asked about now.
     *
     * The scheduled sweep is for stragglers nobody is watching. This is for the
     * opposite case: a guest is sitting in front of the widget *right now*,
     * their booking says `pending_payment`, and the widget has to decide between
     * two sentences that mean opposite things — «your payment is on its way» and
     * «you have not paid yet». Only the gateway knows which is true, so it is
     * asked at the moment the answer is needed rather than up to five minutes
     * later.
     *
     * @return 'confirmed'|'failed'|null null when the gateway had no settled answer
     */
    public function forPayment(Payment $payment): ?string
    {
        return $this->reconcile($payment);
    }

    /**
     * Every payment old enough to be suspicious and young enough to matter.
     *
     * Outside tenancy, because this runs from the scheduler with no tenant in
     * context and the whole platform's stragglers are the point. Each one is
     * then handled *inside* its own tenant.
     *
     * @return iterable<int, Payment>
     */
    private function candidates(): iterable
    {
        return Tenancy::withoutTenancy(static fn (): iterable => Payment::query()
            ->where(static fn (Builder $query) => $query
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
                // And a charge closed on our side a short while ago — its
                // booking expired or was cancelled, or a card was declined —
                // whose gateway page could still be paid until its timeout
                // (2026-09-25). Asked only whether it was paid after all; see
                // `reconcile()`.
                ->orWhere(static fn (Builder $closed) => $closed
                    ->whereIn('status', [PaymentStatus::Cancelled->value, PaymentStatus::Failed->value])
                    ->where('kind', '!=', PaymentKind::Refund->value)
                    ->where('updated_at', '>=', now()->subMinutes(self::CLOSED_WINDOW_MINUTES))))
            ->whereNotNull('gateway_ref')
            ->where('created_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->where('created_at', '>=', now()->subHours(self::HORIZON_HOURS))
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** @return 'confirmed'|'failed'|null */
    private function reconcile(Payment $payment): ?string
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($payment->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $gateway = $this->gateways->named($payment->gateway);

        if (! $gateway instanceof ProvidesTransactionStatus) {
            // Cash, bank transfer and the fake have nothing to be asked.
            return null;
        }

        return Tenancy::forTenant($tenant, function () use ($payment, $gateway): ?string {
            $credential = $this->credentialFor($payment);

            if (! $credential instanceof IntegrationCredential) {
                return null;
            }

            try {
                $transaction = $gateway->transactionFor($credential, (string) $payment->gateway_ref);
            } catch (GatewayCallFailed $failed) {
                Log::warning('payments.reconcile_unreachable', [
                    'gateway' => $payment->gateway->value,
                    'payment_id' => $payment->getKey(),
                    'reason' => $failed->getMessage(),
                ]);

                return null;
            }

            if ($transaction === null || ! $transaction->settled) {
                return null;
            }

            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true) && ! $transaction->succeeded) {
                // A closed charge that stayed unpaid: nothing to do, and
                // nothing to count every five minutes until the window shuts.
                return null;
            }

            if ($transaction->succeeded && $transaction->amountCents !== $payment->amount_cents) {
                // Not confirmed, not failed, and loud: the gateway took an
                // amount nobody here expected. A person decides what that is.
                Log::error('payments.reconcile_amount_mismatch', [
                    'gateway' => $payment->gateway->value,
                    'payment_id' => $payment->getKey(),
                    'expected_cents' => $payment->amount_cents,
                    'gateway_cents' => $transaction->amountCents,
                ]);

                return null;
            }

            if ($transaction->transactionId !== null && $payment->gateway_transaction_ref === null) {
                // What a refund of this charge will need (2026-09-25).
                $payment->forceFill(['gateway_transaction_ref' => $transaction->transactionId])->save();
            }

            ($this->confirm)($payment, succeeded: $transaction->succeeded);

            Log::info('payments.reconciled', [
                'gateway' => $payment->gateway->value,
                'payment_id' => $payment->getKey(),
                'succeeded' => $transaction->succeeded,
            ]);

            return $transaction->succeeded ? 'confirmed' : 'failed';
        });
    }

    /** The credential set this payment was created against. */
    private function credentialFor(Payment $payment): ?IntegrationCredential
    {
        $provider = $payment->gateway->provider();

        if ($provider === null) {
            return null;
        }

        $booking = $payment->booking;

        if (! $booking instanceof Booking) {
            // An orphan payment (PAY-7). There is nothing to confirm and the
            // row is somebody's to investigate, not this job's to guess at.
            return null;
        }

        // The booking's own environment, never the tenant's current mode: a
        // booking made in sandbox is reconciled against sandbox credentials even
        // if the operator has since gone live (PAY-11 — `is_test` is written at
        // creation and never changes).
        $environment = $this->gateways->environmentFor($booking);

        return IntegrationCredential::query()
            ->where('provider', $provider->value)
            ->where('environment', $environment->value)
            ->first();
    }
}
