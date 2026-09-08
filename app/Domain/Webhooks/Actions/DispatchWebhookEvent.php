<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Actions;

use App\Domain\Webhooks\Support\WebhookPayload;
use App\Enums\BookingSource;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns one thing that happened into a row per interested endpoint (OPS-19).
 *
 * ## The payload is built once, here, and never again
 *
 * A retry six hours later re-sends the stored bytes. Rebuilding would let a
 * booking cancelled in the meantime turn a `booking.confirmed` into a POST
 * describing a cancelled booking — the retries would disagree with each other
 * and with the event they claim to be. An event says what was true when it
 * happened; the next event says the rest.
 *
 * ## Two things never fire a webhook, and both are easy to forget
 *
 * **SAA-12** keeps test bookings out of every figure and export — but *not* out
 * of webhooks, because an operator wiring up their integration needs the event
 * to arrive. They are flagged `is_test` instead, and the contract tells
 * consumers to branch on it. That asymmetry is deliberate and is the one place
 * a test booking is allowed out.
 *
 * **BKG-34** is the real suppression: an imported booking *"MUST NOT trigger
 * confirmation notifications, invoices or webhooks."* Importing four seasons of
 * history from WooCommerce must not post four seasons of `booking.confirmed` at
 * somebody's accounting system, which would be an afternoon of support calls and
 * possibly four seasons of duplicate invoices.
 *
 * ## A failure here never breaks what happened
 *
 * BKG-14: a failing listener must not roll a confirmation back. So the whole of
 * this is wrapped — a webhook that cannot be *queued* is a logged problem, not a
 * booking that did not happen.
 */
final class DispatchWebhookEvent
{
    /**
     * @param  array<string, mixed>  $data  the `data` object of §8.2
     */
    public function __invoke(
        Tenant $tenant,
        WebhookEvent $event,
        array $data,
        bool $isTest = false,
        ?Carbon $now = null,
    ): int {
        $now ??= Carbon::now();

        $endpoints = Tenancy::forTenant($tenant, static fn () => WebhookEndpoint::query()
            ->deliverable()
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint): bool => $endpoint->wants($event))
            ->all());

        $queued = 0;

        foreach ($endpoints as $endpoint) {
            // One id per endpoint, not one per event. `Kaiki-Delivery-Id` is the
            // receiver's idempotency key, and two endpoints belonging to two
            // different systems must not be told to deduplicate against each
            // other's id.
            $eventId = (string) Str::uuid();

            $delivery = $this->record($tenant, $endpoint, $event, $eventId, $data, $isTest, $now);

            if ($delivery === null) {
                continue;
            }

            DeliverWebhook::dispatch($delivery->getKey());
            $queued++;
        }

        return $queued;
    }

    /** Convenience for the three booking events, which all carry the same shape. */
    public function forBooking(Booking $booking, WebhookEvent $event, ?Carbon $now = null): int
    {
        if ($booking->source === BookingSource::Import) {
            // BKG-34. See the class docblock.
            return 0;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            return 0;
        }

        // Inside the tenant, because the payload is the API's own resource and
        // that reads tenant-owned relations — a voucher ledger among them. Built
        // outside, it throws `TenantContextMissingException` from a listener,
        // which is TEN-4 working correctly and this code getting it wrong.
        $data = Tenancy::forTenant($tenant, static fn (): array => WebhookPayload::booking($booking));

        return $this($tenant, $event, $data, (bool) $booking->is_test, $now);
    }

    /**
     * The delivery row, or null if one already exists or the write failed.
     *
     * @param  array<string, mixed>  $data
     */
    private function record(
        Tenant $tenant,
        WebhookEndpoint $endpoint,
        WebhookEvent $event,
        string $eventId,
        array $data,
        bool $isTest,
        Carbon $now,
    ): ?WebhookDelivery {
        try {
            return Tenancy::forTenant($tenant, static fn (): WebhookDelivery => DB::transaction(
                static fn (): WebhookDelivery => WebhookDelivery::query()->create([
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'event' => $event,
                    'event_id' => $eventId,
                    'payload' => WebhookPayload::envelope($event, $eventId, $tenant, $data, $isTest, $now),
                    'status' => DeliveryStatus::Pending,
                    'attempts' => 0,
                    // Due immediately. The backoff starts after the first
                    // failure, not before the first attempt.
                    'next_attempt_at' => $now,
                ]),
            ));
        } catch (Throwable $e) {
            // BKG-14: the thing that happened stays happened. A webhook that
            // could not be queued is a line in the log and a row the operator
            // never sees, which is strictly better than a confirmation that
            // rolled back because somebody's integration table was locked.
            report($e);

            return null;
        }
    }
}
