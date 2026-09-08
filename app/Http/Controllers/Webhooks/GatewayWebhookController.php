<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\PaymentGatewayName;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessGatewayWebhook;
use App\Models\GatewayWebhookEvent;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The endpoint a gateway calls (spec PAY-5, PAY-6, PAY-7, AVL-47).
 *
 * ## It does four things and then stops
 *
 * PAY-6 gives it a five-second budget, and gateways retry anything slower —
 * which means a slow endpoint manufactures the duplicate deliveries it then has
 * to deduplicate. So:
 *
 * 1. **Verify the signature, before any parsing.** A payload parsed before it
 *    is verified is a payload an attacker chose.
 * 2. **Write the row**, in its own transaction, with the raw payload.
 * 3. **Answer 2xx.**
 * 4. Queue the work.
 *
 * No money logic happens here. `ProcessGatewayWebhook` does all of it, keyed on
 * the row's id, so a replay is a no-op rather than a second confirmation.
 *
 * ## No tenant, no key, no CSRF
 *
 * A gateway has none of the three. This route sits outside the `api.key` group
 * and outside the tenant middleware, and resolves its tenant from the payload —
 * which is what `payments_gateway_ref_idx` and
 * `integration_credentials.external_account_id` exist for, both deliberately
 * not tenant-first.
 *
 * ## An unverified request is refused *and recorded*
 *
 * PAY-7 asks for both. Refusing without recording means a stream of forged
 * webhooks from one address is invisible, and the source IP is the only thing
 * that makes it investigable — so the row is written with
 * `signature_valid = false` and the request is rate-limited by the route.
 */
final class GatewayWebhookController
{
    public function __construct(private readonly GatewayResolver $gateways) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $gateway = PaymentGatewayName::tryFrom($provider);

        if ($gateway === null || ! $gateway->isExternal()) {
            // `cash` and `bank_transfer` have no gateway and therefore no
            // webhook. A request naming one is not a routing accident; it is
            // somebody probing.
            return response()->json(['received' => false], 404);
        }

        $verified = $this->gateways->named($gateway)->verifyWebhook($request);

        // **Parsed only after verification.** `->all()` on an unverified body is
        // the attacker choosing what our JSON decoder does.
        $payload = $verified ? $request->all() : [];

        $event = $this->record($gateway, $request, $payload, $verified);

        if (! $verified) {
            // PAY-7: rejected, logged with the source IP, rate-limited by the
            // route. The IP is the only thing that makes a forged stream
            // investigable, and it is not personal data about a guest.
            Log::warning('payments.webhook_unverified', [
                'gateway' => $gateway->value,
                'ip' => $request->ip(),
                'event_id' => $event?->event_id,
            ]);

            return response()->json(['received' => false], 400);
        }

        if ($event === null) {
            // The unique index refused it: this exact event has been delivered
            // before. §2.7 — respond 200, do nothing. That *is* the idempotency
            // guarantee, and checking first would leave a window between the
            // check and the insert that two concurrent retries drive straight
            // through.
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        ProcessGatewayWebhook::dispatch($event->getKey());

        // 2xx before any money logic runs (PAY-6). The gateway stops retrying;
        // the work happens on a worker.
        return response()->json(['received' => true]);
    }

    /**
     * Write the row, or discover it already exists.
     *
     * @param  array<string, mixed>  $payload
     * @return GatewayWebhookEvent|null null when this delivery is a duplicate
     */
    private function record(
        PaymentGatewayName $gateway,
        Request $request,
        array $payload,
        bool $verified,
    ): ?GatewayWebhookEvent {
        $eventId = $this->eventIdFor($gateway, $payload, $request);

        try {
            // **Its own transaction**, and outside any tenancy: the row must
            // survive whatever the later money logic does, which is the whole
            // reason §2.7 says to write it first.
            return Tenancy::withoutTenancy(static fn (): GatewayWebhookEvent => DB::transaction(
                static fn (): GatewayWebhookEvent => GatewayWebhookEvent::query()->create([
                    'provider' => $gateway,
                    'event_id' => $eventId,
                    'event_type' => self::eventTypeFor($payload),
                    'signature_valid' => $verified,
                    // Stored whether verified or not. An unverified payload is
                    // evidence (PAY-7) and is encrypted like any other.
                    'payload' => $payload,
                    'status' => $verified ? WebhookEventStatus::Received : WebhookEventStatus::Ignored,
                    'received_at' => now(),
                ]),
            ));
        } catch (QueryException $exception) {
            if (self::isDuplicate($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * The gateway's own event id, which is our idempotency key.
     *
     * Viva does not send an event id at all, so its order code plus event type
     * stands in — the same order code cannot produce two identical events, and
     * two different events on one order are two rows, which is correct. A
     * gateway that does send one gets an arm of its own here.
     *
     * A request with neither gets a synthetic id, so an unverified probe is
     * still recorded rather than colliding with every other one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventIdFor(PaymentGatewayName $gateway, array $payload, Request $request): string
    {
        $id = match ($gateway) {
            PaymentGatewayName::Viva => implode(':', array_filter([
                (string) data_get($payload, 'EventData.OrderCode', ''),
                (string) ($payload['EventTypeId'] ?? ''),
            ])),
            default => null,
        };

        if (is_string($id) && trim($id) !== '') {
            return mb_substr($id, 0, 190);
        }

        // Deliberately unique per request: an unverified probe must be recorded
        // rather than deduplicated away against the last one.
        return 'unidentified:' . $request->ip() . ':' . now()->getTimestampMs();
    }

    /** @param  array<string, mixed>  $payload */
    private static function eventTypeFor(array $payload): ?string
    {
        $type = $payload['type'] ?? $payload['EventTypeId'] ?? null;

        return is_scalar($type) ? mb_substr((string) $type, 0, 64) : null;
    }

    /** The unique-index violation, on either driver. */
    private static function isDuplicate(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'Integrity constraint violation: 1062');
    }
}
