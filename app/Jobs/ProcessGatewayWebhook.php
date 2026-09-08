<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Enums\PaymentGatewayName;
use App\Enums\WebhookEventStatus;
use App\Models\GatewayWebhookEvent;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * All the money logic a webhook causes, off the request (spec PAY-6, AVL-47).
 *
 * ## Keyed on the event row, and unique on it
 *
 * PAY-6: *"do all work in a queued job keyed by the gateway event id so replays
 * are no-ops."* `ShouldBeUnique` on the row id closes the second window — the
 * controller's unique index stops a duplicate *row*, and this stops a duplicate
 * *job* for the same row, which two rapid dispatches could otherwise produce.
 *
 * ## It resolves the tenant, rather than assuming one
 *
 * The whole reason `gateway_webhook_events.tenant_id` is nullable. The payment
 * is found through `payments_gateway_ref_idx` — deliberately not tenant-first —
 * and the tenant comes from it, is written back onto the event row, and every
 * subsequent step runs inside it. A job that assumed the ambient tenant would
 * be assuming whatever the previous job on that worker left behind, which is
 * the cross-tenant write #53 paid for learning about.
 *
 * ## An orphan is not a failure
 *
 * PAY-7: a verified webhook for an unknown booking is *stored and surfaced*,
 * not discarded and not retried forever. Somebody has been charged and the
 * money cannot be matched — that needs a person, not a backoff. So it is marked
 * `orphaned`, which is a distinct status from `failed` precisely so the two are
 * distinguishable in the feed.
 */
class ProcessGatewayWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Retries, and why there are only a few.
     *
     * A webhook that fails three times with backoff is failing for a reason a
     * fourth attempt will not fix. It goes to the failure feed, where a person
     * can replay it — which is possible precisely because the row was written
     * before any of this ran.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $eventId) {}

    /** One job per event row, which is one per gateway event id. */
    public function uniqueId(): string
    {
        return 'gateway-webhook:' . $this->eventId;
    }

    public function handle(ConfirmFromWebhook $confirm): void
    {
        /** @var GatewayWebhookEvent|null $event */
        $event = Tenancy::withoutTenancy(
            fn (): ?GatewayWebhookEvent => GatewayWebhookEvent::query()->find($this->eventId),
        );

        if ($event === null || $event->isSettled()) {
            // AVL-47: a replay after processing is a no-op. Not an error, and
            // not a second confirmation.
            return;
        }

        try {
            $this->process($event, $confirm);
        } catch (Throwable $exception) {
            $this->markFailed($event, $exception);

            throw $exception;
        }
    }

    private function process(GatewayWebhookEvent $event, ConfirmFromWebhook $confirm): void
    {
        $reference = $this->referenceFrom($event);
        $payment = $reference === null
            ? null
            : Payment::findByGatewayRef($event->provider, $reference);

        if ($payment === null) {
            // PAY-7's orphan. Somebody has been charged and we cannot say for
            // what — a person, not a retry.
            $this->mark($event, WebhookEventStatus::Orphaned);

            Log::warning('payments.webhook_orphaned', [
                'gateway' => $event->provider->value,
                'event_id' => $event->event_id,
                'reference' => $reference,
            ]);

            return;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($payment->tenant_id),
        );

        if ($tenant === null) {
            $this->mark($event, WebhookEventStatus::Orphaned);

            return;
        }

        // The tenant is written onto the event row now that it is known —
        // §2.7's *"backfilled once the payment is matched"*.
        $event->forceFill(['tenant_id' => $tenant->getKey(), 'payment_id' => $payment->getKey()])->save();

        $outcome = $this->outcomeFrom($event);

        if ($outcome === null) {
            // An event type we do not act on. `ignored` rather than `failed`:
            // nothing went wrong and it must not appear in the feed.
            $this->mark($event, WebhookEventStatus::Ignored);

            return;
        }

        Tenancy::forTenant($tenant, function () use ($confirm, $payment, $outcome): void {
            $confirm($payment, succeeded: $outcome);
        });

        $this->mark($event, WebhookEventStatus::Processed);
    }

    /**
     * The gateway's reference for the payment this event concerns.
     *
     * Viva puts an order code at the top of `EventData`; another gateway will
     * nest it somewhere else entirely. That difference is the reason this is a
     * `match` with one arm rather than one path — and the reason it lives here
     * rather than in the contract (ADR-0004 fixes that at four methods).
     */
    private function referenceFrom(GatewayWebhookEvent $event): ?string
    {
        $payload = $event->payload;

        $reference = match ($event->provider) {
            PaymentGatewayName::Viva => data_get($payload, 'EventData.OrderCode'),
            default => null,
        };

        return is_scalar($reference) && (string) $reference !== '' ? (string) $reference : null;
    }

    /**
     * Did this event say the money arrived, or that it did not?
     *
     * Null means neither — an event type we do not act on, which is most of
     * what a gateway sends.
     */
    private function outcomeFrom(GatewayWebhookEvent $event): ?bool
    {
        $type = (string) $event->event_type;

        return match ($event->provider) {
            // Viva sends numeric event type ids: 1796 is a successful
            // transaction, 1798 a failed one. Numbers rather than words, which
            // is the same difference the error dictionary accommodates.
            PaymentGatewayName::Viva => match ($type) {
                '1796' => true,
                '1798' => false,
                default => null,
            },
            default => null,
        };
    }

    private function mark(GatewayWebhookEvent $event, WebhookEventStatus $status): void
    {
        $event->forceFill(['status' => $status, 'processed_at' => now()])->save();
    }

    private function markFailed(GatewayWebhookEvent $event, Throwable $exception): void
    {
        $event->forceFill([
            'status' => WebhookEventStatus::Failed,
            // The class and a truncated message, never the payload: it carries
            // a cardholder name and the last four digits (SEC-9, MYD-15).
            'error_message' => Str::limit($exception::class . ': ' . $exception->getMessage(), 490),
            'processed_at' => now(),
        ])->save();
    }
}
